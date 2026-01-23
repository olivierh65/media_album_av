/**
 * Media Album AV - Inline Taxonomy Manager with jsTree
 *
 * This script manages taxonomy terms directly in the album creation form
 * with inline jsTree and context menu for add/edit/delete operations.
 */

(function (Drupal, $) {
  'use strict';

  /**
   * Initialize inline taxonomy managers.
   */
  Drupal.behaviors.mediaAlbumAvTaxonomyManagerInline = {
    attach: function (context) {
      // Initialize each taxonomy tree container using Drupal 10 once() syntax
      once('taxonomy-tree-inline', '.taxonomy-inline-tree-container', context).forEach(function (element) {
        const container = $(element);
        const vocabularyId = container.data('vocabulary-id');
        const vocabularyLabel = container.data('vocabulary-label');

        if (vocabularyId) {
          initializeTaxonomyTree(container, vocabularyId, vocabularyLabel);
        }
      });
    }
  };

  /**
   * Calculate weight for a new term based on sibling count.
   *
   * @param {jQuery} container
   *   The tree container element.
   * @param {number} parentId
   *   The parent term ID (0 for root).
   * @return {number}
   *   The calculated weight.
   */
  function calculateTermWeight(container, parentId) {
    const jstreeInstance = container.jstree(true);
    if (!jstreeInstance) {
      return 0;
    }

    const nodes = jstreeInstance.get_json('#', { flat: true });
    let siblingCount = 0;

    nodes.forEach(function (node) {
      if (node.data && node.data.term_id) {
        const nodeParentId = node.parent === '#' ? 0 : (nodes.find(n => n.id === node.parent) ? nodes.find(n => n.id === node.parent).data.term_id : 0);
        if (nodeParentId === parentId) {
          siblingCount++;
        }
      }
    });

    return siblingCount;
  }

  /**
   * Recalculate weights for all children of a parent based on visual order.
   *
   * @param {jQuery} container
   *   The tree container element.
   * @param {number} parentId
   *   The parent term ID (0 for root).
   */
  function recalculateWeightsForParent(container, parentId) {
    const jstreeInstance = container.jstree(true);
    if (!jstreeInstance) {
      return;
    }

    // Get parent node ID
    let parentNodeId = '#'; // Root
    if (parentId !== 0) {
      // Find parent node
      const nodes = jstreeInstance.get_json('#', { flat: false });
      const parentNode = findNodeById(nodes, 'node_' + parentId);
      if (parentNode) {
        parentNodeId = parentNode.id;
      }
    }

    // Get children of parent
    const childrenIds = jstreeInstance.get_children_dom(parentNodeId);

    // Update weights for each child
    let weight = 0;
    $(childrenIds).each(function () {
      const nodeId = this.id;
      const node = jstreeInstance.get_node(nodeId);
      if (node && node.data) {
        node.data.weight = weight;
        weight++;
      }
    });
  }

  /**
 * Recalcule les poids des enfants d’un parent jsTree.
 * Le poids repart à 0 pour chaque parent.
 */
function jstreeRecalculateSiblingWeights(tree, parentNodeId) {
  const parentNode = tree.get_node(parentNodeId);
  if (!parentNode || !parentNode.children) {
    return;
  }

  parentNode.children.forEach((childId, index) => {
    const childNode = tree.get_node(childId);
    if (childNode && childNode.data) {
      childNode.data.weight = index;
    }
  });
}

/**
 * Retourne le poids suivant pour un parent (création).
 */
function jstreeGetNextWeight(tree, parentNodeId) {
  const parentNode = tree.get_node(parentNodeId);
  return parentNode?.children?.length || 0;
}

  /**
   * Find a node by ID in the tree structure.
   *
   * @param {array} nodes
   *   Array of nodes to search.
   * @param {string} nodeId
   *   The node ID to find.
   * @return {object|null}
   *   The found node or null.
   */
  function findNodeById(nodes, nodeId) {
    for (let i = 0; i < nodes.length; i++) {
      if (nodes[i].id === nodeId) {
        return nodes[i];
      }
      if (nodes[i].children) {
        const found = findNodeById(nodes[i].children, nodeId);
        if (found) {
          return found;
        }
      }
    }
    return null;
  }

  /**
   * Build the complete hierarchy from jstree.
   *
   * @param {jQuery} container
   *   The tree container element.
   * @return {array}
   *   Array of term objects with parent/child relationships and weights.
   */
  function buildTreeHierarchy(container) {
    const jstreeInstance = container.jstree(true);
    if (!jstreeInstance) {
      return [];
    }

    const hierarchy = [];
    const nodes = jstreeInstance.get_json('#', { flat: true });

    nodes.forEach(function (node) {
      if (node.data && node.data.term_id) {
        const parentNode = nodes.find(n => n.id === node.parent);
        const parentId = node.parent === '#' ? 0 : (parentNode && parentNode.data ? parentNode.data.term_id : 0);
        hierarchy.push({
          id: node.data.term_id,
          parent_id: parentId,
          weight: node.data.weight || 0,
        });
      }
    });

    return hierarchy;
  }

  /**
   * Reload a taxonomy tree completely.
   *
   * @param {jQuery} container
   *   The tree container element.
   * @param {string} vocabularyId
   *   The vocabulary ID.
   */
  function reloadTaxonomyTree(container, vocabularyId) {
    // Destroy existing jstree instance completely
    if (container.jstree(true)) {
      container.jstree('destroy');
    }

    // Clear the container
    container.empty();

    // Reinitialize the tree with fresh data
    initializeTaxonomyTree(container, vocabularyId, container.data('vocabulary-label'));
  }

  /**
   * Initialize a single taxonomy tree.
   *
   * @param {jQuery} container
   *   The tree container element.
   * @param {string} vocabularyId
   *   The vocabulary ID.
   * @param {string} vocabularyLabel
   *   The vocabulary label.
   */
  function initializeTaxonomyTree(container, vocabularyId, vocabularyLabel) {
    const apiUrl = '/admin/content/media-album/taxonomy/' + vocabularyId + '/api';  // Keep for media_album_av specific modal

    // Find the corresponding hidden field within the parent form element
    let selectedField = null;

    // Look for hidden field in the immediate parent container
    selectedField = container.parent().find('input[type="hidden"][data-vocabulary-id], input[type="hidden"].taxonomy-selected-value, input[type="hidden"].storage-selected-value');

    if (!selectedField.length) {
      // If not found, look in ancestor fieldsets or containers
      const parentContainer = container.closest('[data-tree-type], fieldset');
      selectedField = parentContainer.find('input[type="hidden"][data-vocabulary-id], input[type="hidden"].taxonomy-selected-value, input[type="hidden"].storage-selected-value');
    }

    // Show loading state
    container.addClass('loading').html('<div class="tree-loading">' + Drupal.t('Loading...') + '</div>');

    // Load tree data via AJAX
    $.ajax({
      url: apiUrl,
      type: 'GET',
      dataType: 'json',
      success: function (data) {
        container.removeClass('loading').empty();

        // Handle empty taxonomy: create a virtual root node
        if (!data || (Array.isArray(data) && data.length === 0)) {
          // Add helper message and a button to create first term
          const emptyMsg = $('<div class="taxonomy-empty-message">' +
            '<p>' + Drupal.t('This vocabulary is empty.') + '</p>' +
            '<button type="button" class="btn btn-primary add-root-term">' +
            Drupal.t('Create first term') + '</button>' +
            '</div>');

          emptyMsg.find('.add-root-term').on('click', function (e) {
            e.preventDefault();
            openTermModal('add', null, 0, vocabularyId, vocabularyLabel, container);
          });

          container.append(emptyMsg);
          return;
        }

        // Initialize jsTree
        container.jstree({
          'core': {
            'data': data,
            'check_callback': true,
            'themes': {
              'name': 'default',
              'icons': true,
              'stripes': true,
            },
          },
          'plugins': ['wholerow', 'contextmenu', 'dnd', 'state'],
          'contextmenu': {
            'items': function (node) {
              // Handle root node (#) - only show 'Add term' option
              if (node.id === '#') {
                return {
                  'add': {
                    'label': Drupal.t('Add term'),
                    'icon': 'fa fa-plus',
                    'action': function (obj) {
                      openTermModal('add', null, 0, vocabularyId, vocabularyLabel, container);
                    },
                  },
                };
              }

              // Regular nodes - show all options
              const items = {
                'add': {
                  'label': Drupal.t('Add term'),
                  'icon': 'fa fa-plus',
                  'action': function (obj) {
                    openTermModal(
                      'add',
                      null,
                      node.data.term_id,
                      vocabularyId,
                      vocabularyLabel,
                      container
                    );
                  },
                },
                'edit': {
                  'label': Drupal.t('Edit term'),
                  'icon': 'fa fa-edit',
                  'action': function (obj) {
                    openTermModal(
                      'edit',
                      node.data.term_id,
                      node.data.term_id,
                      vocabularyId,
                      vocabularyLabel,
                      container
                    );
                  },
                },
                'delete': {
                  'label': Drupal.t('Delete term'),
                  'icon': 'fa fa-trash',
                  'action': function (obj) {
                    if (confirm(Drupal.t('Are you sure you want to delete this term?'))) {
                      deleteTerm(node.data.term_id, container, vocabularyId);
                    }
                  },
                },
              };
              return items;
            },
          },
          'dnd': {
            'copy': false,
            'is_draggable': true,
            'drag_delay': 10,
            'drag_finish': true,
          },
        });

        // After jstree is fully loaded, allow right-click on tree background
        container.on('ready.jstree', function (e, data) {
          const jstreeInstance = container.jstree(true);
          // Show context menu on root when right-clicking the background
          container.on('contextmenu.jstree-contextmenu', function (e) {
            // This is handled by jstree's contextmenu plugin
          });
        });

        // Handle node selection - capture complete hierarchy
        container.on('select_node.jstree', function (e, data) {
          // Debug: log if field was found
          if (selectedField.length === 0) {
            console.error('Hidden field not found for vocabulary:', vocabularyId);
            console.error('Container:', container);
            return;
          }

          // Store selected ID and build complete hierarchy
          selectedField.data('last-selected-id', data.node.data.term_id);
          const hierarchy = buildTreeHierarchy(container);
          const value = JSON.stringify({
            selected_id: data.node.data.term_id,
            hierarchy: hierarchy,
          });

          console.log('Setting hidden field value:', value);
          selectedField.val(value);
          console.log('Hidden field value after set:', selectedField.val());

          // Visual feedback
          container.find('.jstree-node').removeClass('selected');
          container.find('#' + data.node.id).addClass('selected');
        });

        // Handle drag & drop (reparenting) - save to server AND recalculate weights
        container.on('move_node.jstree', function (e, data) {

          const tree = $(this).jstree(true);

  // Recalculer les poids pour l'ancien parent et le nouveau parent
  if (data.old_parent !== data.parent) {
    jstreeRecalculateSiblingWeights(tree, data.old_parent);
  }
  jstreeRecalculateSiblingWeights(tree, data.parent);

  // Construire weightsToUpdate pour tous les enfants affectés
  const weightsToUpdate = {};
  [data.old_parent, data.parent].forEach(parentNodeId => {
    const parentNode = tree.get_node(parentNodeId);
    if (!parentNode) return;
    parentNode.children.forEach(childId => {
      const childNode = tree.get_node(childId);
      if (childNode?.data?.term_id) {
        weightsToUpdate[childNode.data.term_id] = childNode.data.weight;
      }
    });
  });

  // Infos pour l’ajax
  const nodeId = data.node.data.term_id;
  const newParentId = data.parent === '#' ? 0 : tree.get_node(data.parent).data.term_id;

  /*
          const nodeId = data.node.data.term_id;
          const newParentId = data.parent === '#' ? 0 : container.jstree().get_node(data.parent).data.term_id;
          const oldParentId = data.old_parent === '#' ? 0 : (container.jstree().get_node(data.old_parent) ? container.jstree().get_node(data.old_parent).data.term_id : 0);

          // Recalculate weights for old parent and new parent
          if (oldParentId !== newParentId) {
            recalculateWeightsForParent(container, oldParentId);
          }
          recalculateWeightsForParent(container, newParentId);

          // Collect all new weights for all affected terms
          const nodes = container.jstree(true).get_json('#', { flat: true });
          const weightsToUpdate = {};

          nodes.forEach(function (node) {
            if (node.data && node.data.term_id) {
              const nodeParentId = node.parent === '#' ? 0 : (nodes.find(n => n.id === node.parent) ? nodes.find(n => n.id === node.parent).data.term_id : 0);
              if (nodeParentId === oldParentId || nodeParentId === newParentId) {
                weightsToUpdate[node.data.term_id] = node.data.weight;
              }
            }
          });
*/
          // Save the new parent and weights to server
          $.ajax({
            url: '/admin/media_album_av_common/directory/move-term',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
              term_id: nodeId,
              parent_id: newParentId,
              weights: weightsToUpdate,
            }),
            success: function (response) {
              if (!response.success) {
                showMessage('Error moving term', 'error');
                container.jstree('refresh');
              } else {
                // Update hierarchy field after successful move
                const hierarchy = buildTreeHierarchy(container);
                selectedField.val(JSON.stringify({
                  selected_id: selectedField.data('last-selected-id') || null,
                  hierarchy: hierarchy,
                }));
              }
            },
            error: function () {
              showMessage('Error moving term', 'error');
              container.jstree('refresh');
            },
          });
        });
      },
      error: function () {
        container.removeClass('loading').html(
          '<div class="alert alert-error">' + Drupal.t('Error loading taxonomy tree.') + '</div>'
        );
      },
    });
  }

  /**
   * Open a modal dialog for adding/editing a term.
   *
   * @param {string} mode
   *   The mode: 'add' or 'edit'.
   * @param {number|null} termId
   *   The term ID (null for add mode).
   * @param {number} parentId
   *   The parent term ID.
   * @param {string} vocabularyId
   *   The vocabulary ID.
   * @param {string} vocabularyLabel
   *   The vocabulary label.
   * @param {jQuery} treeContainer
   *   The tree container element.
   */
  function openTermModal(mode, termId, parentId, vocabularyId, vocabularyLabel, treeContainer) {
    // Create modal HTML
    const modalId = 'taxonomy-term-modal-' + (termId || 'new');
    let termName = '';
    let termDescription = '';

    // If editing, load the term data
    if (mode === 'edit' && termId) {
      loadTermData(termId, function (termData) {
        termName = termData.name || '';
        termDescription = termData.description || '';
        showTermModal(modalId, mode, termName, termDescription, termId, parentId, vocabularyId, treeContainer);
      });
    } else {
      showTermModal(modalId, mode, termName, termDescription, termId, parentId, vocabularyId, treeContainer);
    }
  }

  /**
   * Display the term modal dialog.
   *
   * @param {string} modalId
   *   The modal ID.
   * @param {string} mode
   *   The mode: 'add' or 'edit'.
   * @param {string} termName
   *   The term name.
   * @param {string} termDescription
   *   The term description.
   * @param {number|null} termId
   *   The term ID.
   * @param {number} parentId
   *   The parent term ID.
   * @param {string} vocabularyId
   *   The vocabulary ID.
   * @param {jQuery} treeContainer
   *   The tree container element.
   */
  function showTermModal(modalId, mode, termName, termDescription, termId, parentId, vocabularyId, treeContainer) {
    const isEdit = mode === 'edit';
    const title = isEdit ? Drupal.t('Edit term') : Drupal.t('Add term');

    // Remove existing modal if present
    $('#' + modalId).remove();

    // Create modal HTML
    const modalHtml = `
      <div id="${modalId}" class="taxonomy-term-modal" role="dialog" aria-labelledby="${modalId}-title">
        <div class="modal-overlay"></div>
        <div class="modal-content">
          <div class="modal-header">
            <h2 id="${modalId}-title">${title}</h2>
            <button type="button" class="modal-close" aria-label="Close">&times;</button>
          </div>
          <form id="${modalId}-form" class="taxonomy-term-form">
            <div class="form-group">
              <label for="${modalId}-name">Term Name *</label>
              <input
                type="text"
                id="${modalId}-name"
                name="name"
                value="${escapeHtml(termName)}"
                placeholder="Enter term name"
                required
              >
            </div>
            <div class="form-group">
              <label for="${modalId}-description">Description</label>
              <textarea
                id="${modalId}-description"
                name="description"
                placeholder="Enter term description (optional)"
                rows="4"
              >${escapeHtml(termDescription)}</textarea>
            </div>
            <div class="modal-footer">
              <button type="button" class="button button--secondary modal-cancel">Cancel</button>
              <button type="submit" class="button button--primary modal-submit">${title}</button>
            </div>
          </form>
        </div>
      </div>
    `;

    // Add modal to page
    $('body').append(modalHtml);
    const modal = $('#' + modalId);

    // Show modal with animation
    setTimeout(() => {
      modal.addClass('show');
      modal.find('input[type="text"]').focus();
    }, 10);

    // Handle form submission
    modal.find('form').on('submit', function (e) {
      e.preventDefault();

      const formName = modal.find('#' + modalId + '-name').val().trim();
      const formDescription = modal.find('#' + modalId + '-description').val().trim();

      if (!formName) {
        showMessage('Term name is required', 'error');
        return;
      }

      // Submit based on mode
      if (isEdit) {
        updateTerm(termId, formName, formDescription, treeContainer, vocabularyId, modal);
      } else {
        addTerm(formName, formDescription, parentId, vocabularyId, treeContainer, modal);
      }
    });

    // Handle close button
    modal.find('.modal-close, .modal-cancel').on('click', function () {
      closeModal(modal);
    });

    // Handle overlay click to close
    modal.find('.modal-overlay').on('click', function () {
      closeModal(modal);
    });

    // Handle Escape key
    $(document).on('keydown.taxonomy-modal-' + modalId, function (e) {
      if (e.key === 'Escape') {
        closeModal(modal);
        $(document).off('keydown.taxonomy-modal-' + modalId);
      }
    });
  }

  /**
   * Close a modal dialog.
   *
   * @param {jQuery} modal
   *   The modal element.
   */
  function closeModal(modal) {
    modal.removeClass('show');
    setTimeout(() => {
      modal.remove();
    }, 300);
  }

  /**
   * Add a new term.
   *
   * @param {string} name
   *   The term name.
   * @param {string} description
   *   The term description.
   * @param {number} parentId
   *   The parent term ID.
   * @param {string} vocabularyId
   *   The vocabulary ID.
   * @param {jQuery} treeContainer
   *   The tree container element.
   * @param {jQuery} modal
   *   The modal element.
   */
  function addTerm(name, description, parentId, vocabularyId, treeContainer, modal) {
    // Calculate weight based on sibling count
    const weight = calculateTermWeight(treeContainer, parentId || 0);
    const url = '/admin/media_album_av_common/directory/create-term';

    $.ajax({
      url: url,
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        name: name,
        description: description,
        parent: parentId || 0,
        weight: weight,
        vocabulary_id: vocabularyId,
        parent_id: parentId,
      }),
      success: function (response) {
        if (response.success) {
          showMessage(Drupal.t('Term added successfully'), 'success');
          closeModal(modal);

          // Completely reload tree with fresh data
          reloadTaxonomyTree(treeContainer, vocabularyId);
        } else {
          showMessage(response.error || Drupal.t('Error adding term'), 'error');
        }
      },
      error: function (xhr) {
        const error = xhr.responseJSON?.error || Drupal.t('Error adding term');
        showMessage(error, 'error');
      },
    });
  }

  /**
   * Update an existing term.
   *
   * @param {number} termId
   *   The term ID.
   * @param {string} name
   *   The term name.
   * @param {string} description
   *   The term description.
   * @param {jQuery} treeContainer
   *   The tree container element.
   * @param {string} vocabularyId
   *   The vocabulary ID.
   * @param {jQuery} modal
   *   The modal element.
   */
  function updateTerm(termId, name, description, treeContainer, vocabularyId, modal) {
    const url = '/admin/media_album_av_common/directory/update-term';

    $.ajax({
      url: url,
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        term_id: termId,
        name: name,
        description: description,
      }),
      success: function (response) {
        if (response.success) {
          showMessage(Drupal.t('Term updated successfully'), 'success');
          closeModal(modal);

          // Completely reload tree with fresh data
          reloadTaxonomyTree(treeContainer, vocabularyId);
        } else {
          showMessage(response.error || Drupal.t('Error updating term'), 'error');
        }
      },
      error: function (xhr) {
        const error = xhr.responseJSON?.error || Drupal.t('Error updating term');
        showMessage(error, 'error');
      },
    });
  }

  /**
   * Delete a term.
   *
   * @param {number} termId
   *   The term ID.
   * @param {jQuery} treeContainer
   *   The tree container element.
   * @param {string} vocabularyId
   *   The vocabulary ID.
   */
  function deleteTerm(termId, treeContainer, vocabularyId) {
    const url = '/admin/media_album_av_common/directory/delete-term';

    $.ajax({
      url: url,
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        term_id: termId,
      }),
      success: function (response) {
        if (response.success) {
          showMessage(Drupal.t('Term deleted successfully'), 'success');

          // Completely reload tree with fresh data
          reloadTaxonomyTree(treeContainer, vocabularyId);
        } else {
          showMessage(response.error || Drupal.t('Error deleting term'), 'error');
        }
      },
      error: function (xhr) {
        const error = xhr.responseJSON?.error || Drupal.t('Error deleting term');
        showMessage(error, 'error');
      },
    });
  }

  /**
   * Load term data from the server.
   *
   * @param {number} termId
   *   The term ID.
   * @param {Function} callback
   *   Callback function with term data.
   */
  function loadTermData(termId, callback) {
    $.ajax({
      url: '/admin/media_album_av_common/directory/get-term/' + termId,
      type: 'GET',
      dataType: 'json',
      success: function (response) {
        if (response.success) {
          callback(response.data);
        } else {
          callback({ name: '', description: '' });
        }
      },
      error: function () {
        callback({ name: '', description: '' });
      },
    });
  }

  /**
   * Show a temporary message.
   *
   * @param {string} message
   *   The message text.
   * @param {string} type
   *   The message type: 'success', 'error', 'info'.
   */
  function showMessage(message, type) {
    const alertClass = 'alert-' + type;
    const messageHtml = '<div class="alert ' + alertClass + '">' + message + '</div>';

    // Remove existing alert
    $('.taxonomy-inline-alert').remove();

    // Add new alert
    const alert = $(messageHtml).addClass('taxonomy-inline-alert');
    $('body').prepend(alert);

    // Auto-remove after 4 seconds
    if (type === 'success') {
      setTimeout(() => {
        alert.fadeOut(function () {
          $(this).remove();
        });
      }, 4000);
    }
  }

  /**
   * Escape HTML special characters.
   *
   * @param {string} text
   *   The text to escape.
   *
   * @return {string}
   *   The escaped text.
   */
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

})(Drupal, jQuery);
