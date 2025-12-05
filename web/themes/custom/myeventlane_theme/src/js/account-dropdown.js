/**
 * @file
 * Account dropdown functionality - direct script (not ES module).
 * This ensures it works even if ES modules aren't supported.
 */

(function() {
  'use strict';

  function initAccountDropdown(context) {
    context = context || document;
    const dropdowns = context.querySelectorAll('.mel-account-dropdown');
    
    dropdowns.forEach(function(dropdown) {
      // Skip if already initialized
      if (dropdown.hasAttribute('data-dropdown-initialized')) {
        return;
      }
      
      dropdown.setAttribute('data-dropdown-initialized', 'true');
      const toggle = dropdown.querySelector('.mel-account-toggle');
      const menu = dropdown.querySelector('.mel-account-menu');

      if (!toggle || !menu) {
        console.warn('Account dropdown missing toggle or menu');
        return;
      }

      // Remove any existing listeners by cloning
      const newToggle = toggle.cloneNode(true);
      toggle.parentNode.replaceChild(newToggle, toggle);
      const newMenu = dropdown.querySelector('.mel-account-menu');

      newToggle.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const isOpen = dropdown.classList.contains('is-open');
        
        if (isOpen) {
          dropdown.classList.remove('is-open');
          newToggle.setAttribute('aria-expanded', 'false');
          newMenu.setAttribute('aria-hidden', 'true');
        } else {
          // Close all others
          document.querySelectorAll('.mel-account-dropdown.is-open').forEach(function(other) {
            if (other !== dropdown) {
              other.classList.remove('is-open');
              const otherToggle = other.querySelector('.mel-account-toggle');
              const otherMenu = other.querySelector('.mel-account-menu');
              if (otherToggle) otherToggle.setAttribute('aria-expanded', 'false');
              if (otherMenu) otherMenu.setAttribute('aria-hidden', 'true');
            }
          });
          
          dropdown.classList.add('is-open');
          newToggle.setAttribute('aria-expanded', 'true');
          newMenu.setAttribute('aria-hidden', 'false');
        }
      });

      // Close on outside click
      const closeHandler = function(e) {
        if (!dropdown.contains(e.target) && dropdown.classList.contains('is-open')) {
          dropdown.classList.remove('is-open');
          newToggle.setAttribute('aria-expanded', 'false');
          newMenu.setAttribute('aria-hidden', 'true');
        }
      };
      document.addEventListener('click', closeHandler, true);

      // Escape key
      const escapeHandler = function(e) {
        if (e.key === 'Escape' && dropdown.classList.contains('is-open')) {
          dropdown.classList.remove('is-open');
          newToggle.setAttribute('aria-expanded', 'false');
          newMenu.setAttribute('aria-hidden', 'true');
          newToggle.focus();
        }
      };
      document.addEventListener('keydown', escapeHandler);
    });
  }

  // Initialize immediately and on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      initAccountDropdown();
    });
  } else {
    // Small delay to ensure elements are rendered
    setTimeout(function() {
      initAccountDropdown();
    }, 50);
  }

  // Also register as Drupal behavior
  if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
    Drupal.behaviors.myeventlaneAccountDropdownDirect = {
      attach: function(context) {
        initAccountDropdown(context);
      }
    };
  }
})();

