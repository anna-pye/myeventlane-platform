/**
 * @file
 * Event wizard JavaScript for event creation/editing form.
 *
 * Implements:
 * - Left vertical stepper navigation
 * - Step validation blocking
 * - Auto-save per step
 * - Back/Next navigation
 */

(function ($, Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Event wizard behavior.
   */
  Drupal.behaviors.eventWizard = {
    attach: function (context, settings) {
      var forms = once('event-wizard', '.mel-event-wizard', context);
      if (!forms.length) {
        return;
      }
      var $form = $(forms);

      var wizardSettings = drupalSettings.eventWizard || {};
      var currentStep = wizardSettings.currentStep || 'basics';
      var steps = wizardSettings.steps || [];
      var stepConfig = wizardSettings.stepConfig || {};

      // Initialize wizard.
      this.initializeWizard($form, currentStep, steps);

      // Handle stepper clicks.
      this.attachStepperHandlers($form, steps);

      // Handle navigation buttons.
      this.attachNavigationHandlers($form, steps);

      // Handle auto-save on field changes.
      this.attachAutoSave($form, steps);

      // Handle form submission.
      this.attachSubmissionHandlers($form, steps);
    },

    /**
     * Initialize wizard - show only current step.
     */
    initializeWizard: function ($form, currentStep, steps) {
      var self = this;

      // Hide legacy tabs.
      $form.find('.mel-simple-tabs, .mel-event-form-tabs, .mel-simple-tabs__buttons').hide();

      // Hide all steps initially - but don't hide the content inside!
      steps.forEach(function (step) {
        var $step = $form.find('[data-wizard-step="' + step + '"]');
        if (!$step.length) {
          // Try alternative selectors.
          $step = $form.find('.mel-wizard-step--' + step);
        }
        if (step !== currentStep) {
          $step.removeClass('mel-wizard-step--active').addClass('mel-wizard-step--hidden');
          $step.css('display', 'none');
        } else {
          $step.removeClass('mel-wizard-step--hidden').addClass('mel-wizard-step--active');
          $step.css('display', 'block');
        }
      });

      // Update stepper indicator.
      this.updateStepper($form, currentStep, steps);
    },

    /**
     * Attach handlers for left stepper clicks.
     */
    attachStepperHandlers: function ($form, steps) {
      var self = this;

      // Handle stepper item clicks.
      $form.find('.mel-wizard-stepper__item').on('click', function (e) {
        var $item = $(this);
        if ($item.hasClass('is-disabled')) {
          return;
        }

        var step = $item.attr('data-step');
        if (step && self.isStepAccessible($form, step, steps)) {
          self.goToStep($form, step, steps, false);
        }
      });

      // Keyboard navigation for stepper.
      $form.find('.mel-wizard-stepper__item').on('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          $(this).trigger('click');
        }
      });
    },

    /**
     * Attach navigation button handlers.
     */
    attachNavigationHandlers: function ($form, steps) {
      var self = this;

      // Back button.
      $form.find('.mel-btn-back').on('click', function (e) {
        e.preventDefault();
        var currentStep = self.getCurrentStep($form);
        var currentIndex = steps.indexOf(currentStep);
        if (currentIndex > 0) {
          self.goToStep($form, steps[currentIndex - 1], steps, false);
        }
      });

      // Next button (submit button).
      $form.find('.mel-btn-primary[data-wizard-action="next"]').on('click', function (e) {
        var currentStep = self.getCurrentStep($form);
        
        // Validate current step before proceeding.
        if (!self.validateStep($form, currentStep)) {
          e.preventDefault();
          return false;
        }

        // Auto-save current step.
        self.autoSaveStep($form, currentStep, function() {
          // After auto-save, proceed to next step.
          var currentIndex = steps.indexOf(currentStep);
          if (currentIndex < steps.length - 1) {
            // Update hidden field and submit to go to next step.
            $form.find('input[name="wizard_current_step"]').val(steps[currentIndex + 1]);
            // Trigger form rebuild via AJAX or page reload.
            self.goToStep($form, steps[currentIndex + 1], steps, true);
          }
        });
      });
    },

    /**
     * Attach auto-save handlers for field changes.
     */
    attachAutoSave: function ($form, steps) {
      var self = this;
      var autoSaveTimer = null;

      // Debounced auto-save on field changes.
      $form.find('input, select, textarea').on('change blur', function () {
        var $field = $(this);
        var $step = $field.closest('.mel-wizard-step');
        var step = $step.attr('data-wizard-step');

        if (!step) {
          return;
        }

        // Clear existing timer.
        if (autoSaveTimer) {
          clearTimeout(autoSaveTimer);
        }

        // Debounce auto-save (wait 2 seconds after last change).
        autoSaveTimer = setTimeout(function () {
          self.autoSaveStep($form, step);
        }, 2000);
      });
    },

    /**
     * Auto-save current step.
     */
    autoSaveStep: function ($form, step, callback) {
      // Mark for auto-save.
      $form.find('input[name="wizard_auto_save"]').val('1');
      $form.find('input[name="wizard_current_step"]').val(step);

      // Create a temporary submit button to trigger save.
      var $tempSubmit = $('<input>', {
        type: 'submit',
        name: 'wizard_auto_save_trigger',
        value: '1',
        style: 'display: none;'
      });
      $form.append($tempSubmit);

      // Submit form via AJAX if available, otherwise regular submit.
      if (typeof Drupal.ajax !== 'undefined') {
        // Use AJAX form submission.
        var ajaxOptions = {
          url: $form.attr('action') || window.location.href,
          type: 'POST',
          data: $form.serialize(),
          success: function (response) {
            $tempSubmit.remove();
            $form.find('input[name="wizard_auto_save"]').val('0');
            if (callback) {
              callback();
            }
          },
          error: function () {
            $tempSubmit.remove();
            $form.find('input[name="wizard_auto_save"]').val('0');
          }
        };
        $.ajax(ajaxOptions);
      } else {
        // Fallback: show saving indicator and submit normally.
        $form.find('.mel-wizard-actions').prepend('<span class="mel-saving-indicator">Saving...</span>');
        // Note: In a real implementation, you'd want to use Drupal's AJAX framework
        // For now, we'll just update the step indicator.
        $form.find('input[name="wizard_auto_save"]').val('0');
        $tempSubmit.remove();
        if (callback) {
          callback();
        }
      }
    },

    /**
     * Navigate to a specific step.
     */
    goToStep: function ($form, step, steps, submit) {
      // Hide all steps.
      $form.find('.mel-wizard-step').each(function() {
        var $step = $(this);
        $step.removeClass('mel-wizard-step--active').addClass('mel-wizard-step--hidden');
        $step.hide();
      });

      // Show target step.
      var $targetStep = $form.find('[data-wizard-step="' + step + '"]');
      if (!$targetStep.length) {
        // Try alternative selector.
        $targetStep = $form.find('.mel-wizard-step--' + step);
      }
      $targetStep.removeClass('mel-wizard-step--hidden').addClass('mel-wizard-step--active');
      $targetStep.show();

      // Update stepper indicator.
      this.updateStepper($form, step, steps);

      // Update hidden field.
      $form.find('input[name="wizard_current_step"]').val(step);

      // Scroll to top of form.
      $('html, body').animate({
        scrollTop: $form.offset().top - 100
      }, 300);

      // If submit is true, submit form to rebuild with new step.
      if (submit) {
        // Create a temporary form to submit.
        var $tempForm = $('<form>', {
          method: 'POST',
          action: window.location.href
        });
        $tempForm.append($('<input>', {
          type: 'hidden',
          name: 'wizard_current_step',
          value: step
        }));
        $tempForm.append($('<input>', {
          type: 'hidden',
          name: 'form_build_id',
          value: $form.find('input[name="form_build_id"]').val()
        }));
        $tempForm.append($('<input>', {
          type: 'hidden',
          name: 'form_token',
          value: $form.find('input[name="form_token"]').val()
        }));
        $tempForm.append($('<input>', {
          type: 'hidden',
          name: 'form_id',
          value: $form.find('input[name="form_id"]').val()
        }));
        $('body').append($tempForm);
        $tempForm.submit();
      }
    },

    /**
     * Get current active step.
     */
    getCurrentStep: function ($form) {
      var $activeStep = $form.find('.mel-wizard-step--active');
      if ($activeStep.length) {
        return $activeStep.attr('data-wizard-step');
      }
      return 'basics';
    },

    /**
     * Update stepper indicator.
     */
    updateStepper: function ($form, currentStep, steps) {
      var currentIndex = steps.indexOf(currentStep);

      $form.find('.mel-wizard-stepper__item').each(function (index) {
        var $item = $(this);
        $item.removeClass('is-active is-completed is-disabled');

        if (index < currentIndex) {
          $item.addClass('is-completed');
          $item.attr('tabindex', '0');
        } else if (index === currentIndex) {
          $item.addClass('is-active');
          $item.attr('tabindex', '0');
        } else {
          // Future steps: check if accessible.
          var step = $item.attr('data-step');
          if (this.isStepAccessible($form, step, steps)) {
            $item.attr('tabindex', '0');
          } else {
            $item.addClass('is-disabled');
            $item.attr('tabindex', '-1');
          }
        }
      }.bind(this));
    },

    /**
     * Check if a step is accessible.
     */
    isStepAccessible: function ($form, step, steps) {
      var currentStep = this.getCurrentStep($form);
      var currentIndex = steps.indexOf(currentStep);
      var targetIndex = steps.indexOf(step);

      if (targetIndex === -1 || currentIndex === -1) {
        return false;
      }

      // Can go back freely.
      if (targetIndex <= currentIndex) {
        return true;
      }

      // Can only go forward if current step is completed.
      return this.isStepCompleted($form, currentStep);
    },

    /**
     * Check if a step is completed.
     */
    isStepCompleted: function ($form, step) {
      var $step = $form.find('[data-wizard-step="' + step + '"]');
      
      // Check required fields.
      var $requiredFields = $step.find('input[required], select[required], textarea[required]');
      var allFilled = true;

      $requiredFields.each(function () {
        var $field = $(this);
        var value = $field.val();
        if (!value || value.trim() === '') {
          allFilled = false;
          return false;
        }
      });

      return allFilled;
    },

    /**
     * Validate current step.
     */
    validateStep: function ($form, step) {
      var $step = $form.find('[data-wizard-step="' + step + '"]');
      var isValid = true;

      // Check required fields in this step.
      $step.find('input[required], select[required], textarea[required]').each(function () {
        var $field = $(this);
        var value = $field.val();
        if (!value || value.trim() === '') {
          isValid = false;
          $field.addClass('error');
          // Show error message.
          if (!$field.siblings('.error-message').length) {
            $field.after('<div class="error-message">' + Drupal.t('This field is required.') + '</div>');
          }
        } else {
          $field.removeClass('error');
          $field.siblings('.error-message').remove();
        }
      });

      // Special validation for schedule step (end cannot be before start).
      if (step === 'schedule') {
        var $start = $step.find('[name*="field_event_start"]');
        var $end = $step.find('[name*="field_event_end"]');
        if ($start.length && $end.length) {
          var startValue = $start.val();
          var endValue = $end.val();
          if (startValue && endValue) {
            var startDate = new Date(startValue);
            var endDate = new Date(endValue);
            if (endDate < startDate) {
              isValid = false;
              $end.addClass('error');
              if (!$end.siblings('.error-message').length) {
                $end.after('<div class="error-message">' + Drupal.t('End date cannot be before start date.') + '</div>');
              }
            }
          }
        }
      }

      return isValid;
    },

    /**
     * Attach submission handlers.
     */
    attachSubmissionHandlers: function ($form, steps) {
      var self = this;

      // Handle save draft - don't validate, just save.
      $form.find('.mel-btn-draft').on('click', function (e) {
        // Allow draft save without validation.
        $form.find('input[name="wizard_save_draft"]').remove();
        $form.append($('<input>', {
          type: 'hidden',
          name: 'wizard_save_draft',
          value: '1'
        }));
      });

      // Handle publish on review step - validate all steps.
      $form.on('submit', function (e) {
        var currentStep = self.getCurrentStep($form);
        if (currentStep === 'review') {
          var allValid = true;
          var firstInvalidStep = null;

          steps.forEach(function (step) {
            if (!self.validateStep($form, step)) {
              allValid = false;
              if (!firstInvalidStep) {
                firstInvalidStep = step;
              }
            }
          });

          if (!allValid && firstInvalidStep) {
            e.preventDefault();
            self.goToStep($form, firstInvalidStep, steps, false);
            return false;
          }
        }
      });
    }
  };

  /**
   * Sticky action bar behavior.
   */
  Drupal.behaviors.stickyActionBar = {
    attach: function (context, settings) {
      var actionBars = once('sticky-action-bar', '.mel-sticky-action-bar', context);
      if (!actionBars.length) {
        return;
      }
      var $actionBar = $(actionBars);

      var $window = $(window);
      var actionBarOffset = $actionBar.offset().top;
      var actionBarHeight = $actionBar.outerHeight();

      function updateStickyActionBar() {
        var scrollTop = $window.scrollTop();
        var windowHeight = $window.height();
        var documentHeight = $(document).height();

        if (scrollTop + windowHeight >= documentHeight - actionBarHeight - 20) {
          $actionBar.removeClass('is-sticky');
          $('body').removeClass('has-sticky-action-bar');
        } else {
          $actionBar.addClass('is-sticky');
          $('body').addClass('has-sticky-action-bar');
        }
      }

      $window.on('scroll', updateStickyActionBar);
      $window.on('resize', updateStickyActionBar);
      updateStickyActionBar();
    }
  };

})(jQuery, Drupal, drupalSettings, once);
