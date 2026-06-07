(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melBoostSelect = {
    attach: function (context) {
      once('melBoostForm', '.myeventlane-boost-select-form', context).forEach(function (form) {
        const cards = Array.from(form.querySelectorAll('.boost-plan-card'));
        const radios = Array.from(form.querySelectorAll('.mel-boost-radios input[type="radio"]'));
        const cardContainer = form.querySelector('.boost-plan-grid');

        if (!cards.length || !radios.length) {
          return;
        }

        if (cardContainer) {
          cardContainer.setAttribute('role', 'radiogroup');
        }

        const syncVisualSelection = function () {
          const checkedRadio = radios.find(function (radio) {
            return radio.checked;
          });
          const selectedValue = checkedRadio ? String(checkedRadio.value) : null;

          cards.forEach(function (card) {
            const cardVariationId = String(card.getAttribute('data-variation-id') || '');
            const isSelected = selectedValue !== null && cardVariationId === selectedValue;

            card.classList.toggle('is-selected', isSelected);
            card.setAttribute('aria-checked', isSelected ? 'true' : 'false');
            card.setAttribute('tabindex', isSelected ? '0' : '-1');
          });
        };

        const setSelectedByVariationId = function (variationId) {
          const targetValue = String(variationId);
          let didSelect = false;

          radios.forEach(function (radio) {
            const shouldCheck = String(radio.value) === targetValue;
            if (shouldCheck && !radio.checked) {
              radio.checked = true;
              radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
            didSelect = didSelect || shouldCheck;
          });

          if (didSelect) {
            syncVisualSelection();
          }
        };

        const initiallyChecked = radios.find(function (radio) {
          return radio.checked;
        });

        if (!initiallyChecked && radios[0]) {
          radios[0].checked = true;
          radios[0].dispatchEvent(new Event('change', { bubbles: true }));
        }

        radios.forEach(function (radio) {
          radio.addEventListener('change', syncVisualSelection);
        });

        once('melBoostCard', '.boost-plan-card', form).forEach(function (card) {
          const variationId = card.getAttribute('data-variation-id');
          if (!variationId) {
            return;
          }

          card.setAttribute('role', 'radio');
          card.setAttribute('aria-checked', 'false');
          card.setAttribute('tabindex', '-1');

          card.addEventListener('click', function () {
            setSelectedByVariationId(variationId);
          });

          card.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              setSelectedByVariationId(variationId);
            }
          });
        });

        syncVisualSelection();
      });
    },
  };
})(Drupal, once);
