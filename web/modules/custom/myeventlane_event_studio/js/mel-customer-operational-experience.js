/**
 * @file
 * Reserved for non-critical UI affordances; operational semantics stay server-side.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.melCustomerOperationalExperience = {
    attach() {
      // Intentionally minimal — no operational logic in the browser.
    },
  };
})(Drupal);
