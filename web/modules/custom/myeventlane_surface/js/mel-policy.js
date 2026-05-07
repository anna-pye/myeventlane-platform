/**
 * @file
 * Progressive enhancement for MELOperationalPolicySystem (data attributes only).
 */
(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Applies non-authoritative shell hints from drupalSettings.melOperationalPolicy.
   */
  Drupal.behaviors.melOperationalPolicy = {
    attach(context) {
      const cfg = drupalSettings.melOperationalPolicy;
      if (!cfg || typeof cfg !== 'object') {
        return;
      }
      const roots = context.querySelectorAll('body');
      roots.forEach((body) => {
        if (cfg.surfaceProfile) {
          body.setAttribute('data-mel-policy-profile', String(cfg.surfaceProfile));
        }
        const hints = cfg.toneHints;
        if (hints && typeof hints === 'object') {
          Object.keys(hints).forEach((key) => {
            if (hints[key]) {
              body.setAttribute(`data-mel-policy-tone-${key.replace(/_/g, '-')}`, 'true');
            }
          });
        }
      });
    },
  };
})(Drupal, drupalSettings);
