<?php

namespace Drupal\media_album_av\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service to retrieve configured media field names with fallback to defaults.
 */
class ConfiguredFieldsService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs the ConfiguredFieldsService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Get the configured category field for a media type.
   *
   * @param string $media_type_id
   *   The media type ID (e.g., 'media_album_av_photo').
   *
   * @return string|null
   *   The category field name, or NULL if not configured.
   */
  public function getCategoryField($media_type_id) {
    $config = $this->configFactory->get('media_album_av.settings');
    $category_fields = $config->get('category_fields') ?? [];

    if (isset($category_fields[$media_type_id])) {
      if (is_array($category_fields[$media_type_id])) {
        return $category_fields[$media_type_id]['field_name'] ?? NULL;
      }
      // Handle case where it might be stored as string (legacy).
      return $category_fields[$media_type_id];
    }

    // Return default value for known media types.
    $defaults = [
      'media_album_av_photo' => 'field_media_album_av_photo_category',
      'media_album_av_video' => 'field_media_album_av_video_category',
    ];

    return $defaults[$media_type_id] ?? NULL;
  }

  /**
   * Get the category field configuration for a media type.
   *
   * @param string $media_type_id
   *   The media type ID (e.g., 'media_album_av_photo').
   *
   * @return array|null
   *   Category field configuration array with 'field_name' and 'autocreate' keys.
   */
  public function getCategoryFieldConfig($media_type_id) {
    $config = $this->configFactory->get('media_album_av.settings');
    $category_fields = $config->get('category_fields') ?? [];

    if (isset($category_fields[$media_type_id])) {
      if (is_array($category_fields[$media_type_id])) {
        return $category_fields[$media_type_id];
      }
      // Handle legacy string format.
      return [
        'field_name' => $category_fields[$media_type_id],
        'autocreate' => FALSE,
      ];
    }

    // Return defaults.
    $defaults = [
      'media_album_av_photo' => [
        'field_name' => 'field_media_album_av_photo_category',
        'autocreate' => FALSE,
      ],
      'media_album_av_video' => [
        'field_name' => 'field_media_album_av_video_category',
        'autocreate' => FALSE,
      ],
    ];

    return $defaults[$media_type_id] ?? NULL;
  }

  /**
   * Get the configured author field for a media type.
   *
   * @param string $media_type_id
   *   The media type ID (e.g., 'media_album_av_photo').
   *
   * @return string|null
   *   The author field name, or NULL if not configured.
   */
  public function getAuthorField($media_type_id) {
    $config = $this->configFactory->get('media_album_av.settings');
    $author_fields = $config->get('author_fields') ?? [];

    if (isset($author_fields[$media_type_id])) {
      return $author_fields[$media_type_id];
    }

    // Return default value for known media types.
    $defaults = [
      'media_album_av_photo' => 'field_media_album_av_photo_autho',
      'media_album_av_video' => 'field_media_album_av_video_autho',
    ];

    return $defaults[$media_type_id] ?? NULL;
  }

  /**
   * Check if autocreate is enabled for category field of a media type.
   *
   * @param string $media_type_id
   *   The media type ID.
   *
   * @return bool
   *   TRUE if autocreate is enabled, FALSE otherwise.
   */
  public function isCategoryAutocreateEnabled($media_type_id) {
    $config = $this->configFactory->get('media_album_av.settings');
    $category_fields = $config->get('category_fields') ?? [];

    if (isset($category_fields[$media_type_id])) {
      if (is_array($category_fields[$media_type_id])) {
        return (bool) ($category_fields[$media_type_id]['autocreate'] ?? FALSE);
      }
    }

    return FALSE;
  }

}
