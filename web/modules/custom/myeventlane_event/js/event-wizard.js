/**
 * Wizard JS (vendor form).
 *
 * Server-authoritative wizard:
 * - PHP controls which step is visible.
 * - JS only:
 *   - Stepper click -> set target step -> trigger hidden AJAX submit (goto).
 *   - Focus step title after rebuild for accessibility.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melEventWizard = {
    attach(context) {
      const wrappers = once('mel-event-wizard', '.mel-event-form--wizard', context);
      wrappers.forEach((wrapper) => {
        const form = wrapper.closest('form');
        if (!form) return;

        const target = form.querySelector('.js-mel-wizard-target-step');
        const gotoBtn = form.querySelector('.js-mel-wizard-goto');
        if (!target || !gotoBtn) return;

        // Stepper click delegation.
        wrapper.addEventListener('click', (e) => {
          const btn = e.target.closest('.js-mel-stepper-button');
          if (!btn) return;

          e.preventDefault();
          const step = btn.getAttribute('data-step-target');
          if (!step) return;

          target.value = step;
          gotoBtn.click();
        });

        // Focus active step title after AJAX update.
        const title = wrapper.querySelector('.mel-wizard-step__title');
        if (title) {
          title.setAttribute('tabindex', '-1');
          title.focus();
        }
      });
    }
  };

})(Drupal, once);
