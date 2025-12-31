<?php

namespace Drupal\media_album_av\Form;

use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\media_album_av\Service\AlbumConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for creating a new media album with taxonomies and node.
 */
class CreateAlbumForm extends FormBase {

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
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The album config service.
   *
   * @var \Drupal\media_album_av\Service\AlbumConfigService
   */
  protected $albumConfig;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a CreateAlbumForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\media_album_av\Service\AlbumConfigService $album_config
   *   The album config service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    MessengerInterface $messenger,
    RendererInterface $renderer,
    AlbumConfigService $album_config,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->messenger = $messenger;
    $this->renderer = $renderer;
    $this->albumConfig = $album_config;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('messenger'),
      $container->get('renderer'),
      $container->get('media_album_av.album_config'),
      $container->get('config.factory'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_album_av_create_album_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;
    $form['#attributes']['id'] = 'media-album-av-create-album-form';
    $form['#attributes']['class'][] = 'media-album-av-create-form';

    // Attach libraries.
    $form['#attached']['library'][] = 'media_album_av/album-forms';

    // ==========================================
    // 1. ALBUM BASIC INFO
    // ==========================================
    $form['album_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Album Information'),
      '#collapsible' => FALSE,
    ];

    $form['album_info']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Album Title'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('Enter the name of the album.'),
    ];

    $form['album_info']['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Album Date'),
      '#required' => FALSE,
      '#description' => $this->t('Select the date for the album (without time).'),
    ];

    // ==========================================
    // 2. TAXONOMY REFERENCES - With integrated jsTree
    // ==========================================
    $form['taxonomy'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Event & Category'),
      '#collapsible' => FALSE,
    ];

    // Attach jsTree library.
    $form['#attached']['library'][] = 'media_album_av/jstree';
    $form['#attached']['library'][] = 'media_album_av/taxonomy-manager-inline';

    // Get configured vocabularies.
    $event_group_vocab = $this->albumConfig->getEventGroupVocabulary();
    $event_vocab = $this->albumConfig->getEventVocabulary();

    // Event Group & Event Taxonomies - Two columns container.
    $form['taxonomy']['trees'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['taxonomy-inline-two-columns']],
    ];

    // Event Group Taxonomy - Integrated jsTree.
    if ($event_group_vocab) {
      $form['taxonomy']['trees']['event_group'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['form-group', 'taxonomy-inline-tree', 'taxonomy-column']],
      ];

      $form['taxonomy']['trees']['event_group']['title'] = [
        '#type' => 'markup',
        '#markup' => '<label>' . $this->t('Event Group') . '</label>',
      ];

      $form['taxonomy']['trees']['event_group']['tree'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'taxonomy-tree-event-group',
          'class' => ['taxonomy-inline-tree-container'],
          'data-vocabulary-id' => $event_group_vocab,
          'data-vocabulary-label' => 'Event Group',
        ],
      ];

      $form['taxonomy']['trees']['event_group']['selected'] = [
        '#type' => 'hidden',
        '#default_value' => '',
        '#attributes' => [
          'id' => 'event-group-selected',
          'class' => ['taxonomy-selected-value'],
          'data-vocabulary-id' => $event_group_vocab,
        ],
      ];
    }

    // Event Taxonomy - Integrated jsTree.
    if ($event_vocab) {
      $form['taxonomy']['trees']['event'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['form-group', 'taxonomy-inline-tree', 'taxonomy-column']],
      ];

      $form['taxonomy']['trees']['event']['title'] = [
        '#type' => 'markup',
        '#markup' => '<label>' . $this->t('Event') . '</label>',
      ];

      $form['taxonomy']['trees']['event']['tree'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'taxonomy-tree-event',
          'class' => ['taxonomy-inline-tree-container'],
          'data-vocabulary-id' => $event_vocab,
          'data-vocabulary-label' => 'Event',
        ],
      ];

      $form['taxonomy']['trees']['event']['selected'] = [
        '#type' => 'hidden',
        '#default_value' => '',
        '#attributes' => [
          'id' => 'event-selected',
          'class' => ['taxonomy-selected-value'],
          'data-vocabulary-id' => $event_vocab,
        ],
      ];
    }

    // ==========================================
    // 3. MEDIA DIRECTORY (STORE)
    // ==========================================
    $form['storage'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Media Directory'),
      '#collapsible' => FALSE,
    ];

    // Attach jsTree library.
    $form['#attached']['library'][] = 'media_album_av/jstree';
    $form['#attached']['library'][] = 'media_album_av/taxonomy-manager-inline';

    $directory_vocab = $this->getDirectoryVocabulary();

    if ($directory_vocab) {
      $form['storage']['title'] = [
        '#type' => 'markup',
        '#markup' => '<label>' . $this->t('Select Directory') . '</label>',
      ];

      $form['storage']['tree'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => [
          'id' => 'storage-directory-tree',
          'class' => ['taxonomy-inline-tree-container', 'storage-tree-container'],
          'data-vocabulary-id' => $directory_vocab,
          'data-vocabulary-label' => 'Directory',
        ],
      ];

      $form['storage']['selected'] = [
        '#type' => 'hidden',
        '#default_value' => '',
        '#attributes' => [
          'id' => 'storage-directory-selected',
          'class' => ['storage-selected-value'],
          'data-vocabulary-id' => $directory_vocab,
        ],
      ];
    }
    else {
      $form['storage']['no_directories'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No storage directories available.') . '</p>',
      ];
    }

    // ==========================================
    // 4. ACTIONS
    // ==========================================
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Album'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('media_album_av.settings'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * Get vocabulary terms organized hierarchically.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return array
   *   Array of terms with hierarchy.
   */
  private function getVocabularyTerms($vocabulary_id) {
    try {
      $terms = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => $vocabulary_id]);

      return $terms;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Format taxonomy terms for select element with hierarchy.
   *
   * @param array $terms
   *   Array of taxonomy terms.
   *
   * @return array
   *   Formatted options array.
   */
  private function formatTermsForSelect(array $terms) {
    $options = [];

    // Group by parent.
    $by_parent = [];
    foreach ($terms as $term) {
      $parent = $term->parent ? $term->parent->target_id : 0;
      if (!isset($by_parent[$parent])) {
        $by_parent[$parent] = [];
      }
      $by_parent[$parent][] = $term;
    }

    // Build hierarchy.
    $this->buildTermOptions($options, $by_parent, 0, '');

    return $options;
  }

  /**
   * Recursively build term options with indentation.
   *
   * @param array $options
   *   Options array to populate.
   * @param array $by_parent
   *   Terms grouped by parent.
   * @param int $parent_id
   *   Current parent ID.
   * @param string $prefix
   *   Prefix for indentation.
   */
  private function buildTermOptions(array &$options, array $by_parent, $parent_id = 0, $prefix = '') {
    if (isset($by_parent[$parent_id])) {
      foreach ($by_parent[$parent_id] as $term) {
        $options[$term->id()] = $prefix . $term->getName();
        $this->buildTermOptions($options, $by_parent, $term->id(), $prefix . '-- ');
      }
    }
  }

  /**
   * Get the directory vocabulary ID.
   *
   * @return string|null
   *   The vocabulary ID, or NULL if not configured.
   */
  private function getDirectoryVocabulary() {
    try {
      $config = $this->configFactory->get('media_directories.settings');
      $directory_taxonomy = $config->get('directory_taxonomy');

      if (!$directory_taxonomy) {
        return NULL;
      }

      // Verify vocabulary exists.
      $vocabulary = $this->entityTypeManager
        ->getStorage('taxonomy_vocabulary')
        ->load($directory_taxonomy);

      if (!$vocabulary) {
        return NULL;
      }

      return $directory_taxonomy;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    try {
      // Get configured content type and vocabularies.
      $content_type = $this->albumConfig->getAlbumContentType();
      $event_group_vocab = $this->albumConfig->getEventGroupVocabulary();
      $event_vocab = $this->albumConfig->getEventVocabulary();

      // Get the selected taxonomy terms from hidden fields.
      // These fields now contain JSON with selected_id and hierarchy.
      $event_group_selected = NULL;
      $event_selected = NULL;
      $directory_selected = NULL;

      if ($event_group_vocab && isset($values['taxonomy']['trees']['event_group']['selected'])) {
        $event_group_selected = $values['taxonomy']['trees']['event_group']['selected'];
      }
      if ($event_vocab && isset($values['taxonomy']['trees']['event']['selected'])) {
        $event_selected = $values['taxonomy']['trees']['event']['selected'];
      }
      if (isset($values['storage']['selected'])) {
        $directory_selected = $values['storage']['selected'];
      }

      // Parse JSON if available, otherwise use old format (backward compatibility).
      $event_group_data = $this->parseHierarchyData($event_group_selected);
      $event_data = $this->parseHierarchyData($event_selected);

      $event_group_id = $event_group_data['selected_id'];
      $event_id = $event_data['selected_id'];

      // Validate that we have selected terms if vocabularies are configured.
      if ($event_group_vocab && !$event_group_id) {
        $this->messenger->addError(
          $this->t('Please select an Event Group from the taxonomy tree.')
        );
        return;
      }

      if ($event_vocab && !$event_id) {
        $this->messenger->addError(
          $this->t('Please select an Event from the taxonomy tree.')
        );
        return;
      }

      if (!$directory_selected) {
        $this->messenger->addError(
          $this->t('Please select a storage directory.')
        );
        return;
      }
      // Apply hierarchy changes to taxonomy terms are made in the tree.
      // Build node field mapping.
      $node_data = [
        'type' => $content_type,
        'title' => $values['album_info']['title'],
        'status' => 1,
      ];

      // Add date if provided.
      if (!empty($values['album_info']['date'])) {
        $date_field = $this->albumConfig->getDateField();
        $node_data[$date_field] = [
          'value' => $values['album_info']['date'],
        ];
      }

      // Add taxonomy references if configured.
      if ($event_group_vocab && $event_group_id) {
        $event_group_field = $this->albumConfig->getEventGroupField();
        $node_data[$event_group_field] = [
          'target_id' => $event_group_id,
        ];
      }

      if ($event_vocab && $event_id) {
        $event_field = $this->albumConfig->getEventField();
        $node_data[$event_field] = [
          'target_id' => $event_id,
        ];
      }

      // Create the node.
      $node = $this->entityTypeManager->getStorage('node')->create($node_data);

      $node->save();

      $this->messenger->addMessage(
        $this->t('Album "@title" has been created successfully.', [
          '@title' => $values['album_info']['title'],
        ])
      );

      $form_state->setRedirect('entity.node.canonical', [
        'node' => $node->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->messenger->addError(
        $this->t('Error creating album: @error', [
          '@error' => $e->getMessage(),
        ])
      );
    }
  }

  /**
   * Parse hierarchy data from JSON or return ID only.
   *
   * @param string $data
   *   The hidden field value (JSON or simple ID).
   *
   * @return array
   *   Array with 'selected_id' and 'hierarchy' keys.
   */
  private function parseHierarchyData($data) {
    if (empty($data)) {
      return ['selected_id' => NULL, 'hierarchy' => []];
    }

    // Try to parse as JSON.
    $decoded = json_decode($data, TRUE);
    if ($decoded && isset($decoded['selected_id'])) {
      return [
        'selected_id' => $decoded['selected_id'],
        'hierarchy' => $decoded['hierarchy'] ?? [],
      ];
    }

    // Fall back to simple numeric ID.
    return [
      'selected_id' => is_numeric($data) ? (int) $data : NULL,
      'hierarchy' => [],
    ];
  }

  /**
   * Apply hierarchy changes to taxonomy terms.
   *
   * @param array $hierarchy
   *   Array of term objects with id, parent_id, and weight.
   *
   * @throws \Exception
   */
  private function applyTaxonomyHierarchy(array $hierarchy) {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');

    foreach ($hierarchy as $item) {
      if (empty($item['id'])) {
        continue;
      }

      try {
        $term = $termStorage->load($item['id']);
        if (!$term) {
          continue;
        }

        $parent_id = $item['parent_id'] ?? 0;
        $weight = $item['weight'] ?? 0;

        // Only update if parent or weight has changed.
        $currentParent = $term->getParentTarget();
        $currentParentId = $currentParent ? $currentParent->id() : 0;
        $currentWeight = (int) $term->getWeight();

        $parentChanged = $currentParentId !== $parent_id;
        $weightChanged = $currentWeight !== $weight;

        if ($parentChanged) {
          $term->setParent($parent_id);
        }

        if ($weightChanged) {
          $term->set('weight', $weight);
        }

        if ($parentChanged || $weightChanged) {
          $term->save();
        }
      }
      catch (\Exception $e) {
        // Log but don't fail the whole operation.
        $this->loggerFactory->get('media_album_av')->error(
          'Error updating taxonomy term parent: @error',
          ['@error' => $e->getMessage()]
        );
      }
    }
  }

}
