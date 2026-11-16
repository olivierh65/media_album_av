<?php

namespace Drupal\media_album_av\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Psr\Log\LoggerInterface;

/**
 * Exports and imports album AV packages.
 *
 * An export package is a directory containing:
 * - manifest.json: album fields, taxonomy terms (by path), medias with their
 *   attributes (directory, event, author, category, description, title...).
 * - files/: the source files and thumbnails, referenced by relative path.
 *
 * On import, missing taxonomy terms are recreated from their path, files are
 * copied to the target scheme, and medias + album node are recreated.
 */
class MediaAlbumAvExportImport {

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
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a MediaAlbumAvExportImport object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->logger = $logger;
  }

  /**
   * Prepares an export package (directories + manifest skeleton).
   *
   * @param int $nid
   *   Album node ID.
   * @param string $destination
   *   Destination directory URI.
   *
   * @return array|null
   *   Manifest skeleton with album, terms and media IDs, or NULL on failure.
   */
  public function prepareExport(int $nid, string $destination): ?array {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'media_album_av') {
      return NULL;
    }

    $files_dir = $destination . '/files';
    $destination_copy = $destination;
    $files_dir_copy = $files_dir;
    $this->fileSystem->prepareDirectory($destination_copy, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $this->fileSystem->prepareDirectory($files_dir_copy, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $term_paths = [];
    foreach ($this->collectTermIds($node) as $tid) {
      $path = $this->getTermPath($tid);
      if ($path !== NULL) {
        $term_paths[$tid] = $path;
      }
    }

    $mids = [];
    foreach ($node->get('field_media_album_av_media')->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }
      $mids[] = (int) $media->id();
      foreach ($this->collectTermIds($media) as $tid) {
        if (!isset($term_paths[$tid])) {
          $path = $this->getTermPath($tid);
          if ($path !== NULL) {
            $term_paths[$tid] = $path;
          }
        }
      }
    }

    return [
      'version' => 1,
      'album' => $this->exportNodeFields($node),
      'terms' => array_values($term_paths),
      'term_paths' => $term_paths,
      'mids' => $mids,
      'medias' => [],
      'destination' => $destination,
    ];
  }

  /**
   * Exports a single media into a prepared manifest (batch-friendly).
   *
   * @param array $manifest
   *   Manifest skeleton from prepareExport(), passed by reference.
   * @param int $mid
   *   Media ID.
   *
   * @return array
   *   Statistics: medias, files, errors.
   */
  public function exportSingleMedia(array &$manifest, int $mid): array {
    $result = ['medias' => 0, 'files' => 0, 'errors' => 0];
    $media = $this->entityTypeManager->getStorage('media')->load($mid);
    if (!$media instanceof MediaInterface) {
      $result['errors']++;
      return $result;
    }

    $entry = $this->exportMedia($media, $manifest['destination'] . '/files', $manifest['term_paths'] ?? []);
    if ($entry === NULL) {
      $result['errors']++;
      return $result;
    }

    $manifest['medias'][] = $entry;
    $result['medias']++;
    $result['files'] += count($entry['files']);
    return $result;
  }

  /**
   * Finalizes an export: writes the manifest and creates the archive.
   *
   * @param array $manifest
   *   Manifest built by prepareExport() + exportSingleMedia().
   * @param int $part_size
   *   Optional split size in bytes. 0 = single .zip archive. When > 0 and the
   *   archive is bigger than this size, it is split into
   *   <name>.zip.part1, .part2, ... (binary split, reassemblable with
   *   combineArchiveParts() or `cat *.part* > archive.zip`). Each part is
   *   downloaded separately through the export-download controller, which
   *   solves both the "several GB download" issue (resume per part, no PHP
   *   memory/timeout blowup) and the temporary:// issue (parts live under
   *   private://album-export, streamed by Drupal).
   *
   * @return array
   *   Statistics: terms, archive (first URI for BC), archives (all URIs),
   *   multipart (bool), part_size.
   */
  public function finalizeExport(array $manifest, int $part_size = 0): array {
    $destination = $manifest['destination'];
    unset($manifest['term_paths'], $manifest['mids'], $manifest['destination']);
    file_put_contents($destination . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $archive = $this->createArchive($destination);
    $archives = $archive !== NULL ? [$archive] : [];
    $multipart = FALSE;
    if ($archive !== NULL && $part_size > 0) {
      $parts = $this->splitArchive($archive, $part_size);
      if (count($parts) > 1) {
        $archives = $parts;
        $multipart = TRUE;
      }
    }
    return [
      'terms' => count($manifest['terms'] ?? []),
      'archive' => $archives[0] ?? NULL,
      'archives' => $archives,
      'multipart' => $multipart,
      'part_size' => $part_size,
    ];
  }

  /**
   * Prepares an import: extracts the archive and maps taxonomy terms.
   *
   * @param string $source
   *   Package directory URI or .zip archive URI.
   *
   * @return array|null
   *   Import state (source dir, manifest, tid_map), or NULL on failure.
   */
  public function prepareImport(string $source): ?array {
    if (substr($source, -4) === '.zip' || preg_match('/\.zip\.part\d+$/', $source)) {
      // Archive multi-part : réassembler d'abord (cat part1 part2 ... > .zip).
      if (preg_match('/\.zip\.part\d+$/', $source)) {
        $original = preg_replace('/\.part\d+$/', '', $source);
        $combined = $this->combineArchiveParts($original);
        if ($combined === NULL) {
          return NULL;
        }
        $source = $combined;
      }
      $extract_dir = 'temporary://album-import-' . time();
      if (!$this->extractArchive($source, $extract_dir)) {
        return NULL;
      }
      $source = $extract_dir;
    }

    $manifest_path = rtrim($source, '/') . '/manifest.json';
    if (!file_exists($manifest_path)) {
      return NULL;
    }

    $manifest = json_decode(file_get_contents($manifest_path), TRUE);
    if (!is_array($manifest) || empty($manifest['album'])) {
      return NULL;
    }

    $tid_map = [];
    $errors = 0;
    foreach ($manifest['terms'] ?? [] as $term_info) {
      $tid = $this->ensureTermByPath($term_info);
      if ($tid !== NULL) {
        $tid_map[$term_info['tid']] = $tid;
      }
      else {
        $errors++;
      }
    }

    return [
      'source' => rtrim($source, '/'),
      'manifest' => $manifest,
      'tid_map' => $tid_map,
      'mid_map' => [],
      'errors' => $errors,
    ];
  }

  /**
   * Imports a single media entry (batch-friendly).
   *
   * @param array $state
   *   Import state from prepareImport(), passed by reference.
   * @param int $index
   *   Index of the media entry in the manifest.
   * @param string $target_scheme
   *   Target scheme for imported files.
   *
   * @return array
   *   Statistics: medias, files, errors.
   */
  public function importSingleMedia(array &$state, int $index, string $target_scheme): array {
    $result = ['medias' => 0, 'files' => 0, 'errors' => 0];
    $entry = $state['manifest']['medias'][$index] ?? NULL;
    if ($entry === NULL) {
      $result['errors']++;
      return $result;
    }

    $media = $this->importMedia($entry, $state['source'] . '/files', $target_scheme, $state['tid_map']);
    if ($media instanceof MediaInterface) {
      $state['mid_map'][$entry['mid']] = $media->id();
      $result['medias']++;
      $result['files'] += count($entry['files'] ?? []);
    }
    else {
      $result['errors']++;
    }

    return $result;
  }

  /**
   * Finalizes an import by recreating the album node.
   *
   * @param array $state
   *   Import state from prepareImport() + importSingleMedia().
   *
   * @return array
   *   Statistics: nid, errors.
   */
  public function finalizeImport(array $state): array {
    $node = $this->importNode($state['manifest']['album'], $state['mid_map'], $state['tid_map']);
    if ($node instanceof NodeInterface) {
      return ['nid' => $node->id(), 'errors' => 0];
    }
    return ['nid' => 0, 'errors' => 1];
  }

  /**
   * Exports an album to a package directory.
   *
   * Kept for programmatic use; the form now uses the batch API above.
   *
   * @param int $nid
   *   Album node ID.
   * @param string $destination
   *   Destination directory URI (ex: temporary://album-export-157).
   *
   * @return array
   *   Statistics: medias, files, terms, errors.
   */
  public function exportAlbum(int $nid, string $destination): array {
    $stats = ['medias' => 0, 'files' => 0, 'terms' => 0, 'errors' => 0];
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'media_album_av') {
      $stats['errors']++;
      return $stats;
    }

    $files_dir = $destination . '/files';
    $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $this->fileSystem->prepareDirectory($files_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $manifest = [
      'version' => 1,
      'album' => $this->exportNodeFields($node),
      'terms' => [],
      'medias' => [],
    ];

    // Collect taxonomy terms used by the album and its medias.
    $term_paths = [];
    foreach ($this->collectTermIds($node) as $tid) {
      $path = $this->getTermPath($tid);
      if ($path !== NULL) {
        $term_paths[$tid] = $path;
      }
    }

    foreach ($node->get('field_media_album_av_media')->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }
      foreach ($this->collectTermIds($media) as $tid) {
        if (!isset($term_paths[$tid])) {
          $path = $this->getTermPath($tid);
          if ($path !== NULL) {
            $term_paths[$tid] = $path;
          }
        }
      }
    }

    $manifest['terms'] = array_values($term_paths);
    $stats['terms'] = count($manifest['terms']);

    foreach ($node->get('field_media_album_av_media')->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }
      $entry = $this->exportMedia($media, $destination . '/files', $term_paths);
      if ($entry === NULL) {
        $stats['errors']++;
        continue;
      }
      $manifest['medias'][] = $entry;
      $stats['medias']++;
      $stats['files'] += count($entry['files']);
    }

    file_put_contents($destination . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $stats['archive'] = $this->createArchive($destination);
    return $stats;
  }

  /**
   * Creates a ZIP archive of an export package.
   *
   * @param string $directory
   *   Package directory URI.
   *
   * @return string|null
   *   The archive URI, or NULL on failure.
   */
  public function createArchive(string $directory): ?string {
    if (!class_exists('\ZipArchive')) {
      $this->logger->warning('ZipArchive is not available, skipping archive creation.');
      return NULL;
    }

    $real_directory = $this->fileSystem->realpath($directory);
    if (!$real_directory || !is_dir($real_directory)) {
      return NULL;
    }

    $archive_uri = rtrim($directory, '/') . '.zip';
    $real_archive = $this->fileSystem->realpath($this->fileSystem->dirname($archive_uri));
    if (!$real_archive) {
      return NULL;
    }
    $real_archive .= '/' . basename($archive_uri);

    $zip = new \ZipArchive();
    if ($zip->open($real_archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
      return NULL;
    }

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($real_directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
      $relative = substr($file->getPathname(), strlen($real_directory) + 1);
      // Garde-fou : rester strictement dans le dossier du paquet.
      if ($relative === '' || strpos($relative, '..') !== FALSE) {
        continue;
      }
      $zip->addFile($file->getPathname(), $relative);
    }
    $zip->close();

    return file_exists($real_archive) ? $archive_uri : NULL;
  }

  /**
   * Splits a large archive into binary parts of at most $part_size bytes.
   *
   * Uses chunked reads/writes (8 MB) so multi-GB archives never exhaust PHP
   * memory. The original .zip is removed after a successful split to save
   * disk space; parts are named <archive>.zip.part1, .part2, ...
   *
   * @param string $archive_uri
   *   Archive URI (ex: private://album-export/foo.zip).
   * @param int $part_size
   *   Max part size in bytes.
   *
   * @return string[]
   *   List of part URIs, or [$archive_uri] when no split was needed/possible.
   */
  public function splitArchive(string $archive_uri, int $part_size): array {
    if ($part_size <= 0) {
      return [$archive_uri];
    }
    $real_archive = $this->fileSystem->realpath($archive_uri);
    if (!$real_archive || !is_file($real_archive)) {
      return [$archive_uri];
    }
    $total = filesize($real_archive);
    if ($total === FALSE || $total <= $part_size) {
      return [$archive_uri];
    }

    $in = fopen($real_archive, 'rb');
    if (!$in) {
      return [$archive_uri];
    }
    $parts = [];
    $index = 1;
    $chunk = 8 * 1024 * 1024;
    $written_in_part = 0;
    $out = NULL;
    try {
      while (!feof($in)) {
        if ($out === NULL) {
          $part_uri = $archive_uri . '.part' . $index;
          $real_part = $this->fileSystem->realpath($this->fileSystem->dirname($part_uri));
          if (!$real_part) {
            break;
          }
          $real_part .= '/' . basename($part_uri);
          $out = fopen($real_part, 'wb');
          if (!$out) {
            break;
          }
          $parts[] = $part_uri;
          $written_in_part = 0;
        }
        $to_read = min($chunk, $part_size - $written_in_part);
        $data = fread($in, $to_read);
        if ($data === FALSE || $data === '') {
          break;
        }
        fwrite($out, $data);
        $written_in_part += strlen($data);
        if ($written_in_part >= $part_size) {
          fclose($out);
          $out = NULL;
          $index++;
        }
      }
    }
    finally {
      if ($out !== NULL) {
        fclose($out);
      }
      fclose($in);
    }

    // Split incomplet ou à une seule part : on garde l'archive d'origine.
    if (count($parts) <= 1) {
      foreach ($parts as $part_uri) {
        $real_part = $this->fileSystem->realpath($part_uri);
        if ($real_part && file_exists($real_part)) {
          @unlink($real_part);
        }
      }
      return [$archive_uri];
    }

    // Succès : l'original est remplacé par les parts (économie disque).
    @unlink($real_archive);
    return $parts;
  }

  /**
   * Reassembles split parts back into a single .zip archive.
   *
   * Accepts either the original archive URI (parts derived as
   * <archive>.zip.partN) or the explicit list of part URIs. Used by
   * prepareImport() so a multi-part export can be reimported transparently.
   *
   * @param string $archive_uri
   *   Original archive URI (ex: private://album-export/foo.zip).
   * @param string[] $parts
   *   Optional explicit part URIs. When empty, derived from $archive_uri.
   *
   * @return string|null
   *   The reassembled archive URI, or NULL on failure.
   */
  public function combineArchiveParts(string $archive_uri, array $parts = []): ?string {
    if (empty($parts)) {
      $parts = [];
      $index = 1;
      while (TRUE) {
        $candidate = $archive_uri . '.part' . $index;
        $real = $this->fileSystem->realpath($candidate);
        if (!$real || !is_file($real)) {
          break;
        }
        $parts[] = $candidate;
        $index++;
      }
    }
    if (empty($parts)) {
      return file_exists($archive_uri) ? $archive_uri : NULL;
    }

    $real_target = $this->fileSystem->realpath($this->fileSystem->dirname($archive_uri));
    if (!$real_target) {
      return NULL;
    }
    $real_target .= '/' . basename($archive_uri);
    $out = fopen($real_target, 'wb');
    if (!$out) {
      return NULL;
    }
    $chunk = 8 * 1024 * 1024;
    try {
      foreach ($parts as $part_uri) {
        $real_part = $this->fileSystem->realpath($part_uri);
        if (!$real_part || !is_file($real_part)) {
          fclose($out);
          @unlink($real_target);
          return NULL;
        }
        $in = fopen($real_part, 'rb');
        if (!$in) {
          fclose($out);
          @unlink($real_target);
          return NULL;
        }
        while (!feof($in)) {
          $data = fread($in, $chunk);
          if ($data === FALSE || $data === '') {
            break;
          }
          fwrite($out, $data);
        }
        fclose($in);
      }
    }
    finally {
      if (is_resource($out)) {
        fclose($out);
      }
    }
    return file_exists($real_target) ? $archive_uri : NULL;
  }

  /**
   * Extracts a ZIP package archive to a directory.
   *
   * @param string $archive_uri
   *   Archive URI (ex: temporary://album-export-157.zip).
   * @param string $destination
   *   Destination directory URI.
   *
   * @return bool
   *   TRUE on success.
   */
  public function extractArchive(string $archive_uri, string $destination): bool {
    if (!class_exists('\ZipArchive')) {
      return FALSE;
    }

    $real_archive = $this->fileSystem->realpath($archive_uri);
    if (!$real_archive || !file_exists($real_archive)) {
      return FALSE;
    }

    $real_destination = $this->fileSystem->realpath($destination);
    if (!$real_destination || !is_dir($real_destination)) {
      $destination_copy = $destination;
      $this->fileSystem->prepareDirectory($destination_copy, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $real_destination = $this->fileSystem->realpath($destination);
    }
    if (!$real_destination || !is_dir($real_destination)) {
      return FALSE;
    }

    $zip = new \ZipArchive();
    if ($zip->open($real_archive) !== TRUE) {
      return FALSE;
    }

    // Garde-fou : refuser les chemins absolus ou avec "..".
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $name = $zip->getNameIndex($i);
      if ($name === FALSE || $name === '' || $name[0] === '/' || strpos($name, '..') !== FALSE) {
        $zip->close();
        return FALSE;
      }
    }

    $result = $zip->extractTo($real_destination);
    $zip->close();
    return $result;
  }

  /**
   * Imports an album package.
   *
   * @param string $source
   *   Package directory URI containing manifest.json and files/.
   * @param string $target_scheme
   *   Target scheme for imported files: "private", "public" or "albumav".
   *
   * @return array
   *   Statistics: terms_created, medias, files, errors, nid.
   */
  public function importAlbum(string $source, string $target_scheme = 'private'): array {
    $stats = ['terms_created' => 0, 'medias' => 0, 'files' => 0, 'errors' => 0, 'nid' => 0];
    $manifest_path = rtrim($source, '/') . '/manifest.json';
    if (!file_exists($manifest_path)) {
      $stats['errors']++;
      return $stats;
    }

    $manifest = json_decode(file_get_contents($manifest_path), TRUE);
    if (!is_array($manifest) || empty($manifest['album'])) {
      $stats['errors']++;
      return $stats;
    }

    // Recreate missing vocabularies and terms from their paths.
    $tid_map = [];
    foreach ($manifest['terms'] ?? [] as $term_info) {
      $tid = $this->ensureTermByPath($term_info);
      if ($tid !== NULL) {
        $tid_map[$term_info['tid']] = $tid;
        $stats['terms_created']++;
      }
      else {
        $stats['errors']++;
      }
    }

    // Recreate medias.
    $mid_map = [];
    foreach ($manifest['medias'] ?? [] as $media_entry) {
      $media = $this->importMedia($media_entry, rtrim($source, '/') . '/files', $target_scheme, $tid_map);
      if ($media instanceof MediaInterface) {
        $mid_map[$media_entry['mid']] = $media->id();
        $stats['medias']++;
        $stats['files'] += count($media_entry['files'] ?? []);
      }
      else {
        $stats['errors']++;
      }
    }

    // Recreate the album node.
    $node = $this->importNode($manifest['album'], $mid_map, $tid_map);
    if ($node instanceof NodeInterface) {
      $stats['nid'] = $node->id();
    }
    else {
      $stats['errors']++;
    }

    return $stats;
  }

  /**
   * Exports album node fields (excluding the media references themselves).
   */
  protected function exportNodeFields(NodeInterface $node): array {
    $data = [
      'bundle' => $node->bundle(),
      'title' => $node->label(),
      'status' => $node->isPublished(),
      'fields' => [],
      'media_order' => [],
    ];

    foreach ($node->getFields() as $field_name => $field) {
      if (strpos($field_name, 'field_media_album_av_') !== 0) {
        continue;
      }
      if ($field_name === 'field_media_album_av_media') {
        foreach ($node->get($field_name)->getValue() as $item) {
          $data['media_order'][] = (int) $item['target_id'];
        }
        continue;
      }
      $data['fields'][$field_name] = $this->exportFieldValues($node, $field_name);
    }

    return $data;
  }

  /**
   * Exports a media entity with its attributes and files.
   */
  protected function exportMedia(MediaInterface $media, string $files_dir, array $term_paths): ?array {
    $source_config = $media->getSource()->getConfiguration();
    $source_field = $source_config['source_field'] ?? NULL;
    if (!$source_field || !$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
      return NULL;
    }

    $entry = [
      'mid' => $media->id(),
      'bundle' => $media->bundle(),
      'name' => $media->label(),
      'status' => $media->isPublished(),
      'source_field' => $source_field,
      'fields' => [],
      'files' => [],
    ];

    foreach ($media->getFields() as $field_name => $field) {
      if ($field_name === $source_field || $field_name === 'thumbnail') {
        continue;
      }
      if (strpos($field_name, 'field_media_album_av_') !== 0 && $field_name !== 'directory' && $field_name !== 'name') {
        continue;
      }
      $entry['fields'][$field_name] = $this->exportFieldValues($media, $field_name);
    }

    // Copy source file.
    $file = $media->get($source_field)->entity;
    if ($file instanceof FileInterface && file_exists($file->getFileUri())) {
      $relative = 'source-' . $media->id() . '-' . basename($file->getFileUri());
      if (@copy($file->getFileUri(), $files_dir . '/' . $relative)) {
        $entry['files']['source'] = $relative;
      }
    }

    // Copy thumbnail file.
    if ($media->hasField('thumbnail') && !$media->get('thumbnail')->isEmpty()) {
      $thumbnail = $media->get('thumbnail')->entity;
      if ($thumbnail instanceof FileInterface && file_exists($thumbnail->getFileUri())) {
        $relative = 'thumbnail-' . $media->id() . '-' . basename($thumbnail->getFileUri());
        if (@copy($thumbnail->getFileUri(), $files_dir . '/' . $relative)) {
          $entry['files']['thumbnail'] = $relative;
        }
      }
    }

    if (empty($entry['files']['source'])) {
      return NULL;
    }

    return $entry;
  }

  /**
   * Exports field values, remapping taxonomy terms to their paths.
   */
  protected function exportFieldValues($entity, string $field_name): array {
    $values = [];
    if (!$entity->hasField($field_name)) {
      return $values;
    }

    $field_definition = $entity->getFieldDefinition($field_name);
    $is_taxonomy = $field_definition->getType() === 'entity_reference'
      && $field_definition->getSetting('target_type') === 'taxonomy_term';

    foreach ($entity->get($field_name)->getValue() as $item) {
      if ($is_taxonomy && !empty($item['target_id'])) {
        $path = $this->getTermPath((int) $item['target_id']);
        $values[] = $path !== NULL ? ['term_path' => $path] : $item;
      }
      else {
        $values[] = $item;
      }
    }

    return $values;
  }

  /**
   * Collects taxonomy term IDs referenced by an entity.
   *
   * @return int[]
   */
  protected function collectTermIds($entity): array {
    $tids = [];
    foreach ($entity->getFields() as $field_name => $field) {
      $definition = $entity->getFieldDefinition($field_name);
      if ($definition->getType() !== 'entity_reference' || $definition->getSetting('target_type') !== 'taxonomy_term') {
        continue;
      }
      foreach ($entity->get($field_name)->getValue() as $item) {
        if (!empty($item['target_id'])) {
          $tids[(int) $item['target_id']] = (int) $item['target_id'];
        }
      }
    }

    return array_values($tids);
  }

  /**
   * Returns term info with vocabulary and full path.
   */
  protected function getTermPath(int $tid): ?array {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    if (!$term) {
      return NULL;
    }

    $names = [];
    $current = $term;
    while ($current) {
      array_unshift($names, $current->getName());
      $parents = $current->get('parent')->getValue();
      $parent_id = isset($parents[0]['target_id']) ? (int) $parents[0]['target_id'] : 0;
      $current = $parent_id > 0 ? $this->entityTypeManager->getStorage('taxonomy_term')->load($parent_id) : NULL;
    }

    return [
      'tid' => $tid,
      'vocabulary' => $term->bundle(),
      'path' => implode('/', $names),
    ];
  }

  /**
   * Ensures a vocabulary and term path exist, creating missing terms.
   *
   * @return int|null
   *   The term ID on the target instance.
   */
  protected function ensureTermByPath(array $term_info): ?int {
    $vocabulary_id = $term_info['vocabulary'] ?? '';
    $path = $term_info['path'] ?? '';
    if ($vocabulary_id === '' || $path === '') {
      return NULL;
    }

    $vocabulary = Vocabulary::load($vocabulary_id);
    if (!$vocabulary) {
      $vocabulary = Vocabulary::create(['vid' => $vocabulary_id, 'name' => $vocabulary_id]);
      $vocabulary->save();
    }

    $parent = 0;
    $tid = NULL;
    foreach (explode('/', $path) as $name) {
      $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
        ->condition('vid', $vocabulary_id)
        ->condition('name', $name)
        ->accessCheck(FALSE);
      if ($parent > 0) {
        $query->condition('parent', $parent);
      }
      $tids = $query->execute();
      if (!empty($tids)) {
        $tid = (int) reset($tids);
      }
      else {
        $term = Term::create([
          'vid' => $vocabulary_id,
          'name' => $name,
          'parent' => $parent > 0 ? [$parent] : [],
        ]);
        $term->save();
        $tid = (int) $term->id();
      }
      $parent = $tid;
    }

    return $tid;
  }

  /**
   * Imports a media entity from a manifest entry.
   */
  protected function importMedia(array $entry, string $files_dir, string $target_scheme, array $tid_map): ?MediaInterface {
    $source_path = $files_dir . '/' . ($entry['files']['source'] ?? '');
    if (!file_exists($source_path)) {
      return NULL;
    }

    $target_uri = $target_scheme . '://album-import/' . basename($source_path);
    $directory = $this->fileSystem->dirname($target_uri);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    if (!@copy($source_path, $target_uri)) {
      return NULL;
    }

    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $target_uri,
      'status' => 1,
    ]);
    $file->save();

    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $entry['bundle'],
      'name' => $entry['name'],
      'status' => $entry['status'] ? 1 : 0,
      $entry['source_field'] => ['target_id' => $file->id()],
    ]);

    foreach ($entry['fields'] ?? [] as $field_name => $values) {
      if (!$media->hasField($field_name)) {
        continue;
      }
      $media->set($field_name, $this->remapFieldValues($values, $tid_map));
    }

    // Import thumbnail when present.
    if (!empty($entry['files']['thumbnail'])) {
      $thumbnail_path = $files_dir . '/' . $entry['files']['thumbnail'];
      if (file_exists($thumbnail_path) && $media->hasField('thumbnail')) {
        $thumbnail_uri = $target_scheme . '://album-import/thumbnails/' . basename($thumbnail_path);
        $thumbnail_dir = $this->fileSystem->dirname($thumbnail_uri);
        $this->fileSystem->prepareDirectory($thumbnail_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
        if (@copy($thumbnail_path, $thumbnail_uri)) {
          $thumbnail_file = $this->entityTypeManager->getStorage('file')->create([
            'uri' => $thumbnail_uri,
            'status' => 1,
          ]);
          $thumbnail_file->save();
          $media->set('thumbnail', ['target_id' => $thumbnail_file->id()]);
        }
      }
    }

    $media->save();
    return $media;
  }

  /**
   * Imports the album node from manifest data.
   */
  protected function importNode(array $album, array $mid_map, array $tid_map): ?NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => $album['bundle'] ?? 'media_album_av',
      'title' => ($album['title'] ?? 'Imported album') . ' (import)',
      'status' => $album['status'] ? 1 : 0,
    ]);

    foreach ($album['fields'] ?? [] as $field_name => $values) {
      if (!$node->hasField($field_name)) {
        continue;
      }
      $node->set($field_name, $this->remapFieldValues($values, $tid_map));
    }

    $media_refs = [];
    foreach ($album['media_order'] ?? [] as $old_mid) {
      if (isset($mid_map[$old_mid])) {
        $media_refs[] = ['target_id' => $mid_map[$old_mid]];
      }
    }
    if ($node->hasField('field_media_album_av_media')) {
      $node->set('field_media_album_av_media', $media_refs);
    }

    $node->save();
    return $node;
  }

  /**
   * Remaps exported term paths to target term IDs.
   */
  protected function remapFieldValues(array $values, array $tid_map): array {
    $remapped = [];
    foreach ($values as $item) {
      if (isset($item['term_path']['tid']) && isset($tid_map[$item['term_path']['tid']])) {
        $remapped[] = ['target_id' => $tid_map[$item['term_path']['tid']]];
      }
      else {
        unset($item['term_path']);
        $remapped[] = $item;
      }
    }

    return $remapped;
  }

}
