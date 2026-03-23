/**
 * @file
 * Donation form JavaScript.
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Handles preset button clicks and custom amount input.
   */
  Drupal.behaviors.donationForm = {
    attach: function (context, settings) {
      // Handle preset button clicks.
      once('donation-preset', '.mel-donation-preset', context).forEach(function (element) {
        element.addEventListener('click', function (e) {
          e.preventDefault();
          var $button = $(element);
          var amount = parseFloat($button.data('amount'));

          // Remove active class from all presets.
          $('.mel-donation-preset').removeClass('active');
          // Add active class to clicked preset.
          $button.addClass('active');
          // Clear custom amount.
          $('.mel-donation-custom-input').val('');
          // Set selected amount.
          $('input[name="selected_amount"]').val(amount);
        });
      });

      // Handle custom amount input.
      once('donation-custom', '.mel-donation-custom-input', context).forEach(function (element) {
        element.addEventListener('input', function () {
          var $input = $(element);
          var amount = parseFloat($input.val()) || 0;

          // Remove active class from presets.
          $('.mel-donation-preset').removeClass('active');
          // Set selected amount.
          $('input[name="selected_amount"]').val(amount > 0 ? amount : '');
        });
      });
    }
  };

})(jQuery, Drupal, once);
