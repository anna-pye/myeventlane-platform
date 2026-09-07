/**
 * @file
 * MyEventLane Theme - Main JavaScript Entry
 *
 * This file is the entry point for Vite and imports all theme assets.
 * Uses Drupal behaviors to ensure compatibility with Commerce payment JS.
 */

// Polyfill _.defer when Lodash overwrites Underscore (fixes toolbar TypeError).
import './underscore-defer-polyfill.js';

// Import SCSS (processed by Vite)
import '../scss/main.scss';

// Import components
import { initMobileDrawer } from './mobile-drawer.js';
import { initMobileOverlays } from './mel-mobile-overlays.js';

// Import event form enhancements (Drupal behavior)
import './event-form.js';

// Import event card media and carousel (Drupal behavior)
import './mel-card-media.js';

// Import card carousel (Swiper) for Recommended Events
import './card-carousel.js';

/**
 * Initialize theme functionality.
 * Wrapped in Drupal behavior to ensure it doesn't interfere with Commerce payment JS.
 * Includes fallback initialization if Drupal is not available at module load time.
 */
(function () {
  'use strict';

  /**
   * Initialize theme components.
   * This function is called both as a Drupal behavior and as a fallback.
   */
  function initMelCalendarTabs(context) {
    var scope = context || document;
    scope.querySelectorAll('.mel-calendar').forEach(function (calendar) {
      if (calendar.getAttribute('data-mel-calendar-init') === '1') {
        return;
      }
      calendar.setAttribute('data-mel-calendar-init', '1');
      calendar.querySelectorAll('.mel-calendar__tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
          var target = tab.getAttribute('data-tab');
          calendar.querySelectorAll('.mel-calendar__tab').forEach(function (t) {
            t.classList.remove('is-active');
            t.setAttribute('aria-selected', 'false');
          });
          calendar.querySelectorAll('.mel-calendar__pane').forEach(function (p) {
            p.classList.remove('is-active');
          });
          tab.classList.add('is-active');
          tab.setAttribute('aria-selected', 'true');
          var pane = calendar.querySelector('[data-pane="' + target + '"]');
          if (pane) {
            pane.classList.add('is-active');
          }
        });
      });
    });
  }

  /**
   * Connect the Discover, My planner and My bookings panels.
   */
  function initMelEventPlanner(context) {
    var scope = context || document;
    scope.querySelectorAll('[data-mel-event-planner]').forEach(function (planner) {
      if (planner.getAttribute('data-mel-event-planner-init') === '1') {
        return;
      }

      var tabs = Array.prototype.slice.call(planner.querySelectorAll('[data-mel-event-planner-tab]'));
      var panels = Array.prototype.slice.call(planner.querySelectorAll('[data-mel-event-planner-panel]'));
      if (!tabs.length || panels.length < 3) {
        return;
      }
      planner.setAttribute('data-mel-event-planner-init', '1');

      function activate(mode, focusTab, updateHash) {
        var activeTab = null;
        tabs.forEach(function (tab) {
          var selected = tab.getAttribute('data-mel-event-planner-tab') === mode;
          tab.classList.toggle('is-active', selected);
          tab.setAttribute('aria-selected', selected ? 'true' : 'false');
          tab.setAttribute('tabindex', selected ? '0' : '-1');
          if (selected) {
            activeTab = tab;
          }
        });
        panels.forEach(function (panel) {
          var selected = panel.getAttribute('data-mel-event-planner-panel') === mode;
          panel.hidden = !selected;
          panel.classList.toggle('is-active', selected);
        });
        if (focusTab && activeTab) {
          activeTab.focus();
        }
        if (updateHash && window.history && window.history.replaceState) {
          window.history.replaceState(null, '', '#' + mode);
        }
        if (mode === 'discover') {
          window.dispatchEvent(new Event('resize'));
        }
      }

      tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
          activate(tab.getAttribute('data-mel-event-planner-tab'), false, true);
        });
        tab.addEventListener('keydown', function (event) {
          var targetIndex = null;
          if (event.key === 'ArrowRight') {
            targetIndex = (index + 1) % tabs.length;
          } else if (event.key === 'ArrowLeft') {
            targetIndex = (index - 1 + tabs.length) % tabs.length;
          } else if (event.key === 'Home') {
            targetIndex = 0;
          } else if (event.key === 'End') {
            targetIndex = tabs.length - 1;
          }
          if (targetIndex !== null) {
            event.preventDefault();
            activate(tabs[targetIndex].getAttribute('data-mel-event-planner-tab'), true, true);
          }
        });
      });

      var initialMode = window.location.hash.replace('#', '');
      if (!tabs.some(function (tab) { return tab.getAttribute('data-mel-event-planner-tab') === initialMode; })) {
        initialMode = 'discover';
      }
      activate(initialMode, false, false);
    });
  }

  function initializeTheme(context) {
    initMobileOverlays(context);
    initMobileDrawer(context);

    initMelCalendarTabs(context);
    initMelEventPlanner(context);
  }

  // Register as Drupal behavior if Drupal is available
  if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
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
        initializeTheme(context);
      }
    };
  }

  // Fallback initialization if Drupal is not available at module load time.
  // This ensures mobile navigation and other components still work even if
  // Drupal behaviors haven't loaded yet or library loading order is disrupted.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      initializeTheme(document);
    });
    // Also try immediately in case DOM is ready but event hasn't fired
    setTimeout(function() {
      initializeTheme(document);
    }, 0);
  } else {
    // DOM already loaded, initialize immediately
    initializeTheme(document);
  }
  
  // Additional fallback - try again after a short delay to catch late-loading elements
  setTimeout(function() {
    initializeTheme(document);
  }, 500);
})();
