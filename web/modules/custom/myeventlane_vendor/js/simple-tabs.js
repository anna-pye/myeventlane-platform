/**
 * @file
 * Simple tab behavior for vendor event form.
 */

(function (Drupal) {
  'use strict';

  /**
   * Behavior for simple tab functionality.
   */
  Drupal.behaviors.simpleTabs = {
    attach: function (context, settings) {
      // Find all simple tab buttons and panes.
      var tabs = context.querySelectorAll('.mel-simple-tab');
      var panes = context.querySelectorAll('.mel-simple-tab-pane[data-simple-tab-pane]');
      
      if (!tabs.length || !panes.length) {
        return;
      }

      /**
       * Activates a specific tab by ID.
       *
       * @param {string} tabId
       *   The tab ID to activate (e.g., 'basics', 'tickets', 'design').
       */
      function activate(tabId) {
        // Update all tabs.
        for (var i = 0; i < tabs.length; i++) {
          var tab = tabs[i];
          var isActive = tab.getAttribute('data-simple-tab') === tabId;
          
          tab.classList.toggle('is-active', isActive);
          tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
          tab.setAttribute('tabindex', isActive ? '0' : '-1');
        }

        // Update all panes.
        for (var j = 0; j < panes.length; j++) {
          var pane = panes[j];
          var paneActive = pane.getAttribute('data-simple-tab-pane') === tabId;
          
          pane.classList.toggle('is-active', paneActive);
          pane.setAttribute('aria-hidden', paneActive ? 'false' : 'true');
        }
      }

      // Initialize: activate the first tab with is-active class, or 'basics' by default.
      var initialTab = 'basics';
      for (var k = 0; k < tabs.length; k++) {
        if (tabs[k].classList.contains('is-active')) {
          initialTab = tabs[k].getAttribute('data-simple-tab');
          break;
        }
      }
      activate(initialTab);

      // Attach click handlers to all tabs.
      for (var l = 0; l < tabs.length; l++) {
        tabs[l].addEventListener('click', function (e) {
          e.preventDefault();
          var targetTabId = this.getAttribute('data-simple-tab');
          if (targetTabId) {
            activate(targetTabId);
          }
        });
      }

      // Add keyboard navigation support.
      for (var m = 0; m < tabs.length; m++) {
        tabs[m].addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            var targetTabId = this.getAttribute('data-simple-tab');
            if (targetTabId) {
              activate(targetTabId);
            }
          }
        });
      }
    }
  };

})(Drupal);









