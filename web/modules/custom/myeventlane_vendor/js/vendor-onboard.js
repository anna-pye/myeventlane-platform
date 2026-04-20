(function (Drupal) {
  Drupal.behaviors.vendorOnboard = {
    attach: function (context) {
      const input = context.querySelector('input[name*="name"]');
      if (input) {
        input.focus();
      }
    }
  };
})(Drupal);
