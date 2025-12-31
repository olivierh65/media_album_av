/**
 * Media Album AV - Forms JavaScript
 */

(function (Drupal) {
  'use strict';

  /**
   * Initialize album form enhancements.
   */
  Drupal.behaviors.mediaAlbumAvForms = {
    attach: function (context) {
      // Initialize taxonomy manager if present
      once('taxonomy-manager', '.taxonomy-manager-form', context).forEach(function (element) {
        mediaAlbumAvInitTaxonomyManager(element);
      });

      // Initialize create album form if present
      once('create-album-form', '#media-album-av-create-album-form', context).forEach(function (element) {
        mediaAlbumAvInitCreateAlbumForm(element);
      });

      // Initialize modal buttons (Manage Terms)
      once('open-taxonomy-modal', '.open-taxonomy-modal', context).forEach(function (button) {
        button.addEventListener('click', function (e) {
          e.preventDefault();
          const vocabularyId = this.getAttribute('data-vocabulary-id');
          const vocabularyLabel = this.getAttribute('data-vocabulary-label');
          if (vocabularyId) {
            mediaAlbumAvOpenTaxonomyModal(vocabularyId, vocabularyLabel);
          }
        });
      });

      // Add AJAX handlers for taxonomy changes
      once('taxonomy-ajax', '.form-group.taxonomy-select', context).forEach(function (element) {
        mediaAlbumAvInitTaxonomyAjax(element);
      });
    }
  };

  /**
   * Initialize taxonomy manager form.
   *
   * @param {HTMLElement} formElement
   *   The form element.
   */
  function mediaAlbumAvInitTaxonomyManager(formElement) {
    // Add expand/collapse functionality to term tree
    const termItems = formElement.querySelectorAll('.term-item');

    termItems.forEach(function (item) {
      const hasChildren = item.querySelector('.term-list');
      if (hasChildren) {
        const header = item.querySelector('.term-header');
        const toggleButton = document.createElement('button');
        toggleButton.type = 'button';
        toggleButton.className = 'term-toggle';
        toggleButton.setAttribute('aria-expanded', 'true');
        toggleButton.innerHTML = '−';
        toggleButton.addEventListener('click', function (e) {
          e.preventDefault();
          const expanded = toggleButton.getAttribute('aria-expanded') === 'true';
          hasChildren.style.display = expanded ? 'none' : 'block';
          toggleButton.innerHTML = expanded ? '+' : '−';
          toggleButton.setAttribute('aria-expanded', !expanded);
        });

        if (header) {
          header.insertBefore(toggleButton, header.firstChild);
        }
      }
    });

    // Add drag-and-drop support for term reordering (optional enhancement)
    mediaAlbumAvInitTermDragDrop(formElement);
  }

  /**
   * Initialize drag-and-drop for term reordering.
   *
   * @param {HTMLElement} container
   *   The container element.
   */
  function mediaAlbumAvInitTermDragDrop(container) {
    // This is an optional enhancement for future use
    // Implement drag-and-drop functionality here if needed
  }

  /**
   * Initialize create album form.
   *
   * @param {HTMLElement} formElement
   *   The form element.
   */
  function mediaAlbumAvInitCreateAlbumForm(formElement) {
    // Set today's date as default
    const dateInput = formElement.querySelector('input[type="date"]');
    if (dateInput && !dateInput.value) {
      const today = new Date().toISOString().split('T')[0];
      dateInput.value = today;
    }

    // Add real-time validation
    const titleInput = formElement.querySelector('input[type="text"]');
    if (titleInput) {
      titleInput.addEventListener('blur', function () {
        mediaAlbumAvValidateTitle(this);
      });
    }
  }

  /**
   * Open taxonomy manager in a modal dialog.
   *
   * @param {string} vocabularyId
   *   The vocabulary ID.
   * @param {string} vocabularyLabel
   *   The vocabulary label for modal title.
   */
  function mediaAlbumAvOpenTaxonomyModal(vocabularyId, vocabularyLabel) {
    // Load content via fetch
    const contentUrl = '/admin/content/media-album/taxonomy/' + vocabularyId + '/content';
    
    fetch(contentUrl, {
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
      })
      .then(html => {
        // Create a temporary container to hold the modal HTML
        const tempContainer = document.createElement('div');
        tempContainer.innerHTML = html;
        const modalWrapper = tempContainer.querySelector('.taxonomy-manager-modal-wrapper');
        
        if (!modalWrapper) {
          throw new Error('Modal wrapper not found in response');
        }

        // Add modal to the page
        document.body.appendChild(modalWrapper);

        // Show the modal
        modalWrapper.classList.add('show');

        // Set up close handlers
        const closeBtn = modalWrapper.querySelector('.modal-close-btn');
        const backdrop = modalWrapper.querySelector('.modal-backdrop');

        const closeModal = function () {
          modalWrapper.classList.remove('show');
          // Remove after animation
          setTimeout(() => {
            modalWrapper.remove();
          }, 300);
        };

        if (closeBtn) {
          closeBtn.addEventListener('click', closeModal);
        }

        if (backdrop) {
          backdrop.addEventListener('click', closeModal);
        }

        // Close on Escape key
        const escapeHandler = function (e) {
          if (e.key === 'Escape') {
            closeModal();
            document.removeEventListener('keydown', escapeHandler);
          }
        };
        document.addEventListener('keydown', escapeHandler);

        // Re-attach behaviors to initialize JavaScript inside modal
        if (typeof Drupal.attachBehaviors === 'function') {
          Drupal.attachBehaviors(modalWrapper);
        }
      })
      .catch(error => {
        console.error('Error loading taxonomy manager:', error);
        alert(Drupal.t('Failed to load taxonomy manager.'));
      });
  }

  /**
   * Initialize AJAX handlers for taxonomy select fields.
   *
   * @param {HTMLElement} element
   *   The form group element.
   */
  function mediaAlbumAvInitTaxonomyAjax(element) {
    // This can be enhanced to load related fields dynamically
    // For example, loading event terms based on event group selection
  }

  /**
   * Validate album title.
   *
   * @param {HTMLElement} input
   *   The input element.
   */
  function mediaAlbumAvValidateTitle(input) {
    const value = input.value.trim();
    const isValid = value.length >= 3 && value.length <= 255;

    if (isValid) {
      input.classList.remove('error');
      input.classList.add('valid');
    } else {
      input.classList.remove('valid');
      input.classList.add('error');
    }
  }

})(Drupal);
