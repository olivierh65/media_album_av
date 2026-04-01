<?php

namespace Drupal\media_album_av\Controller;

use Drupal\image\Controller\ImageStyleDownloadController;
use Drupal\image\ImageStyleInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 *
 */
class AlbumImageStyleDownloadController extends ImageStyleDownloadController {

  use \Drupal\media_album_av\HmacValidatorTrait;

  /**
   *
   */
  public function deliver(Request $request, $scheme, ImageStyleInterface $image_style, string $required_derivative_scheme,) {
    // Construire l'URI du dérivé via l'API Drupal : buildUri() prend la source
    // et retourne l'URI de la vignette (ex: private://styles/medium/private/...).
    $source_uri = $scheme . '://' . $request->query->get('file');
    $n_id = $request->query->get('n_id');
    $m_id = $request->query->get('m_id');
    
    // Validate n_id is a valid integer before attempting to load
    if (!is_numeric($n_id) || $n_id <= 0) {
      return parent::deliver($request, $scheme, $image_style, $required_derivative_scheme);
    }
    
    $derivative_uri = $image_style->buildUri($source_uri);

    $node = $this->entityTypeManager()
      ->getStorage('node')
      ->load((int) $n_id);
    // Verifie si l'utilisateur a le droit de voir le node (et donc les images de l'album)
    if ($node && $node->access('view')) {
      if ($scheme === 'private' && $this->validateHmac($request)) {
        $file_system = \Drupal::service('file_system');
        $realpath = $file_system->realpath($derivative_uri);

        if ($realpath && file_exists($realpath)) {
          $headers = [
            'Content-Type' => \Drupal::service('file.mime_type.guesser')->guessMimeType($derivative_uri),
            'Cache-Control' => 'private, no-cache, must-revalidate',
          ];

          return new BinaryFileResponse($realpath, 200, $headers, TRUE);
        }
      }
    }

    // Fallback au parent si le HMAC est absent ou invalide.
    return parent::deliver($request, $scheme, $image_style, $required_derivative_scheme);
  }

}
