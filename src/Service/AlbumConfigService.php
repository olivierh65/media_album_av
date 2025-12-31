<?php

namespace Drupal\media_album_av\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service to get album configuration settings.
 */
class AlbumConfigService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an AlbumConfigService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Get the configured album content type.
   *
   * @return string
   *   The content type ID (defaults to 'media_album_av').
   */
  public function getAlbumContentType() {
    $config = $this->configFactory->get('media_album_av.settings');
    return $config->get('album_content_type') ?? 'media_album_av';
  }

  /**
   * Get the configured event group vocabulary.
   *
   * @return string|null
   *   The vocabulary ID or NULL if not configured.
   */
  public function getEventGroupVocabulary() {
    $config = $this->configFactory->get('media_album_av.settings');
    $vocab = $config->get('event_group_vocabulary');
    return !empty($vocab) ? $vocab : NULL;
  }

  /**
   * Get the configured event vocabulary.
   *
   * @return string|null
   *   The vocabulary ID or NULL if not configured.
   */
  public function getEventVocabulary() {
    $config = $this->configFactory->get('media_album_av.settings');
    $vocab = $config->get('event_vocabulary');
    return !empty($vocab) ? $vocab : NULL;
  }

  /**
   * Get the configured date field name.
   *
   * @return string
   *   The field name (defaults to 'field_media_album_av_date').
   */
  public function getDateField() {
    $config = $this->configFactory->get('media_album_av.settings');
    return $config->get('date_field') ?? 'field_media_album_av_date';
  }

  /**
   * Get the configured event group field name.
   *
   * @return string
   *   The field name (defaults to 'field_media_album_av_event_group').
   */
  public function getEventGroupField() {
    $config = $this->configFactory->get('media_album_av.settings');
    return $config->get('event_group_field') ?? 'field_media_album_av_event_group';
  }

  /**
   * Get the configured event field name.
   *
   * @return string
   *   The field name (defaults to 'field_media_album_av_event').
   */
  public function getEventField() {
    $config = $this->configFactory->get('media_album_av.settings');
    return $config->get('event_field') ?? 'field_media_album_av_event';
  }

}
