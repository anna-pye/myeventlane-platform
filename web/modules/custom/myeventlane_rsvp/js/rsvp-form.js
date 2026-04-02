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
      once('mel-rsvp-form', '.mel-rsvp, .mel-rsvp-public-form', context).forEach((root) => {
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

        root.addEventListener('input', (e) => {
          const target = e.target;
          if (!(target instanceof Element) || !target.matches('[name^="attendees"]')) {
            return;
          }
          const card = target.closest('.mel-attendee-card');
          if (card) {
            card.classList.add('is-complete');
          }
        });

        root.addEventListener('change', (e) => {
          const target = e.target;
          if (!(target instanceof Element) || target.getAttribute('name') !== 'donation_choice') {
            return;
          }
          const btn = root.querySelector('.mel-rsvp-cta button, .mel-rsvp-cta input[type="submit"]');
          if (!btn) {
            return;
          }
          if ('value' in btn) {
            btn.value = 'Confirm RSVP + Support 💛';
          }
          btn.textContent = 'Confirm RSVP + Support 💛';
        });

        setTimeout(() => {
          const donation = root.querySelector('.mel-donation-card');
          if (donation instanceof Element) {
            donation.animate(
              [
                { transform: 'scale(1)' },
                { transform: 'scale(1.03)' },
                { transform: 'scale(1)' }
              ],
              { duration: 400, easing: 'ease-out' }
            );
          }
        }, 1200);
      });
    }
  };
})(Drupal, once);
