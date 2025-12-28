/**
 * @file
 * Fixes form action URLs on vendor domain to remove /vendor/ prefix.
 */

(function (Drupal) {
  'use strict';

  /**
   * Fixes form action URLs that have /vendor/form_action_ prefix.
   */
  Drupal.behaviors.fixFormAction = {
    attach: function (context) {
      // Find all forms and fix their action URLs.
      const forms = context.querySelectorAll('form[action*="/vendor/form_action_"]');
      forms.forEach(function (form) {
        if (form.action && form.action.includes('/vendor/form_action_')) {
          form.action = form.action.replace('/vendor/form_action_', '/form_action_');
        }
      });

      // Also watch for dynamically added forms.
      const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
          mutation.addedNodes.forEach(function (node) {
            if (node.nodeType === 1) { // Element node
              const forms = node.querySelectorAll ? node.querySelectorAll('form[action*="/vendor/form_action_"]') : [];
              forms.forEach(function (form) {
                if (form.action && form.action.includes('/vendor/form_action_')) {
                  form.action = form.action.replace('/vendor/form_action_', '/form_action_');
                }
              });
              // Also check if the node itself is a form.
              if (node.tagName === 'FORM' && node.action && node.action.includes('/vendor/form_action_')) {
                node.action = node.action.replace('/vendor/form_action_', '/form_action_');
              }
            }
          });
        });
      });

      observer.observe(document.body, {
        childList: true,
        subtree: true
      });
    }
  };

})(Drupal);
