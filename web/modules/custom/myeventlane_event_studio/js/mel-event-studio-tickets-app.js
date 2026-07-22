/**
 * @file
 * VX2 Tickets app: progressive disclosure analytics + Add Ticket sticky CTA.
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

  /**
   * Opens the Add Ticket details panel and focuses the first useful control.
   *
   * Workspace routes load shell JS only — not mel-event-studio.js — so fragment
   * links alone leave a closed <details> collapsed after scroll.
   */
  function openAddTicketPanel(details) {
    if (!(details instanceof HTMLDetailsElement)) {
      return;
    }
    details.open = true;
    window.requestAnimationFrame(() => {
      details.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      const focusTarget = details.querySelector(
        'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])',
      );
      if (focusTarget instanceof HTMLElement) {
        focusTarget.focus({ preventScroll: true });
      }
    });
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

      once('mel-tickets-sticky-add', '.mel-event-studio-ticket-sticky-add__button', context).forEach((link) => {
        link.addEventListener('click', (event) => {
          const href = link.getAttribute('href') || '';
          if (!href.startsWith('#')) {
            return;
          }
          const targetId = href.slice(1);
          if (!targetId) {
            return;
          }
          const details = document.getElementById(targetId);
          if (!(details instanceof HTMLDetailsElement)) {
            return;
          }
          event.preventDefault();
          openAddTicketPanel(details);
        });
      });
    },
  };
})(Drupal, once);
