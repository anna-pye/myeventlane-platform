(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melMessagesHub = {
    attach(context) {
      once('mel-messages-hub', '[data-mel-messages-hub]', context).forEach((hub) => {
        const search = hub.querySelector('[data-mel-message-event-search]');
        const rows = Array.from(hub.querySelectorAll('[data-mel-message-event]'));
        const empty = hub.querySelector('[data-mel-message-event-empty]');
        const count = hub.querySelector('[data-mel-message-event-count]');

        if (!search || rows.length === 0) {
          return;
        }

        const update = () => {
          const query = search.value.trim().toLocaleLowerCase();
          let visible = 0;

          rows.forEach((row) => {
            const matches = query === '' || (row.dataset.melMessageEvent || '').includes(query);
            row.hidden = !matches;
            if (matches) {
              visible += 1;
            }
          });

          if (empty) {
            empty.hidden = visible !== 0;
          }
          if (count) {
            count.textContent = Drupal.formatPlural(
              visible,
              '1 event shown',
              '@count events shown',
            );
          }
        };

        search.addEventListener('input', update);
        update();
      });
    },
  };
})(Drupal, once);
