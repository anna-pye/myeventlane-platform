/**
 * @file
 * VX2 One Attendee Workspace — immediate search and filters.
 */
(function (Drupal, once, drupalSettings) {
  'use strict';

  function emitAnalytics(name, detail) {
    try {
      document.dispatchEvent(
        new CustomEvent('mel:attendee-analytics', {
          detail: Object.assign({ event: name }, detail || {}),
        }),
      );
    } catch (e) {
      // Analytics hooks are best-effort until a pipeline is wired.
    }
  }

  function applyFilters(root) {
    const search = (root.querySelector('[data-mel-attendee-search]')?.value || '')
      .trim()
      .toLowerCase();
    const ticketType = (
      root.querySelector('[data-mel-attendee-ticket-type]')?.value || ''
    )
      .trim()
      .toLowerCase();
    const activeFilter =
      root.querySelector('[data-mel-attendee-filter].is-active')?.getAttribute(
        'data-mel-attendee-filter',
      ) || 'all';
    const cards = root.querySelectorAll('[data-mel-attendee-card]');
    const total = cards.length;
    let visible = 0;

    cards.forEach(function (card) {
      const name = card.getAttribute('data-name') || '';
      const email = card.getAttribute('data-email') || '';
      const type = card.getAttribute('data-ticket-type') || '';
      const filters = (card.getAttribute('data-filters') || '').split(/\s+/);
      const matchesSearch =
        !search || name.includes(search) || email.includes(search);
      const matchesType = !ticketType || type === ticketType;
      const matchesFilter =
        activeFilter === 'all' || filters.indexOf(activeFilter) !== -1;
      const show = matchesSearch && matchesType && matchesFilter;
      card.hidden = !show;
      if (show) {
        visible += 1;
      }
    });

    const status = root.querySelector('[data-mel-attendee-status]');
    if (status) {
      status.textContent =
        visible === 1
          ? Drupal.t('Showing 1 of @total attendees', { '@total': total })
          : Drupal.t('Showing @count of @total attendees', {
              '@count': visible,
              '@total': total,
            });
    }

    const emptyFilter = root.querySelector('[data-mel-attendee-empty-filter]');
    if (emptyFilter) {
      // Any narrowing (chip, search, or ticket type) with zero matches needs
      // guidance — including waitlist/rsvp/ticket/refunded/cancelled chips and
      // ?filter= from legacy redirects. "all" with no search keeps the list.
      const hasNarrowing =
        activeFilter !== 'all' || Boolean(search) || Boolean(ticketType);
      const showEmpty = visible === 0 && hasNarrowing;
      emptyFilter.hidden = !showEmpty;
      if (showEmpty && activeFilter === 'checked_in' && !search && !ticketType) {
        emptyFilter.querySelector('.mel-empty-state__title').textContent =
          Drupal.t('No checked-in guests');
        emptyFilter.querySelector('.mel-empty-state__text').textContent =
          Drupal.t('Door Mode will update this list as guests arrive.');
      } else if (showEmpty) {
        emptyFilter.querySelector('.mel-empty-state__title').textContent =
          Drupal.t('No matching attendees');
        emptyFilter.querySelector('.mel-empty-state__text').textContent =
          Drupal.t('Try another search or clear your filters.');
      }
    }

    const reset = root.querySelector('[data-mel-attendee-reset]');
    if (reset) {
      reset.hidden =
        activeFilter === 'all' && !Boolean(search) && !Boolean(ticketType);
    }

    const list = root.querySelector('[data-mel-attendee-list]');
    if (list) {
      list.hidden = visible === 0 && !!emptyFilter && !emptyFilter.hidden;
    }
  }

  Drupal.behaviors.melAttendeesWorkspace = {
    attach: function (context) {
      once('mel-attendees-workspace', '[data-mel-attendees-workspace]', context).forEach(
        function (root) {
          const initial =
            root.getAttribute('data-mel-initial-filter') ||
            drupalSettings.melAttendeesWorkspace?.initialFilter ||
            'all';

          root.querySelectorAll('[data-mel-attendee-filter]').forEach(function (btn) {
            const id = btn.getAttribute('data-mel-attendee-filter');
            const active = id === initial;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            btn.addEventListener('click', function () {
              root.querySelectorAll('[data-mel-attendee-filter]').forEach(function (other) {
                const on = other === btn;
                other.classList.toggle('is-active', on);
                other.setAttribute('aria-pressed', on ? 'true' : 'false');
              });
              emitAnalytics('attendee_filtered', {
                filter: id,
                eventId: drupalSettings.melAttendeesWorkspace?.eventId,
              });
              applyFilters(root);
            });
          });

          const search = root.querySelector('[data-mel-attendee-search]');
          if (search) {
            search.addEventListener('input', function () {
              applyFilters(root);
            });
          }

          const ticketSelect = root.querySelector('[data-mel-attendee-ticket-type]');
          if (ticketSelect) {
            ticketSelect.addEventListener('change', function () {
              emitAnalytics('attendee_filtered', {
                filter: 'ticket_type',
                ticketType: ticketSelect.value,
                eventId: drupalSettings.melAttendeesWorkspace?.eventId,
              });
              applyFilters(root);
            });
          }

          const reset = root.querySelector('[data-mel-attendee-reset]');
          if (reset) {
            reset.addEventListener('click', function () {
              if (search) {
                search.value = '';
              }
              if (ticketSelect) {
                ticketSelect.value = '';
              }
              root.querySelectorAll('[data-mel-attendee-filter]').forEach(function (button) {
                const active =
                  button.getAttribute('data-mel-attendee-filter') === 'all';
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
              });
              emitAnalytics('attendee_filtered', {
                filter: 'reset',
                eventId: drupalSettings.melAttendeesWorkspace?.eventId,
              });
              applyFilters(root);
            });
          }

          root.querySelectorAll('[data-mel-attendee-analytics]').forEach(function (el) {
            el.addEventListener('click', function () {
              const name = el.getAttribute('data-mel-attendee-analytics');
              if (name) {
                emitAnalytics(name, {
                  eventId: drupalSettings.melAttendeesWorkspace?.eventId,
                });
              }
            });
          });

          root.querySelectorAll('[data-mel-attendee-checkin]').forEach(function (form) {
            form.addEventListener('submit', function () {
              emitAnalytics('attendee_checked_in', {
                eventId: drupalSettings.melAttendeesWorkspace?.eventId,
              });
            });
          });

          applyFilters(root);
        },
      );
    },
  };
})(Drupal, once, drupalSettings);
