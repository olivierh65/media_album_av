<?php

namespace Drupal\media_album_av\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Maintenance service for media album image derivatives.
 */
class MediaAlbumAvDerivativesMaintenance {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the derivatives maintenance service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Generate or regenerate derivatives for all album images.
   *
   * @param bool $force_regenerate
   *   TRUE to flush existing derivatives before regeneration.
   *
   * @return array
   *   Execution statistics.
   */
  public function regenerateAll(bool $force_regenerate = FALSE): array {
    $stats = [
      'albums_scanned' => 0,
      'medias_scanned' => 0,
      'images_processed' => 0,
      'styles_count' => 0,
      'derivatives_generated' => 0,
      'errors' => 0,
    ];

    $styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple();
    if (empty($styles)) {
      return $stats;
    }
    $stats['styles_count'] = count($styles);

    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'media_album_av')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids)) {
      return $stats;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $stats['albums_scanned'] = count($nodes);

    $media_ids = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if (!$node->hasField('field_media_album_av_media')) {
        continue;
      }
      foreach ($node->get('field_media_album_av_media')->getValue() as $item) {
        $mid = (int) ($item['target_id'] ?? 0);
        if ($mid > 0) {
          $media_ids[$mid] = $mid;
        }
      }
    }

    if (empty($media_ids)) {
      return $stats;
    }

    $medias = $this->entityTypeManager->getStorage('media')->loadMultiple($media_ids);
    $stats['medias_scanned'] = count($medias);

    foreach ($medias as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }

      if ($media->getSource()->getPluginId() !== 'image') {
        continue;
      }

      $source_config = $media->getSource()->getConfiguration();
      $source_field = $source_config['source_field'] ?? NULL;
      if (!$source_field || !$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
        continue;
      }

      $file = $media->get($source_field)->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }

      $stats['images_processed']++;
      $uri = $file->getFileUri();

      foreach ($styles as $style) {
        if (!$style instanceof ImageStyle) {
          continue;
        }
        try {
          if ($force_regenerate) {
            $style->flush($uri);
          }

          $derivative_uri = $style->buildUri($uri);
          if ($style->createDerivative($uri, $derivative_uri)) {
            $stats['derivatives_generated']++;
          }
        }
        catch (\Exception $e) {
          $stats['errors']++;
          \Drupal::logger('media_album_av')->warning(
            'Derivative generation failed for media @mid on style @style: @message',
            [
              '@mid' => $media->id(),
              '@style' => $style->id(),
              '@message' => $e->getMessage(),
            ]
          );
        }
      }
    }

    return $stats;
  }

}
