/**
 * @file
 * MyEventLane Admin Theme - JavaScript enhancements.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Initialize admin theme enhancements.
   */
  function initAdminTheme() {
    // Enhance form validation with Bootstrap feedback.
    enhanceFormValidation();
  }

  /**
   * Enhance form validation with Bootstrap feedback.
   */
  function enhanceFormValidation() {
    const forms = once('mel-form-validation', '.mel-admin-form', document);
    
    forms.forEach(function (form) {
      // Add Bootstrap validation classes on submit.
      form.addEventListener('submit', function (e) {
        if (!form.checkValidity()) {
          e.preventDefault();
          e.stopPropagation();
        }
        
        form.classList.add('was-validated');
      }, false);
      
      // Real-time validation feedback.
      const inputs = form.querySelectorAll('input, select, textarea');
      inputs.forEach(function (input) {
        input.addEventListener('blur', function () {
          if (input.checkValidity()) {
            input.classList.remove('is-invalid');
            input.classList.add('is-valid');
          } else {
            input.classList.remove('is-valid');
            input.classList.add('is-invalid');
          }
        });
      });
    });
  }

  // Initialize on DOM ready.
  Drupal.behaviors.myeventlaneAdmin = {
    attach: function (context) {
      initAdminTheme();
    }
  };

})(Drupal, once);


















