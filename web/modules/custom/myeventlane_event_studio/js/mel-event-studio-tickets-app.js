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

  /**
   * Starts a new ticket from a visual preset without saving anything.
   */
  function applyTicketPreset(button) {
    const details = document.getElementById('mel-add-ticket');
    if (!(details instanceof HTMLDetailsElement)) {
      return;
    }

    const kind = details.querySelector('[name="new_ticket[ticket_kind]"]');
    const title = details.querySelector('[name="new_ticket[title]"]');
    if (kind instanceof HTMLSelectElement && button.dataset.melTicketKind) {
      kind.value = button.dataset.melTicketKind;
      kind.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (title instanceof HTMLInputElement) {
      title.value = button.dataset.melTicketTitle || '';
      title.dispatchEvent(new Event('input', { bubbles: true }));
    }

    openAddTicketPanel(details);
    emitAnalytics('ticket_preset_selected', {
      preset: button.dataset.melTicketPreset || 'custom',
    });
  }

  /**
   * Clears one ticket card's sales window without submitting the form.
   */
  function resetSalesWindow(button) {
    const ticketId = button.dataset.melResetSalesWindow || '';
    if (!ticketId) {
      return;
    }

    const card = button.closest('.mel-event-studio-ticket-card');
    if (!(card instanceof HTMLElement)) {
      return;
    }

    const fields = card.querySelectorAll(
      '[name^="tickets[' + ticketId + '][sales_window]"]',
    );
    fields.forEach((field) => {
      if (field instanceof HTMLInputElement) {
        field.value = '';
        field.defaultValue = '';
        field.removeAttribute('value');
        field.setAttribute('autocomplete', 'off');
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        field.blur();
      }
    });

    const status = document.getElementById('mel-ticket-sales-window-status-' + ticketId);
    if (status instanceof HTMLElement) {
      status.textContent = Drupal.t('Sales window cleared. Save tickets to keep this change.');
    }

  }

  Drupal.behaviors.melEventStudioTicketsApp = {
    attach(context) {
      once('mel-tickets-delete-safe-default', 'input[name$="[actions][delete]"]', context).forEach((checkbox) => {
        checkbox.checked = false;
      });

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

      once('mel-ticket-preset', '[data-mel-ticket-preset]', context).forEach((button) => {
        button.addEventListener('click', () => applyTicketPreset(button));
      });

      once('mel-tickets-reset-sales-window', '[data-mel-reset-sales-window]', context).forEach((button) => {
        const ticketId = button.dataset.melResetSalesWindow || '';
        const card = button.closest('.mel-event-studio-ticket-card');
        if (ticketId && card instanceof HTMLElement) {
          card.querySelectorAll(
            '[name^="tickets[' + ticketId + '][sales_window]"]',
          ).forEach((field) => {
            if (field instanceof HTMLInputElement) {
              field.setAttribute('autocomplete', 'off');
            }
          });
        }
        button.addEventListener('click', () => resetSalesWindow(button));
      });
    },
  };
})(Drupal, once);
