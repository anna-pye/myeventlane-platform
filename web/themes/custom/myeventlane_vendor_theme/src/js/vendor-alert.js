/**
 * @file
 * Vendor Alert dismiss — sessionStorage only, per-alert-type.
 * Hides or removes .mel-vendor-alert based on mel_vendor_alert_dismissed_<type>.
 */

(function () {
  'use strict';

  function getSessionStorage() {
    try {
      return typeof window !== 'undefined' && window.sessionStorage ? window.sessionStorage : null;
    } catch (e) {
      return null;
    }
  }

  function init() {
    var alertEl = document.querySelector('.mel-vendor-alert');
    if (!alertEl) return;

    var type = alertEl.getAttribute('data-alert-type') || 'info';
    var key = 'mel_vendor_alert_dismissed_' + type;
    var storage = getSessionStorage();

    if (storage) {
      try {
        if (storage.getItem(key)) {
          alertEl.style.display = 'none';
          return;
        }
      } catch (e) {
        // sessionStorage unavailable or access denied
      }
    }

    var dismissBtn = alertEl.querySelector('.mel-vendor-alert__dismiss');
    if (dismissBtn) {
      dismissBtn.addEventListener('click', function () {
        if (storage) {
          try {
            storage.setItem(key, '1');
          } catch (e) {}
        }
        alertEl.remove();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
