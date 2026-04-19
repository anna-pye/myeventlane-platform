/**
 * @file
 * Vendor onboarding: focus first field, gate primary submit on HTML5 validity.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melVendorOnboard = {
    attach(context) {
      once('mel-onboard-autofocus', '.mel-page--vendor-onboard .mel-onboard-card', context).forEach((card) => {
        const focusable = card.querySelector(
          'input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled])',
        );
        if (focusable) {
          focusable.focus({ preventScroll: true });
        }
      });

      once('mel-onboard-validity', '.mel-page--vendor-onboard .mel-onboard-form-root', context).forEach((form) => {
        const submit = form.querySelector('input[type="submit"].form-submit, button[type="submit"]');
        if (!submit) {
          return;
        }
        const sync = () => {
          submit.disabled = !form.checkValidity();
        };
        form.addEventListener('input', sync);
        form.addEventListener('change', sync);
        sync();
      });
    },
  };
})(Drupal, once);
