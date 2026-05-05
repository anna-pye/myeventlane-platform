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
   * @param {HTMLFormElement} form
   * @returns {{ container: Document|HTMLElement, empty: HTMLElement|null, items: HTMLElement|null, subtotalWrap: HTMLElement|null, subtotalValue: HTMLElement|null, mobileBar: HTMLElement|null, countEl: HTMLElement|null, totalEl: HTMLElement|null, submit: HTMLElement|null }}
   */
  function getTargets(form) {
    const root =
      form.closest('.mel-booking-v2') ||
      document.querySelector('.mel-booking-v2') ||
      document;
    return {
      container: root,
      empty: root.querySelector('[data-mel-summary-empty]'),
      items: root.querySelector('[data-mel-summary-items]'),
      subtotalWrap: root.querySelector('[data-mel-summary-subtotal]'),
      subtotalValue: root.querySelector('[data-mel-summary-subtotal-value]'),
      mobileBar: form.querySelector('[data-mel-mobile-booking-bar]'),
      countEl: form.querySelector('[data-mel-booking-count]'),
      totalEl: form.querySelector('[data-mel-booking-total]'),
      submit: form.querySelector('.mel-add-to-cart-button, input[type="submit"].button--primary, button[type="submit"].button--primary'),
    };
  }

  /**
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function bindForm(form, settings) {
    const fmt = getMoneyFormatter(settings.currencyCode, settings.locale);
    const targets = getTargets(form);
    let announceTimer = null;
    let lastAnnounce = '';

    const strings = settings.strings || {};

    const sync = () => {
      const rows = getTicketRows(form);
      let totalQty = 0;
      let subtotal = 0;
      /** @type {{ title: string, qty: number, line: number }[]} */
      const lines = [];

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
        totalQty += qty;
        const line = qty * unit;
        subtotal += line;
        lines.push({ title, qty, line });
      });

      const hasSelection = totalQty > 0;
      const donationLine = hasSelection ? getOptionalDonation(form) : 0;
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
          lines.forEach((l) => {
            const li = document.createElement('li');
            li.className = 'mel-booking-summary__item';
            const label = document.createElement('span');
            label.className = 'mel-booking-summary__item-label';
            label.textContent = `${l.qty} × ${l.title}`;
            const amt = document.createElement('span');
            amt.className = 'mel-booking-summary__item-amount';
            amt.textContent = fmt.format(l.line);
            li.appendChild(label);
            li.appendChild(amt);
            ul.appendChild(li);
          });
          if (donationLine > 0) {
            const dli = document.createElement('li');
            dli.className = 'mel-booking-summary__item mel-booking-summary__item--donation';
            const dlabel = document.createElement('span');
            dlabel.className = 'mel-booking-summary__item-label';
            dlabel.textContent = strings.donationLine || 'Contribution';
            const damt = document.createElement('span');
            damt.className = 'mel-booking-summary__item-amount';
            damt.textContent = fmt.format(donationLine);
            dli.appendChild(dlabel);
            dli.appendChild(damt);
            ul.appendChild(dli);
          }
          targets.items.innerHTML = '';
          targets.items.appendChild(ul);
        }
      }

      if (targets.subtotalWrap && targets.subtotalValue) {
        targets.subtotalWrap.hidden = !hasSelection;
        targets.subtotalValue.textContent = hasSelection ? fmt.format(displayTotal) : '';
      }

      if (targets.countEl) {
        if (totalQty === 0) {
          targets.countEl.textContent = strings.noTicketsYet || '';
        } else {
          const tpl = strings.ticketCount || '@count tickets';
          targets.countEl.textContent = tpl.replace('@count', String(totalQty));
        }
      }
      if (targets.totalEl) {
        targets.totalEl.textContent = hasSelection ? fmt.format(displayTotal) : '';
      }

      if (targets.submit) {
        targets.submit.classList.toggle('mel-booking-submit--soft-idle', !hasSelection);
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
        let summary = lines.map((l) => `${l.qty} ${l.title}`).join(', ');
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
    getTicketRows(form).forEach((row) => {
      const input = getQtyInput(row);
      if (input) {
        input.addEventListener('input', onChange);
        input.addEventListener('change', onChange);
      }
    });

    const donationInput = form.querySelector('[name="mel_donation"]');
    if (donationInput) {
      donationInput.addEventListener('input', onChange);
      donationInput.addEventListener('change', onChange);
    }

    form.querySelectorAll('.mel-donation-chip[data-amount]').forEach((chip) => {
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

    form.addEventListener(
      'input',
      (e) => {
        if (isQuantityControl(e.target) || isDonationControl(e.target)) {
          onChange();
        }
      },
      false,
    );
    form.addEventListener(
      'change',
      (e) => {
        if (isQuantityControl(e.target) || isDonationControl(e.target)) {
          onChange();
        }
      },
      false,
    );

    form.addEventListener('submit', () => {
      window.setTimeout(sync, 0);
    });
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
      once('mel-booking-summary', '.mel-booking-form[data-mel-booking-form]', context).forEach((el) => {
        bindForm(el, settings);
      });
    },
  };
})(Drupal, drupalSettings, once);
