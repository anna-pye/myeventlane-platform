(function (Drupal) {
  function initToasts(context) {
    var root = context || document;

    var toasts = root.querySelectorAll('[data-mel-toast]');
    if (!toasts || !toasts.length) {
      return;
    }

    toasts.forEach(function (toast) {
      if (toast.dataset.melToastInit === '1') {
        return;
      }
      toast.dataset.melToastInit = '1';

      var close = toast.querySelector('[data-mel-toast-close]');

      var remove = function () {
        toast.classList.add('is-leaving');
        window.setTimeout(function () {
          if (toast && toast.parentNode) {
            toast.parentNode.removeChild(toast);
          }
        }, 180);
      };

      if (close) {
        close.addEventListener('click', remove);
      }

      var isStatus = toast.classList.contains('mel-toast--status');
      var isInfo = toast.classList.contains('mel-toast--info');

      if (isStatus || isInfo) {
        window.setTimeout(remove, 4000);
      }
    });
  }

  Drupal.behaviors.melToasts = {
    attach: function (context) {
      initToasts(context);
    }
  };
})(Drupal);
