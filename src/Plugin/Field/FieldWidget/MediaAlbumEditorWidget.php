<?php

namespace Drupal\media_album_av\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\media_album_av_common\Traits\FieldWidgetBuilderTrait;
use Drupal\media_album_av_common\Service\MediaViewRendererService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of 'media_album_av_media_editor' widget.
 *
 * Renders a VBO media grid for managing media in album nodes.
 *
 * @FieldWidget(
 *   id = "media_album_av_media_editor",
 *   label = @Translation("Media Editor (Album) - Grid View"),
 *   field_types = {
 *     "entity_reference"
 *   },
 *   multiple_values = TRUE
 * )
 */
class MediaAlbumEditorWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  use FieldWidgetBuilderTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The media view renderer service.
   *
   * @var \Drupal\media_album_av_common\Service\MediaViewRendererService
   */
  protected $mediaViewRenderer;

  /**
   * The image style storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $imageStyleStorage;

  /**
   * Constructs a new MediaAlbumEditorWidget.
   */
  public function __construct($plugin_id, $plugin_definition, $field_definition, array $settings, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager, MediaViewRendererService $media_view_renderer) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->entityTypeManager = $entity_type_manager;
    $this->mediaViewRenderer = $media_view_renderer;
    $this->imageStyleStorage = $entity_type_manager->getStorage('image_style');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('media_album_av_common.media_view_renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'view_id' => 'media_album_av_editor',
      'display_id' => 'media_album_av_editor',
      'image_thumbnail_style' => 'medium',
      'columns' => 6,
      'gap' => '10px',
      'items_per_page' => 100,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = [];

    $element['view_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('View ID'),
      '#description' => $this->t('The ID of the Views to use for displaying media.'),
      '#default_value' => $this->getSetting('view_id'),
    ];

    $element['display_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Display ID'),
      '#description' => $this->t('The display ID within the View.'),
      '#default_value' => $this->getSetting('display_id'),
    ];

    // Image styles for thumbnails.
    $image_styles = $this->imageStyleStorage->loadMultiple();
    foreach ($image_styles as $style => $image_style) {
      $image_thumbnail_style[$image_style->id()] = $image_style->label();
    }
    $default_style = '';
    if (isset($this->options['image_thumbnail_style']) && $this->getSetting('image_thumbnail_style')) {
      $default_style = $this->getSetting('image_thumbnail_style');
    }
    elseif (isset($image_styles['medium'])) {
      $default_style = 'medium';
    }
    elseif (isset($image_styles['thumbnail'])) {
      $default_style = 'thumbnail';
    }
    elseif (!empty($image_styles)) {
      $default_style = array_key_first($image_styles);
    }
    $this->setSetting('image_thumbnail_style', $default_style);

    $element['image_thumbnail_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Image thumbnail style'),
      '#description' => $this->t('The image style to use for media thumbnails in the editor.'),
      '#options' => $image_thumbnail_style,
      '#default_value' => $this->getSetting('image_thumbnail_style'),
    ];

    $element['columns'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of columns'),
      '#description' => $this->t('The number of columns to display in the media grid.'),
      '#default_value' => $this->getSetting('columns'),
      '#min' => 1,
      '#max' => 12,
    ];
    $element['gap'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Gap between items'),
      '#description' => $this->t('The gap between items in the media grid.'),
      '#default_value' => $this->getSetting('gap'),
    ];

    $element['items_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Items per page'),
      '#description' => $this->t('The number of media items to display per page.'),
      '#default_value' => $this->getSetting('items_per_page'),
      '#min' => 1,
      '#max' => 1000,
    ];

    return $element + parent::settingsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $view_id = $this->getSetting('view_id');
    $display_id = $this->getSetting('display_id');

    if ($view_id && $display_id) {
      $summary[] = $this->t('View: @view (@display)', [
        '@view' => $view_id,
        '@display' => $display_id,
      ]);
    }
    else {
      $summary[] = $this->t('View: Not configured');
    }

    $summary[] = $this->t('Columns: @columns', [
      '@columns' => $this->getSetting('columns'),
    ]);

    $summary[] = $this->t('Thumbnail style: @style', [
      '@style' => $this->getSetting('image_thumbnail_style'),
    ]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function form(FieldItemListInterface $items, array &$form, FormStateInterface $form_state, $get_delta = NULL) {
    $elements = [];

    $node = $items->getEntity();
    $node_id = $node->id();

    $media_ids = [];
    foreach ($items as $delta => $item) {
      if (!empty($item->target_id)) {
        $media_ids[$delta] = $item->target_id;
      }
    }

    $elements['#type'] = 'container';
    $elements['#attributes'] = ['class' => ['media-album-editor-widget']];
    $elements['#attached']['library'][] = 'media_album_av/media-album-editor-widget';

    $elements['instructions'] = [
      '#type' => 'markup',
      '#markup' => '<div class="media-album-editor-instructions"><p>' .
      $this->t('Use the grid below to manage media. Drag to reorder or use action buttons.') .
      '</p></div>',
    ];

    if ($node_id) {
      $view_id = $this->getSetting('view_id');
      $display_id = $this->getSetting('display_id');

      // Utiliser renderEmbeddedMediaView qui retourne le template personnalisé.
      $elements['view'] = $this->mediaViewRenderer->renderEmbeddedMediaView(
      $view_id,
      $display_id,
      [$node_id],
      // Passer les IDs des médias.
      $media_ids,
      ['media_album_av/media-album-editor-widget']
      );
    }
    else {
      $elements['empty_message'] = [
        '#type' => 'markup',
        '#markup' => '<p><em>' . $this->t('No media selected yet.') . '</em></p>',
      ];
    }

    $elements['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['media-album-editor-actions']],
    ];

    $elements['actions']['add_media'] = [
      '#type' => 'link',
      '#title' => $this->t('+ Add media'),
      '#url' => Url::fromRoute('view.media_drop_manage.page_1'),
      '#attributes' => [
        'class' => ['button', 'button-action'],
        'target' => '_blank',
      ],
    ];

    $elements['media_ids'] = [
      '#type' => 'hidden',
      '#value' => implode(',', $media_ids),
      '#attributes' => ['class' => ['media-ids-field']],
    ];

    // Ne pas définir #theme ici car c'est géré par le template du service.
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    return $element;
  }

}
