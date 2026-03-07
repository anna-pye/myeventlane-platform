(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melStudioSearch = {
    attach(context) {
      once('mel-studio-search', '[data-mel-studio-search]', context).forEach((input) => {
        const list = input.closest('.mel-event-navigator')?.querySelector('[data-mel-studio-list]');
        if (!list) {
          return;
        }

        const rows = Array.from(list.querySelectorAll('.mel-event-row'));
        const filterRows = () => {
          const term = input.value.trim().toLowerCase();
          rows.forEach((row) => {
            const haystack = (row.textContent || '').toLowerCase();
            const visible = term === '' || haystack.includes(term);
            row.style.display = visible ? '' : 'none';
          });
        };

        input.addEventListener('input', filterRows);
      });
    },
  };
})(Drupal, once);
