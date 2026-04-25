/**
 * @file
 * AJAX chip filter for category discovery (no full page reload).
 */
(function (Drupal, once, drupalSettings) {
  'use strict';

  Drupal.behaviors.melChips = {
    attach(context) {
      once('mel-chips-bar', '[data-chip-bar]', context).forEach((bar) => {
        const categoryTid = bar.getAttribute('data-category-tid');
        if (!categoryTid) {
          return;
        }

        const getRegion = () => {
          if (bar.nextElementSibling && bar.nextElementSibling.hasAttribute('data-mel-filter-results')) {
            return bar.nextElementSibling;
          }
          const root = bar.closest('.view') || document;
          return root.querySelector('[data-mel-filter-results]');
        };

        bar.querySelectorAll('.mel-chip').forEach((btn) => {
          btn.addEventListener('click', (e) => {
            e.preventDefault();
            const current = e.currentTarget;
            bar.querySelectorAll('.mel-chip').forEach((c) => c.classList.remove('is-active'));
            current.classList.add('is-active');
            const type = current.getAttribute('data-chip') || 'all';
            const region = getRegion();
            if (!region) {
              return;
            }
            region.classList.add('is-loading');
            const url = new URL('/mel/filter-events', window.location.origin);
            url.searchParams.set('type', type);
            url.searchParams.set('category', categoryTid);
            fetch(url.toString(), { credentials: 'same-origin' })
              .then((res) => {
                if (!res.ok) {
                  throw new Error('MEL filter failed: ' + res.status);
                }
                return res.text();
              })
              .then((html) => {
                const tmp = document.createElement('div');
                tmp.innerHTML = html;
                const next = tmp.querySelector('[data-mel-filter-results]');
                if (next) {
                  region.replaceWith(next);
                  Drupal.attachBehaviors(next, drupalSettings);
                } else {
                  region.classList.remove('is-loading');
                }
              })
              .catch((err) => {
                if (window.console && console.error) {
                  console.error(err);
                }
                region.classList.remove('is-loading');
              });
          });
        });
      });
    }
  };
})(Drupal, once, drupalSettings);
