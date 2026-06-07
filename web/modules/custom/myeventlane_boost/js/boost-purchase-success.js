(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.melBoostPurchaseSuccessRedirect = {
    attach(context) {
      const settings = drupalSettings.myeventlaneBoostPurchaseSuccess || {};
      once('mel-boost-success-redirect', '[data-mel-boost-success-auto-redirect]', context).forEach((root) => {
        const url = settings.redirectUrl || root.getAttribute('data-mel-boost-success-redirect-url') || '';
        const delay = Number(settings.redirectDelayMs || root.getAttribute('data-mel-boost-success-redirect-delay') || 8000);
        if (!url || !Number.isFinite(delay) || delay <= 0) {
          return;
        }

        window.setTimeout(() => {
          window.location.assign(url);
        }, delay);
      });
    },
  };
}(Drupal, drupalSettings, once));
