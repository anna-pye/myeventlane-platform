/**
 * @file
 * Event form enhancements for MyEventLane.
 *
 * Ensures conditional fields (RSVP/Paid) work smoothly and provides
 * visual feedback when fields appear/disappear.
 */

(function (Drupal) {
  'use strict';

  /**
   * Enhances the event form with smooth transitions for conditional fields.
   */
  Drupal.behaviors.myeventlaneEventForm = {
    attach: function (context, settings) {
      const form = context.querySelector('.mel-event-form-wrapper, .mel-form--event, form.node-event-form, form[id*="node-event"]');
      if (!form) {
        return;
      }

      // Verify Drupal.states is available
      if (typeof Drupal.states === 'undefined') {
        console.warn('Drupal.states not available - conditional fields may not work');
        return;
      }

      // Force states API to re-initialize on this form
      // This is critical because the form wrapper might have been added after states initialized
      setTimeout(function() {
        if (Drupal.states && Drupal.states.Trigger) {
          // Find all elements with #states and re-trigger
          const stateElements = form.querySelectorAll('[data-drupal-states]');
          stateElements.forEach(function(element) {
            // Trigger a change event to force states re-evaluation
            const trigger = element.closest('form');
            if (trigger) {
              const event = new Event('change', { bubbles: true });
              element.dispatchEvent(event);
            }
          });
        }
      }, 100);

      // Monitor field visibility changes for smooth transitions
      const conditionalFields = form.querySelectorAll('.js-form-item, [data-drupal-states]');
      conditionalFields.forEach(function(field) {
        if (!field.hasAttribute('data-field-monitored')) {
          field.setAttribute('data-field-monitored', 'true');
          
          const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
              if (mutation.type === 'attributes' && (mutation.attributeName === 'style' || mutation.attributeName === 'class')) {
                const isVisible = field.offsetParent !== null && !field.classList.contains('js-form-item--hidden');
                field.classList.toggle('mel-field-visible', isVisible);
                field.classList.toggle('mel-field-hidden', !isVisible);
              }
            });
          });

          observer.observe(field, {
            attributes: true,
            attributeFilter: ['style', 'class'],
          });
        }
      });

      // Add visual feedback when booking type changes
      // Try multiple selectors to find the event type field
      const bookingTypeSelectors = [
        'select[name="field_event_type[0][value]"]',
        'select[name*="field_event_type"]',
        '[name="field_event_type"]',
        'select[name^="field_event_type"]'
      ];
      
      let bookingTypeField = null;
      for (let i = 0; i < bookingTypeSelectors.length; i++) {
        bookingTypeField = form.querySelector(bookingTypeSelectors[i]);
        if (bookingTypeField) {
          console.log('Found event type field with selector:', bookingTypeSelectors[i]);
          break;
        }
      }
      
      if (bookingTypeField) {
        // Trigger initial state evaluation
        setTimeout(function() {
          if (Drupal.states && Drupal.states.Trigger) {
            Drupal.states.Trigger.states[bookingTypeField.id || bookingTypeField.name] = {};
            const changeEvent = new Event('change', { bubbles: true, cancelable: true });
            bookingTypeField.dispatchEvent(changeEvent);
          }
        }, 200);
        
        bookingTypeField.addEventListener('change', function() {
          const selectedValue = this.value || (this.options && this.options[this.selectedIndex]?.value);
          console.log('Event type changed to:', selectedValue);
          
          // Force states API to re-evaluate all dependent fields
          setTimeout(function() {
            if (Drupal.states && Drupal.states.Trigger) {
              // Trigger states API directly
              const changeEvent = new Event('change', { bubbles: true, cancelable: true });
              bookingTypeField.dispatchEvent(changeEvent);
              
              // Also trigger on all form inputs to ensure states are re-evaluated
              const allInputs = form.querySelectorAll('input, select, textarea');
              allInputs.forEach(function(input) {
                if (input !== bookingTypeField) {
                  const evt = new Event('change', { bubbles: true, cancelable: true });
                  input.dispatchEvent(evt);
                }
              });
            }
          }, 100);
        });
      } else {
        console.warn('Could not find event type field in form');
      }
    },
  };
})(Drupal);


