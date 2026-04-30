(function (Drupal, once, drupalSettings) {
  Drupal.behaviors.melVendorPublic = {
    attach(context) {
      once('mel-vendor-follow', '.mel-follow-button[data-follow-url]', context).forEach((button) => {
        button.addEventListener('click', async (event) => {
          event.preventDefault();

          const followUrl = button.getAttribute('data-follow-url');
          if (!followUrl || button.getAttribute('aria-disabled') === 'true') {
            return;
          }

          button.disabled = true;
          try {
            const response = await fetch(followUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
            });
            const data = await response.json();
            if (!response.ok || data.status !== 'ok') {
              throw new Error(data.message || Drupal.t('Unable to update follow state.'));
            }

            button.textContent = data.label;
            button.classList.toggle('is-following', Boolean(data.following));
            button.setAttribute('aria-pressed', data.following ? 'true' : 'false');

            const followerCount = context.querySelector('[data-mel-follower-count]');
            if (followerCount && typeof data.followers !== 'undefined') {
              followerCount.textContent = Drupal.formatPlural(
                Number(data.followers),
                '1 follower',
                '@count followers',
              );
            }
          }
          catch (error) {
            // Keep failures visible in the browser console; server-side errors are logged in PHP.
            console.error(error);
          }
          finally {
            button.disabled = false;
          }
        });
      });

      once('mel-event-click-analytics', '[data-mel-track-event-click]', context).forEach((link) => {
        link.addEventListener('click', () => {
          const settings = drupalSettings.melPublicAnalytics || {};
          const eventId = link.getAttribute('data-event-id');
          if (!settings.eventClickUrl || !eventId) {
            return;
          }

          const payload = new FormData();
          payload.append('event_id', eventId);
          if (navigator.sendBeacon) {
            navigator.sendBeacon(settings.eventClickUrl, payload);
            return;
          }

          fetch(settings.eventClickUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload,
            keepalive: true,
          }).catch((error) => console.error(error));
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
