/**
 * @file
 * Bulk actions JavaScript for vendor events list.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Behavior for bulk action functionality.
   */
  Drupal.behaviors.vendorBulkActions = {
    attach: function (context) {
      // Handle "Select All" checkbox in bulk actions bar.
      const selectAllCheckbox = once('bulk-select-all', '.mel-select-all', context);

      selectAllCheckbox.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
          const isChecked = this.checked;
          
          // Find all checkboxes in the tableselect.
          const tableCheckboxes = document.querySelectorAll('.mel-table--selectable tbody input[type="checkbox"]');
          
          tableCheckboxes.forEach(function (tableCheckbox) {
            tableCheckbox.checked = isChecked;
          });

          // Update the count display.
          updateSelectedCount();
        });
      });

      // Handle individual checkbox changes to update "Select All" state.
      const tableCheckboxes = once('bulk-checkbox', '.mel-table--selectable tbody input[type="checkbox"]', context);
      
      tableCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
          updateSelectAllState();
          updateSelectedCount();
        });
      });

      // Create count indicator if it doesn't exist.
      const bulkActions = document.querySelector('.mel-bulk-actions');
      if (bulkActions && !document.querySelector('.mel-bulk-actions__count')) {
        const countSpan = document.createElement('span');
        countSpan.className = 'mel-bulk-actions__count';
        countSpan.textContent = '';
        
        // Insert after the apply button.
        const applyBtn = bulkActions.querySelector('input[type="submit"]');
        if (applyBtn) {
          applyBtn.parentNode.insertBefore(countSpan, applyBtn.nextSibling);
        }
      }

      /**
       * Update the "Select All" checkbox based on individual checkbox states.
       */
      function updateSelectAllState() {
        const selectAll = document.querySelector('.mel-select-all');
        if (!selectAll) return;

        const allCheckboxes = document.querySelectorAll('.mel-table--selectable tbody input[type="checkbox"]');
        const checkedCheckboxes = document.querySelectorAll('.mel-table--selectable tbody input[type="checkbox"]:checked');

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

      /**
       * Update the selected count display.
       */
      function updateSelectedCount() {
        const countSpan = document.querySelector('.mel-bulk-actions__count');
        if (!countSpan) return;

        const checkedCheckboxes = document.querySelectorAll('.mel-table--selectable tbody input[type="checkbox"]:checked');
        const count = checkedCheckboxes.length;

        if (count > 0) {
          countSpan.textContent = Drupal.t('@count selected', {'@count': count});
          countSpan.classList.add('mel-bulk-actions__count--active');
        } else {
          countSpan.textContent = '';
          countSpan.classList.remove('mel-bulk-actions__count--active');
        }
      }

      // Initial state update.
      updateSelectedCount();
    }
  };

})(Drupal, once);
















