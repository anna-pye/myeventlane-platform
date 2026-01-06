/**
 * @file
 * MyEventLane Tickets Event Wizard JavaScript.
 *
 * Handles:
 * - Paid vs RSVP ticket type toggle
 * - Conditional field visibility
 * - Wizard button layout
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Find the event type field selector.
   * Supports multiple form structures (EventWizardForm, EventFormAlter, standard node form).
   */
  function findEventTypeField(form) {
    if (!form) return null;

    // Try various selector patterns
    const selectors = [
      'select[name="field_event_type[0][value]"]',
      'select[name="field_event_type"]',
      'input[name="field_event_type[0][value]"]',
      'input[name="field_event_type"]',
      '#edit-field-event-type-0-value',
      '[name*="field_event_type"][name*="value"]',
    ];

    for (const selector of selectors) {
      const field = form.querySelector(selector);
      if (field) {
        return field;
      }
    }

    return null;
  }

  /**
   * Get current event type value.
   */
  function getEventType(form) {
    const field = findEventTypeField(form);
    if (!field) return null;

    const value = field.value || field.options?.[field.selectedIndex]?.value;
    return value || null;
  }

  /**
   * Find ticketing-related fields that should be hidden for RSVP-only events.
   */
  function findTicketingFields(form) {
    const fields = [];

    // Find field_ticket_types wrapper
    const ticketTypesWrapper = form.querySelector('.field--name-field-ticket-types') ||
                               form.querySelector('[data-drupal-selector*="field-ticket-types"]') ||
                               form.querySelector('[id*="field-ticket-types"]');

    if (ticketTypesWrapper) {
      fields.push(ticketTypesWrapper);
    }

    // Find field_collect_per_ticket if it exists
    const collectPerTicket = form.querySelector('.field--name-field-collect-per-ticket') ||
                             form.querySelector('[data-drupal-selector*="field-collect-per-ticket"]') ||
                             form.querySelector('[id*="field-collect-per-ticket"]');

    if (collectPerTicket) {
      fields.push(collectPerTicket);
    }

    return fields;
  }

  /**
   * Toggle visibility of ticketing fields based on event type.
   */
  function toggleTicketingFields(form, eventType) {
    const ticketingFields = findTicketingFields(form);

    // Show fields for 'paid' or 'both', hide for 'rsvp' or 'external'
    const shouldShow = eventType === 'paid' || eventType === 'both';

    ticketingFields.forEach((field) => {
      if (shouldShow) {
        field.style.display = '';
        field.classList.remove('js-hidden');
      } else {
        field.style.display = 'none';
        field.classList.add('js-hidden');
      }
    });
  }

  /**
   * Ensure wizard buttons are inline with Save/Preview buttons.
   */
  function fixButtonLayout(form) {
    // Find the actions container
    const actionsContainer = form.querySelector('.form-actions') ||
                             form.querySelector('[data-drupal-selector="edit-actions"]') ||
                             form.querySelector('#edit-actions') ||
                             form.querySelector('.mel-event-form__actions');

    if (!actionsContainer) return;

    // Find wizard-specific action containers
    const wizardActions = form.querySelector('.mel-vendor-wizard__actions') ||
                          form.querySelector('[data-wizard-actions]') ||
                          form.querySelector('.wizard-actions');

    if (wizardActions && actionsContainer !== wizardActions) {
      // Move wizard actions into the main actions container or ensure they're inline
      const wizardButtons = wizardActions.querySelectorAll('button, input[type="submit"]');
      wizardButtons.forEach((btn) => {
        if (!actionsContainer.contains(btn)) {
          // Clone and move, or just ensure styling is correct
          btn.style.display = 'inline-block';
          btn.style.marginLeft = '0.5em';
        }
      });
    }

    // Ensure all buttons in actions container are inline
    const allButtons = actionsContainer.querySelectorAll('button, input[type="submit"]');
    allButtons.forEach((btn) => {
      if (btn.style.display === 'none') return;
      btn.style.display = 'inline-block';
      btn.style.verticalAlign = 'middle';
    });

    // Add flex layout for better mobile support
    if (!actionsContainer.style.display || actionsContainer.style.display === 'block') {
      actionsContainer.style.display = 'flex';
      actionsContainer.style.flexWrap = 'wrap';
      actionsContainer.style.gap = '0.5em';
      actionsContainer.style.alignItems = 'center';
    }
  }

  /**
   * Initialize event wizard behavior for a form.
   */
  function initEventWizard(form) {
    if (!form) return;

    const eventTypeField = findEventTypeField(form);
    if (!eventTypeField) {
      // Event type field might not be on this step, that's okay
      return;
    }

    // Initial state
    const currentType = getEventType(form);
    if (currentType) {
      toggleTicketingFields(form, currentType);
    }

    // Watch for changes
    eventTypeField.addEventListener('change', function () {
      const newType = getEventType(form);
      toggleTicketingFields(form, newType);
    });

    // Also handle input events for text inputs
    eventTypeField.addEventListener('input', function () {
      const newType = getEventType(form);
      toggleTicketingFields(form, newType);
    });

    // Fix button layout
    fixButtonLayout(form);

    // Re-run button layout fix after AJAX updates
    if (window.jQuery) {
      window.jQuery(form).on('ajaxComplete', function () {
        setTimeout(() => fixButtonLayout(form), 100);
      });
    }
  }

  /**
   * Drupal behavior
   */
  Drupal.behaviors.myeventlaneTicketsEventWizard = {
    attach(context) {
      // Find all event node forms
      const forms = [];

      if (context.tagName === 'FORM') {
        forms.push(context);
      } else {
        // Look for event node forms specifically
        const candidateForms = context.querySelectorAll('form');
        candidateForms.forEach((form) => {
          // Check if this is an event node form
          if (
            form.querySelector('[name*="field_event_type"]') ||
            form.id === 'node-event-form' ||
            form.id === 'node-event-edit-form' ||
            form.classList.contains('node-event-form') ||
            form.querySelector('.field--name-field-event-type')
          ) {
            forms.push(form);
          }
        });
      }

      // Initialize each form once
      once('mel-tickets-event-wizard', forms, context).forEach((form) => {
        // Small delay to ensure form is fully rendered (especially after AJAX)
        setTimeout(() => initEventWizard(form), 50);
      });
    },
  };

})(window.Drupal || {}, window.drupalSettings || {}, window.once || {});

