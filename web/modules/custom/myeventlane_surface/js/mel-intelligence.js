/**
 * @file
 * MELIntelligenceSystem — progressive enhancement for guidance regions only.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Applies polite live-region semantics for intelligence panels.
   *
   * @param {HTMLElement} el
   */
  function bindIntelligenceLiveRegion(el) {
    var live = el.getAttribute('data-mel-intelligence-live');
    if (live && !el.getAttribute('aria-live')) {
      el.setAttribute('aria-live', live);
    }
    if (!el.getAttribute('role')) {
      el.setAttribute('role', 'region');
    }
  }

  Drupal.behaviors.melIntelligenceGuidance = {
    attach(context) {
      once(
        'mel-intelligence-live',
        '[data-mel-intelligence-live]',
        context,
      ).forEach(bindIntelligenceLiveRegion);
    },
  };
})(Drupal, once);
