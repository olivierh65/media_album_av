/**
 * Media Album AV - Taxonomy Manager Modal JavaScript
 */

(function (Drupal, $) {
  'use strict';

  /**
   * Initialize taxonomy manager modal.
   */
  Drupal.behaviors.mediaAlbumAvTaxonomyManagerModal = {
    attach: function (context) {
      // Use jQuery selector directly to find all modals, not just in context
      $(document).find('.taxonomy-manager-modal').each(function () {
        // Only process if not already initialized
        if (!$(this).data('initialized')) {
          const modal = $(this);
          const vocabularyId = modal.data('vocabulary-id');

          // Mark as initialized
          modal.data('initialized', true);

          // Initialize modal events
          initializeModalEvents(modal);

          // Load and initialize jsTree
          loadTaxonomyTree(modal, vocabularyId);

          // Initialize add term form
          initializeAddTermForm(modal, vocabularyId);
        }
      });
    }
  };

  /**
   * Initialize modal events.
   *
   * @param {jQuery} modal
   *   The modal element.
   */
  function initializeModalEvents(modal) {
    // Handled by album-forms.js now
    // Just keep this for compatibility
  }

  /**
   * Load taxonomy tree with jsTree.
   *
   * @param {jQuery} modal
   *   The modal element.
   * @param {string} vocabularyId
   *   The vocabulary ID.
   */
  function loadTaxonomyTree(modal, vocabularyId) {
    const treeContainer = modal.find('#taxonomy-tree');
    const treeApiUrl = '/admin/content/media-album/taxonomy/' + vocabularyId + '/api';

    // Show loading state
    treeContainer.parent().addClass('loading');

    $.ajax({
      url: treeApiUrl,
      type: 'GET',
      dataType: 'json',
      success: function (data) {
        treeContainer.parent().removeClass('loading');

        // Destroy existing jsTree if it exists
        if (treeContainer.jstree()) {
          treeContainer.jstree('destroy');
        }

        // Initialize jsTree
        treeContainer.jstree({
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
              return {
                'delete': {
                  'label': Drupal.t('Delete'),
                  'action': function (obj) {
                    if (confirm(Drupal.t('Are you sure?'))) {
                      deleteTerm(node.data.term_id, treeContainer);
                    }
                  },
                },
                'edit': {
                  'label': Drupal.t('Edit'),
                  'action': function (obj) {
                    editTerm(node.data.term_id);
                  },
                },
              };
            },
          },
          'dnd': {
            'copy': false,
            'is_draggable': true,
            'drag_delay': 10,
            'drag_finish': true,
          },
        });

        // Event: When a node is selected, update the parent select
        treeContainer.on('select_node.jstree', function (e, data) {
          const parentSelect = modal.find('#term-parent');
          parentSelect.val(data.node.data.term_id).change();

          // Visual feedback - highlight selected node
          treeContainer.find('.jstree-node').removeClass('selected-parent');
          treeContainer.find('#' + data.node.id).addClass('selected-parent');
        });

        // Event: When drag & drop is finished, save the new parent
        treeContainer.on('move_node.jstree', function (e, data) {
          const nodeId = data.node.data.term_id;
          const newParentId = data.parent === '#' ? 0 : treeContainer.jstree().get_node(data.parent).data.term_id;

          // AJAX call to update parent
          $.ajax({
            url: '/admin/content/media-album/taxonomy/move-term',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
              term_id: nodeId,
              parent_id: newParentId,
            }),
            success: function (response) {
              if (!response.success) {
                alert(Drupal.t('Error moving term'));
                treeContainer.jstree('refresh');
              }
            },
            error: function () {
              alert(Drupal.t('Error moving term'));
              treeContainer.jstree('refresh');
            },
          });
        });

        // Update parent select when tree loads
        updateParentSelect(data, modal);
      },
      error: function () {
        treeContainer.parent().removeClass('loading');
        treeContainer.html('<p style="color: red;">' + Drupal.t('Error loading taxonomy tree.') + '</p>');
      },
    });
  }

  /**
   * Update parent term select dropdown.
   *
   * @param {Array} treeData
   *   The tree data from API.
   * @param {jQuery} modal
   *   The modal element.
   */
  function updateParentSelect(treeData, modal) {
    const parentSelect = modal.find('#term-parent');
    parentSelect.find('option').not(':first').remove();

    function addOptions(items, prefix) {
      items.forEach(function (item) {
        const option = $('<option>')
          .val(item.data.term_id)
          .text(prefix + item.text);
        parentSelect.append(option);

        if (item.children && item.children.length > 0) {
          addOptions(item.children, prefix + '-- ');
        }
      });
    }

    addOptions(treeData, '');
  }

  /**
   * Initialize add term form.
   *
   * @param {jQuery} modal
   *   The modal element.
   * @param {string} vocabularyId
   *   The vocabulary ID.
   */
  function initializeAddTermForm(modal, vocabularyId) {
    const addBtn = modal.find('#add-term-btn');
    const nameInput = modal.find('#term-name');
    const descriptionInput = modal.find('#term-description');
    const parentSelect = modal.find('#term-parent');
    const addTermUrl = '/admin/content/media-album/taxonomy/' + vocabularyId + '/add-term';

    addBtn.on('click', function (e) {
      e.preventDefault();
      const termName = nameInput.val().trim();

      if (!termName) {
        showMessage(modal, Drupal.t('Term name is required.'), 'error');
        return;
      }

      const data = {
        name: termName,
        description: descriptionInput.val(),
        parent: parentSelect.val() || 0,
      };

      addBtn.prop('disabled', true).text(Drupal.t('Adding...'));

      $.ajax({
        url: addTermUrl,
        type: 'POST',
        data: JSON.stringify(data),
        contentType: 'application/json',
        success: function (response) {
          addBtn.prop('disabled', false).text(Drupal.t('Add Term'));

          if (response.success) {
            showMessage(modal, response.message, 'success');

            // Clear form
            nameInput.val('');
            descriptionInput.val('');
            parentSelect.val('');

            // Reload tree
            loadTaxonomyTree(modal, vocabularyId);
          } else {
            showMessage(modal, response.error || Drupal.t('Error adding term.'), 'error');
          }
        },
        error: function (xhr) {
          addBtn.prop('disabled', false).text(Drupal.t('Add Term'));
          const error = xhr.responseJSON?.error || Drupal.t('Error adding term.');
          showMessage(modal, error, 'error');
        },
      });
    });

    // Enter key to submit
    nameInput.on('keypress', function (e) {
      if (e.key === 'Enter') {
        addBtn.click();
      }
    });
  }

  /**
   * Show message in modal.
   *
   * @param {jQuery} modal
   *   The modal element.
   * @param {string} message
   *   The message text.
   * @param {string} type
   *   The message type: 'success' or 'error'.
   */
  function showMessage(modal, message, type) {
    const messageContainer = modal.find('.modal-body');
    const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
    const alertHtml = '<div class="alert ' + alertClass + '">' + message + '</div>';

    const existingAlert = messageContainer.find('.alert');
    if (existingAlert.length > 0) {
      existingAlert.replaceWith(alertHtml);
    } else {
      messageContainer.prepend(alertHtml);
    }

    // Auto-remove success messages
    if (type === 'success') {
      setTimeout(function () {
        messageContainer.find('.alert').fadeOut(function () {
          $(this).remove();
        });
      }, 3000);
    }
  }

  /**
   * Delete a term.
   *
   * @param {number} termId
   *   The term ID.
   * @param {jQuery} treeContainer
   *   The tree container element.
   */
  function deleteTerm(termId, treeContainer) {
    const deleteUrl = '/admin/content/media-album/taxonomy/delete-term';

    $.ajax({
      url: deleteUrl,
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        term_id: termId,
      }),
      success: function (response) {
        if (response.success) {
          treeContainer.jstree('refresh');
        } else {
          alert(Drupal.t('Error deleting term.'));
        }
      },
      error: function () {
        alert(Drupal.t('Error deleting term.'));
      },
    });
  }

  /**
   * Edit a term.
   *
   * @param {number} termId
   *   The term ID.
   */
  function editTerm(termId) {
    const editUrl = '/taxonomy/term/' + termId + '/edit';
    window.open(editUrl, 'term_editor', 'width=800,height=600');
  }

  /**
   * Close modal.
   *
   * @param {jQuery} modal
   *   The modal element.
   */
  function closeModal(modal) {
    // Unbind keyboard event
    $(document).off('keydown.taxonomy-modal');

    // Fade out and remove
    modal.fadeOut(function () {
      modal.remove();
    });
  }

})(Drupal, jQuery);
