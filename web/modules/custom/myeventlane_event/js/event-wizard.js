/**
 * @file
 * MyEventLane Event Wizard controller.
 *
 * Responsibilities:
 * - Step navigation + persistence
 * - AJAX-safe initialization
 * - Save-per-step UX hooks
 *
 * IMPORTANT:
 * - This file MUST NOT contain address autocomplete logic.
 * - Location handling lives in myeventlane-location.js ONLY.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Wizard state helpers
   */
  function getWizardForm(context) {
    return (
      context.querySelector('form#event-wizard-form') ||
      context.querySelector('form[id*="event_wizard"]') ||
      context.querySelector('.mel-event-wizard form')
    );
  }

  function getCurrentStep(form) {
    return form.querySelector('[data-wizard-step].is-active');
  }

  function getStepIndex(stepEl) {
    return stepEl ? parseInt(stepEl.getAttribute('data-wizard-step'), 10) : null;
  }

  /**
   * Ensure Drupal detects changes before step submit
   */
  function triggerFormUpdated(form) {
    if (window.jQuery) {
      window.jQuery(form).trigger('formUpdated');
    }
  }

  /**
   * Attach handlers for wizard navigation
   */
  function initWizard(form) {
    if (!form) return;

    const steps = form.querySelectorAll('[data-wizard-step]');
    if (!steps.length) return;

    // Next / Continue buttons
    const nextButtons = form.querySelectorAll(
      'button[data-wizard-next], input[data-wizard-next]'
    );

    nextButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        triggerFormUpdated(form);
      });
    });

    // Back buttons
    const backButtons = form.querySelectorAll(
      'button[data-wizard-back], input[data-wizard-back]'
    );

    backButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        triggerFormUpdated(form);
      });
    });

    // Safety: before submit (final publish)
    form.addEventListener(
      'submit',
      () => {
        triggerFormUpdated(form);
      },
      true
    );
  }

  /**
   * Enable/disable sidebar step buttons based on wizard progress.
   */
  function updateStepButtonAccessibility(context) {
    const wizardSettings = (typeof drupalSettings !== 'undefined' && drupalSettings.myeventlaneEventWizard)
      ? drupalSettings.myeventlaneEventWizard
      : null;
    
    const buttons = context.querySelectorAll('.js-mel-stepper-button');
    
    // Find current step.
    const currentStep = context.querySelector('.js-mel-stepper-button.is-active');
    const currentStepId = currentStep 
      ? (currentStep.getAttribute('data-step-target') || currentStep.getAttribute('data-wizard-step'))
      : null;
    
    // Default: enable all buttons unless explicitly disabled.
    buttons.forEach((button) => {
      const targetStep = button.getAttribute('data-step-target') || button.getAttribute('data-wizard-step');
      
      // Always enable the current step.
      if (targetStep === currentStepId) {
        button.removeAttribute('aria-disabled');
        button.classList.remove('is-disabled');
        button.style.opacity = '';
        button.style.cursor = '';
        return;
      }
      
      // If no wizard settings, enable all buttons (default state).
      if (!wizardSettings) {
        button.removeAttribute('aria-disabled');
        button.classList.remove('is-disabled');
        button.style.opacity = '';
        button.style.cursor = '';
        return;
      }

      const { wizard_started, highest_completed_index, steps } = wizardSettings;
      
      // If wizard hasn't started, disable all steps except the first one.
      if (!wizard_started) {
        const stepIndex = steps.indexOf(targetStep);
        // Disable all steps except the first (index 0).
        if (stepIndex > 0) {
          button.setAttribute('aria-disabled', 'true');
          button.classList.add('is-disabled');
          // Don't use pointer-events: none as it blocks JavaScript handlers.
          // Use opacity and cursor styling instead.
          button.style.opacity = '0.5';
          button.style.cursor = 'not-allowed';
        } else {
          // Enable first step.
          button.removeAttribute('aria-disabled');
          button.classList.remove('is-disabled');
          button.style.opacity = '';
          button.style.cursor = '';
        }
        return;
      }

      // Wizard has started: enable steps up to highest_completed_step + 1 (allow next step).
      const stepIndex = steps.indexOf(targetStep);
      
      // Enable if step is at or before highest_completed_step + 1 (allow next step).
      if (stepIndex <= highest_completed_index + 1) {
        button.removeAttribute('aria-disabled');
        button.classList.remove('is-disabled');
        button.style.opacity = '';
        button.style.cursor = '';
      } else {
        // Disable future steps.
        button.setAttribute('aria-disabled', 'true');
        button.classList.add('is-disabled');
        // Don't use pointer-events: none as it blocks JavaScript handlers.
        button.style.opacity = '0.5';
        button.style.cursor = 'not-allowed';
      }
    });
  }

  /**
   * Handle stepper button clicks (for EventWizardForm and EventFormAlter).
   * Step wizard (Basics, When & Where, …) uses real <a href="..."> links; do not intercept.
   */
  function initStepperButtons(context) {
    const buttons = once('mel-stepper-button', context.querySelectorAll('.js-mel-stepper-button'), context);
    
    buttons.forEach((button) => {
      // Step wizard: stepper items are <a href="{{ nav_step.url }}">. Let them navigate.
      if (button.tagName === 'A') {
        const href = (button.getAttribute('href') || '').trim();
        if (href && href !== '#' && !href.startsWith('javascript:')) {
          return;
        }
      }

      // For EventWizardForm: handle clicks on step containers that trigger hidden submit buttons.
      if (button.tagName !== 'BUTTON' && button.tagName !== 'INPUT') {
        const hiddenSubmit = button.querySelector('.js-mel-step-submit');
        if (hiddenSubmit) {
          // Use capture phase to ensure we catch the event early.
          button.addEventListener('click', (e) => {
            // Only block if explicitly disabled via aria-disabled attribute.
            // Don't check pointer-events style as it might be set elsewhere.
            if (button.getAttribute('aria-disabled') === 'true') {
              e.preventDefault();
              e.stopPropagation();
              return;
            }
            e.preventDefault();
            e.stopPropagation();
            // Trigger the hidden submit button.
            hiddenSubmit.click();
          }, true); // Use capture phase
          
          // Also handle keyboard navigation.
          button.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              if (button.getAttribute('aria-disabled') === 'true') {
                e.preventDefault();
                e.stopPropagation();
                return;
              }
              e.preventDefault();
              e.stopPropagation();
              hiddenSubmit.click();
            }
          });
          return;
        }
      }
      
      // Fallback handler for buttons without hidden submit.
      button.addEventListener('click', (e) => {
        // Only block if explicitly disabled via aria-disabled attribute.
        if (button.getAttribute('aria-disabled') === 'true') {
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        
        e.preventDefault();
        e.stopPropagation();
        const targetStep = button.getAttribute('data-step-target') || button.getAttribute('data-wizard-step');
        if (!targetStep) return;

        const form = button.closest('form');
        if (!form) return;

        // Try to find hidden submit button for this step.
        const hiddenSubmit = button.querySelector('.js-mel-step-submit');
        if (hiddenSubmit) {
          hiddenSubmit.click();
          return;
        }
        
        // Fallback: find any wizard_step field and set it, then find the matching button.
        const stepField = form.querySelector('input[name="wizard_step"]');
        if (stepField) {
          stepField.value = targetStep;
          // Find the hidden submit button for this step and click it.
          const matchingButton = form.querySelector(`.js-mel-stepper-button[data-step-target="${targetStep}"]`);
          if (matchingButton) {
            const matchingSubmit = matchingButton.querySelector('.js-mel-step-submit');
            if (matchingSubmit) {
              matchingSubmit.click();
              return;
            }
          }
        }
        // For EventFormAlter: use wizard_target_step mechanism.
        else {
          const targetField = form.querySelector('input[name="wizard_target_step"], .js-mel-wizard-target-step');
          if (targetField) {
            targetField.value = targetStep;
            const gotoButton = form.querySelector('.js-mel-wizard-goto, input[name*="goto"], button[name*="goto"]');
            if (gotoButton) {
              gotoButton.click();
            }
          }
        }
      }, true); // Use capture phase
    });
    
    // Update button accessibility after handlers are attached.
    updateStepButtonAccessibility(context);
  }

  /**
   * Drupal behavior
   */
  Drupal.behaviors.myeventlaneEventWizard = {
    attach(context) {
      const forms = [];

      if (context.tagName === 'FORM') {
        forms.push(context);
      } else {
        forms.push(...context.querySelectorAll('form'));
      }

      for (const form of once('mel-event-wizard', forms, context)) {
        // Only attach to wizard forms
        if (
          form.id === 'event-wizard-form' ||
          form.classList.contains('mel-event-wizard') ||
          form.querySelector('[data-wizard-step]')
        ) {
          // Delay allows AJAX-rendered steps to exist
          setTimeout(() => {
            initWizard(form);
          }, 50);
        }
      }

      // Initialize stepper buttons for both EventWizardForm and EventFormAlter.
      // This will also update button accessibility.
      initStepperButtons(context);

      // Warn when leaving Basics with unsaved image (sidebar links or refresh).
      once('mel-basics-unsaved-image', context, () => {
        const form = context.querySelector('form#event-wizard-basics-form');
        if (!form) return;
        let formSubmitting = false;
        form.addEventListener('submit', () => { formSubmitting = true; });
        const hasUnsavedImage = () => {
          if (formSubmitting) return false;
          const fidsInput = form.querySelector('input[name*="field_event_image"][name*="fids"]');
          return fidsInput && String(fidsInput.value || '').trim() !== '';
        };
        const msg = Drupal.t('You have an image that has not been saved. Click "Continue" to save before switching steps.');
        context.querySelectorAll('.mel-sidebar__link--wizard.is-wizard-step').forEach((link) => {
          const href = (link.getAttribute('href') || '').trim();
          const isActive = link.classList.contains('is-active');
          if (!href || href === '#' || isActive) return;
          link.addEventListener('click', (e) => {
            if (hasUnsavedImage() && !window.confirm(msg)) e.preventDefault();
          });
        });
        window.addEventListener('beforeunload', (e) => {
          if (hasUnsavedImage()) e.preventDefault();
        });
      });

      // Also update button accessibility after a short delay to ensure DOM is ready.
      setTimeout(() => {
        updateStepButtonAccessibility(context);
      }, 100);
    },
  };

})(window.Drupal || {}, window.once);