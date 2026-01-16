<?php

namespace Drupal\media_album_av\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;

/**
 * Configuration form for Media Album AV settings.
 */
class MediaAlbumAvSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a MediaAlbumAvSettingsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['media_album_av.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_album_av_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('media_album_av.settings');
    $field_manager = $this->entityFieldManager;

    $form['#tree'] = TRUE;

    // Create vertical tabs container.
    $form['tabs'] = [
      '#type' => 'vertical_tabs',
      '#default_tab' => 'edit-global',
    ];

    // ==========================================
    // 1. CONFIGURATION GLOBALE
    // ==========================================
    $form['global'] = [
      '#type' => 'details',
      '#title' => $this->t('Global Settings'),
      '#group' => 'tabs',
      '#open' => TRUE,
    ];

    $form['global']['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure author field mapping for media types.') . '</p>',
    ];

    // Get available fields for the node that can store authors.
    $node_author_field = $this->getNodeAuthorFields();

    $form['global']['node_authors_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Node Authors Field'),
      '#description' => $this->t('Select which field in the node will be populated with the authors from media.'),
      '#options' => $node_author_field,
      '#default_value' => $config->get('node_authors_field') ?? 'field_media_album_av_authors',
      '#required' => TRUE,
    ];

    // ==========================================
    // 1b. ALBUM CREATION SETTINGS
    // ==========================================
    $form['album'] = [
      '#type' => 'details',
      '#title' => $this->t('Album Creation Settings'),
      '#group' => 'tabs',
      '#open' => FALSE,
    ];

    $form['album']['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure the content type and taxonomies to use for album creation.') . '</p>',
    ];

    // Get available content types.
    $content_types = $this->getContentTypes();

    $form['album']['album_content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Album Content Type'),
      '#description' => $this->t('Select the content type to use for creating new albums.'),
      '#options' => $content_types,
      '#default_value' => $config->get('album_content_type') ??
      (array_key_exists('media_album_av', $content_types) ? 'media_album_av' : NULL) ?? '',
      '#required' => TRUE,
    ];

    // Get available vocabularies.
    $vocabularies = $this->getVocabularies();

    $form['album']['event_group_vocabulary'] = [
      '#type' => 'select',
      '#title' => $this->t('Event Group Vocabulary'),
      '#description' => $this->t('Select the taxonomy vocabulary to use for Event Group (optional).'),
      '#options' => ['' => '- ' . $this->t('None') . ' -'] + $vocabularies,
      '#default_value' => $config->get('event_group_vocabulary') ??
      (array_key_exists('media_album_av_event_group', $vocabularies) ? 'media_album_av_event_group' : NULL) ?? '',
      '#required' => FALSE,
    ];

    $form['album']['event_vocabulary'] = [
      '#type' => 'select',
      '#title' => $this->t('Event Vocabulary'),
      '#description' => $this->t('Select the taxonomy vocabulary to use for Event (optional).'),
      '#options' => ['' => '- ' . $this->t('None') . ' -'] + $vocabularies,
      '#default_value' => $config->get('event_vocabulary') ??
      (array_key_exists('media_album_av_event', $vocabularies) ? 'media_album_av_event' : NULL) ?? '',
      '#required' => FALSE,
    ];

    // Get available media types by category.
    $photo_media_types = $this->getMediaTypesByCategory('image');
    $video_media_types = $this->getMediaTypesByCategory('video');

    $form['album']['prefered_media_type_photo'] = [
      '#type' => 'select',
      '#title' => $this->t('Preferred Media Type for Photos'),
      '#description' => $this->t('Select the default media type to use for uploaded photos/images.'),
      '#options' => ['' => '- ' . $this->t('None') . ' -'] + $photo_media_types,
      '#default_value' => $config->get('prefered_media_type_photo') ??
      (array_key_exists('media_album_av_photo', $photo_media_types) ? 'media_album_av_photo' : NULL) ?? '',
      '#required' => FALSE,
    ];

    $form['album']['prefered_media_type_video'] = [
      '#type' => 'select',
      '#title' => $this->t('Preferred Media Type for Videos'),
      '#description' => $this->t('Select the default media type to use for uploaded videos.'),
      '#options' => ['' => '- ' . $this->t('None') . ' -'] + $video_media_types,
      '#default_value' => $config->get('prefered_media_type_video') ??
      (array_key_exists('media_album_av_video', $video_media_types) ? 'media_album_av_video' : NULL) ?? '',
      '#required' => FALSE,
    ];

    // Get available stream wrappers.
    $stream_wrappers = $this->getStreamWrappers();

    $form['album']['prefered_storage_location'] = [
      '#type' => 'select',
      '#title' => $this->t('Preferred Storage Location (Legacy)'),
      '#description' => $this->t('Deprecated: Use "Preferred Stream Wrapper" instead. Select the storage location for media files.'),
      '#options' => $stream_wrappers,
      '#default_value' => $config->get('prefered_storage_location') ?? 'private',
      '#required' => FALSE,
    ];

    $form['album']['prefered_media_directory'] = [
      '#type' => 'select',
      '#title' => $this->t('Preferred Media Directory'),
      '#description' => $this->t('Select taxonomy containing directories to store medias.'),
      '#options' => ['' => '- ' . $this->t('None') . ' -'] + $vocabularies,
      '#default_value' => $config->get('prefered_media_directory') ??
      (array_key_exists('media_album_av_folders', $vocabularies) ? 'media_album_av_folders' : NULL) ?? 'private',
      '#required' => FALSE,
    ];
    // ==========================================
    // 2. CONFIGURATION PAR TYPE DE MÉDIA
    // ==========================================
    $form['media_authors'] = [
      '#type' => 'details',
      '#title' => $this->t('Media Type Author Fields'),
      '#group' => 'tabs',
      '#open' => FALSE,
    ];

    $form['media_authors']['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure which field should be used as "Author" for each media type.') . '</p>',
    ];

    // Get the accepted media bundle types from the node field configuration.
    $accepted_bundles = $this->getAcceptedMediaBundles();

    if (empty($accepted_bundles)) {
      $form['media_authors']['no_media_types'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No media types are configured for this content type.') . '</p>',
      ];
      return $form;
    }

    // Create a container for media type settings.
    $form['author_fields'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    // Load only the accepted media types.
    $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple($accepted_bundles);
    $media_types_options = [];
    foreach ($media_types as $media_type) {
      $media_types_options[$media_type->id()] = $media_type->label();
    }

    if (empty($media_types_options)) {
      $form['media_authors']['no_media_types'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No media types available.') . '</p>',
      ];
      return $form;
    }

    // Create fieldset for each media type inside the media_authors tab.
    foreach ($media_types_options as $media_type_id => $media_type_label) {
      $form['media_authors']['author_fields_' . $media_type_id] = [
        '#type' => 'details',
        '#title' => $this->t('Author field for @media_type', ['@media_type' => $media_type_label]),
        '#open' => FALSE,
      ];

      // Get available fields for this media type.
      $media_fields = $field_manager->getFieldDefinitions('media', $media_type_id);
      $field_options = [];

      foreach ($media_fields as $field_name => $field_def) {
        // Only offer string and taxonomy reference fields.
        if ($field_def->getType() === 'string' ||
            ($field_def->getType() === 'entity_reference' &&
             $field_def->getSetting('target_type') === 'taxonomy_term')) {
          $field_options[$field_name] = $field_def->getLabel() ?: $field_name;
        }
      }

      if (empty($field_options)) {
        $form['media_authors']['author_fields_' . $media_type_id]['no_fields'] = [
          '#type' => 'markup',
          '#markup' => '<p>' . $this->t('No suitable fields found for this media type.') . '</p>',
        ];
        continue;
      }

      // Add select field for author field selection.
      $form['media_authors']['author_fields_' . $media_type_id]['field_name'] = [
        '#type' => 'select',
        '#title' => $this->t('Select author field'),
        '#options' => ['' => $this->t('- None -')] + $field_options,
        '#default_value' => $config->get('author_fields.' . $media_type_id) ?? $this->getDefaultAuthorField($media_type_id),
        '#description' => $this->t('Select the field to use as author for this media type.'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Get default author field for a media type.
   *
   * @param string $media_type_id
   *   The media type ID.
   *
   * @return string
   *   The default author field name, or empty string if none.
   */
  private function getDefaultAuthorField($media_type_id) {
    $defaults = [
      'media_album_av_photo' => 'field_media_album_av_photo_autho',
      'media_album_av_video' => 'field_media_album_av_video_autho',
    ];

    return $defaults[$media_type_id] ?? '';
  }

  /**
   * Get text/string fields available in the media_album_av node.
   *
   * @return array
   *   Array of field names suitable for storing authors.
   */
  private function getNodeAuthorFields() {
    $field_manager = $this->entityFieldManager;
    $node_fields = $field_manager->getFieldDefinitions('node', 'media_album_av');
    $field_options = [];

    foreach ($node_fields as $field_name => $field_def) {
      // Only offer string and text fields.
      if ($field_def->getType() === 'string' ||
          $field_def->getType() === 'string_long' ||
          $field_def->getType() === 'text' ||
          $field_def->getType() === 'text_long') {
        $field_options[$field_name] = $field_def->getLabel() ?: $field_name;
      }
    }

    return $field_options;
  }

  /**
   * Get the media bundle types accepted by the media_album_av node type.
   *
   * @return array
   *   Array of accepted media bundle IDs.
   */
  private function getAcceptedMediaBundles() {
    $field_manager = $this->entityFieldManager;

    // Get field definitions for the media_album_av node type.
    $node_fields = $field_manager->getFieldDefinitions('node', 'media_album_av');

    // Find the media reference field.
    foreach ($node_fields as $field_name => $field_def) {
      if ($field_def->getType() === 'entity_reference' &&
          $field_def->getSetting('target_type') === 'media') {

        // Get the handler settings which contains target bundles.
        $handler_settings = $field_def->getSetting('handler_settings') ?? [];
        $target_bundles = $handler_settings['target_bundles'] ?? [];

        if (!empty($target_bundles)) {
          return array_keys($target_bundles);
        }
      }
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('media_album_av.settings');
    $values = $form_state->getValues();

    // Save the node authors field selection.
    $config->set('node_authors_field', $values['global']['node_authors_field']);

    // Save album settings.
    $config->set('album_content_type', $values['album']['album_content_type']);
    $config->set('event_group_vocabulary', $values['album']['event_group_vocabulary']);
    $config->set('event_vocabulary', $values['album']['event_vocabulary']);
    $config->set('prefered_media_type_photo', $values['album']['prefered_media_type_photo']);
    $config->set('prefered_media_type_video', $values['album']['prefered_media_type_video']);
    $config->set('prefered_storage_location', $values['album']['prefered_storage_location']);
    $config->set('prefered_media_directory', $values['album']['prefered_media_directory']);

    // Extract the author_fields from the nested structure.
    $author_fields = $values['author_fields'] ?? [];

    // Clean up: only keep entries with non-empty field_name values.
    $cleaned_author_fields = [];
    foreach ($author_fields as $media_type_id => $settings) {
      if (isset($settings['field_name']) && !empty($settings['field_name'])) {
        $cleaned_author_fields[$media_type_id] = $settings['field_name'];
      }
    }

    $config->set('author_fields', $cleaned_author_fields)->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get available content types.
   *
   * @return array
   *   Array of content type options.
   */
  private function getContentTypes() {
    $options = [];
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    foreach ($content_types as $type) {
      $options[$type->id()] = $type->label();
    }

    return $options;
  }

  /**
   * Get available vocabularies.
   *
   * @return array
   *   Array of vocabulary options.
   */
  private function getVocabularies() {
    $options = [];
    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();

    foreach ($vocabularies as $vocab) {
      $options[$vocab->id()] = $vocab->label();
    }

    return $options;
  }

  /**
   * Get media types filtered by MIME type category.
   *
   * @param string $category
   *   The MIME category (e.g., 'image', 'video').
   *
   * @return array
   *   Array of media type options matching the category.
   */
  private function getMediaTypesByCategory($category) {
    $options = [];
    $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();

    foreach ($media_types as $media_type) {
      $bundle_id = $media_type->id();

      // Determine if this media type is for image or video based on bundle name.
      $is_image = stripos($bundle_id, 'image') !== FALSE ||
                  stripos($bundle_id, 'photo') !== FALSE ||
                  stripos($bundle_id, 'picture') !== FALSE;

      $is_video = stripos($bundle_id, 'video') !== FALSE ||
                  stripos($bundle_id, 'mov') !== FALSE;

      // Add to options if it matches the requested category.
      if (($category === 'image' && $is_image) || ($category === 'video' && $is_video)) {
        $options[$media_type->id()] = $media_type->label();
      }
    }

    return $options;
  }

  /**
   * Get available stream wrappers.
   *
   * @return array
   *   Array of stream wrapper options.
   */
  private function getStreamWrappers() {
    $options = [];
    $stream_wrappers = \Drupal::service('stream_wrapper_manager')->getWrappers(StreamWrapperInterface::VISIBLE);

    foreach ($stream_wrappers as $scheme => $wrapper_info) {
      $label = $wrapper_info['name'] ?? ucfirst($scheme);
      $options[$scheme] = $label . ' (' . $scheme . '://)';
    }

    return $options;
  }

}
