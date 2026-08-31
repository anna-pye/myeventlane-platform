(function (Drupal, once) {
  'use strict';

  function copyText(value, source) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(value);
    }

    source.focus();
    source.select();
    source.setSelectionRange(0, value.length);
    var copied = document.execCommand('copy');
    window.getSelection().removeAllRanges();
    return copied ? Promise.resolve() : Promise.reject(new Error('Copy failed'));
  }

  Drupal.behaviors.melPurchaseSurfaceCopy = {
    attach: function (context) {
      once('mel-purchase-surface-copy', '[data-mel-copy-target]', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var source = document.getElementById(button.getAttribute('data-mel-copy-target'));
          if (!source) {
            return;
          }
          var status = button.parentElement.querySelector('[role="status"]');
          var original = button.textContent;

          copyText(source.value, source).then(function () {
            button.textContent = Drupal.t('Copied');
            if (status) {
              status.textContent = Drupal.t('Embed code copied.');
            }
            window.setTimeout(function () {
              button.textContent = original;
              if (status) {
                status.textContent = '';
              }
            }, 2400);
          }).catch(function () {
            source.focus();
            source.select();
            if (status) {
              status.textContent = Drupal.t('Copy did not work. The code is selected so you can copy it manually.');
            }
          });
        });
      });
    }
  };
}(Drupal, once));
