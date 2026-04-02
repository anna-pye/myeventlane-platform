/**
 * @file
 * Live preview, hero header sync, and builder UX for the vendor event form.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /** @type {WeakMap<HTMLFormElement, {run: function(): void, debounced: function(): void}>} */
  const melEbpCoordinators = new WeakMap();

  /**
   * querySelector does not support jQuery's :input pseudo; expand for safety.
   *
   * @param {string} raw
   * @returns {string}
   */
  function normalizeInputSelector(raw) {
    if (!raw || typeof raw !== 'string') {
      return '';
    }
    const s = raw.trim();
    if (!s) {
      return '';
    }
    if (s.startsWith(':input')) {
      const rest = s.slice(6).trim();
      return ['input', 'select', 'textarea'].map((tag) => `${tag}${rest}`).join(', ');
    }
    return s;
  }

  /**
   * @param {HTMLElement} root
   * @param {string} selector
   * @returns {string}
   */
  function readFirstInputValue(root, selector) {
    const norm = normalizeInputSelector(selector || '');
    if (!norm) {
      return '';
    }
    const el = root.querySelector(norm);
    if (!el) {
      return '';
    }
    if (el.type === 'checkbox' || el.type === 'radio') {
      return el.checked ? el.value : '';
    }
    return (el.value || '').trim();
  }

  /**
   * @param {string} raw
   * @returns {string}
   */
  function formatDateHint(raw) {
    if (!raw) {
      return '';
    }
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) {
      return raw;
    }
    return d.toLocaleString(undefined, {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  }

  /**
   * @param {HTMLFormElement} form
   * @param {object} settings
   * @returns {boolean}
   */
  function hasImageFileSelected(form, settings) {
    const norm = normalizeInputSelector(settings.imageSelector || '');
    if (!norm) {
      return false;
    }
    return Array.from(form.querySelectorAll(norm)).some(
      (input) => input.type === 'file' && input.files && input.files.length > 0,
    );
  }

  /**
   * True if the event image field has a new file, existing managed file id(s),
   * or a rendered preview image (saved events).
   *
   * @param {HTMLFormElement} form
   * @param {object} settings
   * @returns {boolean}
   */
  function hasEventImagePresent(form, settings) {
    if (hasImageFileSelected(form, settings)) {
      return true;
    }
    const fidCandidates = form.querySelectorAll(
      'input[name*="field_event_image"][name*="fids"], input[name*="field_event_image"][name*="_fid"]',
    );
    for (let i = 0; i < fidCandidates.length; i++) {
      const input = fidCandidates[i];
      const v = String(input.value || '').trim();
      if (v !== '' && v !== '0' && v !== '[]') {
        return true;
      }
    }
    const img = form.querySelector('.js-mel-preview-img');
    if (img) {
      const src = img.getAttribute('src') || '';
      if (src.length > 12 && !src.startsWith('data:,')) {
        return true;
      }
    }
    return false;
  }

  /**
   * After ticket AJAX, .mel-event-builder is replaced with fresh PHP state; copy onto the form.
   *
   * @param {HTMLFormElement} form
   */
  function syncMelStateFromBuilder(form) {
    if (!form || !form.querySelector) {
      return;
    }
    const builder = form.querySelector('.mel-event-builder[data-mel-state]');
    const fresh = builder && builder.getAttribute('data-mel-state');
    if (fresh) {
      form.setAttribute('data-mel-state', fresh);
    }
  }

  /**
   * Server-rendered MEL builder state (EventBuilderStateService JSON on the form).
   *
   * @param {HTMLFormElement} form
   * @returns {object}
   */
  function getMelState(form) {
    syncMelStateFromBuilder(form);
    try {
      return JSON.parse(form.dataset.melState || '{}');
    } catch (e) {
      return {};
    }
  }

  /**
   * @param {HTMLFormElement} form
   * @returns {string}
   */
  function getBuilderMode(form) {
    const state = getMelState(form);
    return state.mode || 'none';
  }

  /**
   * Guided copy for the current ticket mix (matches PHP builder state).
   *
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function updateModeExplainer(form, settings) {
    const el = form.querySelector('.mel-mode-explainer');
    if (!el) {
      return;
    }
    const mode = getBuilderMode(form);
    const map = settings.modeExplainerByMode || {};
    el.textContent = typeof map[mode] === 'string' ? map[mode] : '';
  }

  /**
   * Empty-state + dashed highlight until at least one ticket card exists.
   *
   * @param {HTMLFormElement} form
   */
  function updateTicketsSectionChrome(form) {
    const section = form.querySelector('.mel-step--tickets');
    if (!section) {
      return;
    }
    const isFirstSave = form.getAttribute('data-mel-event-is-new') === '1';
    const hasAnyTicketCard = Boolean(form.querySelector('.mel-ticket-card'));
    section.querySelectorAll('.mel-tickets-guided-empty').forEach((hint) => {
      hint.hidden = hasAnyTicketCard;
    });
    // First-save flow: no "error" framing — user cannot add tickets until draft exists.
    section.classList.toggle('mel-highlight', !isFirstSave && !hasAnyTicketCard);
    section.classList.toggle('mel-first-save-focus', isFirstSave);
  }

  /**
   * Capacity heading copy from server builder mode (visibility is PHP #access only).
   *
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function updateBuilderMode(form, settings) {
    const mode = getBuilderMode(form);

    const titleEl = form.querySelector('.js-mel-capacity-block-title');
    const byMode = settings.capacityTitleByMode || {};
    if (titleEl && typeof byMode[mode] === 'string' && byMode[mode] !== '') {
      const icon = titleEl.querySelector('.mel-capacity-block__icon');
      const iconClone = icon ? icon.cloneNode(true) : null;
      titleEl.textContent = '';
      if (iconClone) {
        titleEl.appendChild(iconClone);
        titleEl.appendChild(document.createTextNode(` ${byMode[mode]}`));
      } else {
        titleEl.textContent = byMode[mode];
      }
    }
  }

  function readCategoryHint(form, settings) {
    const norm = normalizeInputSelector(settings.categorySelector || '');
    if (!norm) {
      return '';
    }
    const el = form.querySelector(norm);
    if (!el || !el.value) {
      return '';
    }
    const v = String(el.value).trim();
    if (!v) {
      return '';
    }
    const paren = v.match(/\(([^)]+)\)\s*$/);
    if (paren) {
      return paren[1].trim();
    }
    if (v.includes('(')) {
      return v.replace(/\s*\([^)]*\)\s*$/, '').trim() || v;
    }
    return v;
  }

  /**
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function updateHeaderWhenWhere(form, settings) {
    const el = form.querySelector('.js-mel-header-when-where');
    if (!el) {
      return;
    }
    const start = readFirstInputValue(form, settings.dateStartSelector || '');
    const locBits = [];
    const locNorm = normalizeInputSelector(settings.locationSelector || '');
    if (locNorm) {
      form.querySelectorAll(locNorm).forEach((input) => {
        if (input.value && String(input.value).trim() !== '') {
          locBits.push(String(input.value).trim());
        }
      });
    }
    const datePart = start ? formatDateHint(start) : '';
    const loc = locBits.slice(0, 2).join(', ');
    const parts = [];
    if (loc) {
      parts.push(loc);
    }
    if (datePart) {
      parts.push(datePart);
    }
    el.textContent = parts.join(' · ');
  }

  /**
   * Progress: title → tickets → date → location → image (optional).
   *
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function updateHeaderDots(form, settings) {
    const dotsWrap = form.querySelector('.js-mel-header-dots');
    if (!dotsWrap) {
      return;
    }
    let score = 0;
    if (readFirstInputValue(form, settings.titleSelector || 'input[name^="title"]')) {
      score += 1;
    }
    if (form.querySelectorAll('.js-mel-ticket-card').length > 0) {
      score += 1;
    }
    if (readFirstInputValue(form, settings.dateStartSelector || '')) {
      score += 1;
    }
    let hasLoc = false;
    const locNormDots = normalizeInputSelector(settings.locationSelector || '');
    if (locNormDots) {
      form.querySelectorAll(locNormDots).forEach((input) => {
        if (input.value && String(input.value).trim() !== '') {
          hasLoc = true;
        }
      });
    }
    if (hasLoc) {
      score += 1;
    }
    if (hasEventImagePresent(form, settings)) {
      score += 1;
    }
    const dots = dotsWrap.querySelectorAll('.mel-event-header__dot');
    dots.forEach((d, i) => {
      d.classList.toggle('is-filled', i < score);
    });
  }

  /**
   * Weighted completion 0–100 (title, tickets, start, location, image).
   *
   * @param {HTMLFormElement} form
   * @param {object} settings
   * @returns {number}
   */
  function computeCompletionScore(form, settings) {
    let score = 0;
    if (readFirstInputValue(form, settings.titleSelector || '')) {
      score += 20;
    }
    if (form.querySelector('.js-mel-ticket-card')) {
      score += 30;
    }
    if (readFirstInputValue(form, settings.dateStartSelector || '')) {
      score += 15;
    }
    let hasLoc = false;
    const locNormProg = normalizeInputSelector(settings.locationSelector || '');
    if (locNormProg) {
      form.querySelectorAll(locNormProg).forEach((input) => {
        if (input.value && String(input.value).trim() !== '') {
          hasLoc = true;
        }
      });
    }
    if (hasLoc) {
      score += 15;
    }
    if (hasEventImagePresent(form, settings)) {
      score += 20;
    }
    return score;
  }

  /**
   * Progressive confidence copy under Visibility (no storage impact).
   *
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function updatePublishConfidence(form, settings) {
    const el = form.querySelector('.js-mel-publish-confidence');
    if (!el) {
      return;
    }
    const isNew = form.getAttribute('data-mel-event-is-new') === '1';
    const hasTicketCard = Boolean(form.querySelector('.js-mel-ticket-card'));
    const score = computeCompletionScore(form, settings);
    el.classList.remove('mel-confidence--ready', 'mel-confidence--warn', 'mel-confidence--draft');
    if (isNew) {
      el.textContent = settings.confidenceDraftFirst || '';
      el.setAttribute('data-mel-confidence-state', 'draft');
      el.classList.add('mel-confidence--draft');
      return;
    }
    if (!hasTicketCard) {
      el.textContent = settings.confidenceNeedTickets || '';
      el.setAttribute('data-mel-confidence-state', 'warn');
      el.classList.add('mel-confidence--warn');
      return;
    }
    if (score >= 100) {
      el.textContent = settings.confidenceReady || '';
      el.setAttribute('data-mel-confidence-state', 'ready');
      el.classList.add('mel-confidence--ready');
      return;
    }
    el.textContent = settings.confidenceBuilding || '';
    el.setAttribute('data-mel-confidence-state', 'neutral');
  }

  /**
   * Weighted completion bar (0–100) aligned with header dots signals.
   *
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function updateProgress(form, settings) {
    const fill = form.querySelector('.js-mel-progress-fill');
    const label = form.querySelector('.js-mel-progress-label');
    if (!fill && !label) {
      return;
    }
    const score = computeCompletionScore(form, settings);
    if (fill) {
      fill.style.width = `${score}%`;
    }
    if (label) {
      label.textContent = Drupal.t('@pct% complete', { '@pct': score });
    }
  }

  /**
   * @param {HTMLFormElement} form
   */
  function updateHeaderStatusFromForm(form) {
    const label = form.querySelector('.js-mel-header-status-label');
    if (!label) {
      return;
    }
    const statusRadio = form.querySelector('input[name="status[value]"]:checked');
    const statusSelect = form.querySelector('select[name="status"]');
    let published = false;
    if (statusRadio) {
      published = String(statusRadio.value) === '1';
    } else if (statusSelect) {
      published = String(statusSelect.value) === '1';
    }
    label.textContent = published ? Drupal.t('Published') : Drupal.t('Draft');
  }

  /**
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function updatePreviewMedia(form, settings) {
    const label = form.querySelector('.js-mel-preview-media-label');
    const wrap = form.querySelector('.js-mel-preview-media');
    const img = form.querySelector('.js-mel-preview-img');
    if (!label || !wrap) {
      return;
    }
    const hasFile = hasEventImagePresent(form, settings);
    label.classList.toggle('is-empty', !hasFile);
    label.textContent = hasFile ? '' : settings.previewImagePlaceholder || Drupal.t('Add an event image');
    wrap.classList.toggle('has-image', hasFile);
    if (img) {
      if (!hasFile) {
        img.setAttribute('hidden', 'hidden');
        img.removeAttribute('src');
      }
    }
  }

  /**
   * Preview CTA label from builder mode (aligned with PHP ctaPreviewByMode).
   *
   * @param {HTMLFormElement} form
   * @param {object} state
   * @param {object} settings
   */
  function updatePreviewFromState(form, state, settings) {
    const cta = form.querySelector('.mel-preview-cta, .js-mel-preview-cta');
    const map = settings.ctaPreviewByMode || {};
    const mode = state.mode || 'none';
    const labels = {
      rsvp: map.rsvp || settings.rsvpLabel || 'RSVP',
      paid: map.paid || settings.buyLabel || 'Buy tickets',
      both: map.both || settings.bothKindsLabel || 'Get tickets',
      external: map.external || settings.externalLabel || 'Get tickets',
      none: map.none || settings.needsTicketsLabel || 'Add tickets to publish',
      needs_tickets: map.needs_tickets || settings.needsTicketsLabel || 'Add tickets to publish',
    };
    if (cta) {
      cta.textContent = typeof labels[mode] === 'string' ? labels[mode] : labels.none;
    }
  }

  /**
   * Inline preview: title, schedule, category badge, CTA, ticket summary, image.
   *
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function updatePreview(form, settings) {
    const title = readFirstInputValue(form, settings.titleSelector || 'input[name^="title"]');
    const start = readFirstInputValue(form, settings.dateStartSelector || '');
    const end = readFirstInputValue(form, settings.dateEndSelector || '');
    const locBits = [];
    const locNormPreview = normalizeInputSelector(settings.locationSelector || '');
    if (locNormPreview) {
      form.querySelectorAll(locNormPreview).forEach((input) => {
        if (input.value && String(input.value).trim() !== '') {
          locBits.push(String(input.value).trim());
        }
      });
    }
    const location = locBits.slice(0, 3).join(', ');
    const categoryHint = readCategoryHint(form, settings);

    const st = getMelState(form);
    const effectiveMode = st.mode || 'none';
    updatePreviewFromState(form, st, settings);

    let badge = '';
    switch (effectiveMode) {
      case 'rsvp':
        badge = settings.rsvpFreeLabel || 'FREE RSVP';
        break;
      case 'both':
        badge = settings.rsvpFreeLabel || 'FREE RSVP';
        break;
      default:
        badge = '';
        break;
    }

    const tEl = form.querySelector('.js-mel-preview-title');
    const dtEl = form.querySelector('.js-mel-preview-datetime');
    const badgeEl = form.querySelector('.js-mel-preview-badge');
    const catEl = form.querySelector('.js-mel-preview-category');
    if (tEl) {
      tEl.textContent = title || settings.defaultTitle || Drupal.t('Your event title');
    }
    let dateText = '';
    if (start) {
      dateText = formatDateHint(start);
      if (end) {
        dateText += ' — ' + formatDateHint(end);
      }
    }
    const datetimeLine = [dateText, location].filter(Boolean).join(' · ');
    if (dtEl) {
      dtEl.textContent = datetimeLine || '—';
    }
    if (badgeEl) {
      badgeEl.textContent = badge;
      badgeEl.hidden = !badge;
    }
    if (catEl) {
      if (categoryHint) {
        catEl.textContent = categoryHint;
        catEl.hidden = false;
      } else {
        catEl.textContent = '';
        catEl.hidden = true;
      }
    }

    updatePreviewTicketsSummary(form, settings);
    updatePreviewMedia(form, settings);
  }

  /**
   * Ticket tier line under badges in the live preview card.
   *
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function updatePreviewTicketsSummary(form, settings) {
    const el = form.querySelector('.js-mel-preview-tickets-summary');
    if (!el) {
      return;
    }
    const cards = form.querySelectorAll('.js-mel-ticket-card[data-mel-ticket-kind]');
    if (!cards.length) {
      el.textContent = '';
      el.setAttribute('hidden', 'hidden');
      return;
    }
    el.removeAttribute('hidden');
    const n = cards.length;
    const kindList = Array.from(cards)
      .map((c) => c.getAttribute('data-mel-ticket-kind'))
      .filter(Boolean);
    const kinds = [...new Set(kindList)];
    const map = {
      rsvp: settings.rsvpLabel || 'RSVP',
      paid: settings.buyLabel || 'Paid',
      external: settings.externalLabel || 'External',
    };
    let mix;
    if (kinds.length === 1) {
      mix = map[kinds[0]] || kinds[0];
    } else {
      mix = kinds.map((k) => map[k] || k).join(' + ');
    }
    const tierWord =
      n === 1
        ? settings.ticketTierSingular || Drupal.t('ticket type')
        : settings.ticketTierPlural || Drupal.t('ticket types');
    el.textContent = `${n} ${tierWord} · ${mix}`;
  }

  /**
   * Live image preview via FileReader (first selected file only).
   *
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function bindImageFilePreview(form, settings) {
    const norm = normalizeInputSelector(settings.imageSelector || '');
    if (!norm) {
      return;
    }
    const img = form.querySelector('.js-mel-preview-img');
    if (!img) {
      return;
    }
    form.querySelectorAll(norm).forEach((input) => {
      if (input.type !== 'file') {
        return;
      }
      input.addEventListener('change', () => {
        const file = input.files && input.files[0];
        if (!file || !file.type.startsWith('image/')) {
          img.setAttribute('hidden', 'hidden');
          img.removeAttribute('src');
          return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
          const url = e.target && e.target.result;
          if (typeof url === 'string') {
            img.removeAttribute('hidden');
            img.setAttribute('src', url);
          }
        };
        reader.readAsDataURL(file);
      });
    });
  }

  /**
   * @param {function(): void} fn
   * @param {number} ms
   * @returns {function(): void}
   */
  function debounce(fn, ms) {
    let t;
    return function debounced() {
      clearTimeout(t);
      t = setTimeout(fn, ms);
    };
  }

  /**
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function bindHeaderActions(form, settings) {
    const previewBtn = form.querySelector('.js-mel-scroll-preview');
    previewBtn?.addEventListener('click', () => {
      document.getElementById('mel-event-preview-wrapper')?.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
      });
    });
    const headerPublish = form.querySelector('.js-mel-header-publish');
    headerPublish?.addEventListener('click', () => {
      const target = form.querySelector('[data-mel-action="publish"]');
      if (target) {
        target.click();
      }
    });
  }

  /**
   * @param {HTMLFormElement} form
   */
  function bindSaveDraftScroll(form) {
    form.querySelectorAll('.js-mel-save-draft-scroll').forEach((btn) => {
      btn.addEventListener('click', () => {
        const draft = form.querySelector('[data-mel-action="save-draft"]');
        if (draft) {
          draft.scrollIntoView({ behavior: 'smooth', block: 'center' });
          draft.focus({ preventScroll: true });
          draft.classList.add('mel-action-pulse');
          window.setTimeout(() => draft.classList.remove('mel-action-pulse'), 1200);
        }
      });
    });
  }

  /**
   * After Save draft navigation, scroll to When & where on the next edit screen.
   *
   * @param {HTMLFormElement} form
   */
  function bindSaveDraftForNextStepScroll(form) {
    const draft = form.querySelector('[data-mel-action="save-draft"]');
    if (!draft || draft.dataset.melScrollAfterSave === '1') {
      return;
    }
    draft.dataset.melScrollAfterSave = '1';
    draft.addEventListener('click', () => {
      try {
        window.sessionStorage.setItem('melBuilderScrollTo', 'when_where');
      } catch (e) {
        // Private mode / storage disabled.
      }
    });
  }

  /**
   * @param {HTMLFormElement} form
   */
  function consumeScrollAfterDraft(form) {
    try {
      if (window.sessionStorage.getItem('melBuilderScrollTo') !== 'when_where') {
        return;
      }
      window.sessionStorage.removeItem('melBuilderScrollTo');
      window.requestAnimationFrame(() => {
        form
          .querySelector('[data-step="when_where"], [data-section="when_where"]')
          ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    } catch (e) {
      // Ignore.
    }
  }

  /**
   * @param {string} message
   */
  function showMelToast(message) {
    if (!message) {
      return;
    }
    const el = document.createElement('div');
    el.className = 'mel-toast';
    el.setAttribute('role', 'status');
    el.textContent = message;
    document.body.appendChild(el);
    window.requestAnimationFrame(() => {
      el.classList.add('mel-toast--visible');
    });
    window.setTimeout(() => {
      el.classList.remove('mel-toast--visible');
      window.setTimeout(() => el.remove(), 280);
    }, 3000);
  }

  /**
   * Toast + gentle scroll when a new ticket card appears (AJAX builder).
   *
   * @param {HTMLFormElement} form
   * @param {object} settings
   */
  function maybeAnnounceNewTickets(form, settings) {
    const live = form.querySelectorAll('.js-mel-ticket-card').length;
    if (form.dataset.melTicketDeltaInit !== '1') {
      form.dataset.melTicketDeltaInit = '1';
      form.dataset.melTicketCardCountJs = String(live);
      return;
    }
    const prev = parseInt(form.dataset.melTicketCardCountJs || '0', 10);
    if (live > prev) {
      showMelToast(settings.ticketAddedToast || '');
      form.querySelector('.mel-step--tickets')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    form.dataset.melTicketCardCountJs = String(live);
  }

  /**
   * @param {HTMLFormElement} form
   * @param {object} settings
   * @returns {{run: function(): void, debounced: function(): void}}
   */
  function getOrCreateCoordinator(form, settings) {
    let coord = melEbpCoordinators.get(form);
    if (!coord) {
      const run = () => {
        syncMelStateFromBuilder(form);
        updateBuilderMode(form, settings);
        updateModeExplainer(form, settings);
        updateTicketsSectionChrome(form);
        updatePreview(form, settings);
        updateHeaderWhenWhere(form, settings);
        updateHeaderDots(form, settings);
        updateProgress(form, settings);
        updatePublishConfidence(form, settings);
        updateHeaderStatusFromForm(form);
        updatePublishGate(form);
        maybeAnnounceNewTickets(form, settings);
      };
      coord = {
        run,
        debounced: debounce(run, 200),
      };
      melEbpCoordinators.set(form, coord);
    }
    return coord;
  }

  /**
   * Publish control hints from data attributes (no HTML disabled on submit).
   *
   * @param {HTMLFormElement} form
   */
  function updatePublishGate(form) {
    const isNew = form.getAttribute('data-mel-event-is-new') === '1';
    const publishBtn = form.querySelector('[data-mel-action="publish"]');
    const headerPublish = form.querySelector('.js-mel-header-publish');
    let ticketCount = parseInt(form.getAttribute('data-mel-ticket-count') || '0', 10);
    if (Number.isNaN(ticketCount)) {
      ticketCount = 0;
    }
    const liveCards = form.querySelectorAll('.js-mel-ticket-card').length;
    if (!isNew && liveCards > ticketCount) {
      ticketCount = liveCards;
    }
    const blocked = isNew || ticketCount < 1;
    [publishBtn, headerPublish].forEach((el) => {
      if (!el) {
        return;
      }
      el.classList.toggle('is-muted-publish', blocked);
      el.setAttribute('aria-disabled', blocked ? 'true' : 'false');
    });
  }

  /**
   * Observe ticket list AJAX replacements.
   *
   * @param {HTMLFormElement} form
   * @param {function(): void} onChange
   */
  function observeTicketBuilder(shell, onChange) {
    if (!shell || typeof MutationObserver === 'undefined') {
      return;
    }
    const obs = new MutationObserver(() => onChange());
    obs.observe(shell, { childList: true, subtree: true });
  }

  Drupal.behaviors.melEventBuilderPreview = {
    attach(context) {
      const settings = drupalSettings.melEventBuilderPreview || {};
      once('mel-event-builder-preview', 'form.mel-event-builder-form', context).forEach((form) => {
        const coord = getOrCreateCoordinator(form, settings);
        if (!form.dataset.melBuilderListenersBound) {
          form.dataset.melBuilderListenersBound = '1';
          form.addEventListener('input', coord.debounced);
          form.addEventListener('change', coord.debounced);
          form.addEventListener('input', () => {
            console.log('Form changed — autosave ready');
          });
          bindHeaderActions(form, settings);
          bindSaveDraftScroll(form);
          bindImageFilePreview(form, settings);
          bindSaveDraftForNextStepScroll(form);
        }
        coord.run();
        consumeScrollAfterDraft(form);
      });

      once('mel-event-builder-ticket-shell', '#mel-ticket-builder-ajax-wrapper', context).forEach((shell) => {
        const form = shell.closest('form.mel-event-builder-form');
        if (!form) {
          return;
        }
        const coord = getOrCreateCoordinator(form, settings);
        observeTicketBuilder(shell, () => coord.debounced());
        coord.debounced();
      });
    },
  };

  /**
   * After ticket AJAX, sync server state from replaced .mel-event-builder into the form.
   */
  Drupal.behaviors.melStateSync = {
    attach(context) {
      const forms = [];
      if (context.matches && context.matches('form.mel-event-builder-form')) {
        forms.push(context);
      }
      if (context.querySelectorAll) {
        context.querySelectorAll('form.mel-event-builder-form').forEach((f) => {
          forms.push(f);
        });
      }
      forms.forEach((form) => {
        syncMelStateFromBuilder(form);
        const state = getMelState(form);
        const settings = drupalSettings.melEventBuilderPreview || {};
        updatePreviewFromState(form, state, settings);
      });
    },
  };
})(Drupal, drupalSettings, once);
