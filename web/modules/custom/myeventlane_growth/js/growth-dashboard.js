/**
 * @file
 * Dismiss handlers for dashboard growth cards.
 */
(function (Drupal, once) {
  'use strict';

  function postDismiss(url, token, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
      },
      body: JSON.stringify(body),
    });
  }

  Drupal.behaviors.melGrowthDashboardCards = {
    attach(context) {
      const settings = drupalSettings.melGrowth || {};
      const dismissUrl = settings.dismissUrl;
      const token = settings.csrfToken;
      if (!dismissUrl || !token) {
        return;
      }
      once('mel-growth-dismiss', '[data-mel-growth-dismiss]', context).forEach((btn) => {
        btn.addEventListener('click', () => {
          const card = btn.closest('.mel-growth-card');
          if (!card) {
            return;
          }
          const key = card.getAttribute('data-insight-key') || '';
          const eidAttr = card.getAttribute('data-event-id');
          const eventId = eidAttr && eidAttr !== '' ? parseInt(eidAttr, 10) : null;
          postDismiss(dismissUrl, token, {
            insight_key: key,
            surface: 'dashboard',
            event_id: Number.isFinite(eventId) && eventId > 0 ? eventId : null,
          })
            .then((res) => {
              if (res.ok) {
                card.remove();
              }
            })
            .catch(() => {
              // Fail loudly in UI: keep card visible.
            });
        });
      });
    },
  };
})(Drupal, once);
