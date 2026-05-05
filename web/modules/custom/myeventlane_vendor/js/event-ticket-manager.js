(function (Drupal, once) {
  Drupal.behaviors.melEventTicketManager = {
    attach: function (context) {
      once('mel-event-ticket-manager', '[data-mel-ticket-manager-form]', context).forEach(function (form) {
        var focusTarget = form.querySelector('[data-mel-ticket-autofocus="1"] [data-mel-ticket-first-input="1"]');
        if (focusTarget && typeof focusTarget.focus === 'function') {
          window.setTimeout(function () {
            focusTarget.focus();
            if (typeof focusTarget.select === 'function') {
              focusTarget.select();
            }
          }, 0);
        }

        form.addEventListener('change', function (event) {
          var input = event.target;
          if (!(input instanceof HTMLInputElement) || input.getAttribute('data-mel-ticket-delete') !== '1') {
            return;
          }
          var row = input.closest('[data-mel-ticket-row]');
          if (row) {
            row.classList.toggle('is-pending-delete', input.checked);
          }
        });
      });
    },
  };
})(Drupal, once);
