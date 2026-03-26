/**
 * @file
 * List / grid toggle for vendor events wrapper (data-view on container).
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melVendorEventsViewToggle = {
    attach(context) {
      once('mel-events-view-toggle', '[data-view-toggle]', context).forEach((toggle) => {
        toggle.addEventListener('click', (e) => {
          const btn = e.target.closest('button');
          if (!btn || !toggle.contains(btn)) {
            return;
          }
          const view = btn.getAttribute('data-view');
          if (!view) {
            return;
          }
          const container = document.querySelector('[data-view-container]');
          if (!container) {
            return;
          }
          toggle.querySelectorAll('button').forEach((b) => b.classList.remove('is-active'));
          btn.classList.add('is-active');
          container.setAttribute('data-view', view);
        });
      });
    },
  };
})(Drupal, once);
