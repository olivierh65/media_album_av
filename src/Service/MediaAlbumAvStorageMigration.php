<?php

namespace Drupal\media_album_av\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Migrates album media files from one storage scheme to another.
 *
 * Example: private:// -> albumav://. Files are copied through a local
 * temporary file (S3fsFileService::copy() cannot copy a local file straight
 * to S3), then the file entity URI is updated. Thumbnails and image style
 * derivatives are moved or regenerated so the album keeps working.
 */
class MediaAlbumAvStorageMigration {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a MediaAlbumAvStorageMigration object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system, StreamWrapperManagerInterface $stream_wrapper_manager, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->logger = $logger;
  }

  /**
   * Collects every media ID of the given albums.
   *
   * Used to build batch operations: one operation per media.
   *
   * @param int[] $nids
   *   Album node IDs. Empty array means all media_album_av nodes.
   *
   * @return int[]
   *   Media IDs.
   */
  public function collectMediaIds(array $nids): array {
    if (empty($nids)) {
      $nids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'media_album_av')
        ->accessCheck(FALSE)
        ->execute();
    }

    if (empty($nids)) {
      return [];
    }

    $mids = [];
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface || !$node->hasField('field_media_album_av_media')) {
        continue;
      }
      foreach ($node->get('field_media_album_av_media')->referencedEntities() as $media) {
        if ($media instanceof MediaInterface) {
          $mids[(int) $media->id()] = (int) $media->id();
        }
      }
    }

    return array_values($mids);
  }

  /**
   * Migrates a single media entity by ID (batch-friendly).
   *
   * @param int $mid
   *   Media ID.
   * @param string $target_scheme
   *   Target scheme: "private", "public" or "albumav".
   * @param bool $regenerate_derivatives
   *   Whether to regenerate image style derivatives after the move.
   *
   * @return array
   *   Statistics: files_moved, thumbnails_moved, derivatives_generated, errors.
   */
  public function migrateSingleMedia(int $mid, string $target_scheme, bool $regenerate_derivatives = TRUE): array {
    $media = $this->entityTypeManager->getStorage('media')->load($mid);
    if (!$media instanceof MediaInterface) {
      return [
        'files_moved' => 0,
        'thumbnails_moved' => 0,
        'derivatives_generated' => 0,
        'errors' => 1,
      ];
    }

    return $this->migrateMedia($media, $target_scheme, $regenerate_derivatives);
  }

  /**
   * Migrates every media of the given albums to the target scheme.
   *
   * @param int[] $nids
   *   Album node IDs. Empty array means all media_album_av nodes.
   * @param string $target_scheme
   *   Target scheme: "private", "public" or "albumav".
   * @param bool $regenerate_derivatives
   *   Whether to regenerate image style derivatives after the move.
   *
   * @return array
   *   Statistics: albums, medias, files_moved, thumbnails_moved,
   *   derivatives_generated, errors.
   */
  public function migrateAlbums(array $nids, string $target_scheme, bool $regenerate_derivatives = TRUE): array {
    $stats = [
      'albums' => 0,
      'medias' => 0,
      'files_moved' => 0,
      'thumbnails_moved' => 0,
      'derivatives_generated' => 0,
      'errors' => 0,
    ];

    if (!in_array($target_scheme, ['public', 'private', 'albumav'], TRUE)) {
      $stats['errors']++;
      return $stats;
    }

    if (empty($nids)) {
      $nids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'media_album_av')
        ->accessCheck(FALSE)
        ->execute();
    }

    if (empty($nids)) {
      return $stats;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface || !$node->hasField('field_media_album_av_media')) {
        continue;
      }
      $stats['albums']++;

      foreach ($node->get('field_media_album_av_media')->referencedEntities() as $media) {
        if (!$media instanceof MediaInterface) {
          continue;
        }
        $stats['medias']++;
        $result = $this->migrateMedia($media, $target_scheme, $regenerate_derivatives);
        $stats['files_moved'] += $result['files_moved'];
        $stats['thumbnails_moved'] += $result['thumbnails_moved'];
        $stats['derivatives_generated'] += $result['derivatives_generated'];
        $stats['errors'] += $result['errors'];
      }
    }

    return $stats;
  }

  /**
   * Migrates a single media entity to the target scheme.
   */
  protected function migrateMedia(MediaInterface $media, string $target_scheme, bool $regenerate_derivatives): array {
    $result = [
      'files_moved' => 0,
      'thumbnails_moved' => 0,
      'derivatives_generated' => 0,
      'errors' => 0,
    ];

    try {
      $source_config = $media->getSource()->getConfiguration();
      $source_field = $source_config['source_field'] ?? NULL;
      if (!$source_field || !$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
        return $result;
      }

      $file = $media->get($source_field)->entity;
      if (!$file instanceof FileInterface) {
        return $result;
      }

      $old_uri = $file->getFileUri();
      $old_scheme = $this->streamWrapperManager->getScheme($old_uri);
      if ($old_scheme === $target_scheme) {
        return $result;
      }

      $new_uri = $this->buildTargetUri($old_uri, $target_scheme);
      if ($new_uri === NULL) {
        $result['errors']++;
        return $result;
      }

      if (!$this->copyThroughTempFile($old_uri, $new_uri)) {
        $result['errors']++;
        $this->logger->error('Migration failed for file @fid (@old -> @new).', [
          '@fid' => $file->id(),
          '@old' => $old_uri,
          '@new' => $new_uri,
        ]);
        return $result;
      }

      $file->setFileUri($new_uri);
      $file->save();
      $result['files_moved']++;

      // Move the thumbnail file as well when it lives on the old scheme.
      $thumbnail_moved = $this->migrateThumbnail($media, $old_scheme, $target_scheme);
      $result['thumbnails_moved'] += $thumbnail_moved ? 1 : 0;

      // Old derivatives are stale: flush them, then regenerate on the new URI.
      if ($regenerate_derivatives && $media->getSource()->getPluginId() === 'image') {
        $result['derivatives_generated'] += $this->regenerateDerivatives($file);
      }

      // Remove the old source file once the new one is saved.
      if (file_exists($old_uri)) {
        try {
          $this->fileSystem->delete($old_uri);
        }
        catch (\Exception $e) {
          $this->logger->warning('Could not delete old file @uri after migration: @message', [
            '@uri' => $old_uri,
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }
    catch (\Exception $e) {
      $result['errors']++;
      $this->logger->error('Migration failed for media @mid: @message', [
        '@mid' => $media->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    return $result;
  }

  /**
   * Migrates the media thumbnail file when needed.
   *
   * @return bool
   *   TRUE when a thumbnail file was moved.
   */
  protected function migrateThumbnail(MediaInterface $media, ?string $old_scheme, string $target_scheme): bool {
    if (!$media->hasField('thumbnail') || $media->get('thumbnail')->isEmpty()) {
      return FALSE;
    }

    $thumbnail = $media->get('thumbnail')->entity;
    if (!$thumbnail instanceof FileInterface) {
      return FALSE;
    }

    $old_uri = $thumbnail->getFileUri();
    if ($this->streamWrapperManager->getScheme($old_uri) !== $old_scheme) {
      return FALSE;
    }

    $new_uri = $this->buildTargetUri($old_uri, $target_scheme);
    if ($new_uri === NULL || !$this->copyThroughTempFile($old_uri, $new_uri)) {
      return FALSE;
    }

    $thumbnail->setFileUri($new_uri);
    $thumbnail->save();

    if (file_exists($old_uri)) {
      try {
        $this->fileSystem->delete($old_uri);
      }
      catch (\Exception $e) {
        // Non-blocking: the new thumbnail already works.
      }
    }

    return TRUE;
  }

  /**
   * Flushes old derivatives and regenerates them on the new file URI.
   *
   * @return int
   *   Number of generated derivatives.
   */
  protected function regenerateDerivatives(FileInterface $file): int {
    $generated = 0;
    $styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple();
    foreach ($styles as $style) {
      try {
        $style->flush($file->getFileUri());

        if (\Drupal::hasService('media_album_s3.thumbnail_storage')) {
          $derivative_uri = \Drupal::service('media_album_s3.thumbnail_storage')->getDerivativeUri($style, $file->getFileUri());
        }
        else {
          $derivative_uri = $style->buildUri($file->getFileUri());
        }

        if ($style->createDerivative($file->getFileUri(), $derivative_uri)) {
          $generated++;
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Derivative regeneration failed for file @fid on style @style: @message', [
          '@fid' => $file->id(),
          '@style' => $style->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $generated;
  }

  /**
   * Builds the target URI, preserving the directory tree.
   */
  protected function buildTargetUri(string $old_uri, string $target_scheme): ?string {
    $target = $this->streamWrapperManager->getTarget($old_uri);
    if ($target === FALSE || $target === '') {
      return NULL;
    }

    return $target_scheme . '://' . ltrim($target, '/');
  }

  /**
   * Copies a file through a local temporary file.
   *
   * Direct S3fsFileService::copy() from a local file to S3 fails, so the
   * content is staged in temporary:// first, then moved to the destination.
   */
  protected function copyThroughTempFile(string $source_uri, string $destination_uri): bool {
    if (!file_exists($source_uri)) {
      return FALSE;
    }

    $directory = $this->fileSystem->dirname($destination_uri);
    try {
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }
    catch (\Exception $e) {
      return FALSE;
    }

    $temp_uri = $this->fileSystem->tempnam('temporary://', 'albumav_migrate_');
    if (!$temp_uri) {
      return FALSE;
    }

    try {
      $source_handle = @fopen($source_uri, 'rb');
      $temp_handle = @fopen($temp_uri, 'wb');
      if (!$source_handle || !$temp_handle) {
        if ($source_handle) {
          fclose($source_handle);
        }
        if ($temp_handle) {
          fclose($temp_handle);
        }
        return FALSE;
      }

      $copied = stream_copy_to_stream($source_handle, $temp_handle);
      fclose($source_handle);
      fclose($temp_handle);
      if ($copied === FALSE) {
        return FALSE;
      }

      $destination_uri = $this->fileSystem->getDestinationFilename($destination_uri, FileExists::Replace);
      $this->fileSystem->move($temp_uri, $destination_uri, FileExists::Replace);
      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
    finally {
      if (file_exists($temp_uri)) {
        @$this->fileSystem->delete($temp_uri);
      }
    }
  }

}
