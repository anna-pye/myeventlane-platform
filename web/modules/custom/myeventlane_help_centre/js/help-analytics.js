/**
 * @file
 * Tracks support panel analytics events.
 */
(function (drupalSettings) {
  'use strict';

  const base = drupalSettings?.path?.baseUrl ?? '/';
  const prefix = drupalSettings?.path?.pathPrefix ?? '';
  const buildEndpoint = (suffix) => (base.endsWith('/') ? base : `${base}/`) + prefix + suffix;

  const sendJson = (endpoint, payload, extraHeaders = {}) => fetch(endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...extraHeaders,
    },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  });

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

    const endpoint = buildEndpoint('mel-help/click');
    const payload = key === 'boost_event'
      ? { key, type: 'boost_click' }
      : { key };

    sendJson(endpoint, payload).catch((error) => {
      console.error('melHelpTrackClick failed', error);
    });
  };

  /**
   * Sends "Was this helpful?" feedback for a support panel.
   *
   * @param {string} key
   *   Analytics key for the support panel.
   * @param {boolean} helpful
   *   Whether the panel was helpful.
   */
  window.melHelpFeedback = function (key, helpful) {
    if (!key) {
      console.error('melHelpFeedback missing analytics key');
      return;
    }
    const csrfToken = drupalSettings?.ajaxPageState?.csrfToken;
    if (!csrfToken) {
      console.error('melHelpFeedback missing CSRF token');
      return;
    }

    const endpoint = buildEndpoint('mel-help/feedback');
    sendJson(endpoint, { key, helpful: !!helpful }, { 'X-CSRF-Token': csrfToken })
      .catch((error) => {
        console.error('melHelpFeedback failed', error);
      });
  };
})(drupalSettings);
