/**
 * @file
 * Ticket card interactions: edit state, inline validation, and reorder.
 */

(function (Drupal, once, drupalSettings) {
  'use strict';

  const PENDING_SAVE_KEY = 'melTicketCardsPendingSaves';
  const ACTIVE_EDIT_KEY = 'melTicketCardsActiveEdits';
  const PENDING_PRESET_FOCUS_KEY = 'melTicketCardsPendingPresetFocus';

  function elementsFromContext(selector, context) {
    const root = context || document;
    const matches = [];
    if (root.nodeType === Node.ELEMENT_NODE && root.matches(selector)) {
      matches.push(root);
    }
    root.querySelectorAll(selector).forEach((element) => matches.push(element));
    return matches;
  }

  function ticketStorageIds(key) {
    try {
      return JSON.parse(window.sessionStorage.getItem(key) || '[]');
    }
    catch (e) {
      return [];
    }
  }

  function writeTicketStorageIds(key, ids) {
    try {
      window.sessionStorage.setItem(key, JSON.stringify([...new Set(ids)]));
    }
    catch (e) {
      // Storage may be unavailable in private contexts; interaction still works.
    }
  }

  function readPendingPresetFocus() {
    try {
      return window.sessionStorage.getItem(PENDING_PRESET_FOCUS_KEY) || '';
    }
    catch (e) {
      return '';
    }
  }

  function writePendingPresetFocus(presetKey) {
    try {
      if (presetKey) {
        window.sessionStorage.setItem(PENDING_PRESET_FOCUS_KEY, presetKey);
      }
      else {
        window.sessionStorage.removeItem(PENDING_PRESET_FOCUS_KEY);
      }
    }
    catch (e) {
      // Storage may be unavailable; the preset card still opens.
    }
  }

  function presetValues(presetKey) {
    const presets = drupalSettings.myeventlaneTicketPresets && drupalSettings.myeventlaneTicketPresets.presets
      ? drupalSettings.myeventlaneTicketPresets.presets
      : {};
    return presets[presetKey] && presets[presetKey].values ? presets[presetKey].values : {};
  }

  function rememberActiveEdit(card) {
    const id = card.getAttribute('data-ticket-id');
    if (!id) {
      return;
    }
    writeTicketStorageIds(ACTIVE_EDIT_KEY, [...ticketStorageIds(ACTIVE_EDIT_KEY), id]);
  }

  function forgetActiveEdit(card) {
    const id = card.getAttribute('data-ticket-id');
    if (!id) {
      return;
    }
    writeTicketStorageIds(
      ACTIVE_EDIT_KEY,
      ticketStorageIds(ACTIVE_EDIT_KEY).filter((activeId) => String(activeId) !== String(id)),
    );
  }

  function shouldRestoreEdit(card) {
    const id = card.getAttribute('data-ticket-id');
    return Boolean(id && ticketStorageIds(ACTIVE_EDIT_KEY).map(String).includes(String(id)));
  }

  function collectTicketIds(list) {
    const cards = list.querySelectorAll('.js-mel-ticket-card[data-ticket-id]');
    const ids = [];
    cards.forEach((card) => {
      const id = parseInt(card.getAttribute('data-ticket-id'), 10);
      if (!Number.isNaN(id) && id > 0) {
        ids.push(id);
      }
    });
    return ids;
  }

  /**
   * Marks the first ticket card in list DOM order (Event Studio hero tier).
   *
   * @param {HTMLElement|null} list
   */
  function syncPrimaryTicketClassInList(list) {
    if (!list || !list.children) {
      return;
    }
    const cards = [];
    for (let i = 0; i < list.children.length; i++) {
      const el = list.children[i];
      if (el.classList && el.classList.contains('js-mel-ticket-card')) {
        cards.push(el);
      }
    }
    cards.forEach((card, i) => {
      card.classList.toggle('mel-ticket--primary', i === 0);
    });
  }

  /**
   * @param {HTMLElement} list
   */
  function clearDropTargets(list) {
    list.querySelectorAll('.js-mel-ticket-card.is-drop-target').forEach((el) => {
      el.classList.remove('is-drop-target');
    });
  }

  /**
   * @param {HTMLElement} list
   * @param {HTMLElement} dragged
   * @param {number} clientY
   */
  function updateDropTargetHighlight(list, dragged, clientY) {
    clearDropTargets(list);
    if (!dragged || !list.contains(dragged)) {
      return;
    }
    const after = getDragAfterElement(list, clientY);
    if (after) {
      after.classList.add('is-drop-target');
    }
    else {
      const cards = [...list.querySelectorAll('.js-mel-ticket-card[data-ticket-id]')].filter(
        (c) => c !== dragged,
      );
      const last = cards[cards.length - 1];
      if (last) {
        last.classList.add('is-drop-target');
      }
    }
  }

  /**
   * @param {HTMLElement} list
   */
  function persistOrder(list) {
    const wrapper = list.closest('#mel-ticket-builder-ajax-wrapper');
    if (!wrapper) {
      return;
    }
    const input = wrapper.querySelector('.js-mel-ticket-order');
    if (!input) {
      return;
    }
    input.value = JSON.stringify(collectTicketIds(list));
    const submitBtn = wrapper.querySelector('.js-mel-ticket-reorder-submit');
    if (!submitBtn) {
      return;
    }
    if (typeof submitBtn.requestSubmit === 'function') {
      submitBtn.requestSubmit();
    }
    else {
      submitBtn.click();
    }
  }

  /**
   * @param {HTMLElement} container
   * @param {HTMLElement} dragged
   * @param {number} y
   * @return {Element|null}
   */
  function getDragAfterElement(container, y) {
    const cards = [
      ...container.querySelectorAll('.js-mel-ticket-card[data-ticket-id]:not(.is-dragging)'),
    ];
    return cards.reduce(
      (closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
          return { offset, element: child };
        }
        return closest;
      },
      { offset: Number.NEGATIVE_INFINITY, element: null },
    ).element;
  }

  function editableFields(card) {
    const edit = card.querySelector('[data-mel-ticket-edit]') || card;
    return [...edit.querySelectorAll('input, select, textarea')].filter((field) => {
      const type = (field.getAttribute('type') || '').toLowerCase();
      return !field.disabled && !['hidden', 'submit', 'button', 'reset'].includes(type);
    });
  }

  function focusNewPresetCard(wrapper) {
    const presetKey = readPendingPresetFocus();
    const card = wrapper.querySelector('.mel-ticket-card--new');
    if (!presetKey || !card) {
      return;
    }

    const values = presetValues(presetKey);
    const kind = values.ticket_kind || card.getAttribute('data-mel-ticket-kind') || 'paid';
    const preferredSelector = kind === 'paid'
      ? 'input[data-mel-ticket-preset-focus="price"]'
      : 'input[name$="[title]"], textarea[name$="[title]"]';
    const preferred = card.querySelector(preferredSelector);
    const fallback = editableFields(card)[0];
    const field = preferred && !preferred.disabled ? preferred : fallback;
    if (field) {
      window.setTimeout(() => field.focus({ preventScroll: true }), 0);
    }
    writePendingPresetFocus('');
  }

  function fieldLabel(field) {
    const item = field.closest('.form-item, .form-type-checkbox');
    const label = item ? item.querySelector('label') : null;
    return label ? label.textContent.trim().replace(/\s+/g, ' ') : field.getAttribute('name') || 'Field';
  }

  function fieldSignature(card) {
    return editableFields(card)
      .map((field) => {
        const name = field.getAttribute('name') || '';
        if (field.type === 'checkbox' || field.type === 'radio') {
          return `${name}:${field.checked ? '1' : '0'}`;
        }
        return `${name}:${field.value}`;
      })
      .join('|');
  }

  function stateLabel(state) {
    return {
      dirty: 'Unsaved changes',
      saving: 'Saving...',
      saved: 'Saved',
    }[state] || '';
  }

  function setCardState(card, state) {
    const labels = card.querySelectorAll('[data-mel-ticket-state]');
    labels.forEach((label) => {
      label.textContent = stateLabel(state);
      label.hidden = state === '';
      label.setAttribute('data-mel-ticket-state-value', state);
    });
    card.setAttribute('data-mel-ticket-state-value', state);
  }

  function fieldWrapper(field) {
    return field.closest('.form-item, .form-type-checkbox') || field.parentElement;
  }

  function clearFieldError(field) {
    field.removeAttribute('aria-invalid');
    const wrapper = fieldWrapper(field);
    if (!wrapper) {
      return;
    }
    wrapper.querySelectorAll('.mel-ticket-field-error[data-mel-ticket-field-error]').forEach((error) => {
      error.remove();
    });
  }

  function setFieldError(field, message) {
    clearFieldError(field);
    field.setAttribute('aria-invalid', 'true');
    const id =
      field.id ||
      `mel-ticket-field-${Math.random().toString(36).slice(2)}`;
    if (!field.id) {
      field.id = id;
    }
    const error = document.createElement('div');
    error.className = 'mel-ticket-field-error';
    error.setAttribute('data-mel-ticket-field-error', '1');
    error.id = `${id}-error`;
    error.textContent = message;
    field.setAttribute('aria-describedby', `${field.getAttribute('aria-describedby') || ''} ${error.id}`.trim());
    field.insertAdjacentElement('afterend', error);
  }

  function validateRequiredFields(card) {
    let valid = true;
    editableFields(card).forEach((field) => {
      clearFieldError(field);
      if (
        field.matches('[required], [aria-required="true"], .js-mel-ticket-title') &&
        String(field.value || '').trim() === ''
      ) {
        const message = field.getAttribute('data-mel-ticket-required-message') || `${fieldLabel(field)} is required.`;
        setFieldError(field, message);
        valid = false;
      }
    });

    const kindField = card.querySelector('select[name$="[ticket_kind]"]');
    const ticketKind = kindField ? kindField.value : card.getAttribute('data-mel-ticket-kind');
    if (ticketKind === 'paid') {
      const priceField = card.querySelector(
        'input[name$="[price_amount]"], input[name$="[price_number]"], input[name$="[price]"]',
      );
      const price = priceField ? parseFloat(priceField.value) : 0;
      if (!priceField || Number.isNaN(price) || price <= 0) {
        if (priceField) {
          setFieldError(priceField, 'Paid tickets require a price greater than zero.');
        }
        valid = false;
      }
    }

    return valid;
  }

  /** @param {HTMLElement} card */
  function silentTicketOutcomeValid(card) {
    let valid = true;
    editableFields(card).forEach((field) => {
      if (
        field.matches('[required], [aria-required="true"], .js-mel-ticket-title') &&
        String(field.value || '').trim() === ''
      ) {
        valid = false;
      }
    });
    const kindField = card.querySelector('select[name$="[ticket_kind]"]');
    const ticketKind = kindField ? kindField.value : card.getAttribute('data-mel-ticket-kind');
    if (ticketKind === 'paid') {
      const priceField = card.querySelector(
        'input.js-mel-ticket-price, input[name$="[price_amount]"], input[name$="[price_number]"], input[name$="[price]"]',
      );
      const price = priceField ? parseFloat(String(priceField.value || '')) : 0;
      if (!priceField || Number.isNaN(price) || price <= 0) {
        valid = false;
      }
    }
    if (ticketKind === 'external') {
      const ext = card.querySelector('input[name$="[external_uri]"], input[type="url"][name*="external_uri"]');
      if (!ext && card.getAttribute('data-ticket-id')) {
        // Persisted tiers may omit URI from this inline subtree.
      }
      else if (ext) {
        const uri = String(ext.value || '').trim().toLowerCase();
        if (uri.indexOf('https://') !== 0) {
          valid = false;
        }
      }
      else {
        valid = false;
      }
    }
    return valid;
  }

  /** Updates outcome header (PHP-rendered preview) while editing — Event Studio UX. */
  function syncOutcomeDisplay(card) {
    const displayRoot = card.querySelector('[data-mel-ticket-outcome-display="1"], [data-mel-ticket-outcome-display]');
    if (!displayRoot) {
      return;
    }
    const titleSlot = displayRoot.querySelector('.mel-ticket-card__display-title');
    const priceSlot = displayRoot.querySelector('[data-mel-ticket-display-price]');
    const kindField = card.querySelector('select[name$="[ticket_kind]"]');
    let ticketKind = kindField ? kindField.value : card.getAttribute('data-mel-ticket-kind');

    const titleEl = card.querySelector('.js-mel-ticket-title');
    const titleTxt = titleEl ? String(titleEl.value || '').trim() : '';
    if (titleSlot) {
      titleSlot.textContent = titleTxt || Drupal.t('New ticket');
    }

    let priceTxt = Drupal.t('—');
    if (ticketKind === 'paid') {
      const priceField = card.querySelector(
        'input.js-mel-ticket-price, input[name$="[price_amount]"], input[name$="[price_number]"], input[name$="[price]"]',
      );
      const currField = card.querySelector('input[name$="[price_currency]"], input[name$="price_currency"]');
      const amt = priceField ? String(priceField.value || '').trim() : '';
      const code = currField ? String(currField.value || '').trim().toUpperCase().slice(0, 3) : '';
      if (amt !== '' && !Number.isNaN(parseFloat(amt)) && parseFloat(amt) > 0) {
        priceTxt = `${code ? code + ' ' : ''}${amt}`;
      }
    }
    else if (ticketKind === 'rsvp') {
      priceTxt = Drupal.t('$0');
    }
    else if (ticketKind === 'external') {
      priceTxt = Drupal.t('External');
    }

    if (priceSlot) {
      priceSlot.textContent = priceTxt;
    }

    card.classList.toggle('mel-ticket--complete', silentTicketOutcomeValid(card));
  }

  function updateEditState(card) {
    const validation = card.querySelector('[data-mel-ticket-validation]');
    const initial = card.getAttribute('data-mel-ticket-initial') || '';
    let invalid = false;

    editableFields(card).forEach((field) => {
      if (field.matches('[aria-invalid="true"]')) {
        invalid = true;
      }
    });

    card.classList.toggle('is-invalid', invalid);
    if (validation) {
      validation.innerHTML = '';
    }
    if (card.getAttribute('data-mel-ticket-state-value') !== 'saving') {
      setCardState(card, fieldSignature(card) === initial ? '' : 'dirty');
    }
    syncOutcomeDisplay(card);
  }

  function initInlineEditCard(card) {
    const initialized = card.hasAttribute('data-mel-ticket-inline-init');
    card.setAttribute('data-mel-ticket-initial', fieldSignature(card));
    if (!initialized) {
      card.setAttribute('data-mel-ticket-inline-init', '1');
    }
    if (initialized) {
      updateEditState(card);
      return;
    }
    editableFields(card).forEach((field) => {
      field.addEventListener('input', () => {
        if (String(field.value || '').trim() !== '') {
          clearFieldError(field);
        }
        updateEditState(card);
      });
      field.addEventListener('change', () => updateEditState(card));
      field.addEventListener('invalid', (e) => {
        e.preventDefault();
        validateRequiredFields(card);
        updateEditState(card);
      });
    });
    updateEditState(card);
  }

  function setEditMode(card, editing, persist = true) {
    card.classList.toggle('is-editing', editing);
    card.classList.toggle('is-view', !editing);
    const view = card.querySelector('[data-mel-ticket-view]');
    const edit = card.querySelector('[data-mel-ticket-edit]');
    card.querySelectorAll('[data-mel-ticket-edit-toggle]').forEach((toggle) => {
      toggle.setAttribute('aria-expanded', editing ? 'true' : 'false');
    });
    if (view) {
      view.hidden = editing;
    }
    if (edit) {
      edit.hidden = !editing;
    }
    if (persist) {
      if (editing) {
        rememberActiveEdit(card);
      }
      else {
        forgetActiveEdit(card);
      }
    }
    if (editing) {
      initInlineEditCard(card);
      if (card.getAttribute('data-mel-ticket-state-value') !== 'dirty') {
        setCardState(card, '');
      }
      const firstField = editableFields(card)[0];
      if (firstField) {
        firstField.focus({ preventScroll: true });
      }
    }
  }

  function pendingSaveIds() {
    return ticketStorageIds(PENDING_SAVE_KEY);
  }

  function writePendingSaveIds(ids) {
    writeTicketStorageIds(PENDING_SAVE_KEY, ids);
  }

  function markPendingSave(card) {
    const id = card.getAttribute('data-ticket-id');
    if (!id) {
      return;
    }
    writePendingSaveIds([...pendingSaveIds(), id]);
  }

  function consumePendingSaves(wrapper) {
    const pending = pendingSaveIds();
    if (pending.length === 0) {
      return;
    }
    const remaining = [];
    pending.forEach((id) => {
      const escapedId = window.CSS && typeof window.CSS.escape === 'function'
        ? window.CSS.escape(id)
        : String(id).replace(/"/g, '\\"');
      const card = wrapper.querySelector(`.js-mel-ticket-card[data-ticket-id="${escapedId}"]`);
      if (!card) {
        remaining.push(id);
        return;
      }
      if (card.classList.contains('is-editing')) {
        rememberActiveEdit(card);
        setCardState(card, 'dirty');
        return;
      }
      forgetActiveEdit(card);
      setCardState(card, 'saved');
      window.setTimeout(() => {
        if (card.isConnected && card.getAttribute('data-mel-ticket-state-value') === 'saved') {
          setCardState(card, '');
        }
      }, 3000);
    });
    writePendingSaveIds(remaining);
  }

  function handleDelegatedClick(e, wrapper) {
    const target = e.target && e.target.nodeType === Node.ELEMENT_NODE ? e.target : e.target.parentElement;
    if (!target) {
      return;
    }
    const editToggle = target.closest('[data-mel-ticket-edit-toggle]');
    if (editToggle && wrapper.contains(editToggle)) {
      e.preventDefault();
      e.stopImmediatePropagation();
      const card = editToggle.closest('.js-mel-ticket-card');
      if (card) {
        setEditMode(card, true);
      }
      return;
    }

    const cancel = target.closest('[data-mel-ticket-cancel]');
    if (cancel && wrapper.contains(cancel)) {
      e.preventDefault();
      e.stopImmediatePropagation();
      const card = cancel.closest('.js-mel-ticket-card');
      if (card) {
        setEditMode(card, false);
      }
      return;
    }

    const submit = target.closest('.js-mel-ticket-save, .js-mel-ticket-create');
    if (!submit || !wrapper.contains(submit)) {
      return;
    }
    const card = submit.closest('.js-mel-ticket-card');
    if (!card) {
      return;
    }
    const validatesBeforeSubmit =
      submit.classList.contains('js-mel-ticket-save') ||
      submit.classList.contains('js-mel-ticket-create');
    if (validatesBeforeSubmit && !validateRequiredFields(card)) {
      e.preventDefault();
      e.stopImmediatePropagation();
      updateEditState(card);
      const invalid = card.querySelector('[aria-invalid="true"]');
      if (invalid) {
        invalid.focus();
      }
      return;
    }
    setCardState(card, 'saving');
    markPendingSave(card);
    forgetActiveEdit(card);
  }

  function setPresetPanelOpen(wrapper, open) {
    const panel = wrapper.querySelector('.js-mel-ticket-presets');
    if (!panel) {
      return;
    }
    panel.hidden = !open;
    wrapper.querySelectorAll('[data-mel-ticket-presets-toggle]').forEach((toggle) => {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    if (open) {
      const firstPreset = panel.querySelector('.js-mel-ticket-preset');
      if (firstPreset) {
        firstPreset.focus({ preventScroll: true });
      }
    }
  }

  function initPresetSelector(wrapper) {
    wrapper.addEventListener('click', (e) => {
      const target = e.target && e.target.nodeType === Node.ELEMENT_NODE ? e.target : e.target.parentElement;
      if (!target) {
        return;
      }

      const toggle = target.closest('[data-mel-ticket-presets-toggle]');
      if (toggle && wrapper.contains(toggle)) {
        e.preventDefault();
        const panel = wrapper.querySelector('.js-mel-ticket-presets');
        setPresetPanelOpen(wrapper, panel ? panel.hidden : true);
        return;
      }

      const submit = target.closest('.js-mel-ticket-preset-submit');
      if (submit && wrapper.contains(submit)) {
        writePendingPresetFocus(submit.getAttribute('data-mel-ticket-preset-key') || '');
        return;
      }

      const card = target.closest('.js-mel-ticket-preset');
      if (!card || !wrapper.contains(card)) {
        return;
      }

      const button = card.querySelector('.js-mel-ticket-preset-submit');
      if (!button) {
        return;
      }
      e.preventDefault();
      writePendingPresetFocus(button.getAttribute('data-mel-ticket-preset-key') || '');
      button.click();
    });

    wrapper.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter' && e.key !== ' ') {
        return;
      }
      const target = e.target && e.target.nodeType === Node.ELEMENT_NODE ? e.target : e.target.parentElement;
      const card = target && target.closest ? target.closest('.js-mel-ticket-preset') : null;
      if (!card || !wrapper.contains(card)) {
        return;
      }
      const button = card.querySelector('.js-mel-ticket-preset-submit');
      if (!button) {
        return;
      }
      e.preventDefault();
      writePendingPresetFocus(button.getAttribute('data-mel-ticket-preset-key') || '');
      button.click();
    });
  }

  function initDocumentFallback() {
    if (document.documentElement.hasAttribute('data-mel-ticket-document-fallback')) {
      return;
    }
    document.documentElement.setAttribute('data-mel-ticket-document-fallback', '1');
    document.addEventListener(
      'click',
      (e) => {
        const target = e.target && e.target.nodeType === Node.ELEMENT_NODE ? e.target : e.target.parentElement;
        const editToggle = target && target.closest ? target.closest('[data-mel-ticket-edit-toggle]') : null;
        if (!editToggle) {
          return;
        }
        const wrapper = editToggle.closest('#mel-ticket-builder-ajax-wrapper');
        const card = editToggle.closest('.js-mel-ticket-card');
        if (!wrapper || !card) {
          return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        setEditMode(card, true);
      },
      true,
    );
  }

  function initEditToggle(toggle) {
    toggle.addEventListener(
      'click',
      (e) => {
        const card = toggle.closest('.js-mel-ticket-card');
        if (!card) {
          return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        setEditMode(card, true);
      },
      true,
    );
  }

  /**
   * @param {HTMLElement} list
   * @param {HTMLElement} wrapper
   */
  function initSortable(list, wrapper) {
    if (list.classList.contains('mel-ticket-list--no-reorder')) {
      return;
    }

    let dragged = null;

    list.addEventListener('dragstart', (e) => {
      const origin =
        e.target && e.target.nodeType === Node.ELEMENT_NODE
          ? e.target
          : e.target && e.target.parentElement;
      const handle = origin && origin.closest ? origin.closest('.js-mel-ticket-drag-handle') : null;
      if (!handle || !list.contains(handle)) {
        e.preventDefault();
        return;
      }
      const card = handle.closest('.js-mel-ticket-card');
      if (!card || !list.contains(card)) {
        e.preventDefault();
        return;
      }
      dragged = card;
      card.classList.add('is-dragging');
      list.classList.add('is-reordering');
      e.dataTransfer.effectAllowed = 'move';
      try {
        e.dataTransfer.setData('text/plain', card.getAttribute('data-ticket-id') || '');
      }
      catch (err) {
        // Some browsers throw on setData; reorder still works.
      }
    });

    list.addEventListener('dragend', () => {
      if (dragged) {
        dragged.classList.remove('is-dragging');
      }
      dragged = null;
      clearDropTargets(list);
      list.classList.remove('is-reordering');
    });

    list.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      if (dragged && list.contains(dragged)) {
        updateDropTargetHighlight(list, dragged, e.clientY);
      }
    });

    list.addEventListener('dragleave', (e) => {
      // relatedTarget is often null while moving between children; only clear
      // when we know the pointer left the list for another node outside it.
      if (e.relatedTarget && !list.contains(e.relatedTarget)) {
        clearDropTargets(list);
      }
    });

    list.addEventListener('drop', (e) => {
      e.preventDefault();
      clearDropTargets(list);
      const active =
        dragged && list.contains(dragged)
          ? dragged
          : list.querySelector('.js-mel-ticket-card.is-dragging');
      if (!active || !list.contains(active)) {
        return;
      }
      const after = getDragAfterElement(list, e.clientY);
      if (after == null) {
        list.appendChild(active);
      }
      else {
        list.insertBefore(active, after);
      }
      persistOrder(list);
      syncPrimaryTicketClassInList(list);
    });

    wrapper.querySelectorAll('.js-mel-ticket-drag-handle').forEach((handle) => {
      if (!list.contains(handle)) {
        return;
      }
      handle.addEventListener('keydown', (e) => {
        if (e.key === ' ' || e.key === 'Enter') {
          e.preventDefault();
        }
      });
    });
  }

  function attachTicketCards(context) {
    initDocumentFallback();

    once('mel-ticket-dnd', elementsFromContext('#mel-ticket-builder-ajax-wrapper', context)).forEach(
        (wrapper) => {
          const list = wrapper.querySelector('.js-mel-ticket-sortable');
          if (list) {
            initSortable(list, wrapper);
            syncPrimaryTicketClassInList(list);
          }
        },
    );

    once('mel-ticket-card-delegated', elementsFromContext('#mel-ticket-builder-ajax-wrapper', context)).forEach(
        (wrapper) => {
          wrapper.addEventListener('click', (e) => handleDelegatedClick(e, wrapper), true);
          wrapper.addEventListener(
            'input',
            (e) => {
              const t = e.target && e.target.nodeType === Node.ELEMENT_NODE ? e.target : null;
              if (!t || !wrapper.contains(t)) {
                return;
              }
              const card = t.closest('.js-mel-ticket-card.mel-ticket-card--new, .mel-ticket-card--new');
              if (card && wrapper.contains(card)) {
                syncOutcomeDisplay(card);
              }
            },
            true,
          );
          wrapper.addEventListener(
            'change',
            (e) => {
              const t = e.target && e.target.nodeType === Node.ELEMENT_NODE ? e.target : null;
              if (!t || !wrapper.contains(t)) {
                return;
              }
              const card = t.closest('.js-mel-ticket-card.mel-ticket-card--new');
              if (card && wrapper.contains(card)) {
                syncOutcomeDisplay(card);
              const kindEl = card.querySelector('select[name$="[ticket_kind]"]');
              const kv = kindEl ? kindEl.value : '';
              if (kv) {
                card.setAttribute('data-mel-ticket-kind', kv);
              }
              }
            },
            true,
          );
          consumePendingSaves(wrapper);
          focusNewPresetCard(wrapper);
          wrapper.querySelectorAll('.mel-ticket-card--new').forEach((c) => syncOutcomeDisplay(c));
        },
    );

    once('mel-ticket-preset-selector', elementsFromContext('#mel-ticket-builder-ajax-wrapper', context)).forEach(
        (wrapper) => initPresetSelector(wrapper),
    );

    once('mel-ticket-edit-toggle-direct', elementsFromContext('[data-mel-ticket-edit-toggle]', context)).forEach(
        (toggle) => initEditToggle(toggle),
    );

    once('mel-ticket-inline-edit', elementsFromContext('.js-mel-ticket-card[data-ticket-id]', context)).forEach(
        (card) => {
          initInlineEditCard(card);
          syncOutcomeDisplay(card);
          const shouldEdit = card.classList.contains('is-editing') || shouldRestoreEdit(card);
          setEditMode(card, shouldEdit, shouldEdit);
        },
    );
  }

  Drupal.behaviors.melTicketCards = {
    attach(context) {
      attachTicketCards(context);
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => attachTicketCards(document), { once: true });
  }
  else {
    attachTicketCards(document);
  }
})(Drupal, once, drupalSettings);
