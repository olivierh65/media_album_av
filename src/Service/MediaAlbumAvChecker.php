<?php

namespace Drupal\media_album_av\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;

/**
 * Service to check media album integrity and details.
 */
class MediaAlbumAvChecker {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileSystemInterface $fileSystem;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FileSystemInterface $fileSystem
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->fileSystem = $fileSystem;
  }

  /**
   * Get all albums with their media references details.
   *
   * @return array
   *   Array of albums with detailed media information.
   */
  public function getAlbumsDetails() {
    $albums = [];

    // Load all album nodes.
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'media_album_av')
      ->accessCheck(FALSE);

    $nids = $query->execute();

    foreach ($nids as $nid) {
      $node = Node::load($nid);
      if (!$node || !$node->hasField('field_media_album_av_media')) {
        continue;
      }

      $field_items = $node->get('field_media_album_av_media');
      if ($field_items->isEmpty()) {
        continue;
      }

      $media_details = [];
      $delta = 0;

      foreach ($field_items as $field_item) {
        $media_id = $field_item->target_id;
        $media = Media::load($media_id);

        $media_info = [
          'delta' => $delta,
          'media_id' => $media_id,
          'media_name' => $media ? $media->label() : $this->t('Unknown (ID: @id)', ['@id' => $media_id]),
          'media_status' => $media ? ($media->isPublished() ? 'Published' : 'Unpublished') : 'Missing',
          'media_exists' => $media ? TRUE : FALSE,
          'files' => [],
        ];

        // Get file information if media exists.
        if ($media) {
          $media_info['files'] = $this->getMediaFiles($media);
        }

        $media_details[] = $media_info;
        $delta++;
      }

      $albums[$nid] = [
        'title' => $node->label(),
        'nid' => $nid,
        'media_count' => count($media_details),
        'media' => $media_details,
      ];
    }

    return $albums;
  }

  /**
   * Get all files associated with a media entity.
   *
   * @param \Drupal\media\Entity\Media $media
   *   The media entity.
   *
   * @return array
   *   Array of file information.
   */
  protected function getMediaFiles(Media $media): array {
    $files = [];

    $source_field = $media->getSource()->getConfiguration()['source_field'] ?? NULL;
    if (!$source_field || !$media->hasField($source_field)) {
      return $files;
    }

    $field_items = $media->get($source_field);
    if ($field_items->isEmpty()) {
      return $files;
    }

    foreach ($field_items as $field_item) {
      $file_id = $field_item->target_id;
      $file = $field_item->entity;

      if (!$file) {
        $files[] = [
          'fid' => $file_id,
          'filename' => $this->t('Missing file'),
          'size' => 'N/A',
          'status' => 'Missing',
          'exists' => FALSE,
        ];
        continue;
      }

      $file_uri = $file->getFileUri();
      $file_exists = file_exists($file_uri);
      $file_size = $file_exists ? filesize($file_uri) : 0;

      $files[] = [
        'fid' => $file->id(),
        'filename' => $file->getFilename(),
        'size' => $this->formatBytes($file_size),
        'status' => $file->isPermanent() ? 'Permanent' : 'Temporary',
        'exists' => $file_exists,
      ];
    }

    return $files;
  }

  /**
   * Format bytes to human-readable format.
   *
   * @param int $bytes
   *   Number of bytes.
   *
   * @return string
   *   Formatted size.
   */
  protected function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, 2) . ' ' . $units[$pow];
  }

  /**
   * Translate a string.
   *
   * @param string $string
   *   String to translate.
   * @param array $args
   *   Arguments.
   *
   * @return string
   *   Translated string.
   */
  protected function t(string $string, array $args = []): string {
    return \Drupal::translation()->translate($string, $args);
  }

}
