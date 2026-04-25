/**
 * @file
 * Rotates trust line items in the event full booking panel.
 */
((Drupal, once) => {
  'use strict';

  const INTERVAL_MS = 3000;

  Drupal.behaviors.melEventFullTrustRotator = {
    attach(context) {
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
      }

      once('melEventFullTrustRotator', '.mel-trust-rotator[data-rotate]', context).forEach((el) => {
        const items = el.querySelectorAll('.mel-trust-item');
        if (items.length < 2) {
          return;
        }
        let i = 0;
        window.setInterval(() => {
          if (!el.isConnected) {
            return;
          }
          const prev = i;
          items[prev].classList.remove('is-active');
          i = (i + 1) % items.length;
          items[i].classList.add('is-active');
        }, INTERVAL_MS);
      });
    },
  };
})(Drupal, once);
