/**
 * @file
 * MELExperienceSystem — progressive enhancement for continuity regions only.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Ensures polite continuity regions expose aria-live for assistive tech.
   *
   * @param {HTMLElement} el
   */
  function bindExperienceLiveRegion(el) {
    var live = el.getAttribute('data-mel-experience-live');
    if (live && !el.getAttribute('aria-live')) {
      el.setAttribute('aria-live', live);
    }
    if (!el.getAttribute('role')) {
      el.setAttribute('role', 'status');
    }
  }

  Drupal.behaviors.melExperienceContinuation = {
    attach(context) {
      once('mel-experience-live', '[data-mel-experience-live]', context).forEach(bindExperienceLiveRegion);
    },
  };
})(Drupal, once);
