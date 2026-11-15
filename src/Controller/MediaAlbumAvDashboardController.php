<?php

namespace Drupal\media_album_av\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Dashboard listing all Media Album AV tools.
 */
class MediaAlbumAvDashboardController extends ControllerBase {

  /**
   * Builds the dashboard page.
   *
   * @return array
   *   A render array.
   */
  public function overview(): array {
    $items = [
      'settings' => [
        'route' => 'media_album_av.settings',
        'title' => $this->t('Settings'),
        'description' => $this->t('Configure storage, thumbnails, media types and directories.'),
      ],
      'create_album' => [
        'route' => 'media_album_av.create_album',
        'title' => $this->t('Create Album'),
        'description' => $this->t('Create a new media album.'),
      ],
      'repair' => [
        'route' => 'media_album_av.repair_references',
        'title' => $this->t('Repair'),
        'description' => $this->t('Check and repair broken media references.'),
      ],
      'check' => [
        'route' => 'media_album_av.check_albums',
        'title' => $this->t('Check References'),
        'description' => $this->t('Check media album references and file status.'),
      ],
      'migrate' => [
        'route' => 'media_album_av.migrate_storage',
        'title' => $this->t('Migrate Storage'),
        'description' => $this->t('Migrate album media files to another storage scheme.'),
      ],
      'export' => [
        'route' => 'media_album_av.export_import',
        'title' => $this->t('Export / Import'),
        'description' => $this->t('Export an album (taxonomy, medias, attributes, files) or import it on another instance.'),
      ],
    ];

    $rows = [];
    foreach ($items as $item) {
      try {
        $url = Url::fromRoute($item['route']);
        $link = Link::fromTextAndUrl($item['title'], $url)->toString();
      }
      catch (\Exception $e) {
        $link = $item['title'];
      }
      $rows[] = [$link, $item['description']];
    }

    return [
      '#type' => 'table',
      '#header' => [$this->t('Tool'), $this->t('Description')],
      '#rows' => $rows,
    ];
  }

}
