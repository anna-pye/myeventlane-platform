/**
 * @file
 * Vendor onboarding: focus organiser name on profile step.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.vendorOnboard = {
    attach: function (context) {
      once(
        'vendor-onboard-focus-name',
        'form.mel-onboard-form input[name="step_content[name]"]',
        context,
      ).forEach(function (input) {
        input.focus();
      });
    },
  };
})(Drupal, once);
