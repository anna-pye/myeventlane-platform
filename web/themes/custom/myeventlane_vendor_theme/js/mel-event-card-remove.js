/**
 * @file
 * Opens/closes vendor event card removal <dialog> elements (keyboard-friendly).
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melEventCardRemove = {
    attach(context) {
      once('mel-event-card-remove-open', '[data-mel-open-dialog]', context).forEach((btn) => {
        btn.addEventListener('click', () => {
          const id = btn.getAttribute('data-mel-open-dialog');
          if (!id) {
            return;
          }
          const dialog = document.getElementById(id);
          if (dialog && dialog.tagName === 'DIALOG' && typeof dialog.showModal === 'function') {
            dialog.showModal();
            const focusTarget = dialog.querySelector(
              'button[data-mel-dialog-close], button[type="submit"], a[href]',
            );
            if (focusTarget && typeof focusTarget.focus === 'function') {
              focusTarget.focus();
            }
          }
        });
      });

      once('mel-event-card-remove-close', '[data-mel-dialog-close]', context).forEach((el) => {
        el.addEventListener('click', () => {
          const dialog = el.closest('dialog');
          if (dialog && typeof dialog.close === 'function') {
            dialog.close();
          }
        });
      });
    },
  };
})(Drupal, once);
