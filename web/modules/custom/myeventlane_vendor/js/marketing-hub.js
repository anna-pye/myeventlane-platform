/**
 * @file
 * Marketing hub share controls (copy link + live status).
 */
(function (Drupal, once) {
  'use strict';

  /**
   * Copies text to the clipboard with a textarea fallback.
   *
   * @param {string} text
   * @return {Promise<void>}
   */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      const area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.left = '-9999px';
      document.body.appendChild(area);
      area.select();
      try {
        const ok = document.execCommand('copy');
        document.body.removeChild(area);
        if (ok) {
          resolve();
        }
        else {
          reject(new Error('Copy command failed'));
        }
      }
      catch (e) {
        document.body.removeChild(area);
        reject(e);
      }
    });
  }

  Drupal.behaviors.melMarketingHub = {
    attach(context) {
      once('mel-marketing-hub-copy', '[data-mel-share-copy]', context).forEach(function (button) {
        button.addEventListener('click', function () {
          const url = button.getAttribute('data-copy-url') || '';
          const card = button.closest('[data-mel-share-card]');
          const status = card ? card.querySelector('[data-mel-copy-status]') : null;
          if (!url) {
            if (status) {
              status.textContent = Drupal.t('Nothing to copy yet.');
            }
            return;
          }
          copyText(url)
            .then(function () {
              if (status) {
                status.textContent = Drupal.t('Link copied. Paste it wherever your community is.');
              }
              button.setAttribute('aria-label', Drupal.t('Link copied'));
              window.setTimeout(function () {
                button.removeAttribute('aria-label');
              }, 2000);
            })
            .catch(function () {
              if (status) {
                status.textContent = Drupal.t('Could not copy automatically. Select the link and copy it manually.');
              }
            });
        });
      });
    },
  };
})(Drupal, once);
