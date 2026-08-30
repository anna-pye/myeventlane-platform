/**
 * @file
 * VX2 Tickets app: progressive disclosure analytics + Add Ticket sticky CTA.
 */
(function (Drupal, once, drupalSettings) {
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

  function clearSavedTicketSetup(details) {
    const select = details.querySelector('[data-mel-saved-ticket-setup]');
    const hydrated = details.querySelector('[data-mel-saved-ticket-setup-hydrated]');
    if (select instanceof HTMLSelectElement) {
      select.value = '';
    }
    if (hydrated instanceof HTMLInputElement) {
      hydrated.value = '0';
    }
  }

  /**
   * Starts a new ticket from a visual preset without saving anything.
   */
  function applyTicketPreset(button) {
    const details = document.getElementById('mel-add-ticket');
    if (!(details instanceof HTMLDetailsElement)) {
      return;
    }

    clearSavedTicketSetup(details);
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
   * Prefills the add-ticket fields from a private configuration-only setup.
   */
  function applySavedTicketSetup(select) {
    const details = document.getElementById('mel-add-ticket');
    if (!(details instanceof HTMLDetailsElement) || !(select instanceof HTMLSelectElement)) {
      return;
    }
    const hydrated = details.querySelector('[data-mel-saved-ticket-setup-hydrated]');
    const setupId = select.value;
    if (!setupId) {
      if (hydrated instanceof HTMLInputElement) {
        hydrated.value = '0';
      }
      return;
    }
    const setups = drupalSettings.myeventlaneEventStudio?.ticketSetups || {};
    const setup = setups[setupId];
    if (!setup) {
      return;
    }

    const assign = (selector, value, eventName) => {
      const field = details.querySelector(selector);
      if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement) {
        field.value = value;
        field.dispatchEvent(new Event(eventName || 'change', { bubbles: true }));
      }
    };
    assign('[name="new_ticket[ticket_kind]"]', setup.kind || 'paid');
    assign('[name="new_ticket[title]"]', setup.title || '', 'input');
    assign('[name="new_ticket[price_amount]"]', setup.price || '', 'input');
    assign('[name="new_ticket[capacity]"]', '', 'input');
    assign('[name="new_ticket[external_uri]"]', setup.externalUrl || '', 'input');
    assign('[name="new_ticket[visibility_mode]"]', setup.visibility || 'public');

    const status = details.querySelector('[name="new_ticket[status]"]');
    if (status instanceof HTMLInputElement) {
      status.checked = false;
      status.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const bestValue = details.querySelector('[name="new_ticket[field_is_best_value]"]');
    if (bestValue instanceof HTMLInputElement) {
      bestValue.checked = false;
    }
    if (hydrated instanceof HTMLInputElement) {
      hydrated.value = '1';
    }

    openAddTicketPanel(details);
    emitAnalytics('reusable_ticket_setup_selected', { setup_id: setupId });
  }

  /**
   * Shows one ticket editor while keeping every form control in the document.
   */
  function selectTicketEditor(form, ticketId, focusEditor) {
    const selectors = form.querySelectorAll('[data-mel-ticket-select]');
    const editors = form.querySelectorAll('[data-mel-ticket-editor]');
    let selectedEditor = null;

    selectors.forEach((button) => {
      const selected = button.dataset.melTicketSelect === ticketId;
      button.classList.toggle('is-selected', selected);
      button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
    editors.forEach((editor) => {
      const selected = editor.dataset.melTicketEditor === ticketId;
      editor.classList.toggle('is-selected', selected);
      editor.setAttribute('aria-hidden', selected ? 'false' : 'true');
      if (selected) {
        selectedEditor = editor;
      }
    });

    form.classList.add('is-master-detail-ready');
    if (focusEditor && selectedEditor instanceof HTMLElement) {
      const heading = selectedEditor.querySelector('input[name$="[title]"]');
      if (heading instanceof HTMLElement) {
        heading.focus({ preventScroll: true });
      }
    }
  }

  /**
   * Keeps the compact ticket list in sync with unsaved name and price edits.
   */
  function updateTicketSelector(form, field) {
    const card = field.closest('[data-mel-ticket-editor]');
    if (!(card instanceof HTMLElement)) {
      return;
    }
    const ticketId = card.dataset.melTicketEditor || '';
    const selector = form.querySelector('[data-mel-ticket-select="' + ticketId + '"]');
    if (!(selector instanceof HTMLElement)) {
      return;
    }

    const nameField = card.querySelector('input[name$="[title]"]');
    const priceField = card.querySelector('input[name$="[price_amount]"]');
    const name = nameField instanceof HTMLInputElement && nameField.value.trim()
      ? nameField.value.trim()
      : Drupal.t('Untitled ticket');
    const price = priceField instanceof HTMLInputElement && priceField.value !== ''
      ? '$' + Number(priceField.value).toFixed(2).replace(/\.00$/, '')
      : selector.dataset.melTicketSelectorPrice || Drupal.t('Free');
    const nameLabel = selector.querySelector('[data-mel-ticket-selector-name-label]');
    const priceLabel = selector.querySelector('[data-mel-ticket-selector-price-label]');
    const editorHeading = card.querySelector('[data-mel-ticket-editor-heading]');
    const status = selector.dataset.melTicketSelectorStatus || Drupal.t('Draft');
    if (nameLabel instanceof HTMLElement) {
      nameLabel.textContent = name;
    }
    if (priceLabel instanceof HTMLElement) {
      priceLabel.textContent = price;
    }
    if (editorHeading instanceof HTMLElement) {
      editorHeading.textContent = name;
    }
    selector.setAttribute('aria-label', Drupal.t('Edit @name, @price, @status', {
      '@name': name,
      '@price': price,
      '@status': status,
    }));
    selector.dataset.melTicketSelectorName = name;
    selector.dataset.melTicketSelectorPrice = price;
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

      once('mel-saved-ticket-setup-remove-safe-default', '[data-mel-saved-ticket-setup-remove]', context).forEach((checkbox) => {
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

      once('mel-ticket-add', '[data-mel-ticket-add]', context).forEach((link) => {
        link.addEventListener('click', (event) => {
          const details = document.getElementById('mel-add-ticket');
          if (!(details instanceof HTMLDetailsElement)) {
            return;
          }
          event.preventDefault();
          openAddTicketPanel(details);
        });
      });

      once('mel-ticket-master-detail', '.mel-event-studio-operational-tickets', context).forEach((form) => {
        const selected = form.querySelector('[data-mel-ticket-select][aria-pressed="true"]')
          || form.querySelector('[data-mel-ticket-select]');
        if (selected instanceof HTMLElement) {
          selectTicketEditor(form, selected.dataset.melTicketSelect || '', false);
        }

        form.querySelectorAll('[data-mel-ticket-select]').forEach((button) => {
          button.addEventListener('click', () => {
            selectTicketEditor(form, button.dataset.melTicketSelect || '', true);
            emitAnalytics('ticket_editor_selected', {
              ticket_id: button.dataset.melTicketSelect || null,
            });
          });
        });

        form.querySelectorAll('input[name$="[title]"], input[name$="[price_amount]"]').forEach((field) => {
          field.addEventListener('input', () => updateTicketSelector(form, field));
        });
      });

      once('mel-ticket-preset', '[data-mel-ticket-preset]', context).forEach((button) => {
        button.addEventListener('click', () => applyTicketPreset(button));
      });

      once('mel-saved-ticket-setup', '[data-mel-saved-ticket-setup]', context).forEach((select) => {
        select.addEventListener('change', () => applySavedTicketSetup(select));
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
})(Drupal, once, drupalSettings);
