<?php

namespace Drupal\media_album_av\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\media_album_av\Service\MediaAlbumAvExportImport;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to export or import a media album package.
 */
class MediaAlbumAvExportImportForm extends FormBase {

  /**
   * The export/import service.
   *
   * @var \Drupal\media_album_av\Service\MediaAlbumAvExportImport
   */
  protected $exportImport;

  /**
   * Constructs a MediaAlbumAvExportImportForm object.
   */
  public function __construct(MediaAlbumAvExportImport $export_import) {
    $this->exportImport = $export_import;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('media_album_av.export_import')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_album_av_export_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'media_album_av/export-auto-download';

    $form['export'] = [
      '#type' => 'details',
      '#title' => $this->t('Export an album'),
      '#open' => TRUE,
    ];

    $form['export']['nid'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Album'),
      '#target_type' => 'node',
      '#selection_settings' => ['target_bundles' => ['media_album_av']],
      '#description' => $this->t('Select the album to export (taxonomy terms, medias, attributes and files).'),
    ];

    $form['export']['destination'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Destination directory URI'),
      // private:// est servi par Drupal (streaming + contrôle d'accès) ;
      // temporary:// (/tmp) n'est PAS accessible via HTTP, donc pas de
      // téléchargement direct possible.
      '#default_value' => 'private://album-export/album-export-' . date('Ymd-His'),
      '#description' => $this->t('Example: private://album-export/album-157. The manifest and files are written there, plus .zip archive(s) streamed via a download controller (works for multi-GB files).'),
    ];

    $form['export']['part_size'] = [
      '#type' => 'select',
      '#title' => $this->t('Split archive into parts of'),
      '#options' => [
        '0' => $this->t('Single .zip (no split)'),
        '524288000' => $this->t('500 MB parts'),
        '1073741824' => $this->t('1 GB parts'),
        '2147483648' => $this->t('2 GB parts'),
      ],
      '#default_value' => '1073741824',
      '#description' => $this->t('Large archives (several GB) should be split: each part is downloaded separately with resume support (HTTP Range), avoiding PHP memory/timeout limits and browser failures on huge files.'),
    ];

    $form['export']['auto_download'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Start download automatically when the archive is ready'),
      '#default_value' => TRUE,
      '#description' => $this->t('If checked, the browser starts downloading the archive(s) as soon as the export batch finishes. With split archives, downloads start one after another.'),
    ];

    $form['export']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export album'),
      '#submit' => ['::exportSubmit'],
    ];

    // Section "téléchargement" : affichée après un export (liens persistés en
    // tempstore car le batch reconstruit le formulaire sur une requête neuve).
    $downloads = $this->getPendingDownloads();
    if (!empty($downloads)) {
      $form['download'] = [
        '#type' => 'details',
        '#title' => $this->t('Download ready archives (@count)', ['@count' => count($downloads)]),
        '#open' => TRUE,
        '#attributes' => ['id' => 'media-album-av-export-downloads'],
      ];
      $items = [];
      $auto_urls = [];
      foreach ($downloads as $i => $download) {
        $url = $this->buildDownloadUrl($download['relative']);
        if ($url === NULL) {
          // Route inconnue du routeur (cache non reconstruit après déploiement).
          $items[] = [
            '#markup' => $this->t('@label (@size) — download route unavailable, rebuild caches.', [
              '@label' => $download['label'],
              '@size' => $download['size'],
            ]),
          ];
          continue;
        }
        $link = Link::fromTextAndUrl($download['label'] . ' (' . $download['size'] . ')', $url)->toString();
        $items[] = [
          '#markup' => $link,
          '#allowed_tags' => ['a'],
        ];
        $auto_urls[] = $url->toString();
      }
      // Téléchargement automatique : uniquement si les URLs ont pu être générées.
      if (!empty($auto_urls) && !empty($downloads[0]['auto'])) {
        $form['#attached']['drupalSettings']['mediaAlbumAvExportAutoDownload'] = [
          'urls' => $auto_urls,
        ];
      }
      $form['download']['links'] = [
        '#theme' => 'item_list',
        '#items' => $items,
      ];
      if (!empty($downloads[0]['multipart'])) {
        $form['download']['reassemble'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Multi-part archive: download ALL parts, then reassemble with: <code>cat @name.part* > @name</code> (Linux/macOS) or <code>copy /b @name.part* @name</code> (Windows).', [
            '@name' => $downloads[0]['basename'],
          ]),
        ];
      }
      $form['download']['clear'] = [
        '#type' => 'submit',
        '#value' => $this->t('Dismiss'),
        '#submit' => ['::clearDownloadsSubmit'],
        '#limit_validation_errors' => [],
      ];
    }

    $form['import'] = [
      '#type' => 'details',
      '#title' => $this->t('Import an album package'),
      '#open' => TRUE,
    ];

    $form['import']['archive'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Package archive (.zip)'),
      '#upload_location' => 'temporary://album-import',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'zip'],
      ],
      '#description' => $this->t('Upload a package archive created by the export. It is extracted automatically.'),
    ];

    $form['import']['source'] = [
      '#type' => 'textfield',
      '#title' => $this->t('... or package directory URI'),
      '#default_value' => '',
      '#description' => $this->t('Alternative to the upload: directory containing manifest.json and files/. Missing taxonomy terms are recreated from their paths.'),
    ];

    $form['import']['target_scheme'] = [
      '#type' => 'select',
      '#title' => $this->t('Target storage'),
      '#options' => [
        'albumav' => $this->t('Albumav (albumav://)'),
        'private' => $this->t('Private files (private://)'),
        'public' => $this->t('Public files (public://)'),
      ],
      '#default_value' => 'private',
      '#required' => TRUE,
    ];

    $form['import']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import album'),
      '#submit' => ['::importSubmit'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Handles the export button.
   */
  public function exportSubmit(array &$form, FormStateInterface $form_state) {
    // Note: entity_autocomplete is not #tree here, values arrive flat.
    $nid = (int) $form_state->getValue('nid');
    $destination = trim((string) $form_state->getValue('destination'));
    $part_size = (int) $form_state->getValue('part_size');
    $auto_download = (bool) $form_state->getValue('auto_download');
    if ($nid <= 0 || $destination === '') {
      $this->messenger()->addError($this->t('Select an album and a destination directory.'));
      return;
    }
    // Sécuriser : forcer l'export sous private://album-export (téléchargeable
    // via le contrôleur). temporary:// (/tmp) n'est pas servi par le web.
    $destination = $this->normalizeExportDestination($destination);

    $manifest = $this->exportImport->prepareExport($nid, $destination);
    if ($manifest === NULL) {
      $this->messenger()->addError($this->t('Could not prepare the export package.'));
      return;
    }

    $operations = [];
    foreach ($manifest['mids'] as $mid) {
      $operations[] = [
        [static::class, 'processExportMedia'],
        [$manifest, $mid],
      ];
    }
    // Finalize even with zero media (writes manifest + archive).
    $operations[] = [
      [static::class, 'finishExport'],
      [$manifest, $part_size, $auto_download],
    ];

    batch_set([
      'title' => $this->t('Exporting album @nid', ['@nid' => $nid]),
      'operations' => $operations,
      'finished' => [static::class, 'finishedExport'],
      'file' => \Drupal::service('extension.list.module')->getPath('media_album_av') . '/src/Form/MediaAlbumAvExportImportForm.php',
    ]);
  }

  /**
   * Batch operation: exports a single media.
   */
  public static function processExportMedia(array $manifest, int $mid, array &$context): void {
    $service = \Drupal::service('media_album_av.export_import');
    if (!isset($context['results']['manifest'])) {
      $context['results']['manifest'] = $manifest;
      $context['results']['stats'] = ['medias' => 0, 'files' => 0, 'errors' => 0];
    }

    $result = $service->exportSingleMedia($context['results']['manifest'], $mid);
    foreach ($result as $key => $value) {
      $context['results']['stats'][$key] += $value;
    }
    $context['message'] = t('Exported media @mid.', ['@mid' => $mid]);
  }

  /**
   * Batch operation: writes the manifest and creates the archive.
   */
  public static function finishExport(array $manifest, int $part_size, bool $auto_download, array &$context): void {
    $service = \Drupal::service('media_album_av.export_import');
    // Merge medias exported by previous operations.
    if (isset($context['results']['manifest']['medias'])) {
      $manifest['medias'] = $context['results']['manifest']['medias'];
    }
    $final = $service->finalizeExport($manifest, $part_size);
    $context['results']['stats']['terms'] = $final['terms'];
    $context['results']['archives'] = $final['archives'];
    $context['results']['multipart'] = $final['multipart'];
    $context['results']['auto_download'] = $auto_download;
    $context['results']['destination'] = $manifest['destination'];
  }

  /**
   * Batch finished callback for export.
   */
  public static function finishedExport(bool $success, array $results, array $operations): void {
    $stats = $results['stats'] ?? ['medias' => 0, 'files' => 0, 'errors' => 0, 'terms' => 0];
    if ($success) {
      $archives = $results['archives'] ?? [];
      $multipart = !empty($results['multipart']);
      $auto_download = !empty($results['auto_download']);

      // Persister les liens en tempstore : le batch redirige vers une requête
      // neuve, donc $results ne survit pas jusqu'au buildForm suivant.
      $downloads = [];
      foreach ($archives as $archive_uri) {
        $relative = preg_replace('#^private://album-export/#', '', $archive_uri);
        if ($relative === $archive_uri) {
          continue;
        }
        $real = \Drupal::service('file_system')->realpath($archive_uri);
        // PHP natif uniquement : le service file_system est décoré par s3fs
        // en prod (S3fsFileSystemD103) et n'offre ni getFileSize() ni
        // formatSize(). filesize()/format_size() n'ont aucune dépendance.
        $size = ($real && is_file($real)) ? (int) @filesize($real) : 0;
        $downloads[] = [
          'relative' => $relative,
          'label' => basename($archive_uri),
          'basename' => preg_replace('/\.part\d+$/', '', basename($archive_uri)),
          'size' => format_size($size),
          'multipart' => $multipart,
          'auto' => $auto_download,
        ];
      }
      if (!empty($downloads)) {
        \Drupal::service('tempstore.private')->get('media_album_av')->set('export_downloads', $downloads);
      }

      // Message SANS HTML : le messenger échappe les balises (d'où le
      // "<a href...>" affiché en texte). Les vrais liens cliquables sont
      // rendus dans la section "Download ready archives" du formulaire.
      \Drupal::messenger()->addStatus(t(
        'Export done. Medias: @medias, files: @files, terms: @terms, errors: @errors. @count archive(s) ready below (@mode).',
        [
          '@medias' => $stats['medias'],
          '@files' => $stats['files'],
          '@terms' => $stats['terms'] ?? 0,
          '@errors' => $stats['errors'],
          '@count' => count($downloads),
          '@mode' => $multipart ? t('multi-part')->render() : t('single archive')->render(),
        ]
      ));
    }
    else {
      \Drupal::messenger()->addError(t('Export did not complete. Check the logs.'));
    }
  }

  /**
   * Normalizes the export destination under private://album-export.
   */
  protected function normalizeExportDestination(string $destination): string {
    $destination = rtrim($destination, '/');
    // Refuser les schemes non téléchargeables (temporary://, public:// brut...).
    if (strpos($destination, 'private://album-export') !== 0) {
      // Extraire un nom de dossier sûr depuis la saisie.
      $base = basename(preg_replace('#^[a-z0-9]+://#i', '', $destination));
      $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base);
      if ($base === '' || $base === '-') {
        $base = 'album-export-' . date('Ymd-His');
      }
      $destination = 'private://album-export/' . $base;
      $this->messenger()->addWarning($this->t('Destination moved under @dest so archives can be downloaded (temporary:// is not web-accessible).', ['@dest' => $destination]));
    }
    return $destination;
  }

  /**
   * Returns pending download links stored by the last export.
   *
   * @return array[]
   *   List of ['relative', 'label', 'basename', 'size', 'multipart', 'auto'].
   */
  protected function getPendingDownloads(): array {
    try {
      $downloads = \Drupal::service('tempstore.private')->get('media_album_av')->get('export_downloads') ?? [];
    }
    catch (\Exception $e) {
      return [];
    }
    // Ne garder que les fichiers existants.
    $file_system = \Drupal::service('file_system');
    $valid = [];
    foreach ($downloads as $download) {
      $real = $file_system->realpath('private://album-export/' . ($download['relative'] ?? ''));
      if ($real && is_file($real)) {
        $valid[] = $download;
      }
    }
    return $valid;
  }

  /**
   * Builds the download URL for an export file, or NULL if unavailable.
   *
   * Never throws: Url::fromRoute() raises RouteNotFoundException when the
   * route cache does not know media_album_av.export_download yet (code
   * deployed but caches not rebuilt). A 500 here would break the whole form.
   */
  protected function buildDownloadUrl(string $relative): ?Url {
    try {
      $url = Url::fromRoute('media_album_av.export_download', [], ['query' => ['file' => $relative]]);
      // Force la génération pour détecter une route manquante tout de suite.
      $url->toString();
      return $url;
    }
    catch (\Exception $e) {
      \Drupal::logger('media_album_av')->warning('Export download route unavailable for @file: @error', [
        '@file' => $relative,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Dismisses the download section.
   */
  public function clearDownloadsSubmit(array &$form, FormStateInterface $form_state) {
    \Drupal::service('tempstore.private')->get('media_album_av')->delete('export_downloads');
    $this->messenger()->addStatus($this->t('Download links dismissed (files are kept under private://album-export).'));
  }

  /**
   * Handles the import button.
   */
  public function importSubmit(array &$form, FormStateInterface $form_state) {
    // Note: fields are not #tree here, values arrive flat.
    $source = trim((string) $form_state->getValue('source'));
    $archive_fids = $form_state->getValue('archive');
    $archive_fids = is_array($archive_fids) ? array_filter($archive_fids) : [];

    if (!empty($archive_fids)) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load(reset($archive_fids));
      if (!$file) {
        $this->messenger()->addError($this->t('Uploaded archive not found.'));
        return;
      }
      $source = $file->getFileUri();
    }

    if ($source === '') {
      $this->messenger()->addError($this->t('Upload a .zip archive or enter a package directory URI.'));
      return;
    }

    $target_scheme = (string) $form_state->getValue('target_scheme');
    $state = $this->exportImport->prepareImport($source);
    if ($state === NULL) {
      $this->messenger()->addError($this->t('Could not read the package (manifest.json missing or invalid).'));
      return;
    }

    $operations = [];
    foreach (array_keys($state['manifest']['medias'] ?? []) as $index) {
      $operations[] = [
        [static::class, 'processImportMedia'],
        [$state, $index, $target_scheme],
      ];
    }
    $operations[] = [
      [static::class, 'finishImport'],
      [$state],
    ];

    batch_set([
      'title' => $this->t('Importing album package'),
      'operations' => $operations,
      'finished' => [static::class, 'finishedImport'],
      'file' => \Drupal::service('extension.list.module')->getPath('media_album_av') . '/src/Form/MediaAlbumAvExportImportForm.php',
    ]);
  }

  /**
   * Batch operation: imports a single media entry.
   */
  public static function processImportMedia(array $state, int $index, string $target_scheme, array &$context): void {
    $service = \Drupal::service('media_album_av.export_import');
    if (!isset($context['results']['state'])) {
      $context['results']['state'] = $state;
      $context['results']['stats'] = ['medias' => 0, 'files' => 0, 'errors' => $state['errors']];
      $context['results']['terms'] = count($state['manifest']['terms'] ?? []);
    }

    $result = $service->importSingleMedia($context['results']['state'], $index, $target_scheme);
    foreach ($result as $key => $value) {
      $context['results']['stats'][$key] += $value;
    }
    $context['message'] = t('Imported media entry @index.', ['@index' => $index + 1]);
  }

  /**
   * Batch operation: recreates the album node.
   */
  public static function finishImport(array $state, array &$context): void {
    $service = \Drupal::service('media_album_av.export_import');
    if (isset($context['results']['state'])) {
      $state = $context['results']['state'];
    }
    $final = $service->finalizeImport($state);
    $context['results']['nid'] = $final['nid'];
    $context['results']['stats']['errors'] += $final['errors'];
  }

  /**
   * Batch finished callback for import.
   */
  public static function finishedImport(bool $success, array $results, array $operations): void {
    $stats = $results['stats'] ?? ['medias' => 0, 'files' => 0, 'errors' => 0];
    if ($success) {
      \Drupal::messenger()->addStatus(t(
        'Import done. New album NID: @nid, medias: @medias, files: @files, terms: @terms, errors: @errors.',
        [
          '@nid' => $results['nid'] ?? 0,
          '@medias' => $stats['medias'],
          '@files' => $stats['files'],
          '@terms' => $results['terms'] ?? 0,
          '@errors' => $stats['errors'],
        ]
      ));
    }
    else {
      \Drupal::messenger()->addError(t('Import did not complete. Check the logs.'));
    }
  }

}
