/**
 * @file
 * Tracks support panel click events.
 */
(function (drupalSettings) {
  'use strict';

  /**
   * Sends a click event for a support panel.
   *
   * @param {HTMLElement} el
   *   The link element that was clicked.
   */
  window.melHelpTrackClick = function (el) {
    if (!el || !el.dataset) {
      return;
    }
    const key = el.dataset.analyticsKey || '';
    if (!key) {
      return;
    }
    const base = drupalSettings?.path?.baseUrl ?? '/';
    const prefix = drupalSettings?.path?.pathPrefix ?? '';
    const endpoint = (base.endsWith('/') ? base : base + '/') + prefix + 'mel-help/click';

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ key }),
    }).catch((error) => {
      console.error('melHelpTrackClick failed', error);
    });
  };
})(drupalSettings);
