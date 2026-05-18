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
        if (toast.classList.contains('is-leaving')) {
          return;
        }
        toast.classList.add('is-leaving');

        var removed = false;
        var detach = function () {
          if (removed || !toast || !toast.parentNode) {
            return;
          }
          removed = true;
          toast.parentNode.removeChild(toast);
        };

        var onTransitionEnd = function (event) {
          if (event.target !== toast) {
            return;
          }
          if (event.propertyName !== 'opacity' && event.propertyName !== 'transform') {
            return;
          }
          toast.removeEventListener('transitionend', onTransitionEnd);
          detach();
        };

        toast.addEventListener('transitionend', onTransitionEnd);
        // Match .mel-toast.is-leaving transition (200ms); fallback if transitionend does not fire.
        window.setTimeout(function () {
          toast.removeEventListener('transitionend', onTransitionEnd);
          detach();
        }, 220);
      };

      if (close) {
        close.addEventListener('click', remove);
      }

      var isStatus = toast.classList.contains('mel-toast--status');
      var isInfo = toast.classList.contains('mel-toast--info');

      if (isStatus || isInfo) {
        window.setTimeout(remove, 5500);
      }
    });
  }

  Drupal.behaviors.melToasts = {
    attach: function (context) {
      initToasts(context);
    }
  };
})(Drupal);
