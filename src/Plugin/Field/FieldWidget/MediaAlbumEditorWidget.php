<?php

namespace Drupal\media_album_av_common\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\media_album_av_common\Service\AlbumGroupingFieldsService;

/**
 * Plugin implementation of the 'grouping_fields_widget'.
 *
 * @FieldWidget(
 *   id = "grouping_fields_widget",
 *   label = @Translation("Grouping Fields Selector"),
 *   field_types = {
 *     "list_string"
 *   },
 *   multiple_values = TRUE
 * )
 */
class GroupingFieldsWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The grouping fields service.
   *
   * @var \Drupal\media_album_av_common\Service\AlbumGroupingFieldsService
   */
  protected $groupingFieldsService;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, AlbumGroupingFieldsService $grouping_fields_service) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->groupingFieldsService = $grouping_fields_service;
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
      $container->get('media_album_av_common.grouping_fields')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Récupérer tous les champs disponibles directement via le service.
    $field_options = $this->getFieldOptions();

    if (empty($field_options)) {
      $element['warning'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t('No grouping fields available. Please check field definitions.') . '</div>',
      ];
      return $element;
    }

    $element['#attached']['library'][] = 'core/drupal.tabledrag';

    $element['grouping_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Level'),
        $this->t('Field'),
        $this->t('Weight'),
      ],
      '#attributes' => ['id' => 'grouping-fields-table-' . $this->fieldDefinition->getName()],
      '#empty' => $this->t('No grouping fields selected.'),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'grouping-order-weight',
        ],
      ],
      '#prefix' => '<div id="grouping-fields-wrapper">',
      '#suffix' => '</div>',
    ];

    // Construire les lignes existantes.
    $values = [];
    foreach ($items as $item) {
      if (!empty($item->value)) {
        $values[] = $item->value;
      }
    }

    // Ajouter une ligne vide pour permettre l'ajout.
    $values[] = '';

    foreach ($values as $delta => $field_value) {
      $level = $delta + 1;

      $element['grouping_table'][$delta] = [
        '#attributes' => [
          'class' => ['draggable'],
        ],
      ];

      $element['grouping_table'][$delta]['level'] = [
        '#markup' => '<strong>' . $this->t('Level @level', ['@level' => $level]) . '</strong>',
      ];

      $element['grouping_table'][$delta]['field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field for level @level', ['@level' => $level]),
        '#title_display' => 'invisible',
        '#options' => ['' => $this->t('- Select field -')] + $field_options,
        '#default_value' => $field_value,
        '#required' => $delta < count($items),
      ];

      $element['grouping_table'][$delta]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for row @number', ['@number' => $level]),
        '#title_display' => 'invisible',
        '#default_value' => $delta,
        '#attributes' => ['class' => ['grouping-order-weight']],
        '#delta' => 10,
      ];
    }

    // Explication pour l'utilisateur.
    $element['help'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['description']],
      'content' => [
        '#markup' => '<p><strong>' . $this->t('Hierarchy:') . '</strong> ' .
        $this->t('Drag rows to reorder. Level 1 is the top-level grouping (broadest), Level 2 is nested within Level 1, etc.') . '</p>',
      ],
    ];

    return $element;
  }

  /**
   * Get all available field options from service.
   */
  protected function getFieldOptions() {
    $options = [];

    $node_fields = $this->groupingFieldsService->getNodeFields();
    foreach ($node_fields as $field_name => $config) {
      $options[$field_name] = $config['label'] . ' (' . $this->t('Node') . ')';
    }

    $media_fields = $this->groupingFieldsService->getMediaFields();
    foreach ($media_fields as $field_name => $config) {
      $options[$field_name] = $config['label'] . ' (' . $this->t('Media') . ')';
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $result = [];

    if (isset($values['grouping_table'])) {
      // Trier par poids (ordre du drag and drop)
      $sorted = $values['grouping_table'];
      uasort($sorted, function ($a, $b) {
        return $a['weight'] <=> $b['weight'];
      });

      foreach ($sorted as $row) {
        if (!empty($row['field'])) {
          $result[] = ['value' => $row['field']];
        }
      }
    }

    return $result;
  }

}
