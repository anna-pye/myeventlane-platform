/**
 * @file
 * Progressive enhancement for customer fulfillment execution strip.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melOperationalFulfillmentExecutionCustomer = {
    attach(context) {
      once('mel-ofe-enhance', '.mel-ofe', context).forEach((root) => {
        root.classList.add('mel-ofe--js');
      });
    },
  };
})(Drupal, once);
