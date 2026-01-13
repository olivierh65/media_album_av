<?php

namespace Drupal\media_album_av\Service;

use Drupal\node\Entity\Node;
use Drupal\media\Entity\Media;

/**
 *
 */
class MediaAlbumAvIntegrityChecker {

  /**
   * Vérifie les albums et retourne les problèmes.
   */
  public function check(): array {
    $issues = [];

    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'media_album_av')
      ->accessCheck(FALSE)
      ->execute();

    foreach ($nids as $nid) {
      $node = Node::load($nid);
      if (!$node->hasField('field_media_album_av_media')) {
        continue;
      }

      $broken = [];
      foreach ($node->get('field_media_album_av_media')->getValue() as $item) {
        if (!Media::load($item['target_id'])) {
          $broken[] = $item['target_id'];
        }
      }

      if ($broken) {
        $issues[$nid] = [
          'title' => $node->label(),
          'broken' => $broken,
        ];
      }
    }

    return $issues;
  }

  /**
   * Répare les références cassées.
   */
  public function repair(array $nids = []): int {
    $removed = 0;
    $issues = $this->check();

    foreach ($issues as $nid => $data) {
      if ($nids && !in_array($nid, $nids)) {
        continue;
      }

      $node = Node::load($nid);
      $clean = [];

      foreach ($node->get('field_media_album_av_media')->getValue() as $item) {
        if (!in_array($item['target_id'], $data['broken'])) {
          $clean[] = $item;
        }
        else {
          $removed++;
        }
      }

      $node->set('field_media_album_av_media', $clean);
      $node->save();
    }

    return $removed;
  }

}
