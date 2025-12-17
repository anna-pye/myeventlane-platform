/**
 * @file
 * Tooltip functionality for vendor onboarding help text.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.vendorOnboardTooltip = {
    attach: function (context, settings) {
      var trigger = context.querySelector('.mel-help-trigger');
      var tooltip = context.querySelector('.mel-help-tooltip');
      
      if (trigger && tooltip) {
        trigger.addEventListener('mouseenter', function () {
          tooltip.style.opacity = '1';
          tooltip.style.visibility = 'visible';
        });
        
        trigger.addEventListener('mouseleave', function () {
          tooltip.style.opacity = '0';
          tooltip.style.visibility = 'hidden';
        });
      }
    }
  };
})(Drupal);



















