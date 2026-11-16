<?php

namespace Drupal\media_album_av\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams album export archives stored under private://.
 *
 * Why a dedicated controller instead of a direct file URL?
 * - temporary:// (/tmp) is NOT web-accessible and FileUrlGenerator cannot
 *   produce a working HTTP URL for it.
 * - private:// files are served by Drupal (/system/files) but a dedicated
 *   route gives us: permission check, path confinement, Content-Disposition
 *   attachment, Accept-Ranges + automatic Range support (resume) via
 *   BinaryFileResponse, and correct handling of very large files (several GB,
 *   streamed without loading into memory).
 */
class ExportDownloadController extends ControllerBase {

  /**
   * Allowed export base directory (URI).
   */
  const EXPORT_BASE_URI = 'private://album-export';

  /**
   * Streams one export file.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request with ?file=<relative path inside private://album-export>.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   The streamed download response (supports Range / resume).
   */
  public function download(Request $request): BinaryFileResponse {
    $relative = (string) $request->query->get('file', '');
    // Confinement strict : pas de chemin absolu, pas de "..", pas de scheme.
    $relative = trim($relative, "/");
    if ($relative === '' || strpos($relative, '..') !== FALSE || strpos($relative, ':') !== FALSE || $relative[0] === '.') {
      throw new NotFoundHttpException();
    }
    // N'autoriser que les archives produites par l'export.
    if (!preg_match('/\.zip(\.part\d+)?$/', $relative)) {
      throw new NotFoundHttpException();
    }

    $uri = self::EXPORT_BASE_URI . '/' . $relative;
    $file_system = \Drupal::service('file_system');
    $realpath = $file_system->realpath($uri);
    if (!$realpath || !is_file($realpath) || !is_readable($realpath)) {
      throw new NotFoundHttpException();
    }

    // Double confinement : le chemin réel doit rester sous le dossier d'export.
    $base_real = $file_system->realpath(self::EXPORT_BASE_URI);
    if (!$base_real || strpos($realpath, $base_real) !== 0) {
      throw new AccessDeniedHttpException();
    }

    // Pas de limite de temps pour les archives de plusieurs Go.
    @set_time_limit(0);
    // Couper le output buffering pour streamer.
    while (ob_get_level() > 0) {
      @ob_end_clean();
    }

    $response = new BinaryFileResponse($realpath, 200, [
      'Content-Type' => 'application/zip',
      'Accept-Ranges' => 'bytes',
      'Cache-Control' => 'private, no-cache, must-revalidate',
    ], FALSE);
    // Attachment => force le téléchargement avec le bon nom de fichier.
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($realpath));
    // Active la gestion des requêtes Range (reprise) et l'envoi par chunks.
    // BinaryFileResponse gère If-Range / Range automatiquement dans prepare().
    return $response;
  }

}
