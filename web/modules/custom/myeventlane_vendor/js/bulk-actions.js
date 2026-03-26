/**
 * @file
 * Bulk actions JavaScript for vendor events list (card layout).
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Returns all event row checkboxes (card layout or legacy table).
   */
  function getEventCheckboxes() {
    const fromCards = document.querySelectorAll(
      '.mel-events-bulk-list .mel-vendor-event-row input[type="checkbox"]',
    );
    if (fromCards.length > 0) {
      return fromCards;
    }
    return document.querySelectorAll(
      '.mel-table--selectable tbody input[type="checkbox"]',
    );
  }

  /**
   * Handle "Select All" checkbox in bulk actions bar.
   */
  Drupal.behaviors.vendorBulkActions = {
    attach: function (context) {
      const selectAllCheckbox = once('bulk-select-all', '.mel-select-all', context);

      selectAllCheckbox.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
          const isChecked = this.checked;
          getEventCheckboxes().forEach(function (rowCheckbox) {
            rowCheckbox.checked = isChecked;
          });
          updateSelectedCount();
        });
      });

      const rowCheckboxes = once(
        'bulk-checkbox',
        '.mel-events-bulk-list .mel-vendor-event-row input[type="checkbox"], .mel-table--selectable tbody input[type="checkbox"]',
        context,
      );

      rowCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
          updateSelectAllState();
          updateSelectedCount();
        });
      });

      const bulkActions = document.querySelector('.mel-bulk-actions');
      if (bulkActions && !document.querySelector('.mel-bulk-actions__count')) {
        const countSpan = document.createElement('span');
        countSpan.className = 'mel-bulk-actions__count';
        countSpan.textContent = '';
        const applyBtn = bulkActions.querySelector('input[type="submit"]');
        if (applyBtn) {
          applyBtn.parentNode.insertBefore(countSpan, applyBtn.nextSibling);
        }
      }

      function updateSelectAllState() {
        const selectAll = document.querySelector('.mel-select-all');
        if (!selectAll) {
          return;
        }
        const allCheckboxes = getEventCheckboxes();
        const checkedCheckboxes = document.querySelectorAll(
          '.mel-events-bulk-list .mel-vendor-event-row input[type="checkbox"]:checked, .mel-table--selectable tbody input[type="checkbox"]:checked',
        );
        if (allCheckboxes.length === 0) {
          selectAll.checked = false;
          selectAll.indeterminate = false;
        } else if (checkedCheckboxes.length === 0) {
          selectAll.checked = false;
          selectAll.indeterminate = false;
        } else if (checkedCheckboxes.length === allCheckboxes.length) {
          selectAll.checked = true;
          selectAll.indeterminate = false;
        } else {
          selectAll.checked = false;
          selectAll.indeterminate = true;
        }
      }

      function updateSelectedCount() {
        const countSpan = document.querySelector('.mel-bulk-actions__count');
        if (!countSpan) {
          return;
        }
        const checkedCheckboxes = document.querySelectorAll(
          '.mel-events-bulk-list .mel-vendor-event-row input[type="checkbox"]:checked, .mel-table--selectable tbody input[type="checkbox"]:checked',
        );
        const count = checkedCheckboxes.length;
        if (count > 0) {
          countSpan.textContent = Drupal.t('@count selected', { '@count': count });
          countSpan.classList.add('mel-bulk-actions__count--active');
        } else {
          countSpan.textContent = '';
          countSpan.classList.remove('mel-bulk-actions__count--active');
        }
      }

      updateSelectedCount();
    },
  };
})(Drupal, once);
