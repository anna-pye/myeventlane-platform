(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melFormProtection = {
    attach: function (context) {

      once('mel-form-protection', 'form.mel-protected-form', context).forEach((form) => {

        form.addEventListener('submit', function (e) {

          // Only fix broken submissions
          if (!e.defaultPrevented) {
            return;
          }

          console.warn('[MEL] Submit prevented by JS. Restoring native submit.');

          // Stop ALL other JS handlers
          e.stopImmediatePropagation();

          // Prevent loops
          if (form.dataset.melSubmitting === 'true') {
            return;
          }

          form.dataset.melSubmitting = 'true';

          // Call native submit safely (bypass JS overrides)
          HTMLFormElement.prototype.submit.call(form);

        }, true); // capture phase

      });

    }
  };

})(Drupal, once);
