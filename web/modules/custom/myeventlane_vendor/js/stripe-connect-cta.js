/**
 * @file
 * Hard GET navigation only — updates label so users see progress (no preventDefault).
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melStripeConnectCta = {
    attach: function (context) {
      once('mel-stripe-connect-cta', 'a.mel-button--stripe', context).forEach(
        function (a) {
          a.addEventListener('click', function () {
            a.textContent = Drupal.t('Connecting to Stripe...');
          });
        }
      );
    }
  };
})(Drupal, once);
