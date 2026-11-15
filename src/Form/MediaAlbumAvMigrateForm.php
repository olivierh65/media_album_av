<?php

namespace Drupal\media_album_av\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media_album_av\Service\MediaAlbumAvStorageMigration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to migrate album media files to another storage scheme.
 */
class MediaAlbumAvMigrateForm extends FormBase {

  /**
   * The storage migration service.
   *
   * @var \Drupal\media_album_av\Service\MediaAlbumAvStorageMigration
   */
  protected $migration;

  /**
   * Constructs a MediaAlbumAvMigrateForm object.
   */
  public function __construct(MediaAlbumAvStorageMigration $migration) {
    $this->migration = $migration;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('media_album_av.storage_migration')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_album_av_migrate_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $albums = $this->getAlbumOptions();

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Migrate album media files (sources and thumbnails) to another storage scheme. Derivatives are flushed and regenerated. Files are copied through a local temporary file, then the old files are deleted.') . '</p>',
    ];

    $form['albums'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Albums'),
      '#options' => $albums,
      '#default_value' => array_keys($albums),
      '#description' => $this->t('Leave all checked to migrate every album. Uncheck all is not allowed: select at least one album or use "All albums".'),
    ];

    $form['all_albums'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('All albums'),
      '#default_value' => TRUE,
    ];

    $form['target_scheme'] = [
      '#type' => 'select',
      '#title' => $this->t('Target storage'),
      '#options' => [
        'albumav' => $this->t('Albumav (albumav://) - private S3 via Drupal'),
        'private' => $this->t('Private files (private://)'),
        'public' => $this->t('Public files (public://)'),
      ],
      '#default_value' => 'albumav',
      '#required' => TRUE,
    ];

    $form['regenerate_derivatives'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Regenerate image derivatives'),
      '#default_value' => TRUE,
      '#description' => $this->t('Flush old derivatives and regenerate them on the new URIs.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Migrate selected albums'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $all = (bool) $form_state->getValue('all_albums');
    $selected = array_filter((array) $form_state->getValue('albums'));
    $nids = $all ? [] : array_map('intval', array_keys($selected));

    if (!$all && empty($nids)) {
      $this->messenger()->addError($this->t('Select at least one album or check "All albums".'));
      return;
    }

    // Batch: one operation per media, so large albums never hit PHP timeouts.
    // The media list is resolved now; each operation reloads its own entities.
    $mids = $this->migration->collectMediaIds($nids);
    if (empty($mids)) {
      $this->messenger()->addWarning($this->t('No media found for the selected albums.'));
      return;
    }

    $operations = [];
    foreach (array_chunk($mids, 1) as $chunk) {
      $operations[] = [
        [static::class, 'processMedia'],
        [
          $chunk[0],
          (string) $form_state->getValue('target_scheme'),
          (bool) $form_state->getValue('regenerate_derivatives'),
        ],
      ];
    }

    batch_set([
      'title' => $this->t('Migrating album media storage'),
      'operations' => $operations,
      'finished' => [static::class, 'finished'],
      'file' => \Drupal::service('extension.list.module')->getPath('media_album_av') . '/src/Form/MediaAlbumAvMigrateForm.php',
    ]);
  }

  /**
   * Batch operation: migrates a single media entity.
   */
  public static function processMedia(int $mid, string $target_scheme, bool $regenerate_derivatives, array &$context): void {
    $migration = \Drupal::service('media_album_av.storage_migration');
    if (!isset($context['results']['stats'])) {
      $context['results']['stats'] = [
        'medias' => 0,
        'files_moved' => 0,
        'thumbnails_moved' => 0,
        'derivatives_generated' => 0,
        'errors' => 0,
      ];
    }

    $result = $migration->migrateSingleMedia($mid, $target_scheme, $regenerate_derivatives);
    foreach ($result as $key => $value) {
      if (isset($context['results']['stats'][$key])) {
        $context['results']['stats'][$key] += $value;
      }
    }
    $context['results']['stats']['medias']++;
    $context['message'] = t('Migrated media @mid.', ['@mid' => $mid]);
  }

  /**
   * Batch finished callback.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $stats = $results['stats'] ?? [
      'medias' => 0,
      'files_moved' => 0,
      'thumbnails_moved' => 0,
      'derivatives_generated' => 0,
      'errors' => 0,
    ];

    if ($success) {
      \Drupal::messenger()->addStatus(t(
        'Migration done. Medias: @medias, files moved: @files, thumbnails moved: @thumbs, derivatives: @derivatives, errors: @errors.',
        [
          '@medias' => $stats['medias'],
          '@files' => $stats['files_moved'],
          '@thumbs' => $stats['thumbnails_moved'],
          '@derivatives' => $stats['derivatives_generated'],
          '@errors' => $stats['errors'],
        ]
      ));
    }
    else {
      \Drupal::messenger()->addError(t('Migration did not complete. Check the logs.'));
    }
  }

  /**
   * Gets album options for the checkboxes.
   *
   * @return array
   *   Node ID => title.
   */
  protected function getAlbumOptions(): array {
    $options = [];
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'media_album_av')
      ->accessCheck(FALSE)
      ->execute();
    if (empty($nids)) {
      return $options;
    }

    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
    foreach ($nodes as $nid => $node) {
      $options[$nid] = $node->label() . ' (NID ' . $nid . ')';
    }

    return $options;
  }

}
