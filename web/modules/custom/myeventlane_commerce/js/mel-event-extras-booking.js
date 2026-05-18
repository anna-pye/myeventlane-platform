/**
 * @file
 * Event Extras booking cards: gallery thumbs, quantity stepper sync.
 */
(function (Drupal, once) {
  'use strict';

  function syncQtyDisplay(card, value) {
    const display = card.querySelector('.mel-event-extra-card__qty-value');
    const input = card.querySelector('.mel-event-extra-card__qty-input');
    const v = String(value);
    if (display) {
      display.textContent = v;
    }
    if (input) {
      input.value = v;
    }
  }

  Drupal.behaviors.melEventExtrasBooking = {
    attach(context) {
      once('mel-event-extra-card', '.mel-event-extra-card', context).forEach((card) => {
        const input = card.querySelector('.mel-event-extra-card__qty-input');
        const dec = card.querySelector('.mel-event-extra-card__qty-btn--dec');
        const inc = card.querySelector('.mel-event-extra-card__qty-btn--inc');
        const max = input ? parseInt(input.getAttribute('max') || '10', 10) : 10;

        if (input) {
          syncQtyDisplay(card, input.value || '0');
          input.addEventListener('change', () => {
            let v = parseInt(input.value || '0', 10);
            if (Number.isNaN(v) || v < 0) {
              v = 0;
            }
            if (v > max) {
              v = max;
            }
            syncQtyDisplay(card, v);
          });
        }

        if (dec) {
          dec.addEventListener('click', (e) => {
            e.preventDefault();
            const cur = parseInt(input?.value || '0', 10) || 0;
            syncQtyDisplay(card, Math.max(0, cur - 1));
          });
        }

        if (inc) {
          inc.addEventListener('click', (e) => {
            e.preventDefault();
            const cur = parseInt(input?.value || '0', 10) || 0;
            syncQtyDisplay(card, Math.min(max, cur + 1));
          });
        }

        once('mel-event-extra-thumb', '.mel-event-extra-card__thumb', card).forEach((btn) => {
          btn.addEventListener('click', (e) => {
            e.preventDefault();
            const url = btn.getAttribute('data-image-url');
            const alt = btn.getAttribute('data-image-alt') || '';
            const img = card.querySelector('.mel-event-extra-card__image');
            if (img && url) {
              img.setAttribute('src', url);
              img.setAttribute('alt', alt);
            }
            card.querySelectorAll('.mel-event-extra-card__thumb').forEach((t) => {
              t.classList.remove('is-active');
            });
            btn.classList.add('is-active');
          });
        });
      });
    },
  };
})(Drupal, once);
