/**
 * @file
 * Event form conditional fields - JavaScript fallback/enhancement.
 * This ensures fields show/hide correctly regardless of conditional_fields module.
 */

(function (Drupal) {
  'use strict';

  // Store interval IDs for cleanup - keyed by select element
  const intervalStore = new WeakMap();

  Drupal.behaviors.eventFormConditionalFields = {
    attach: function (context, settings) {
      // Find the event type select - try multiple selectors
      // Priority: exact name match, then data attribute, then pattern match
      const selectors = [
        'select[name="field_event_type[0][value]"]',
        'select[data-booking-type-select="true"]',
        'select[name*="field_event_type"][name*="value"]',
        '.mel-form-content select[name*="field_event_type"]',
        '.booking_config select[name*="field_event_type"]',
        'select[name*="field_event_type"]',
      ];
      
      let select = null;
      for (let i = 0; i < selectors.length; i++) {
        select = context.querySelector(selectors[i]);
        if (select) {
          console.log('Event form: Found select with selector:', selectors[i]);
          break;
        }
      }
      
      if (!select) {
        console.warn('Event form: Could not find field_event_type select element');
        return;
      }
      
      // Ensure conditional_fields has processed the form
      // Wait for Drupal.behaviors to complete, especially conditional_fields
      // The conditional_fields module should handle #states automatically, but we ensure it's triggered
      if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
        // Give conditional_fields time to initialize after form is fully rendered
        setTimeout(function() {
          // Trigger change event to ensure #states API evaluates
          // This works with both Drupal's native #states and conditional_fields enhancement
          if (typeof jQuery !== 'undefined' && jQuery(select).length) {
            // Trigger both change and input events to ensure all handlers fire
            jQuery(select).trigger('change').trigger('input');
          } else if (select) {
            // Fallback if jQuery not available
            select.dispatchEvent(new Event('change', { bubbles: true }));
            select.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }, 200);
      }

      // Skip if already attached to this select
      if (intervalStore.has(select)) {
        return;
      }

      // Find containers
      const rsvp = context.querySelector('.mel-booking-rsvp');
      const paid = context.querySelector('.mel-booking-paid');
      const external = context.querySelector('.mel-booking-external');
      
      console.log('Event form: Containers found - RSVP:', !!rsvp, 'Paid:', !!paid, 'External:', !!external);

      // Update visibility function - minimal fallback only
      // Primary visibility is handled by conditional_fields module
      // This is just a safety net if conditional_fields doesn't work
      const updateVisibility = function() {
        const value = select.value;
        console.log('Event form: Fallback visibility check for value:', value);
        
        // Check if conditional_fields has already handled visibility
        // If it has, we don't need to do anything
        // Only act if fields are in wrong state (very rare)
        
        if (rsvp) {
          const computedStyle = window.getComputedStyle(rsvp);
          const isVisible = computedStyle.display !== 'none' && computedStyle.visibility !== 'hidden';
          const shouldBeVisible = value === 'rsvp' || value === 'both';
          
          if (shouldBeVisible && !isVisible) {
            // conditional_fields should have shown it, but didn't - fallback
            console.warn('Event form: Fallback - showing RSVP fields');
            rsvp.style.display = 'block';
            rsvp.removeAttribute('data-force-hidden');
          } else if (!shouldBeVisible && isVisible && !rsvp.hasAttribute('data-conditional-fields-processed')) {
            // Only hide if conditional_fields hasn't processed it
            rsvp.style.display = 'none';
            rsvp.setAttribute('data-force-hidden', 'true');
          }
        }
        
        if (paid) {
          const computedStyle = window.getComputedStyle(paid);
          const isVisible = computedStyle.display !== 'none' && computedStyle.visibility !== 'hidden';
          const shouldBeVisible = value === 'paid' || value === 'both';
          
          if (shouldBeVisible && !isVisible) {
            console.warn('Event form: Fallback - showing Paid fields');
            paid.style.display = 'block';
            paid.removeAttribute('data-force-hidden');
          } else if (!shouldBeVisible && isVisible && !paid.hasAttribute('data-conditional-fields-processed')) {
            paid.style.display = 'none';
            paid.setAttribute('data-force-hidden', 'true');
          }
        }
        
        if (external) {
          const computedStyle = window.getComputedStyle(external);
          const isVisible = computedStyle.display !== 'none' && computedStyle.visibility !== 'hidden';
          const shouldBeVisible = value === 'external';
          
          if (shouldBeVisible && !isVisible) {
            console.warn('Event form: Fallback - showing External fields');
            external.style.display = 'block';
            external.removeAttribute('data-force-hidden');
          } else if (!shouldBeVisible && isVisible && !external.hasAttribute('data-conditional-fields-processed')) {
            external.style.display = 'none';
            external.setAttribute('data-force-hidden', 'true');
          }
        }
      };

      // Wait for conditional_fields to initialize first
      // Give it time to process the form
      setTimeout(function() {
        // Only run fallback if conditional_fields hasn't processed the fields
        if (rsvp && !rsvp.hasAttribute('data-conditional-fields-processed')) {
          updateVisibility();
        }
        if (paid && !paid.hasAttribute('data-conditional-fields-processed')) {
          updateVisibility();
        }
        if (external && !external.hasAttribute('data-conditional-fields-processed')) {
          updateVisibility();
        }
      }, 500); // Give conditional_fields time to initialize

      // On change - let conditional_fields handle it first, then check
      select.addEventListener('change', function() {
        // Wait a tick for conditional_fields to process
        setTimeout(function() {
          updateVisibility();
        }, 100);
      });

      // Minimal fallback interval - only check if conditional_fields isn't working
      // This should rarely trigger if conditional_fields is working properly
      let visibilityInterval = null;
      if (rsvp || paid || external) {
        visibilityInterval = setInterval(function() {
          if (select.value) {
            // Only act if conditional_fields hasn't processed the fields
            // This is a safety net, not the primary mechanism
            const needsFallback = 
              (rsvp && rsvp.hasAttribute('data-force-hidden') && !rsvp.hasAttribute('data-conditional-fields-processed')) ||
              (paid && paid.hasAttribute('data-force-hidden') && !paid.hasAttribute('data-conditional-fields-processed')) ||
              (external && external.hasAttribute('data-force-hidden') && !external.hasAttribute('data-conditional-fields-processed'));
            
            if (needsFallback) {
              console.warn('Event form: Conditional fields not working, using fallback');
              updateVisibility();
            }
          }
        }, 3000); // Very infrequent - only as last resort
        
        // Store interval ID using WeakMap for cleanup when behavior is detached
        intervalStore.set(select, visibilityInterval);
      }
    },
    
    detach: function (context, settings, trigger) {
      // Cleanup: clear interval when behavior is detached
      // This prevents memory leaks when form is removed or page unloads
      const selectors = [
        'select[name="field_event_type[0][value]"]',
        'select[name*="field_event_type"][name*="value"]',
        'select[name*="field_event_type"]',
      ];
      
      for (let i = 0; i < selectors.length; i++) {
        const select = context.querySelector(selectors[i]);
        if (select && intervalStore.has(select)) {
          const intervalId = intervalStore.get(select);
          if (intervalId) {
            clearInterval(intervalId);
            intervalStore.delete(select);
            console.log('Event form: Cleaned up interval for select element');
          }
          break;
        }
      }
    }
  };

})(Drupal);
