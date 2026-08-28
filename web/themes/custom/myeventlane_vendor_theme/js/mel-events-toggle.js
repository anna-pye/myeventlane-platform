/**
 * @file
 * Organiser event-index controls: search, sort and optional selection mode.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melVendorEventsIndex = {
    attach(context) {
      once('mel-events-index', '[data-events-index]', context).forEach((root) => {
        const selectToggle = root.querySelector('[data-events-select-toggle]');
        const selectCancel = root.querySelector('[data-events-select-cancel]');
        const bulkRegion = root.querySelector('[data-events-bulk-region]');
        const bulkToolbar = root.querySelector('[data-events-bulk-toolbar]');
        const searchInput = root.querySelector('[data-events-search]');
        const searchApply = root.querySelector('[data-events-search-apply]');
        const sortSelect = root.querySelector('[data-events-sort]');

        const setSelectionMode = (enabled) => {
          root.classList.toggle('is-selecting', enabled);
          if (selectToggle) {
            selectToggle.setAttribute('aria-expanded', enabled ? 'true' : 'false');
          }
          if (bulkRegion) {
            bulkRegion.hidden = !enabled;
          }
          if (bulkToolbar) {
            bulkToolbar.hidden = !enabled;
          }
          if (!enabled) {
            root.querySelectorAll('[data-event-select], .mel-select-all').forEach((checkbox) => {
              checkbox.checked = false;
              checkbox.indeterminate = false;
              checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            });
          }
        };

        if (selectToggle) {
          selectToggle.addEventListener('click', () => setSelectionMode(true));
        }
        if (selectCancel) {
          selectCancel.addEventListener('click', () => setSelectionMode(false));
        }

        const applySearch = () => {
          if (!searchInput) {
            return;
          }
          const target = new URL(root.dataset.eventsIndexUrl, window.location.origin);
          const current = new URL(window.location.href);
          ['status', 'sort'].forEach((key) => {
            const value = current.searchParams.get(key);
            if (value) {
              target.searchParams.set(key, value);
            }
          });
          const search = searchInput.value.trim();
          if (search) {
            target.searchParams.set('search', search);
          }
          window.location.assign(target.toString());
        };

        if (searchApply) {
          searchApply.addEventListener('click', applySearch);
        }
        if (searchInput) {
          searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              applySearch();
            }
          });
        }
        if (sortSelect) {
          sortSelect.addEventListener('change', () => {
            if (sortSelect.value) {
              window.location.assign(sortSelect.value);
            }
          });
        }
      });
    },
  };
})(Drupal, once);
