/**
 * @file
 * Vendor onboarding: focus first field (HTML5 validity gating disabled — see onboard_debug).
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
    },
  };
})(Drupal, once);
