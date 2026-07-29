/**
 * @file
 * Dismissal and accessible timing for organiser status notifications.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melStatusNotifications = {
    attach(context) {
      once('mel-status-notice', '[data-mel-status-notice]', context).forEach(
        (notice) => {
          const dismissButton = notice.querySelector('[data-mel-status-dismiss]');
          const duration = Number.parseInt(
            notice.getAttribute('data-mel-status-auto-dismiss') || '0',
            10,
          );
          let remaining = Number.isFinite(duration) ? duration : 0;
          let startedAt = 0;
          let timer = null;
          let removed = false;

          const removeNotice = () => {
            if (removed) {
              return;
            }
            removed = true;
            window.clearTimeout(timer);
            notice.classList.add('is-leaving');

            const finish = () => {
              if (notice.isConnected) {
                notice.remove();
              }
            };

            notice.addEventListener('transitionend', finish, { once: true });
            window.setTimeout(finish, 240);
          };

          const startTimer = () => {
            if (remaining <= 0 || removed) {
              return;
            }
            startedAt = Date.now();
            timer = window.setTimeout(removeNotice, remaining);
          };

          const pauseTimer = () => {
            if (!timer) {
              return;
            }
            window.clearTimeout(timer);
            timer = null;
            remaining = Math.max(0, remaining - (Date.now() - startedAt));
          };

          dismissButton?.addEventListener('click', removeNotice);

          if (remaining > 0) {
            notice.addEventListener('pointerenter', pauseTimer);
            notice.addEventListener('pointerleave', startTimer);
            notice.addEventListener('focusin', pauseTimer);
            notice.addEventListener('focusout', (event) => {
              if (!notice.contains(event.relatedTarget)) {
                startTimer();
              }
            });
            startTimer();
          }
        },
      );
    },
  };
})(Drupal, once);
