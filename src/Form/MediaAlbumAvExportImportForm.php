<?php

namespace Drupal\media_album_av\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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
      '#default_value' => 'temporary://album-export',
      '#description' => $this->t('Example: temporary://album-export-157. The manifest and files are written there, plus a .zip archive for download.'),
    ];

    $form['export']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export album'),
      '#submit' => ['::exportSubmit'],
    ];

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
    if ($nid <= 0 || $destination === '') {
      $this->messenger()->addError($this->t('Select an album and a destination directory.'));
      return;
    }

    $stats = $this->exportImport->exportAlbum($nid, $destination);
    $message = $this->t(
      'Export done. Medias: @medias, files: @files, terms: @terms, errors: @errors. Package: @destination',
      [
        '@medias' => $stats['medias'],
        '@files' => $stats['files'],
        '@terms' => $stats['terms'],
        '@errors' => $stats['errors'],
        '@destination' => $destination,
      ]
    );

    if (!empty($stats['archive'])) {
      $url = \Drupal::service('file_url_generator')->generateAbsoluteString($stats['archive']);
      $message .= ' ' . $this->t('<a href=":url">Download the .zip archive</a>.', [':url' => $url]);
    }
    $this->messenger()->addStatus($message);
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
      $extract_dir = 'temporary://album-import-' . time();
      if (!$this->exportImport->extractArchive($file->getFileUri(), $extract_dir)) {
        $this->messenger()->addError($this->t('Could not extract the archive.'));
        return;
      }
      $source = $extract_dir;
    }

    if ($source === '') {
      $this->messenger()->addError($this->t('Upload a .zip archive or enter a package directory URI.'));
      return;
    }

    $stats = $this->exportImport->importAlbum($source, (string) $form_state->getValue('target_scheme'));
    $this->messenger()->addStatus($this->t(
      'Import done. New album NID: @nid, medias: @medias, files: @files, terms created: @terms, errors: @errors.',
      [
        '@nid' => $stats['nid'],
        '@medias' => $stats['medias'],
        '@files' => $stats['files'],
        '@terms' => $stats['terms_created'],
        '@errors' => $stats['errors'],
      ]
    ));
  }

}
