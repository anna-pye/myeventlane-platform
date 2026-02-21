/**
 * @file
 * Select-all checkbox and multi-form syncing for the payout ledger.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.payoutBulkSelect = {
    attach: function (context) {
      var toggle = context.querySelector('#pcc-check-all');
      if (!toggle || toggle.dataset.initialized) {
        return;
      }
      toggle.dataset.initialized = 'true';

      toggle.addEventListener('change', function () {
        var boxes = context.querySelectorAll('.pcc-payout__checkbox');
        for (var i = 0; i < boxes.length; i++) {
          boxes[i].checked = toggle.checked;
        }
      });

      // Sync checked values into forms that don't own the checkboxes.
      var formIds = ['pcc-batch-form', 'pcc-create-batch-form'];
      formIds.forEach(function (formId) {
        var form = context.querySelector('#' + formId);
        if (form && !form.dataset.initialized) {
          form.dataset.initialized = 'true';
          form.addEventListener('submit', function () {
            form.querySelectorAll('input[name="ledger_ids[]"]').forEach(function (el) {
              el.remove();
            });
            var boxes = context.querySelectorAll('.pcc-payout__checkbox:checked');
            boxes.forEach(function (box) {
              var hidden = document.createElement('input');
              hidden.type = 'hidden';
              hidden.name = 'ledger_ids[]';
              hidden.value = box.value;
              form.appendChild(hidden);
            });
          });
        }
      });
    },
  };
})(Drupal);
