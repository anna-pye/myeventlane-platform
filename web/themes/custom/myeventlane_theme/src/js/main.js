/**
 * @file
 * MyEventLane Theme - Main JavaScript Entry
 *
 * This file is the entry point for Vite and imports all theme assets.
 * Uses Drupal behaviors to ensure compatibility with Commerce payment JS.
 */

// Import SCSS (processed by Vite)
import '../scss/main.scss';

// Import components
import { initMobileNav, initAccountDropdown } from './header.js';

// Import event form enhancements (Drupal behavior)
import './event-form.js';

/**
 * Initialize theme functionality.
 * Wrapped in Drupal behavior to ensure it doesn't interfere with Commerce payment JS.
 */
(function (Drupal, once) {
  'use strict';

  /**
   * Theme initialization behavior.
   * Ensures theme JS doesn't interfere with Commerce payment gateway initialization.
   */
  Drupal.behaviors.myeventlaneTheme = {
    attach: function (context, settings) {
      // Only run on full page load, not on AJAX updates
      if (context !== document) {
        return;
      }

      // Initialize mobile navigation
      initMobileNav();

      // Initialize account dropdown
      initAccountDropdown();
      
      // Retry account dropdown after a short delay in case elements aren't ready
      setTimeout(() => {
        initAccountDropdown();
      }, 200);

      // Add loaded class for CSS transitions
      document.documentElement.classList.add('js-loaded');
    }
  };

  // Also run on DOM ready for immediate initialization (non-AJAX)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      if (typeof Drupal !== 'undefined' && Drupal.behaviors && Drupal.behaviors.myeventlaneTheme) {
        Drupal.behaviors.myeventlaneTheme.attach(document, drupalSettings || {});
      }
    });
  } else {
    if (typeof Drupal !== 'undefined' && Drupal.behaviors && Drupal.behaviors.myeventlaneTheme) {
      Drupal.behaviors.myeventlaneTheme.attach(document, drupalSettings || {});
    }
  }
})(Drupal, once);
