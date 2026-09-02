/**
 * @file
 * Booking page: live subtotal + order summary (visual only; form stays source of truth).
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * @param {string} currencyCode
   * @param {string} locale
   * @returns {Intl.NumberFormat}
   */
  function getMoneyFormatter(currencyCode, locale) {
    const code = currencyCode || 'AUD';
    const loc = locale || undefined;
    try {
      return new Intl.NumberFormat(loc, { style: 'currency', currency: code });
    } catch (e) {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: code });
    }
  }

  /**
   * Parse a formatted price string (e.g. "A$150.00", "1,234.50 AUD").
   *
   * @param {string} text
   * @returns {number}
   */
  function parsePriceFromText(text) {
    if (!text) {
      return 0;
    }
    const normalized = String(text).replace(/\u00a0/g, ' ').trim();
    const match = normalized.match(/-?\d[\d.,]*/);
    if (!match) {
      return 0;
    }
    let numStr = match[0].replace(/,/g, '');
    const n = parseFloat(numStr, 10);
    return Number.isFinite(n) ? n : 0;
  }

  /**
   * @param {HTMLFormElement} form
   * @returns {number}
   */
  function getOptionalDonation(form) {
    const input = form.querySelector('[name="mel_donation"]');
    if (!input || input.disabled) {
      return 0;
    }
    const raw = parseFloat(String(input.value), 10);
    return Number.isFinite(raw) && raw > 0 ? raw : 0;
  }

  /**
   * @param {EventTarget|null} t
   * @returns {boolean}
   */
  function isDonationControl(t) {
    return t instanceof HTMLElement && t.matches('[name="mel_donation"]');
  }

  /**
   * @param {HTMLFormElement} form
   * @returns {HTMLElement[]}
   */
  function getTicketRows(form) {
    const withAttr = Array.from(form.querySelectorAll('.mel-ticket-row[data-ticket-price-number]'));
    if (withAttr.length > 0) {
      return withAttr;
    }
    return Array.from(form.querySelectorAll('.mel-ticket-row'));
  }

  /**
   * @param {HTMLElement} row
   * @returns {HTMLInputElement|HTMLSelectElement|null}
   */
  function getQtyInput(row) {
    return (
      row.querySelector(
        'select.mel-ticket-quantity, input.mel-ticket-quantity, select[name*="quantity"], input[name*="quantity"], input[type="number"]',
      ) || null
    );
  }

  /**
   * @param {EventTarget|null} t
   * @returns {boolean}
   */
  function isQuantityControl(t) {
    if (!(t instanceof HTMLElement)) {
      return false;
    }
    if (!t.closest('.mel-ticket-row')) {
      return false;
    }
    if (t.classList.contains('mel-ticket-quantity')) {
      return true;
    }
    if (t instanceof HTMLSelectElement) {
      return true;
    }
    if (t instanceof HTMLInputElement && t.type === 'number') {
      return true;
    }
    return false;
  }

  /**
   * @param {HTMLElement} row
   * @returns {number}
   */
  function getQuantity(row) {
    const input = getQtyInput(row);
    if (!input || input.disabled) {
      return 0;
    }
    const raw = parseInt(String(input.value), 10);
    return Number.isFinite(raw) && raw > 0 ? raw : 0;
  }

  /**
   * @param {HTMLElement} row
   * @returns {number}
   */
  function getUnitPrice(row) {
    const attr = row.getAttribute('data-ticket-price-number');
    if (attr) {
      const n = parseFloat(String(attr).replace(/,/g, ''), 10);
      if (Number.isFinite(n)) {
        return n;
      }
    }
    const priceEl = row.querySelector('.mel-ticket-price');
    if (priceEl) {
      return parsePriceFromText(priceEl.textContent || '');
    }
    return 0;
  }

  /**
   * @param {HTMLElement} flow
   * @returns {{ container: Document|HTMLElement, empty: HTMLElement|null, items: HTMLElement|null, subtotalWrap: HTMLElement|null, subtotalValue: HTMLElement|null, mobileBar: HTMLElement|null, countEl: HTMLElement|null, totalEl: HTMLElement|null, submit: HTMLElement|null }}
   */
  function getTargets(flow) {
    const root =
      flow.closest('.mel-booking-v2') ||
      document.querySelector('.mel-booking-v2') ||
      document;
    return {
      container: root,
      empty: root.querySelector('[data-mel-summary-empty]'),
      items: root.querySelector('[data-mel-summary-items]'),
      subtotalWrap: root.querySelector('[data-mel-summary-subtotal]'),
      subtotalValue: root.querySelector('[data-mel-summary-subtotal-value]'),
      mobileBar: flow.querySelector('[data-mel-mobile-booking-bar]'),
      countEl: flow.querySelector('[data-mel-booking-count]'),
      totalEl: flow.querySelector('[data-mel-booking-total]'),
      submit: flow.querySelector('.mel-add-to-cart-button, input[type="submit"].button--primary, button[type="submit"].button--primary'),
    };
  }

  /**
   * @param {HTMLElement} card
   * @returns {Record<string, {number?: string, currency?: string, label?: string}>}
   */
  function getExtraPricesBySize(card) {
    const raw = card.getAttribute('data-extra-prices');
    if (!raw) {
      return {};
    }
    try {
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  /**
   * @param {HTMLElement} card
   * @returns {string}
   */
  function getSelectedExtraSizeKey(card) {
    const checked = card.querySelector('input[type="radio"][name*="selected_size"]:checked');
    if (checked instanceof HTMLInputElement) {
      return checked.value;
    }
    const selectedChip = card.querySelector(
      '.mel-event-extra-card__sizes .form-type-radio.is-selected input[type="radio"]',
    );
    if (selectedChip instanceof HTMLInputElement) {
      return selectedChip.value;
    }
    return '';
  }

  /**
   * @param {HTMLElement} card
   * @returns {number}
   */
  function getExtraQuantity(card) {
    const input = card.querySelector('.mel-event-extra-card__qty-input');
    if (input && !input.disabled) {
      const raw = parseInt(String(input.value), 10);
      if (Number.isFinite(raw) && raw > 0) {
        return raw;
      }
    }
    const display = card.querySelector('.mel-event-extra-card__qty-value');
    if (display) {
      const raw = parseInt(String(display.textContent || '').trim(), 10);
      if (Number.isFinite(raw) && raw > 0) {
        return raw;
      }
    }
    return 0;
  }

  /**
   * @param {HTMLElement} card
   * @param {string} sizeKey
   * @returns {string}
   */
  function getExtraMergeKey(card, sizeKey) {
    const productId = card.getAttribute('data-product-id') || '';
    return `${productId}:${sizeKey || 'default'}`;
  }

  /**
   * @param {HTMLElement} card
   * @returns {number}
   */
  function getExtraUnitPrice(card) {
    const sizeKey = getSelectedExtraSizeKey(card);
    const prices = getExtraPricesBySize(card);
    if (sizeKey && prices[sizeKey] && prices[sizeKey].number) {
      const n = parseFloat(String(prices[sizeKey].number).replace(/,/g, ''), 10);
      if (Number.isFinite(n)) {
        return n;
      }
    }
    const attr = card.getAttribute('data-extra-price-number');
    if (attr) {
      const n = parseFloat(String(attr).replace(/,/g, ''), 10);
      if (Number.isFinite(n)) {
        return n;
      }
    }
    const priceEl = card.querySelector('.mel-event-extra-card__price');
    if (priceEl) {
      return parsePriceFromText(priceEl.textContent || '');
    }
    return 0;
  }

  /**
   * @param {HTMLElement} flow
   * @param {object} strings
   * @returns {{ title: string, qty: number, line: number }[]}
   */
  function getExtraSummaryLines(flow, strings) {
    /** @type {{ title: string, qty: number, line: number, mergeKey: string }[]} */
    const lines = [];
    flow.querySelectorAll('.mel-event-extra-card').forEach((card) => {
      const qty = getExtraQuantity(card);
      if (qty <= 0) {
        return;
      }
      const requiresSize = card.getAttribute('data-requires-size') === '1';
      const sizeKey = getSelectedExtraSizeKey(card);
      if (requiresSize && !sizeKey) {
        return;
      }
      const title =
        card.getAttribute('data-extra-title') ||
        card.querySelector('.mel-event-extra-card__title')?.textContent?.trim() ||
        strings.extraFallback ||
        'Event extra';
      let label = title;
      if (sizeKey) {
        const prices = getExtraPricesBySize(card);
        const sizeLabel = prices[sizeKey]?.label || sizeKey.toUpperCase();
        label = `${title} (${sizeLabel})`;
      }
      const unit = getExtraUnitPrice(card);
      lines.push({
        title: label,
        qty,
        line: qty * unit,
        mergeKey: getExtraMergeKey(card, sizeKey),
      });
    });
    return lines;
  }

  /**
   * @param {object} settings
   * @returns {{ title: string, qty: number, line: number, mergeKey: string }[]}
   */
  function getCartExtraSummaryLines(settings) {
    const raw = settings.cartExtras;
    if (!Array.isArray(raw) || raw.length === 0) {
      return [];
    }
    /** @type {{ title: string, qty: number, line: number, mergeKey: string }[]} */
    const lines = [];
    raw.forEach((entry) => {
      if (!entry || typeof entry !== 'object') {
        return;
      }
      const qty = parseInt(String(entry.qty ?? ''), 10);
      if (!Number.isFinite(qty) || qty < 1) {
        return;
      }
      const unit = parseFloat(String(entry.unit_number ?? '').replace(/,/g, ''), 10);
      if (!Number.isFinite(unit)) {
        return;
      }
      const title = String(entry.title ?? '').trim();
      if (title === '') {
        return;
      }
      const mergeKey = String(entry.merge_key ?? '').trim();
      lines.push({
        title,
        qty,
        line: qty * unit,
        mergeKey: mergeKey !== '' ? mergeKey : `cart:${title}`,
      });
    });
    return lines;
  }

  /**
   * @param {{ title: string, qty: number, line: number, mergeKey: string }[]} cartLines
   * @param {{ title: string, qty: number, line: number, mergeKey: string }[]} formLines
   * @returns {{ title: string, qty: number, line: number }[]}
   */
  function mergeExtraSummaryLines(cartLines, formLines) {
    /** @type {Map<string, { title: string, qty: number, line: number }>} */
    const merged = new Map();
    cartLines.forEach((line) => {
      merged.set(line.mergeKey, {
        title: line.title,
        qty: line.qty,
        line: line.line,
      });
    });
    formLines.forEach((line) => {
      merged.set(line.mergeKey, {
        title: line.title,
        qty: line.qty,
        line: line.line,
      });
    });
    return Array.from(merged.values());
  }

  /**
   * @param {HTMLElement} flow
   * @param {HTMLFormElement} ticketForm
   * @param {object} settings
   */
  function bindBookingFlow(flow, ticketForm, settings) {
    const fmt = getMoneyFormatter(settings.currencyCode, settings.locale);
    const targets = getTargets(flow);
    const submitDisabledByServer =
      (targets.submit instanceof HTMLButtonElement || targets.submit instanceof HTMLInputElement) &&
      targets.submit.disabled;
    let announceTimer = null;
    let lastAnnounce = '';

    const strings = settings.strings || {};

    const appendSummaryLine = (ul, line, extraClass) => {
      const li = document.createElement('li');
      li.className = extraClass
        ? `mel-booking-summary__item ${extraClass}`
        : 'mel-booking-summary__item';
      const label = document.createElement('span');
      label.className = 'mel-booking-summary__item-label';
      label.textContent = `${line.qty} × ${line.title}`;
      const amt = document.createElement('span');
      amt.className = 'mel-booking-summary__item-amount';
      amt.textContent = fmt.format(line.line);
      li.appendChild(label);
      li.appendChild(amt);
      ul.appendChild(li);
    };

    const sync = () => {
      const rows = getTicketRows(ticketForm);
      let ticketQty = 0;
      let subtotal = 0;
      /** @type {{ title: string, qty: number, line: number }[]} */
      const ticketLines = [];

      rows.forEach((row) => {
        const qty = getQuantity(row);
        const unit = getUnitPrice(row);
        if (qty <= 0) {
          return;
        }
        const title =
          row.getAttribute('data-ticket-title') ||
          row.querySelector('.mel-ticket-label')?.textContent?.trim() ||
          strings.ticketFallback ||
          'Ticket';
        ticketQty += qty;
        const line = qty * unit;
        subtotal += line;
        ticketLines.push({ title, qty, line });
      });

      const formExtraLines = getExtraSummaryLines(flow, strings);
      const cartExtraLines = getCartExtraSummaryLines(settings);
      const extraLines = mergeExtraSummaryLines(cartExtraLines, formExtraLines);
      let extraQty = 0;
      extraLines.forEach((l) => {
        extraQty += l.qty;
        subtotal += l.line;
      });

      const hasTickets = ticketQty > 0;
      const hasExtras = extraLines.length > 0;
      const hasSelection = hasTickets || hasExtras;
      const donationLine = hasTickets ? getOptionalDonation(ticketForm) : 0;
      const displayTotal = subtotal + donationLine;

      if (targets.empty) {
        targets.empty.hidden = hasSelection;
      }
      if (targets.items) {
        if (!hasSelection) {
          targets.items.innerHTML = '';
        } else {
          const ul = document.createElement('ul');
          ul.className = 'mel-booking-summary__list';
          ticketLines.forEach((l) => appendSummaryLine(ul, l, ''));
          extraLines.forEach((l) => appendSummaryLine(ul, l, 'mel-booking-summary__item--extra'));
          if (donationLine > 0) {
            appendSummaryLine(
              ul,
              { title: strings.donationLine || 'Contribution', qty: 1, line: donationLine },
              'mel-booking-summary__item--donation',
            );
          }
          targets.items.innerHTML = '';
          targets.items.appendChild(ul);
        }
      }

      if (targets.subtotalWrap && targets.subtotalValue) {
        targets.subtotalWrap.hidden = !hasSelection;
        targets.subtotalValue.textContent = hasSelection ? fmt.format(displayTotal) : '';
      }

      const totalItemQty = ticketQty + extraQty;
      if (targets.countEl) {
        if (totalItemQty === 0) {
          targets.countEl.textContent = strings.noTicketsYet || '';
        } else {
          const tpl = strings.ticketCount || '@count tickets';
          targets.countEl.textContent = tpl.replace('@count', String(totalItemQty));
        }
      }
      if (targets.totalEl) {
        targets.totalEl.textContent = hasSelection ? fmt.format(displayTotal) : '';
      }

      if (targets.submit) {
        targets.submit.classList.toggle('mel-booking-submit--soft-idle', !hasTickets);
        if (targets.submit instanceof HTMLButtonElement || targets.submit instanceof HTMLInputElement) {
          const shouldDisable = submitDisabledByServer || !hasTickets;
          targets.submit.disabled = shouldDisable;
          targets.submit.setAttribute('aria-disabled', shouldDisable ? 'true' : 'false');
        }
      }

      rows.forEach((row) => {
        const qty = getQuantity(row);
        const selected = qty > 0;
        row.classList.toggle('has-quantity', selected);
        row.classList.toggle('mel-ticket-row--selected', selected);
        row.classList.toggle('selected', selected);
        row.setAttribute('data-selected', selected ? 'true' : 'false');
        const badge = row.querySelector('[data-mel-ticket-selection-badge]');
        if (badge) {
          badge.hidden = !selected;
        }
      });

      if (announceTimer) {
        clearTimeout(announceTimer);
      }
      announceTimer = window.setTimeout(() => {
        if (!targets.items || !hasSelection) {
          return;
        }
        const allLines = ticketLines.concat(extraLines);
        let summary = allLines.map((l) => `${l.qty} ${l.title}`).join(', ');
        if (donationLine > 0) {
          summary += `. ${strings.donationLine || 'Contribution'} ${fmt.format(donationLine)}`;
        }
        const next = `${summary}. ${strings.subtotal || 'Subtotal'} ${fmt.format(displayTotal)}`;
        if (next !== lastAnnounce) {
          lastAnnounce = next;
          targets.items.setAttribute('aria-label', next);
        }
      }, 450);
    };

    sync();
    window.requestAnimationFrame(() => {
      sync();
    });

    const onChange = () => sync();
    getTicketRows(ticketForm).forEach((row) => {
      const input = getQtyInput(row);
      if (input) {
        input.addEventListener('input', onChange);
        input.addEventListener('change', onChange);
      }
    });

    const donationInput = ticketForm.querySelector('[name="mel_donation"]');
    if (donationInput) {
      donationInput.addEventListener('input', onChange);
      donationInput.addEventListener('change', onChange);
    }

    ticketForm.querySelectorAll('.mel-donation-chip[data-amount]').forEach((chip) => {
      chip.addEventListener('click', (e) => {
        e.preventDefault();
        const amount = parseFloat(chip.getAttribute('data-amount') || '', 10);
        if (!donationInput || !Number.isFinite(amount)) {
          return;
        }
        donationInput.value = String(amount);
        donationInput.dispatchEvent(new Event('input', { bubbles: true }));
        donationInput.dispatchEvent(new Event('change', { bubbles: true }));
        onChange();
      });
    });

    ticketForm.addEventListener(
      'input',
      (e) => {
        if (isQuantityControl(e.target) || isDonationControl(e.target)) {
          onChange();
        }
      },
      false,
    );
    ticketForm.addEventListener(
      'change',
      (e) => {
        if (isQuantityControl(e.target) || isDonationControl(e.target)) {
          onChange();
        }
      },
      false,
    );

    ticketForm.addEventListener('submit', () => {
      window.setTimeout(sync, 0);
    });

    flow.addEventListener('mel-booking-selection-change', onChange, false);
    flow.addEventListener(
      'change',
      (e) => {
        if (e.target instanceof HTMLElement && e.target.closest('.mel-event-extra-card')) {
          onChange();
        }
      },
      false,
    );
    flow.addEventListener(
      'input',
      (e) => {
        if (e.target instanceof HTMLElement && e.target.closest('.mel-event-extra-card')) {
          onChange();
        }
      },
      false,
    );
  }

  Drupal.behaviors.melBookingSummary = {
    attach(context) {
      const raw = drupalSettings.melBookingSummary;
      if (raw && raw.enabled === false) {
        return;
      }
      const settings = {
        currencyCode: 'AUD',
        locale: undefined,
        strings: {},
        ...raw,
        enabled: true,
      };
      once('mel-booking-summary', '[data-mel-booking-flow]', context).forEach((flow) => {
        const ticketForm = flow.querySelector('.mel-booking-form[data-mel-booking-form]');
        if (!(ticketForm instanceof HTMLFormElement)) {
          return;
        }
        bindBookingFlow(flow, ticketForm, settings);
      });
    },
  };
})(Drupal, drupalSettings, once);
