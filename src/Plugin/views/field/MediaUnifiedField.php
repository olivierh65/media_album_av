<?php

namespace Drupal\media_album_av\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Champ Views permettant d'afficher un media unifié (photo / vidéo).
 *
 * @ViewsField("media_album_av_unified_media")
 */
class MediaUnifiedField extends FieldPluginBase {

  /**
   * Aucun impact sur la requête SQL.
   */
  public function query() {
    // Intentionnellement vide.
  }

  /**
   * Options du champ Views.
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['media_fields'] = ['default' => []];
    $options['view_mode'] = ['default' => 'default'];
    return $options;
  }

  /**
   * Formulaire de configuration du champ dans Views.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $media_fields = $this->getMediaFieldsFromEntity();

    $form['media_fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Champs media à utiliser (ordre de priorité)'),
      '#options' => $this->getMediaSourceFields(),
      '#default_value' => $this->options['media_fields'],
      '#required' => TRUE,
      '#description' => $this->t(
    'Le premier champ non vide sera affiché (fallback automatique).'
      ),
    ];

    $form['view_mode'] = [
      '#type' => 'textfield',
      '#title' => $this->t('View mode'),
      '#default_value' => $this->options['view_mode'],
      '#description' => $this->t('Ex: thumbnail, default, media_library'),
    ];
  }

  /**
   * Rendu du champ.
   */
  public function render(ResultRow $values) {
    $media = $values->_entity;

    if (!$media) {
      return [];
    }

    $view_mode = $this->options['view_mode'] ?: 'default';

    foreach (array_filter($this->options['media_fields']) as $field_name) {
      if ($media->hasField($field_name) && !$media->get($field_name)->isEmpty()) {
        return $media->get($field_name)->view($view_mode);
      }
    }

    return [];
  }

  /**
   * Récupère les champs media déjà présents dans la vue.
   */
  protected function getMediaFieldsFromView() {
    $options = [];

    foreach ($this->view->field as $field_id => $field) {
      if (
        isset($field->definition['entity_type']) &&
        $field->definition['entity_type'] === 'media'
      ) {
        $options[$field_id] = $field->definition['title'] ?? $field_id;
      }
    }

    return $options;
  }

  /**
   *
   */
  protected function getMediaFieldsFromEntity() {
    $options = [];

    /** @var \Drupal\Core\Entity\EntityFieldManager $field_manager */
    $field_manager = \Drupal::service('entity_field.manager');

    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('media');

    foreach (array_keys($bundles) as $bundle) {
      $fields = $field_manager->getFieldDefinitions('media', $bundle);

      foreach ($fields as $field_name => $definition) {
        if (
        $definition->getType() === 'image' ||
        $definition->getType() === 'file' ||
        $definition->getType() === 'media'
        ) {
          $options[$field_name] = $definition->getLabel() . " ($bundle)";
        }
      }
    }

    return $options;
  }

  /**
   *
   */
  protected function getMediaSourceFields() {
    $options = [];

    $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo('media');
    $field_manager = \Drupal::service('entity_field.manager');

    foreach (array_keys($bundle_info) as $bundle) {
      $fields = $field_manager->getFieldDefinitions('media', $bundle);

      foreach ($fields as $field_name => $definition) {

        if (
        in_array($definition->getType(), ['image', 'file']) &&
        !($definition instanceof BaseFieldDefinition)
        ) {
          $options[$field_name] = $definition->getLabel() . " ($bundle)";
        }
      }
    }

    return $options;
  }

}
