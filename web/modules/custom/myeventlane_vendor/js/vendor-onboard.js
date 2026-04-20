/**
 * @file
 * Temporary onboarding debug listeners (profile step) — remove when stable.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.vendorOnboardDebug = {
    attach: function (context) {
      once('vendor-onboard-debug-form', 'form.mel-onboard-form', context).forEach(function (form) {
        form.addEventListener('submit', function () {
          console.log('FORM SUBMIT EVENT FIRED');
        });
      });
      once('vendor-onboard-debug-submit', 'form.mel-onboard-form #edit-submit', context).forEach(function (el) {
        el.addEventListener('click', function () {
          console.log('CLICK WORKING');
        });
      });
    },
  };
})(Drupal, once);
