<?php

namespace Drupal\media_album_av\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media_album_av\Service\AlbumConfigService;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Taxonomy Manager modal and API.
 */
class TaxonomyManagerController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The album config service.
   *
   * @var \Drupal\media_album_av\Service\AlbumConfigService
   */
  protected $albumConfig;

  /**
   * Constructs a TaxonomyManagerController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\media_album_av\Service\AlbumConfigService $album_config
   *   The album config service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AlbumConfigService $album_config) {
    $this->entityTypeManager = $entity_type_manager;
    $this->albumConfig = $album_config;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('media_album_av.album_config')
    );
  }

  /**
   * Modal page for taxonomy manager.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return array
   *   Render array for modal.
   */
  public function modal($vocabulary_id) {
    // Verify vocabulary exists.
    try {
      $vocabulary = Vocabulary::load($vocabulary_id);
      if (!$vocabulary) {
        throw new \Exception('Vocabulary not found');
      }
    }
    catch (\Exception $e) {
      return [
        '#markup' => '<p>' . $this->t('Vocabulary not found.') . '</p>',
      ];
    }

    return [
      '#theme' => 'taxonomy_manager_modal',
      '#vocabulary_id' => $vocabulary_id,
      '#vocabulary_label' => $vocabulary->label(),
      '#attached' => [
        'library' => [
          'media_album_av/taxonomy-manager-modal',
          'media_album_av/jstree',
        ],
      ],
    ];
  }

  /**
   * Get tree data as JSON for jsTree.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with tree data.
   */
  public function getTreeJson($vocabulary_id) {
    try {
      $vocabulary = Vocabulary::load($vocabulary_id);
      if (!$vocabulary) {
        return new JsonResponse(['error' => 'Vocabulary not found'], 404);
      }

      // Load terms.
      $terms = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => $vocabulary_id]);

      // Build tree structure for jsTree.
      $tree = $this->buildTreeForJsTree($terms);

      return new JsonResponse($tree);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 400);
    }
  }

  /**
   * Build tree structure for jsTree library.
   *
   * @param array $terms
   *   Array of taxonomy terms.
   *
   * @return array
   *   Tree data for jsTree.
   */
  private function buildTreeForJsTree(array $terms) {
    $tree_data = [];
    $by_id = [];

    // Build ID map.
    foreach ($terms as $term) {
      $by_id[$term->id()] = [
        'id' => 'node_' . $term->id(),
        'text' => $term->getName(),
        'data' => [
          'term_id' => $term->id(),
          'description' => $term->getDescription(),
          'weight' => (int) $term->getWeight(),
        ],
        'children' => [],
      ];
    }

    // Build hierarchy and sort by weight.
    foreach ($terms as $term) {
      $parent_id = 0;
      if ($term->parent && !empty($term->parent->target_id)) {
        $parent_id = $term->parent->target_id;
      }

      if ($parent_id === 0 || !isset($by_id[$parent_id])) {
        // Root node.
        $tree_data[] = &$by_id[$term->id()];
      }
      else {
        // Child node.
        $by_id[$parent_id]['children'][] = &$by_id[$term->id()];
      }
    }

    // Sort all levels by weight.
    $this->sortTreeByWeight($tree_data);

    return $tree_data;
  }

  /**
   * Sort tree nodes by weight recursively.
   *
   * @param array &$tree_data
   *   Reference to tree data to sort.
   */
  private function sortTreeByWeight(array &$tree_data) {
    // Sort current level by weight.
    usort($tree_data, function ($a, $b) {
      $weight_a = $a['data']['weight'] ?? 0;
      $weight_b = $b['data']['weight'] ?? 0;
      return $weight_a - $weight_b;
    });

    // Sort children recursively.
    foreach ($tree_data as &$node) {
      if (!empty($node['children'])) {
        $this->sortTreeByWeight($node['children']);
      }
    }
  }

  /**
   * Get modal content as HTML (for AJAX loading).
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return array
   *   Render array for modal content only (no page wrapper).
   */
  public function modalContent($vocabulary_id) {
    // Verify vocabulary exists.
    try {
      $vocabulary = Vocabulary::load($vocabulary_id);
      if (!$vocabulary) {
        throw new \Exception('Vocabulary not found');
      }
    }
    catch (\Exception $e) {
      return [
        '#markup' => '<p>' . $this->t('Vocabulary not found.') . '</p>',
      ];
    }

    return [
      '#theme' => 'taxonomy_manager_modal',
      '#vocabulary_id' => $vocabulary_id,
      '#vocabulary_label' => $vocabulary->label(),
      '#attached' => [
        'library' => [
          'media_album_av/taxonomy-manager-modal',
          'media_album_av/jstree',
        ],
      ],
    ];
  }

  /**
   * Add a new term via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function addTerm(Request $request, $vocabulary_id) {
    try {
      $vocabulary = Vocabulary::load($vocabulary_id);
      if (!$vocabulary) {
        return new JsonResponse(['error' => 'Vocabulary not found'], 404);
      }

      // Get POST data.
      $data = json_decode($request->getContent(), TRUE);

      if (!isset($data['name'])) {
        return new JsonResponse([
          'error' => 'Term name is required',
        ], 400);
      }

      // Create term.
      $term = Term::create([
        'vid' => $vocabulary_id,
        'name' => $data['name'],
        'description' => $data['description'] ?? '',
        'parent' => $data['parent'] ?? 0,
        'weight' => isset($data['weight']) ? (int) $data['weight'] : 0,
      ]);

      $term->save();

      return new JsonResponse([
        'success' => TRUE,
        'id' => 'node_' . $term->id(),
        'term_id' => $term->id(),
        'message' => $this->t('Term "@term" created successfully.', [
          '@term' => $data['name'],
        ]),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 400);
    }
  }

  /**
   * Move a term (change its parent) via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function moveTerm(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);

      if (!isset($data['term_id']) || !isset($data['parent_id'])) {
        return new JsonResponse([
          'error' => 'Missing term_id or parent_id',
        ], 400);
      }

      $term = Term::load($data['term_id']);
      if (!$term) {
        return new JsonResponse(['error' => 'Term not found'], 404);
      }

      // Update parent.
      $term->set('parent', $data['parent_id']);
      $term->save();

      // Update weights for all terms if provided.
      if (!empty($data['weights']) && is_array($data['weights'])) {
        foreach ($data['weights'] as $term_id => $weight) {
          $t = Term::load($term_id);
          if ($t) {
            $t->set('weight', (int) $weight);
            $t->save();
          }
        }
      }

      return new JsonResponse([
        'success' => TRUE,
        'message' => $this->t('Term moved successfully.'),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 400);
    }
  }

  /**
   * Delete a term via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function deleteTerm(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);

      if (!isset($data['term_id'])) {
        return new JsonResponse([
          'error' => 'Missing term_id',
        ], 400);
      }

      $term = Term::load($data['term_id']);
      if (!$term) {
        return new JsonResponse(['error' => 'Term not found'], 404);
      }

      $term_name = $term->label();
      $term->delete();

      return new JsonResponse([
        'success' => TRUE,
        'message' => $this->t('Term "@term" deleted successfully.', [
          '@term' => $term_name,
        ]),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 400);
    }
  }

  /**
   * Update a term via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function updateTerm(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);

      if (!isset($data['term_id'])) {
        return new JsonResponse([
          'error' => 'Missing term_id',
        ], 400);
      }

      $term = Term::load($data['term_id']);
      if (!$term) {
        return new JsonResponse(['error' => 'Term not found'], 404);
      }

      // Update name.
      if (isset($data['name'])) {
        $term->set('name', $data['name']);
      }

      // Update description.
      if (isset($data['description'])) {
        $term->set('description', $data['description']);
      }

      $term->save();

      return new JsonResponse([
        'success' => TRUE,
        'message' => $this->t('Term updated successfully.'),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 400);
    }
  }

  /**
   * Get term data via AJAX.
   *
   * @param int $term_id
   *   The term ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with term data.
   */
  public function getTerm($term_id) {
    try {
      $term = Term::load($term_id);
      if (!$term) {
        return new JsonResponse(['success' => FALSE, 'error' => 'Term not found'], 404);
      }

      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'id' => $term->id(),
          'name' => $term->getName(),
          'description' => $term->getDescription(),
          'vid' => $term->bundle(),
        ],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
      ], 400);
    }
  }

}
