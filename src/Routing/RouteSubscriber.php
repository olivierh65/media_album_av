<?php

namespace Drupal\media_album_av\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Liste les modifications de routes pour media_album_av.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // On cible la route native de Drupal pour les fichiers privés.
    if ($route = $collection->get('system.private_file_download')) {
      $route->setDefault('_controller', '\Drupal\media_album_av\Controller\AlbumFileDownloadController::download');
    }
    if ($route = $collection->get('system.files')) {
      $route->setDefault('_controller', '\Drupal\media_album_av\Controller\AlbumFileDownloadController::download');
    }
    if ($route = $collection->get('image.style_private')) {
      $route->setDefault('_controller',
      '\Drupal\media_album_av\Controller\AlbumImageStyleDownloadController::deliver');
    }
  }

}
