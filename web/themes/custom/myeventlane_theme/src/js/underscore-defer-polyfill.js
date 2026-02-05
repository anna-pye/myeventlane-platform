/**
 * @file
 * Polyfill for _.defer when Lodash overwrites Underscore.
 *
 * Drupal's toolbar uses _.defer() from Underscore.js. If Lodash (which uses
 * the same global _) is loaded, it overwrites Underscore. Lodash does not
 * include _.defer, causing "_.defer is not a function" errors.
 *
 * This behavior patches _.defer when missing: _.defer(fn) === setTimeout(fn, 0).
 */
(function (Drupal) {
  'use strict';

  function patchUnderscoreDefer() {
    if (typeof window._ !== 'undefined' && typeof window._.defer === 'undefined') {
      window._.defer = function (fn) {
        setTimeout(fn, 0);
      };
    }
  }

  // Patch immediately in case _ is already loaded.
  patchUnderscoreDefer();

  // Patch when behaviors run (catches toolbar and other Drupal scripts).
  Drupal.behaviors.underscoreDeferPolyfill = {
    attach: function () {
      patchUnderscoreDefer();
    },
  };

  // Delayed patch for late-loading scripts (e.g. Lodash loaded after our bundle).
  setTimeout(patchUnderscoreDefer, 0);
  setTimeout(patchUnderscoreDefer, 100);
})(Drupal);
