/**
 * MyEventLane RSVP Form enhancements.
 * Progressive enhancement only. No business logic.
 *
 * - Adds a "compact" class to long textareas on load, removed on focus.
 * - Ensures the sticky action area has safe bottom spacing on iOS.
 */

(function (Drupal, once) {
  Drupal.behaviors.melRsvpForm = {
    attach(context) {
      once('mel-rsvp-form', '.mel-rsvp', context).forEach((root) => {
        const textareas = root.querySelectorAll('textarea');
        textareas.forEach((ta) => {
          ta.classList.add('mel-rsvp__textarea--compact');
          ta.addEventListener('focus', () => ta.classList.remove('mel-rsvp__textarea--compact'), { once: true });
        });

        // Add safe-area padding for iOS notches.
        const actions = root.querySelector('.form-actions');
        if (actions) {
          actions.style.paddingBottom = 'calc(12px + env(safe-area-inset-bottom))';
        }
      });
    }
  };
})(Drupal, once);
