(function (Drupal, once, drupalSettings) {
  const vendorPublicSettings = drupalSettings.melVendorPublic || {};
  let csrfToken = vendorPublicSettings.csrfToken || null;
  let csrfTokenPromise = null;

  const getCsrfToken = () => {
    if (csrfToken) {
      return Promise.resolve(csrfToken);
    }

    if (!csrfTokenPromise) {
      csrfTokenPromise = fetch(Drupal.url('session/token'), {
        credentials: 'same-origin',
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error(Drupal.t('Unable to get a security token.'));
          }
          return response.text();
        })
        .then((token) => {
          csrfToken = (token || '').trim();
          if (!csrfToken) {
            throw new Error(Drupal.t('Unable to get a security token.'));
          }
          return csrfToken;
        })
        .catch((error) => {
          csrfTokenPromise = null;
          throw error;
        });
    }

    return csrfTokenPromise;
  };

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
            const csrfToken = await getCsrfToken();
            const response = await fetch(followUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
              },
            });
            const contentType = response.headers.get('content-type') || '';
            const data = contentType.includes('application/json') ? await response.json() : {};
            if (!response.ok || data.status !== 'ok') {
              throw new Error(data.message || Drupal.t('Unable to update follow state.'));
            }

            button.textContent = data.label;
            button.classList.toggle('is-following', Boolean(data.following));
            button.setAttribute('aria-pressed', data.following ? 'true' : 'false');

            const followerCount = button.closest('.mel-vendor-card')?.querySelector('[data-mel-follower-count-value]');
            if (followerCount && typeof data.followers !== 'undefined') {
              followerCount.textContent = Drupal.formatPlural(
                Number(data.followers),
                '1 follower',
                '@count followers',
              );
              const followerStat = followerCount.closest('[data-mel-follower-count]');
              if (followerStat) {
                followerStat.setAttribute(
                  'aria-label',
                  Drupal.formatPlural(
                    Number(data.followers),
                    '1 follower',
                    '@count followers',
                  ),
                );
              }
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
          const eventClickToken = settings.csrfToken || csrfToken;
          if (!settings.eventClickUrl || !eventId || !eventClickToken) {
            return;
          }

          const payload = new FormData();
          payload.append('event_id', eventId);

          fetch(settings.eventClickUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload,
            keepalive: true,
            headers: {
              'X-CSRF-Token': eventClickToken,
              'X-Requested-With': 'XMLHttpRequest',
            },
          }).catch((error) => console.error(error));
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
