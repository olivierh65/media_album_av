<?php

namespace Drupal\media_album_av\Controller;

use Drupal\system\FileDownloadController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 *
 */
class AlbumFileDownloadController extends FileDownloadController {

  use \Drupal\media_album_av\HmacValidatorTrait;

  /**
   * {@inheritdoc}
   */
  public function download(Request $request, $scheme = 'private') {
    $target = $request->query->get('file');
    $uri = $scheme . '://' . $target;

    $n_id = $request->query->get('n_id');
    $m_id = $request->query->get('m_id');

    // Validate n_id is a valid integer before attempting to load.
    if (!is_numeric($n_id) || $n_id <= 0) {
      return parent::download($request, $scheme);
    }

    $node = $this->entityTypeManager()
      ->getStorage('node')
      ->load((int) $n_id);
    // Verifie si l'utilisateur a le droit de voir le node (et donc les images de l'album)
    if ($node && $node->access('view')) {
      if ($scheme === 'private' && $this->validateHmac($request)) {
        $file_system = \Drupal::service('file_system');
        $realpath = $file_system->realpath($uri);

        // On vérifie l'existence physique et la référence à l'album.
        if ($realpath && file_exists($realpath) /* && $this->fileIsReferencedByAlbum($uri) */) {

          $headers = [
            // 'Content-Type' => \Drupal::service('file.mime_type.guesser')->guessMimeType($uri),
            'Cache-Control' => 'private, no-cache, must-revalidate',
          ];

          // BinaryFileResponse gère très bien le streaming et les gros fichiers.
          return new BinaryFileResponse($uri, 200, $headers, TRUE);
        }
      }
    }

    // Fallback : On laisse le Core gérer (et donc les hooks s'appliquer)
    return parent::download($request, $scheme);

  }

  /**
   * Reconstruit l'URI private:// depuis la requête.
   */
  protected function buildUri(Request $request, string $scheme): string {
    $target = $request->query->get('file');
    // FileDownloadController reconstruit l'URI ainsi :
    return $scheme . '://' . $target;
  }

  /**
   * Vérifie que l'URI est référencée par au moins un node media_album_av.
   */
  protected function fileIsReferencedByAlbum(string $uri): bool {
    $entity_type_manager = \Drupal::entityTypeManager();

    $files = $entity_type_manager->getStorage('file')
      ->loadByProperties(['uri' => $uri]);
    if (empty($files)) {
      return FALSE;
    }
    $file = reset($files);

    $media_storage = $entity_type_manager->getStorage('media');
    foreach (['field_media_image', 'field_media_video_file'] as $field) {
      $medias = $media_storage->loadByProperties([$field => $file->id()]);
      foreach ($medias as $media) {
        $count = $entity_type_manager->getStorage('node')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'media_album_av')
          ->condition('field_media_album_items', $media->id())
          ->count()
          ->execute();
        if ($count > 0) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
