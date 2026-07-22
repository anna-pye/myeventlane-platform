/**
 * @file
 * VX2 Tickets app progressive-disclosure analytics hooks.
 */
(function (Drupal, once) {
  'use strict';

  function emitAnalytics(eventName, detail) {
    const payload = Object.assign({ mel_analytics_event: eventName }, detail || {});
    document.dispatchEvent(new CustomEvent('mel:analytics', { detail: payload }));
    if (window.dataLayer && typeof window.dataLayer.push === 'function') {
      window.dataLayer.push(payload);
    }
  }

  Drupal.behaviors.melEventStudioTicketsApp = {
    attach(context) {
      once('mel-tickets-advanced-tools', '.mel-event-studio-advanced-tools', context).forEach((details) => {
        details.addEventListener('toggle', () => {
          if (!details.open) {
            return;
          }
          if (details.dataset.melAnalyticsFired === '1') {
            return;
          }
          details.dataset.melAnalyticsFired = '1';
          emitAnalytics(details.dataset.melAnalyticsEvent || 'advanced_tools_opened', {
            event_id: details.dataset.melEventId || null,
          });
        });
      });
    },
  };
})(Drupal, once);
