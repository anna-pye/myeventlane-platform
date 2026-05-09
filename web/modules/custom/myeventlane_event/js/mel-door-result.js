/**
 * @file
 * Structured door check-in result card (manual + scanner). Text-only DOM API.
 */
(function (Drupal) {
  'use strict';

  function clearTitles(el) {
    const titleEl = el.querySelector('[data-mel-door-result-title]');
    const metaEl = el.querySelector('[data-mel-door-result-meta]');
    const detailEl = el.querySelector('[data-mel-door-result-detail]');
    const badgesEl = el.querySelector('[data-mel-door-result-badges]');
    if (titleEl) {
      titleEl.textContent = '';
    }
    if (metaEl) {
      metaEl.textContent = '';
    }
    if (detailEl) {
      detailEl.textContent = '';
    }
    if (badgesEl) {
      while (badgesEl.firstChild) {
        badgesEl.removeChild(badgesEl.firstChild);
      }
      badgesEl.hidden = true;
    }
  }

  Drupal.melDoorResult = {

    hide(el) {
      if (!el) {
        return;
      }
      el.hidden = true;
      el.setAttribute('data-state', '');
      clearTitles(el);
    },

    show(el, state, titles) {
      if (!el) {
        return;
      }
      titles = titles || {};
      el.hidden = false;
      el.setAttribute('data-state', state);
      clearTitles(el);
      const titleEl = el.querySelector('[data-mel-door-result-title]');
      const metaEl = el.querySelector('[data-mel-door-result-meta]');
      const detailEl = el.querySelector('[data-mel-door-result-detail]');
      const badgesEl = el.querySelector('[data-mel-door-result-badges]');
      if (titleEl && titles.title) {
        titleEl.textContent = titles.title;
      }
      if (metaEl && titles.meta) {
        metaEl.textContent = titles.meta;
      }
      if (detailEl && titles.detail) {
        detailEl.textContent = titles.detail;
      }

      const acc = titles.accessibility;
      if (
        badgesEl &&
        Array.isArray(acc) &&
        acc.length > 0 &&
        badgesEl.getAttribute('data-mel-door-acc') !== 'suppress'
      ) {
        badgesEl.hidden = false;
        acc.slice(0, 4).forEach((lbl) => {
          const pill = document.createElement('span');
          pill.className = 'mel-door-result-card__badge';
          pill.textContent = String(lbl);
          badgesEl.appendChild(pill);
        });
      }
    },

    fromValidate(el, response) {
      if (!response || typeof response !== 'object') {
        this.show(el, 'error', {
          title: Drupal.t('Unable to validate this ticket.'),
          meta: Drupal.t('Try again in a moment, or type the code manually.'),
          detail: '',
        });
        return;
      }
      const status = response.status;
      const att = response.attendee && typeof response.attendee === 'object' ? response.attendee : {};
      const name = att.name ? String(att.name) : '';
      const ticket = att.ticket_type ? String(att.ticket_type) : '';
      const checkedAt = att.checked_in_at ? String(att.checked_in_at) : '';

      const acc = [];
      if (Array.isArray(att.accessibility)) {
        att.accessibility.forEach((a) => {
          if (a) {
            acc.push(String(a));
          }
        });
      }

      if (status === 'success') {
        const headline = name || Drupal.t('Checked in');
        const meta = ticket || Drupal.t('Welcome in');
        const detail = checkedAt ? Drupal.t('Checked in just now · @time', { '@time': checkedAt }) : Drupal.t('Checked in successfully');
        this.show(el, 'success', {
          title: headline,
          meta,
          detail,
          accessibility: acc,
        });
        return;
      }

      if (status === 'duplicate') {
        this.show(el, 'warning', {
          title: name || Drupal.t('Already checked in'),
          meta: ticket,
          detail: checkedAt ? Drupal.t('Previously checked in at @time.', { '@time': checkedAt }) : (response.message ? String(response.message) : ''),
          accessibility: acc,
        });
        return;
      }

      const msg =
        response.message ?
          String(response.message) :
          Drupal.t('This ticket could not be checked in.');

      this.show(el, 'error', {
        title: Drupal.t('Ticket not recognised'),
        meta: msg,
        detail: Drupal.t('Ask the guest to show their QR, or verify the typed code.'),
      });
    },

  };

})(Drupal);
