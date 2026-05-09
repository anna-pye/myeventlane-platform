/**
 * @file
 * Venue operations:
 *  - client-side attendee filter + search clear control
 *  - progressive-enhancement AJAX check-in (no page reload, no raw JSON
 *    page) backed by the same canonical writer used by the
 *    non-XHR form fallback (`MelAttendeeCheckinManager::checkInAttendee`
 *    via VendorAttendeeController::checkIn).
 *
 * Strategy:
 *  - Keep the inner <form method="post" action="..."> intact for
 *    no-JS / progressive-enhancement (the controller now redirects
 *    back to operations with a flash message instead of rendering
 *    raw JSON).
 *  - When JS is available, intercept submit and POST via fetch with
 *    `X-Requested-With: XMLHttpRequest` so the controller returns
 *    JSON and we update the row + metrics + progress ring in place.
 */
(function (Drupal, once) {
  'use strict';

  function normalise(s) {
    return String(s || '')
      .trim()
      .toLowerCase();
  }

  Drupal.behaviors.melVendorVenueOperations = {
    attach(context) {
      once('mel-vendor-venue-ops', '[data-mel-venue-operations]', context).forEach((root) => {
        const input = root.querySelector('#mel-venue-ops-search');
        const clearBtn = root.querySelector('[data-mel-ops-search-clear]');
        const items = () => root.querySelectorAll('[data-mel-ops-search-key]');

        const applyFilter = () => {
          if (!input) {
            return;
          }
          const q = normalise(input.value);
          let visible = 0;
          items().forEach((el) => {
            const key = normalise(el.getAttribute('data-mel-ops-search-key'));
            const show = q === '' || key.indexOf(q) !== -1;
            el.toggleAttribute('hidden', !show);
            if (show) {
              visible += 1;
            }
          });

          const empty = root.querySelector('[data-mel-ops-filter-empty]');
          if (empty) {
            empty.toggleAttribute('hidden', visible !== 0 || q === '');
          }

          if (clearBtn) {
            clearBtn.hidden = input.value.trim() === '';
          }
        };

        if (input) {
          input.addEventListener('input', applyFilter);
          input.addEventListener('search', applyFilter);
          if (clearBtn) {
            clearBtn.addEventListener('click', () => {
              input.value = '';
              input.focus();
              applyFilter();
            });
          }
          applyFilter();

          if (window.location.hash === '#mel-ops-attendee-search') {
            input.focus();
          }
        }
      });
    },
  };

  Drupal.behaviors.melVendorVenueOperationsCheckin = {
    attach(context) {
      once('mel-vendor-venue-ops-checkin', '[data-mel-venue-operations]', context).forEach((root) => {
        const statusEl = root.querySelector('[data-mel-ops-checkin-status]');
        const csrfToken = root.getAttribute('data-mel-ops-checkin-token') || '';

        const announce = (msg, level) => {
          if (typeof msg !== 'string' || msg === '') {
            return;
          }
          if (typeof Drupal.announce === 'function') {
            Drupal.announce(msg, level || 'polite');
          }
          if (statusEl) {
            statusEl.textContent = msg;
          }
        };

        const metricEl = (key) => {
          const card = root.querySelector('[data-mel-metric="' + key + '"]');
          if (!card) {
            return null;
          }
          return card.querySelector('[data-mel-metric-value]');
        };

        const readMetric = (key) => {
          const el = metricEl(key);
          if (!el) {
            return null;
          }
          const parsed = parseInt(String(el.textContent || '').trim(), 10);
          return Number.isFinite(parsed) ? parsed : null;
        };

        const writeMetric = (key, value) => {
          const el = metricEl(key);
          if (!el) {
            return;
          }
          el.textContent = String(value);
        };

        const pulseCheckedInMetric = () => {
          const inCard = root.querySelector('[data-mel-metric="in"]');
          if (!inCard) {
            return;
          }
          inCard.classList.add('mel-live-ops__stat-card--pulse');
          window.setTimeout(() => {
            inCard.classList.remove('mel-live-ops__stat-card--pulse');
          }, 460);
        };

        const nudgeProgressShell = () => {
          const shell = root.querySelector('[data-mel-ops-progress-shell]');
          if (!shell) {
            return;
          }
          shell.setAttribute('data-mel-ops-progress-delta', 'up');
          window.setTimeout(() => {
            shell.removeAttribute('data-mel-ops-progress-delta');
          }, 740);
        };

        const updateProgress = () => {
          const total = readMetric('total');
          const checkedIn = readMetric('in');
          if (total === null || checkedIn === null) {
            return;
          }
          const pct = total > 0 ? Math.round((checkedIn / total) * 100) : 0;
          window.requestAnimationFrame(() => {
            const shell = root.querySelector('[data-mel-ops-progress-shell]');
            if (shell) {
              shell.style.setProperty('--mel-ops-progress', String(pct));
            }
            const ring = root.querySelector('[data-mel-ops-progress-ring]');
            if (ring) {
              ring.setAttribute('aria-valuenow', String(pct));
            }
            const fill = root.querySelector('[data-mel-ops-progress-fill]');
            if (fill) {
              fill.style.width = pct + '%';
            }
            const percent = root.querySelector('[data-mel-ops-progress-percent]');
            if (percent) {
              percent.textContent = pct + '%';
            }
          });
        };

        const swapRowToCheckedIn = (form) => {
          const side = form.closest('[data-mel-ops-attendee-side]');
          const row = form.closest('.mel-live-ops__attendee-row');
          if (!side) {
            return;
          }
          const chip = side.querySelector('[data-mel-ops-state-chip]');
          if (chip) {
            chip.className = 'mel-live-ops__state-chip mel-live-ops__state-chip--checked_in';
            chip.textContent = Drupal.t('Checked in');
          }
          const pill = document.createElement('span');
          pill.className = 'mel-live-ops__done-pill';
          pill.setAttribute('aria-live', 'polite');
          pill.setAttribute('tabindex', '-1');
          pill.textContent = Drupal.t('Checked in');
          form.replaceWith(pill);
          if (row) {
            row.setAttribute('data-checked-in-state', '1');
            row.classList.add('mel-live-ops__attendee-row--checkin-settle');
            window.setTimeout(() => {
              row.classList.remove('mel-live-ops__attendee-row--checkin-settle');
            }, 980);
          }
          window.requestAnimationFrame(() => {
            pill.focus({ preventScroll: true });
          });
        };

        const handleSuccess = (form, payload) => {
          if (payload && payload.reason === 'already_checked_in') {
            announce(payload.message || Drupal.t('This guest was already checked in.'));
            swapRowToCheckedIn(form);
            return;
          }
          // Canonical transition: increment in, decrement remain + ready.
          const checkedIn = readMetric('in');
          if (checkedIn !== null) {
            writeMetric('in', checkedIn + 1);
          }
          const remain = readMetric('remain');
          if (remain !== null) {
            writeMetric('remain', Math.max(0, remain - 1));
          }
          const ready = readMetric('ready');
          if (ready !== null) {
            writeMetric('ready', Math.max(0, ready - 1));
          }
          updateProgress();
          nudgeProgressShell();
          pulseCheckedInMetric();
          swapRowToCheckedIn(form);
          announce((payload && payload.message) || Drupal.t('Checked in.'));
        };

        const handleFailure = (form, payload, status) => {
          const button = form.querySelector('button[type="submit"]');
          if (button) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
          }
          const message =
            (payload && payload.message)
            || (status === 403
              ? Drupal.t('You don\'t have access to complete that.')
              : status === 0
                ? Drupal.t('Connection issue — try again when you have signal.')
                : Drupal.t('We couldn’t finish check-in.'));
          announce(message, 'assertive');
          const row = form.closest('.mel-live-ops__attendee-row');
          if (row) {
            row.classList.add('mel-live-ops__attendee-row--checkin-alert');
            window.setTimeout(() => row.classList.remove('mel-live-ops__attendee-row--checkin-alert'), 720);
          }
          window.requestAnimationFrame(() => {
            if (button) {
              button.focus({ preventScroll: true });
            }
          });
        };

        root.addEventListener('submit', (event) => {
          const form = event.target;
          if (!(form instanceof HTMLFormElement)) {
            return;
          }
          if (!form.matches('[data-mel-ops-checkin]')) {
            return;
          }
          event.preventDefault();

          const button = form.querySelector('button[type="submit"]');
          if (button) {
            if (button.disabled) {
              return;
            }
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
          }

          // Per-purpose CSRF protection: the matching token also lives in
          // the form's hidden `_token` field for the no-JS fallback path.
          // Server validates either source against
          // MelVenueOperationsViewModelBuilder::CHECKIN_CSRF_ID.
          const headers = {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
          };
          if (csrfToken !== '') {
            headers['X-CSRF-Token'] = csrfToken;
          }

          fetch(form.action, {
            method: 'POST',
            headers,
            credentials: 'same-origin',
          })
            .then((response) => response.json().then((payload) => ({ status: response.status, ok: response.ok, payload })))
            .then(({ status, ok, payload }) => {
              if (ok && payload && payload.success) {
                handleSuccess(form, payload);
              }
              else {
                handleFailure(form, payload, status);
              }
            })
            .catch(() => {
              handleFailure(form, null, 0);
            });
        });
      });
    },
  };

})(Drupal, once);
