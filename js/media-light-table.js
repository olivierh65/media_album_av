// media-light-table.js
(function($, Drupal, dragula) {
  'use strict';

  Drupal.behaviors.mediaLightTable = {
    attach: function(context, settings) {
      $('.light-table', context).once('mediaLightTable').each(function() {
        var $lightTable = $(this);
        var $container = $lightTable.find('.dragula-container');
        var $orderField = $lightTable.find('.media-order-field');
        var $selectionField = $lightTable.find('.media-selection-field');
        var $saveButton = $lightTable.find('.save-order');

        // Initialiser dragula pour le drag & drop
        var drake = dragula([$container.get(0)], {
          moves: function(el, source, handle, sibling) {
            return $(handle).hasClass('drag-handle') ||
                   $(handle).closest('.media-item').length > 0;
          }
        });

        // Mettre à jour l'ordre quand on déplace des éléments
        drake.on('drop', function(el, target, source, sibling) {
          updateMediaOrder();
          $saveButton.prop('disabled', false);
        });

        // Fonction pour mettre à jour l'ordre
        function updateMediaOrder() {
          var order = [];
          $lightTable.find('.media-item').each(function() {
            var mediaId = $(this).data('media-id');
            if (mediaId) {
              order.push(mediaId);
            }
          });
          $orderField.val(order.join(','));
        }

        // Sélection/désélection
        $lightTable.on('click', '.select-toggle', function(e) {
          e.stopPropagation();
          var $item = $(this).closest('.media-item');
          var mediaId = $item.data('media-id');
          var $checkbox = $item.find('.media-select-checkbox');

          $checkbox.prop('checked', !$checkbox.prop('checked'));
          $item.toggleClass('selected', $checkbox.prop('checked'));
          updateSelection();
        });

        // Mettre à jour la sélection
        function updateSelection() {
          var selectedIds = [];
          $lightTable.find('.media-select-checkbox:checked').each(function() {
            selectedIds.push($(this).val());
          });
          $selectionField.val(selectedIds.join(','));

          // Mettre à jour le compteur
          $lightTable.find('.stat.selected').text(selectedIds.length + ' selected');

          // Mettre à jour les icônes
          $lightTable.find('.select-toggle i').each(function() {
            var $checkbox = $(this).closest('.media-item').find('.media-select-checkbox');
            $(this).toggleClass('fa-check-square', $checkbox.prop('checked'))
                   .toggleClass('fa-square', !$checkbox.prop('checked'));
          });
        }

        // Sélectionner/désélectionner tout
        $lightTable.on('click', '.select-all', function() {
          $lightTable.find('.media-select-checkbox').prop('checked', true);
          $lightTable.find('.media-item').addClass('selected');
          updateSelection();
        });

        $lightTable.on('click', '.deselect-all', function() {
          $lightTable.find('.media-select-checkbox').prop('checked', false);
          $lightTable.find('.media-item').removeClass('selected');
          updateSelection();
        });

        // Filtres
        $lightTable.on('click', '.filter-btn', function() {
          var filter = $(this).data('filter');

          $lightTable.find('.filter-btn').removeClass('active');
          $(this).addClass('active');

          $lightTable.find('.media-item').each(function() {
            var $item = $(this);
            var mediaType = $item.data('media-type');
            var isSelected = $item.hasClass('selected');

            switch(filter) {
              case 'all':
                $item.show();
                break;
              case 'image':
              case 'video':
              case 'audio':
              case 'document':
                $item.toggle(mediaType === filter);
                break;
              case 'selected':
                $item.toggle(isSelected);
                break;
            }
          });
        });

        // Recherche
        $lightTable.find('.media-search').on('keyup', function() {
          var searchTerm = $(this).val().toLowerCase();

          $lightTable.find('.media-item').each(function() {
            var $item = $(this);
            var mediaLabel = $item.find('.media-label').text().toLowerCase();

            $item.toggle(mediaLabel.includes(searchTerm));
          });
        });

        // Sauvegarder l'ordre
        $saveButton.on('click', function() {
          var albumId = $(this).data('album-id');
          var order = $orderField.val();

          if (!order) return;

          $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

          // Envoyer l'ordre au serveur via AJAX
          $.ajax({
            url: Drupal.url('media-light-table/save-order'),
            method: 'POST',
            data: {
              album_id: albumId,
              order: order,
              _token: drupalSettings.mediaLightTable.csrfToken
            },
            success: function(response) {
              $saveButton.html('<i class="fas fa-check"></i> Order saved');
              setTimeout(function() {
                $saveButton.html('<i class="fas fa-save"></i> Save order');
              }, 2000);
            },
            error: function() {
              $saveButton.html('<i class="fas fa-exclamation-triangle"></i> Error');
              setTimeout(function() {
                $saveButton.html('<i class="fas fa-save"></i> Save order');
                $saveButton.prop('disabled', false);
              }, 2000);
            }
          });
        });

        // Initialiser lightgallery
        $lightTable.find('[data-lightgallery="gallery-item"]').on('click', function(e) {
          e.preventDefault();

          var galleryId = $(this).closest('.light-table').attr('id');
          var items = [];

          $lightTable.find('[data-lightgallery="gallery-item"]').each(function() {
            items.push({
              src: $(this).attr('href'),
              subHtml: $(this).data('subtitle') || ''
            });
          });

          // Ouvrir lightgallery
          if (typeof lightGallery !== 'undefined') {
            lightGallery(document.getElementById(galleryId), {
              dynamic: true,
              dynamicEl: items,
              index: $(this).index('[data-lightgallery="gallery-item"]')
            });
          }
        });

        // Initialiser l'ordre et la sélection
        updateMediaOrder();
        updateSelection();
      });
    }
  };

})(jQuery, Drupal, typeof dragula !== 'undefined' ? dragula : null);
