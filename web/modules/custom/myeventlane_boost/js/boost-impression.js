(function (Drupal, once, drupalSettings) {
  const sentKeys = window.__melBoostImpressionKeys = window.__melBoostImpressionKeys || new Set();

  const dedupeKey = (orderItemId, placement) => `${orderItemId}|${placement}`;

  const sendImpression = (orderItemId, eventId, placement) => {
    const key = dedupeKey(orderItemId, placement);
    if (sentKeys.has(key)) {
      return;
    }
    sentKeys.add(key);

    const impressionUrl = drupalSettings.melBoostImpression?.impressionUrl;
    if (!impressionUrl) {
      return;
    }

    const payload = JSON.stringify({
      order_item_id: orderItemId,
      event_id: eventId,
      placement,
    });

    if (typeof navigator.sendBeacon === 'function') {
      navigator.sendBeacon(
        impressionUrl,
        new Blob([payload], { type: 'application/json' }),
      );
      return;
    }

    fetch(impressionUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: payload,
      keepalive: true,
    }).catch(() => {});
  };

  Drupal.behaviors.melBoostImpression = {
    attach(context) {
      once('mel-boost-impression', '[data-mel-boost-impression]', context).forEach((element) => {
        const orderItemId = Number.parseInt(element.getAttribute('data-boost-order-item-id') || '', 10);
        const eventId = Number.parseInt(element.getAttribute('data-boost-event-id') || '', 10);
        const placement = (element.getAttribute('data-boost-placement') || '').trim();
        if (!Number.isFinite(orderItemId) || orderItemId <= 0) {
          return;
        }
        if (!Number.isFinite(eventId) || eventId <= 0) {
          return;
        }
        if (!placement) {
          return;
        }

        const key = dedupeKey(orderItemId, placement);
        if (sentKeys.has(key)) {
          return;
        }

        if ('IntersectionObserver' in window) {
          const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
              if (!entry.isIntersecting) {
                return;
              }
              sendImpression(orderItemId, eventId, placement);
              observer.disconnect();
            });
          }, {
            threshold: 0.25,
            rootMargin: '0px',
          });
          observer.observe(element);
          return;
        }

        sendImpression(orderItemId, eventId, placement);
      });
    },
  };
})(Drupal, once, drupalSettings);
