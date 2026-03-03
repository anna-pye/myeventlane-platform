(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melBoostSelect = {
    attach: function (context) {
      once('melBoostForm', '.myeventlane-boost-select-form', context).forEach(function (form) {
        const rows = Array.from(form.querySelectorAll('.boost-row'));
        const radios = Array.from(form.querySelectorAll('.mel-boost-radios input[type="radio"]'));
        const rowContainer = form.querySelector('.boost-list');

        if (!rows.length || !radios.length) {
          return;
        }

        if (rowContainer) {
          rowContainer.setAttribute('role', 'radiogroup');
        }

        const syncVisualSelection = function () {
          const checkedRadio = radios.find(function (radio) {
            return radio.checked;
          });
          const selectedValue = checkedRadio ? String(checkedRadio.value) : null;

          rows.forEach(function (row) {
            const rowVariationId = String(row.getAttribute('data-variation-id') || '');
            const isSelected = selectedValue !== null && rowVariationId === selectedValue;

            row.classList.toggle('is-selected', isSelected);
            row.setAttribute('aria-checked', isSelected ? 'true' : 'false');
            row.setAttribute('tabindex', isSelected ? '0' : '-1');
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

        once('melBoostRow', '.boost-row', form).forEach(function (row) {
          const variationId = row.getAttribute('data-variation-id');
          if (!variationId) {
            return;
          }

          row.setAttribute('role', 'radio');
          row.setAttribute('aria-checked', 'false');
          row.setAttribute('tabindex', '-1');

          row.addEventListener('click', function () {
            setSelectedByVariationId(variationId);
          });

          row.addEventListener('keydown', function (event) {
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
