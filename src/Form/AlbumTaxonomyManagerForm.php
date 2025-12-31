<?php

namespace Drupal\media_album_av\Form;

use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing taxonomy terms within album creation.
 */
class AlbumTaxonomyManagerForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a AlbumTaxonomyManagerForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_album_av_taxonomy_manager_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $vocabulary_id = NULL) {
    if (!$vocabulary_id) {
      $this->messenger->addError($this->t('No vocabulary selected.'));
      return [];
    }

    // Load the vocabulary.
    try {
      $vocabulary = Vocabulary::load($vocabulary_id);
      if (!$vocabulary) {
        $this->messenger->addError($this->t('Vocabulary not found.'));
        return [];
      }
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error loading vocabulary: @error', [
        '@error' => $e->getMessage(),
      ]));
      return [];
    }

    $form['#tree'] = TRUE;
    $form['#attributes']['class'][] = 'taxonomy-manager-form';

    $form['vocabulary_id'] = [
      '#type' => 'hidden',
      '#value' => $vocabulary_id,
    ];

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<div class="intro-text"><h2>' . $this->t('Manage: @vocab', [
        '@vocab' => $vocabulary->label(),
      ]) . '</h2><p>' . $this->t('Add, edit, or delete terms for this taxonomy.') . '</p></div>',
    ];

    // ==========================================
    // Existing Terms Display
    // ==========================================
    $form['existing_terms'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Existing Terms'),
      '#collapsible' => FALSE,
      '#attributes' => ['class' => ['taxonomy-tree']],
    ];

    $terms = $this->loadTermsHierarchy($vocabulary_id);
    $form['existing_terms']['tree'] = [
      '#type' => 'markup',
      '#markup' => $this->renderTermTree($terms, $vocabulary_id),
    ];

    // ==========================================
    // Add New Term
    // ==========================================
    $form['add_term'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Add New Term'),
      '#collapsible' => FALSE,
      '#attributes' => ['class' => ['taxonomy-add-form']],
    ];

    $form['add_term']['term_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Term Name'),
      '#required' => TRUE,
      '#size' => 50,
    ];

    $form['add_term']['term_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#rows' => 3,
    ];

    $form['add_term']['term_parent'] = [
      '#type' => 'select',
      '#title' => $this->t('Parent Term'),
      '#options' => $this->getParentOptions($terms),
      '#empty_option' => $this->t('- Root (No parent) -'),
      '#description' => $this->t('Select a parent term for hierarchy.'),
    ];

    $form['add_term']['submit_add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Term'),
      '#name' => 'add_term_submit',
      '#submit' => [[$this, 'submitAddTerm']],
      '#ajax' => [
        'callback' => [$this, 'ajaxRefreshTerms'],
        'event' => 'click',
      ],
    ];

    // ==========================================
    // Actions
    // ==========================================
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['close'] = [
      '#type' => 'submit',
      '#value' => $this->t('Done'),
      '#submit' => [[$this, 'submitClose']],
    ];

    return $form;
  }

  /**
   * Load terms organized in hierarchy.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return array
   *   Hierarchical array of terms.
   */
  private function loadTermsHierarchy($vocabulary_id) {
    try {
      $terms = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => $vocabulary_id]);

      // Organize by parent.
      $by_parent = [0 => []];
      foreach ($terms as $term) {
        $parent_id = 0;
        if ($term->parent && !empty($term->parent->target_id)) {
          $parent_id = $term->parent->target_id;
        }
        if (!isset($by_parent[$parent_id])) {
          $by_parent[$parent_id] = [];
        }
        $by_parent[$parent_id][] = $term;
      }

      return $by_parent;
    }
    catch (\Exception $e) {
      return [0 => []];
    }
  }

  /**
   * Render term tree as HTML.
   *
   * @param array $by_parent
   *   Terms organized by parent.
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param int $parent_id
   *   Current parent ID.
   *
   * @return string
   *   HTML markup for term tree.
   */
  private function renderTermTree(array $by_parent, $vocabulary_id, $parent_id = 0) {
    if (!isset($by_parent[$parent_id]) || empty($by_parent[$parent_id])) {
      return '';
    }

    $html = '<ul class="term-list">';

    foreach ($by_parent[$parent_id] as $term) {
      $edit_url = Url::fromRoute('entity.taxonomy_term.edit_form', [
        'taxonomy_term' => $term->id(),
      ])->toString();

      $delete_url = Url::fromRoute('entity.taxonomy_term.delete_form', [
        'taxonomy_term' => $term->id(),
      ])->toString();

      $html .= '<li class="term-item">';
      $html .= '<div class="term-header">';
      $html .= '<span class="term-name">' . $term->getName() . '</span>';
      $html .= '<div class="term-actions">';
      $html .= '<a href="' . $edit_url . '" class="button button-small" target="_blank">' . $this->t('Edit') . '</a>';
      $html .= '<a href="' . $delete_url . '" class="button button-small" target="_blank">' . $this->t('Delete') . '</a>';
      $html .= '</div>';
      $html .= '</div>';

      // Render children.
      $children_html = $this->renderTermTree($by_parent, $vocabulary_id, $term->id());
      if ($children_html) {
        $html .= $children_html;
      }

      $html .= '</li>';
    }

    $html .= '</ul>';
    return $html;
  }

  /**
   * Get options for parent term select.
   *
   * @param array $by_parent
   *   Terms organized by parent.
   *
   * @return array
   *   Options for select element.
   */
  private function getParentOptions(array $by_parent) {
    $options = [];

    if (isset($by_parent[0])) {
      foreach ($by_parent[0] as $term) {
        $options[$term->id()] = $term->getName();
        $this->addChildOptions($options, $by_parent, $term->id(), '-- ');
      }
    }

    return $options;
  }

  /**
   * Recursively add child term options.
   *
   * @param array $options
   *   Options array to populate.
   * @param array $by_parent
   *   Terms organized by parent.
   * @param int $parent_id
   *   Parent term ID.
   * @param string $prefix
   *   Prefix for indentation.
   */
  private function addChildOptions(array &$options, array $by_parent, $parent_id, $prefix = '') {
    if (isset($by_parent[$parent_id])) {
      foreach ($by_parent[$parent_id] as $term) {
        $options[$term->id()] = $prefix . $term->getName();
        $this->addChildOptions($options, $by_parent, $term->id(), $prefix . '-- ');
      }
    }
  }

  /**
   * Submit handler for adding a term.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function submitAddTerm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $vocabulary_id = $values['vocabulary_id'];

    try {
      // Create new term.
      $term = Term::create([
        'vid' => $vocabulary_id,
        'name' => $values['add_term']['term_name'],
        'description' => $values['add_term']['term_description'] ?? '',
        'parent' => $values['add_term']['term_parent'] ?? 0,
      ]);

      $term->save();

      $this->messenger->addMessage($this->t('Term "@term" has been created.', [
        '@term' => $values['add_term']['term_name'],
      ]));

      // Clear the add form fields.
      $form_state->setValueForElement($form['add_term']['term_name'], '');
      $form_state->setValueForElement($form['add_term']['term_description'], '');
      $form_state->setValueForElement($form['add_term']['term_parent'], '');
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error creating term: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * AJAX callback to refresh terms list.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   AJAX response.
   */
  public function ajaxRefreshTerms(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    $vocabulary_id = $form_state->getValue('vocabulary_id');
    $terms = $this->loadTermsHierarchy($vocabulary_id);
    $html = $this->renderTermTree($terms, $vocabulary_id);

    $response->addCommand(new HtmlCommand(
      '.taxonomy-tree',
      '<div class="taxonomy-tree"><div class="existing-terms">' . $html . '</div></div>'
    ));

    return $response;
  }

  /**
   * Submit handler for close button.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function submitClose(array &$form, FormStateInterface $form_state) {
    // Just close the form, parent form will handle it.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Main submit is handled by submitAddTerm.
  }

}
