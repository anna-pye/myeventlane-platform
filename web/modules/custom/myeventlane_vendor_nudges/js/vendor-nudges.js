/**
 * @file
 * Local-only dismiss functionality for vendor nudges.
 *
 * Dismiss state is stored in sessionStorage (browser tab only).
 * No server-side tracking, no cookies, no persistent storage.
 */

(function (Drupal) {

  'use strict';

  var STORAGE_KEY = 'mel_vendor_nudges_dismissed';

  /**
   * Get the set of dismissed nudge IDs from sessionStorage.
   *
   * @return {string[]}
   */
  function getDismissed() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : [];
    }
    catch (e) {
      return [];
    }
  }

  /**
   * Save the set of dismissed nudge IDs to sessionStorage.
   *
   * @param {string[]} ids
   */
  function setDismissed(ids) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
    }
    catch (e) {
      // Silent — storage may be unavailable in private browsing.
    }
  }

  /**
   * Drupal behaviour for vendor nudge dismiss buttons.
   */
  Drupal.behaviors.vendorNudges = {
    attach: function (context) {
      var dismissed = getDismissed();

      // Hide previously dismissed nudges on page load.
      dismissed.forEach(function (nudgeId) {
        var card = context.querySelector
          ? context.querySelector('[data-nudge-id="' + nudgeId + '"]')
          : null;
        if (card) {
          card.style.display = 'none';
        }
      });

      // Attach dismiss handlers to buttons.
      var buttons = context.querySelectorAll
        ? context.querySelectorAll('[data-nudge-dismiss]')
        : [];

      buttons.forEach(function (button) {
        // Prevent duplicate binding.
        if (button.dataset.melNudgeBound) {
          return;
        }
        button.dataset.melNudgeBound = '1';

        button.addEventListener('click', function () {
          var nudgeId = button.getAttribute('data-nudge-dismiss');
          var card = button.closest('[data-nudge-id]');

          if (card) {
            card.style.display = 'none';
          }

          // Persist dismissal in session storage.
          var current = getDismissed();
          if (current.indexOf(nudgeId) === -1) {
            current.push(nudgeId);
            setDismissed(current);
          }

          // If all nudges are dismissed, hide the entire container.
          var container = button.closest('.vendor-nudges');
          if (container) {
            var visible = container.querySelectorAll(
              '.vendor-nudges__card:not([style*="display: none"])'
            );
            if (visible.length === 0) {
              container.style.display = 'none';
            }
          }
        });
      });
    }
  };

})(Drupal);
