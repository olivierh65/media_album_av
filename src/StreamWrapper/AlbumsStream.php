<?php

namespace Drupal\media_album_av\StreamWrapper;

use Drupal\s3fs\StreamWrapper\S3fsStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;

/**
 * Defines a custom S3 stream wrapper for archives.
 *
 * Provides support for storing files in a dedicated S3 location
 * using the "albumav://" scheme.
 */
class AlbumsStream extends S3fsStream {

  /**
   * {@inheritdoc}
   */
  public static function getType() {
    return StreamWrapperInterface::NORMAL;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return t('Albums (S3)');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return t('albumAV files stored on S3.');
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    $path = str_replace('\\', '/', $this->getTarget());
    $config = \Drupal::config('s3fs.settings');

    $bucket = $config->get('bucket');
    $use_customhost = $config->get('use_customhost');
    $use_path_style_endpoint = $config->get('use_path_style_endpoint');
    $use_https = $config->get('use_https');

    // Préfixe spécifique pour ce stream wrapper.
    $prefix = 'albumav';

    // If using a custom host.
    if ($use_customhost) {
      $hostname = $config->get('hostname');
      // Remove trailing slashes and protocol if present.
      $hostname = rtrim($hostname, '/');
      if (strpos($hostname, 'http') === 0) {
        $hostname = preg_replace('~^https?://~', '', $hostname);
      }

      // Determine protocol.
      $protocol = $use_https ? 'https' : 'http';

      // Path-style endpoint: https://hostname/bucket/prefix/path
      if ($use_path_style_endpoint) {
        return sprintf(
          '%s://%s/%s/%s/%s',
          $protocol,
          $hostname,
          $bucket,
          $prefix,
          $path
        );
      }
      // Virtual-hosted-style: https://bucket.hostname/prefix/path
      else {
        return sprintf(
          '%s://%s.%s/%s/%s',
          $protocol,
          $bucket,
          $hostname,
          $prefix,
          $path
        );
      }
    }

    // Standard AWS S3 format.
    $region = $config->get('region');
    return sprintf(
      'https://%s.s3.%s.amazonaws.com/%s/%s',
      $bucket,
      $region,
      $prefix,
      $path
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getS3Key($uri) {
    $target = $this->getTarget($uri);

    // Ajouter le préfixe "albumav/" à tous les fichiers.
    return 'albumav/' . $target;
  }

  /**
   * {@inheritdoc}
   */
  public function getTarget($uri = NULL) {
    if ($uri === NULL) {
      $uri = $this->uri;
    }

    // Extraire le chemin après "albumav://".
    $path = preg_replace('/^albumav:\/\//', '', $uri);

    return $path;
  }

}
