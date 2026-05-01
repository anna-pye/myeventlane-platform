(function (Drupal, once) {
  Drupal.behaviors.melDisableAjax = {
    attach: function (context) {
      once('mel-disable-ajax', 'form.mel-no-ajax', context).forEach(function (form) {
        // Remove Drupal AJAX attribute
        form.removeAttribute('data-drupal-ajax');
        // Remove jQuery AJAX bindings
        if (window.jQuery) {
          jQuery(form).off('submit');
        }
        // Force native submit
        form.addEventListener('submit', function () {
          console.log('MEL: Native POST enforced');
        });
      });
    }
  };
})(Drupal, once);
