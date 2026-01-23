<?php

namespace Drupal\media_album_av\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media_album_av\Service\AlbumConfigService;
use Drupal\media_album_av_common\Service\DirectoryService;
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
   * The directory service (from ).
   *
   * @var \Drupal\\Service\DirectoryService
   */
  protected $directoryService;

  /**
   * Constructs a TaxonomyManagerController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\media_album_av\Service\AlbumConfigService $album_config
   *   The album config service.
   * @param \Drupal\\Service\DirectoryService $directory_service
   *   The directory service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AlbumConfigService $album_config,
    DirectoryService $directory_service,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->albumConfig = $album_config;
    $this->directoryService = $directory_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('media_album_av.album_config'),
      $container->get('media_album_av_common.directory_service')
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
          'media_album_av_common/jstree',
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

      // Use DirectoryService to build tree structure.
      $tree = $this->directoryService->getDirectoryTreeData($vocabulary_id, NULL);

      return new JsonResponse($tree);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 400);
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
          'media_album_av_common/jstree',
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

      // Use DirectoryService to create term.
      $term_id = $this->directoryService->createDirectoryTerm(
        $vocabulary_id,
        $data['name'],
        $data['parent'] ?? 0
      );

      return new JsonResponse([
        'success' => TRUE,
        'id' => 'node_' . $term_id,
        'term_id' => $term_id,
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

      // Use DirectoryService to move term.
      $this->directoryService->moveDirectoryTerm($data['term_id'], $data['parent_id']);

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

      // Use DirectoryService to delete term.
      $this->directoryService->deleteDirectoryTerm($data['term_id']);

      return new JsonResponse([
        'success' => TRUE,
        'message' => $this->t('Term deleted successfully.'),
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
