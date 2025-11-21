// cSpell:ignore selectpicker
(function ($, Drupal, once) {
  Drupal.behaviors.bootstrapSelect = {
    attach: function attach(context) {
      once('bootstrap-select', '.selectpicker', context).forEach(function () {
        $('.selectpicker').selectpicker();
      });
    }
  };
})(jQuery, Drupal, once);
