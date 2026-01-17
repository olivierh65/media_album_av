/**
 * Media Album Editor Widget JavaScript
 */

(function (Drupal) {
  'use strict';

  /**
   * Initialize media editor widget.
   */
  Drupal.behaviors.mediaAlbumEditor = {
    attach: function (context, settings) {
      var widgets = context.querySelectorAll('.media-album-editor-widget');

      widgets.forEach(function (widget) {
        // Initialize drag and drop for reordering.
        this.initializeDragDrop(widget);

        // Initialize field watchers.
        this.initializeFieldWatchers(widget);

        // Initialize quick actions.
        this.initializeQuickActions(widget);
      }, this);
    },

    /**
     * Initialize drag and drop for media reordering.
     *
     * @param {HTMLElement} widget
     *   The widget container.
     */
    initializeDragDrop: function (widget) {
      var mediaItems = widget.querySelectorAll('.media-editor-item');
      var draggedItem = null;

      mediaItems.forEach(function (item) {
        item.addEventListener('dragstart', function (e) {
          draggedItem = this;
          this.style.opacity = '0.5';
          e.dataTransfer.effectAllowed = 'move';
        });

        item.addEventListener('dragend', function (e) {
          this.style.opacity = '1';
          draggedItem = null;
        });

        item.addEventListener('dragover', function (e) {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';

          if (this !== draggedItem) {
            this.style.borderTop = '3px solid #0066cc';
          }
        });

        item.addEventListener('dragleave', function (e) {
          this.style.borderTop = '';
        });

        item.addEventListener('drop', function (e) {
          e.preventDefault();
          e.stopPropagation();

          if (this !== draggedItem) {
            this.parentNode.insertBefore(draggedItem, this);
          }

          this.style.borderTop = '';
        });

        // Make items draggable.
        item.draggable = true;
      });
    },

    /**
     * Initialize field change watchers.
     *
     * @param {HTMLElement} widget
     *   The widget container.
     */
    initializeFieldWatchers: function (widget) {
      var inputs = widget.querySelectorAll('.media-edit-fields input, .media-edit-fields textarea, .media-edit-fields select');

      inputs.forEach(function (input) {
        input.addEventListener('change', function (e) {
          // Mark form as modified.
          var form = e.target.closest('form');
          if (form) {
            form.classList.add('modified');
          }

          // Optional: Show a "Save" indicator.
          var item = e.target.closest('.media-editor-item');
          if (item) {
            var summary = item.querySelector('summary');
            if (summary && !summary.textContent.includes('*')) {
              summary.textContent = '* ' + summary.textContent;
            }
          }
        });
      });
    },

    /**
     * Initialize quick action handlers.
     *
     * @param {HTMLElement} widget
     *   The widget container.
     */
    initializeQuickActions: function (widget) {
      var removeButtons = widget.querySelectorAll('.button-danger');

      removeButtons.forEach(function (button) {
        button.addEventListener('click', function (e) {
          if (!confirm(Drupal.t('Are you sure you want to remove this media?'))) {
            e.preventDefault();
          }
        });
      });
    }
  };
})(Drupal);
