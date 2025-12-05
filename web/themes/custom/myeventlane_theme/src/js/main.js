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
(function (Drupal) {
  'use strict';

  /**
   * Theme initialization behavior.
   * Ensures theme JS doesn't interfere with Commerce payment gateway initialization.
   * Only runs on full page load to avoid conflicts with Commerce payment JS.
   * 
   * This behavior is automatically attached by Drupal's behavior system.
   * Do not manually call attach() - it will cause double initialization.
   */
  Drupal.behaviors.myeventlaneTheme = {
    attach: function (context, settings) {
      // Only run on full page load, not on AJAX updates
      // This prevents interference with Commerce payment gateway initialization
      if (context !== document) {
        return;
      }

      // Prevent double initialization by checking if already initialized
      if (document.documentElement.classList.contains('js-loaded')) {
        return;
      }

      // Initialize mobile navigation
      initMobileNav();

      // Initialize account dropdown
      initAccountDropdown();
      
      // Retry account dropdown after a short delay in case elements aren't ready
      setTimeout(function() {
        initAccountDropdown();
      }, 200);

      // Add loaded class for CSS transitions
      document.documentElement.classList.add('js-loaded');
    }
  };
})(typeof Drupal !== 'undefined' ? Drupal : {});
