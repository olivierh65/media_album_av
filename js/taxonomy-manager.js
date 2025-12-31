/**
 * Media Album AV - Taxonomy Manager JavaScript
 */

(function (Drupal) {
  'use strict';

  /**
   * Taxonomy Manager behavior.
   */
  Drupal.behaviors.mediaAlbumAvTaxonomyManager = {
    attach: function (context) {
      // Initialize taxonomy manager UI
      once('taxonomy-manager-init', '.taxonomy-manager-form', context).forEach(function (element) {
        mediaAlbumAvInitTaxonomyManagerUI(element);
      });

      // Initialize search/filter
      once('taxonomy-filter', '.taxonomy-filter input', context).forEach(function (element) {
        mediaAlbumAvInitTaxonomyFilter(element);
      });

      // Initialize term actions
      once('taxonomy-term-actions', '.term-actions', context).forEach(function (element) {
        mediaAlbumAvInitTermActions(element);
      });
    }
  };

  /**
   * Initialize taxonomy manager UI enhancements.
   *
   * @param {HTMLElement} element
   *   The manager form element.
   */
  function mediaAlbumAvInitTaxonomyManagerUI(element) {
    const termItems = element.querySelectorAll('.term-item');

    termItems.forEach(function (item) {
      // Add term IDs for reference
      const termName = item.querySelector('.term-name');
      if (termName) {
        const termId = termName.textContent.toLowerCase().replace(/\s+/g, '-');
        item.setAttribute('data-term-id', termId);
        item.setAttribute('data-level', getLevelFromMargin(item));
      }

      // Add expand/collapse for nested items
      const children = item.querySelector('.term-list');
      if (children) {
        addCollapseToggle(item);
      }

      // Add hover effects
      item.addEventListener('mouseenter', function () {
        showTermActions(item);
      });

      item.addEventListener('mouseleave', function () {
        hideTermActionsDelay(item);
      });
    });

    // Add keyboard navigation
    addKeyboardNavigation(element);
  }

  /**
   * Add collapse/expand toggle to term item.
   *
   * @param {HTMLElement} item
   *   The term item element.
   */
  function addCollapseToggle(item) {
    const header = item.querySelector('.term-header');
    if (!header) {
      return;
    }

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'term-toggle';
    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-label', Drupal.t('Toggle term children'));
    toggle.textContent = '−';

    const childrenList = item.querySelector('.term-list');

    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
      childrenList.style.display = isExpanded ? 'none' : 'block';
      toggle.textContent = isExpanded ? '+' : '−';
      toggle.setAttribute('aria-expanded', !isExpanded);
    });

    header.insertBefore(toggle, header.firstChild);
  }

  /**
   * Get hierarchy level from element margin.
   *
   * @param {HTMLElement} element
   *   The element to check.
   *
   * @return {number}
   *   The hierarchy level.
   */
  function getLevelFromMargin(element) {
    const style = window.getComputedStyle(element);
    const marginLeft = parseFloat(style.marginLeft);
    return Math.round(marginLeft / 40);
  }

  /**
   * Show term action buttons.
   *
   * @param {HTMLElement} item
   *   The term item element.
   */
  function showTermActions(item) {
    const actions = item.querySelector('.term-actions');
    if (actions) {
      actions.style.display = 'flex';
      actions.style.opacity = '1';
    }
  }

  /**
   * Hide term action buttons with delay.
   *
   * @param {HTMLElement} item
   *   The term item element.
   */
  function hideTermActionsDelay(item) {
    setTimeout(function () {
      if (!item.matches(':hover')) {
        const actions = item.querySelector('.term-actions');
        if (actions) {
          actions.style.opacity = '0.7';
        }
      }
    }, 200);
  }

  /**
   * Initialize taxonomy search/filter.
   *
   * @param {HTMLElement} searchInput
   *   The search input element.
   */
  function mediaAlbumAvInitTaxonomyFilter(searchInput) {
    const debounceDelay = 300;
    let debounceTimer;

    searchInput.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () {
        filterTerms(searchInput.value);
      }, debounceDelay);
    });

    // Add clear button
    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'filter-clear';
    clearBtn.textContent = '×';
    clearBtn.style.display = 'none';
    clearBtn.addEventListener('click', function (e) {
      e.preventDefault();
      searchInput.value = '';
      filterTerms('');
      clearBtn.style.display = 'none';
      searchInput.focus();
    });

    if (searchInput.parentNode) {
      searchInput.parentNode.appendChild(clearBtn);
    }

    searchInput.addEventListener('input', function () {
      clearBtn.style.display = this.value ? 'block' : 'none';
    });
  }

  /**
   * Filter term items based on search text.
   *
   * @param {string} searchText
   *   The search text.
   */
  function filterTerms(searchText) {
    const normalizedSearch = searchText.toLowerCase().trim();
    const termItems = document.querySelectorAll('.term-item');
    const termTree = document.querySelector('.taxonomy-tree');

    if (!normalizedSearch) {
      termItems.forEach(function (item) {
        item.style.display = '';
        item.classList.remove('search-match', 'search-no-match');
      });
      if (termTree) {
        termTree.classList.remove('empty');
      }
      return;
    }

    let matchCount = 0;
    termItems.forEach(function (item) {
      const termName = item.querySelector('.term-name');
      if (termName) {
        const name = termName.textContent.toLowerCase();
        const isMatch = name.includes(normalizedSearch);

        if (isMatch) {
          item.style.display = '';
          item.classList.add('search-match');
          matchCount++;

          // Show all parent items
          showParents(item);
        } else {
          item.classList.remove('search-match');
        }
      }
    });

    if (matchCount === 0 && termTree) {
      termTree.classList.add('empty');
    } else if (termTree) {
      termTree.classList.remove('empty');
    }
  }

  /**
   * Show parent items of a matched term.
   *
   * @param {HTMLElement} item
   *   The term item element.
   */
  function showParents(item) {
    let parent = item.closest('.term-list');
    while (parent) {
      parent.style.display = '';
      const parentItem = parent.closest('.term-item');
      if (parentItem) {
        parentItem.style.display = '';
        // Expand parent
        const toggle = parentItem.querySelector('.term-toggle');
        if (toggle && toggle.getAttribute('aria-expanded') === 'false') {
          toggle.click();
        }
        parent = parentItem.closest('.term-list');
      } else {
        break;
      }
    }
  }

  /**
   * Initialize term action buttons.
   *
   * @param {HTMLElement} actions
   *   The actions container element.
   */
  function mediaAlbumAvInitTermActions(actions) {
    const buttons = actions.querySelectorAll('a');
    buttons.forEach(function (btn) {
      // Add loading state for external links
      btn.addEventListener('click', function () {
        if (this.target === '_blank' || this.target === 'taxonomy_manager') {
          this.classList.add('loading');
        }
      });

      // Keyboard accessibility
      if (btn.href) {
        btn.addEventListener('keypress', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            this.click();
          }
        });
      }
    });
  }

  /**
   * Add keyboard navigation support.
   *
   * @param {HTMLElement} element
   *   The manager form element.
   */
  function addKeyboardNavigation(element) {
    const termItems = element.querySelectorAll('.term-item');
    const toggles = element.querySelectorAll('.term-toggle');

    // Arrow key navigation
    termItems.forEach(function (item, index) {
      item.addEventListener('keydown', function (e) {
        let newIndex = index;

        if (e.key === 'ArrowDown') {
          e.preventDefault();
          newIndex = Math.min(index + 1, termItems.length - 1);
          termItems[newIndex].focus();
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          newIndex = Math.max(index - 1, 0);
          termItems[newIndex].focus();
        }
      });
    });

    // Space/Enter to expand/collapse
    toggles.forEach(function (toggle) {
      toggle.addEventListener('keypress', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          this.click();
        }
      });
    });
  }

  // Expose utility functions for external use
  window.mediaAlbumAvTaxonomy = {
    filterTerms: filterTerms,
    showParents: showParents
  };

})(Drupal);
