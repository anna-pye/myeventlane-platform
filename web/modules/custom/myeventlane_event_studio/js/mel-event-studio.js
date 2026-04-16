/**
 * @file
 * Event Studio — preview sync, location display, ticket setup panel, insights.
 */
(function (Drupal, once) {
  'use strict';

  var HIGHLIGHT_MAX = 6;

  /**
   * Guided wizard step model (order is fixed).
   *
   * @type {{id: string, label: string}[]}
   */
  var MEL_STEPS = [
    { id: 'basic', label: 'Basic Info' },
    { id: 'schedule', label: 'Date & Time' },
    { id: 'location', label: 'Location' },
    { id: 'tickets', label: 'Tickets' },
    { id: 'advanced', label: 'Advanced' },
  ];

  function getSettings() {
    return (typeof drupalSettings !== 'undefined' && drupalSettings.melEventStudio) || {};
  }

  function formRoot(form) {
    return form.closest('.mel-event-studio') || form.parentElement;
  }

  /**
   * Scroll to and focus a field by name= (no anchor navigation).
   *
   * @param {HTMLFormElement} form
   * @param {string} fieldName
   */
  function melJumpToField(form, fieldName) {
    if (!form || !fieldName) {
      return;
    }
    var el = form.querySelector('[name="' + fieldName + '"]');
    if (!el && fieldName === 'mel[field_category]') {
      el =
        form.querySelector('select[name="mel[field_category][]"]') ||
        form.querySelector('input[name="mel[field_category]"]');
    }
    if (!el && fieldName === 'mel[field_event_image][]') {
      el = form.querySelector('input[name="mel[field_event_image][]"]');
    }
    if (!el && fieldName === 'mel[studio_ticket_focus]') {
      el = form.querySelector('#mel-add-ticket-tier') || form.querySelector('.mel-tier-title');
    }
    if (!el) {
      return;
    }
    var scrollTarget = el.closest('.js-form-item, .form-item, .fieldset, .mel-step') || el;
    if (scrollTarget.scrollIntoView) {
      scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    window.setTimeout(function () {
      try {
        if (el && typeof el.focus === 'function' && !el.disabled) {
          el.focus({ preventScroll: true });
        }
      } catch (err) {}
    }, 320);
  }

  function val(form, name) {
    var el = form.querySelector('[name="' + name + '"]');
    if (!el) {
      return '';
    }
    if (el.type === 'checkbox') {
      return el.checked;
    }
    return (el.value || '').trim();
  }

  /** Category: multi-select uses mel[field_category][]; autocomplete uses mel[field_category]. */
  function categoryFieldHasValue(form) {
    var multi = form.querySelector('select[name="mel[field_category][]"]');
    if (multi && multi.selectedOptions && multi.selectedOptions.length > 0) {
      return true;
    }
    return !!val(form, 'mel[field_category]');
  }

  /** Token string for X-CSRF-Token (must match GET /session/token body). */
  function melGetCsrfToken() {
    return fetch(Drupal.url('session/token'), {
      credentials: 'same-origin',
    }).then(function (response) {
      if (!response.ok) {
        return Promise.reject(new Error('session_token_http_' + response.status));
      }
      return response.text().then(function (text) {
        return (text || '').trim();
      });
    });
  }

  /** Category labels for AI payload (multi-select vs autocomplete). */
  function categoryForAiPayload(form) {
    var multi = form.querySelector('select[name="mel[field_category][]"]');
    if (multi && multi.selectedOptions && multi.selectedOptions.length > 0) {
      var parts = [];
      for (var i = 0; i < multi.selectedOptions.length; i++) {
        parts.push(multi.selectedOptions[i].text);
      }
      return parts.join(', ');
    }
    return val(form, 'mel[field_category]');
  }

  /** Tags field (entity autocomplete may use mel[field_tags] or target_id children). */
  function tagsForAiPayload(form) {
    var v = val(form, 'mel[field_tags]');
    if (v) {
      return v;
    }
    var parts = [];
    var inputs = form.querySelectorAll('input[name*="[field_tags]"]');
    for (var i = 0; i < inputs.length; i++) {
      var x = (inputs[i].value || '').trim();
      if (x) {
        parts.push(x);
      }
    }
    return parts.join(', ');
  }

  /** Panel tone/audience selects (one per form; class-scoped, not global IDs). */
  function getAiToneSelect(form) {
    return form.querySelector('select.mel-ai-tone');
  }

  function getAiAudienceSelect(form) {
    return form.querySelector('select.mel-ai-audience');
  }

  /** Panel selects → hidden mel[ai_settings][*] (submit + AI). */
  function syncAiControlsToForm(form) {
    var toneSelect = getAiToneSelect(form);
    var audienceSelect = getAiAudienceSelect(form);
    if (!toneSelect || !audienceSelect) {
      return;
    }
    var toneInput = form.querySelector('[name="mel[ai_settings][ai_tone]"]');
    var audienceInput = form.querySelector('[name="mel[ai_settings][ai_audience]"]');
    if (toneInput) {
      toneInput.value = toneSelect.value;
    }
    if (audienceInput) {
      audienceInput.value = audienceSelect.value;
    }
  }

  /** Hidden fields → panel (initial load / after rebuild). */
  function syncFormToAiControls(form) {
    var toneSelect = getAiToneSelect(form);
    var audienceSelect = getAiAudienceSelect(form);
    if (!toneSelect || !audienceSelect) {
      return;
    }
    var toneInput = form.querySelector('[name="mel[ai_settings][ai_tone]"]');
    var audienceInput = form.querySelector('[name="mel[ai_settings][ai_audience]"]');
    if (toneInput && toneInput.value) {
      toneSelect.value = toneInput.value;
    }
    if (audienceInput && audienceInput.value) {
      audienceSelect.value = audienceInput.value;
    }
  }

  function aiToneFromPanel(form) {
    var el = getAiToneSelect(form);
    return el && el.value ? el.value : 'community';
  }

  function aiAudienceFromPanel(form) {
    var el = getAiAudienceSelect(form);
    return el && el.value ? el.value : 'general';
  }

  /**
   * Word-level highlight of tokens present in new but not in old (naive).
   *
   * @param {string} oldText
   * @param {string} newText
   * @return {string}
   */
  function melSimpleDiff(oldText, newText) {
    var oldWords = oldText.split(/\s+/);
    var newWords = newText.split(/\s+/);
    var result = '';
    newWords.forEach(function (word) {
      var safe = Drupal.checkPlain(word);
      if (oldWords.indexOf(word) === -1) {
        result += '<span class="mel-diff-added">' + safe + '</span> ';
      } else {
        result += safe + ' ';
      }
    });
    return result.trim();
  }

  /**
   * Side-by-side preview before applying AI rewrite.
   *
   * @param {string} oldText
   * @param {string} newText
   * @param {function(): void} onApply
   */
  function melShowDiffModal(oldText, newText, onApply) {
    var modal = document.getElementById('mel-ai-diff-modal');
    var currentEl = document.getElementById('mel-ai-diff-current');
    var newEl = document.getElementById('mel-ai-diff-new');

    if (!modal || !currentEl || !newEl) {
      return;
    }

    var previousActive = document.activeElement;

    currentEl.textContent = oldText;
    newEl.innerHTML = melSimpleDiff(oldText, newText);

    modal.removeAttribute('hidden');

    var applyBtn = document.getElementById('mel-ai-diff-apply');
    var cancelBtn = document.getElementById('mel-ai-diff-cancel');
    var overlay = modal.querySelector('.mel-ai-diff-modal__overlay');

    if (!applyBtn || !cancelBtn) {
      return;
    }

    function cleanup() {
      modal.setAttribute('hidden', 'hidden');
      applyBtn.removeEventListener('click', applyHandler);
      cancelBtn.removeEventListener('click', cancelHandler);
      document.removeEventListener('keydown', keyHandler);
      if (overlay) {
        overlay.removeEventListener('click', cancelHandler);
      }
      if (previousActive && typeof previousActive.focus === 'function') {
        try {
          previousActive.focus();
        } catch (e) {}
      }
    }

    function applyHandler() {
      onApply();
      cleanup();
    }

    function cancelHandler() {
      cleanup();
    }

    function keyHandler(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        cancelHandler();
      }
    }

    applyBtn.addEventListener('click', applyHandler);
    cancelBtn.addEventListener('click', cancelHandler);
    document.addEventListener('keydown', keyHandler);
    if (overlay) {
      overlay.addEventListener('click', cancelHandler);
    }

    window.setTimeout(function () {
      applyBtn.focus();
    }, 0);
  }

  /**
   * AI rewrite for About (body) and What to expect (field_event_intro).
   *
   * @param {HTMLFormElement} form
   * @param {'about'|'expect'} field
   */
  function melRewriteField(form, field) {
    syncAiControlsToForm(form);
    var aboutEl = form.querySelector('[name="mel[body]"]');
    var expectEl = form.querySelector('[name="mel[field_event_intro]"]');
    var about = aboutEl ? String(aboutEl.value || '') : '';
    var expect = expectEl ? String(expectEl.value || '') : '';

    setFormState(form, 'mel-studio--saving', Drupal.t('Improving your text…'));

    var rewriteUrl = Drupal.url('vendor/events/ai/rewrite');

    melGetCsrfToken()
      .then(function (token) {
        if (!token) {
          return Promise.reject(new Error('empty_csrf_token'));
        }
        return fetch(rewriteUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
            Accept: 'application/json',
          },
          body: JSON.stringify({
            about: about,
            what_to_expect: expect,
            category: categoryForAiPayload(form),
            tone: aiToneFromPanel(form),
            audience: aiAudienceFromPanel(form),
          }),
        });
      })
      .then(function (response) {
        return response.text().then(function (text) {
          var data = {};
          if (text) {
            try {
              data = JSON.parse(text);
            } catch (parseErr) {
              data = { ok: false };
            }
          }
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok || !result.data || !result.data.ok) {
          setFormState(form, 'mel-studio--error', Drupal.t('Rewrite could not be applied.'));
          return;
        }

        var original = field === 'about' ? about : expect;
        var updated =
          field === 'about' ? result.data.about : result.data.what_to_expect;
        if (updated === undefined || updated === null) {
          updated = '';
        }

        setFormState(form, '', '');

        melShowDiffModal(original, updated, function () {
          if (field === 'about' && aboutEl) {
            aboutEl.value = updated;
            aboutEl.dispatchEvent(new Event('input', { bubbles: true }));
          }

          if (field === 'expect' && expectEl) {
            expectEl.value = updated;
            expectEl.dispatchEvent(new Event('input', { bubbles: true }));
          }

          refreshIntelligence(form);
          setFormState(form, 'mel-studio--dirty', Drupal.t('AI changes applied'));
        });
      })
      .catch(function () {
        setFormState(form, 'mel-studio--error', Drupal.t('Rewrite request failed.'));
      });
  }

  function valRadio(form, name) {
    var el = form.querySelector('[name="' + name + '"]:checked');
    return el ? el.value : '';
  }

  function getNid(form) {
    var h = form.querySelector('input[name="nid"]');
    return h && h.value ? String(h.value).trim() : '';
  }

  function ticketsWorkspaceUrl(nid) {
    if (!nid) {
      return '';
    }
    try {
      return Drupal.url('vendor/events/' + encodeURIComponent(nid) + '/tickets');
    } catch (e) {
      return '';
    }
  }

  /**
   * Tier titles from the inline ticket builder (paid/external/rsvp table).
   *
   * @param {HTMLFormElement} form
   * @return {string[]}
   */
  function collectTierTitlesFromDom(form) {
    var table = getBuilderTable(form);
    if (!table) {
      return [];
    }
    var rows = table.querySelectorAll('tbody .mel-tier-row');
    var out = [];
    rows.forEach(function (tr) {
      var titleEl = tr.querySelector('.mel-tier-title');
      var t = titleEl ? String(titleEl.value || '').trim() : '';
      if (t) {
        out.push(t);
      }
    });
    return out;
  }

  /**
   * Fills empty ticket product / ticket types autocomplete fields from server
   * (entity label + id), using event title, inline tier titles, and optional nid.
   *
   * @param {HTMLFormElement} form
   */
  function applyTicketLinkSuggestions(form) {
    if (valRadio(form, 'mel[field_event_type]') !== 'paid') {
      return;
    }
    var productInp = form.querySelector('[name="mel[field_product_target]"]');
    var typesInp = form.querySelector('[name="mel[field_ticket_types]"]');
    if (!productInp || !typesInp) {
      return;
    }
    var hasProduct = String(productInp.value || '').trim() !== '';
    var hasTypes = String(typesInp.value || '').trim() !== '';
    if (hasProduct && hasTypes) {
      return;
    }
    var eventTitle = val(form, 'mel[title]');
    if (eventTitle.length < 2) {
      return;
    }
    var tierTitles = collectTierTitlesFromDom(form);
    if (!hasTypes && tierTitles.length === 0 && hasProduct) {
      return;
    }

    if (form.getAttribute('data-mel-ticket-suggest-fetching') === '1') {
      return;
    }
    form.setAttribute('data-mel-ticket-suggest-fetching', '1');

    var suggestUrl = Drupal.url('vendor/events/studio/ticket-link-suggestions');
    var nidStr = getNid(form);
    var nidNum = nidStr ? parseInt(nidStr, 10) : 0;

    melGetCsrfToken()
      .then(function (token) {
        if (!token) {
          return Promise.reject(new Error('empty_csrf_token'));
        }
        return fetch(suggestUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
            Accept: 'application/json',
          },
          body: JSON.stringify({
            event_title: eventTitle,
            tier_titles: tierTitles,
            nid: !isNaN(nidNum) && nidNum > 0 ? nidNum : 0,
          }),
        });
      })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        form.removeAttribute('data-mel-ticket-suggest-fetching');
        if (!result.ok || !result.data || !result.data.ok) {
          return;
        }
        var d = result.data;
        var changed = false;
        if (d.product && !String(productInp.value || '').trim()) {
          productInp.value = d.product;
          changed = true;
        }
        if (d.ticket_types && !String(typesInp.value || '').trim()) {
          typesInp.value = d.ticket_types;
          changed = true;
        }
        if (changed) {
          productInp.dispatchEvent(new Event('change', { bubbles: true }));
          typesInp.dispatchEvent(new Event('change', { bubbles: true }));
          productInp.dispatchEvent(new Event('input', { bubbles: true }));
          typesInp.dispatchEvent(new Event('input', { bubbles: true }));
          setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
          refreshIntelligence(form);
        }
      })
      .catch(function () {
        form.removeAttribute('data-mel-ticket-suggest-fetching');
      });
  }

  function scheduleTicketLinkSuggestions(form) {
    if (valRadio(form, 'mel[field_event_type]') !== 'paid') {
      return;
    }
    if (form._melTicketSuggestTimer) {
      clearTimeout(form._melTicketSuggestTimer);
    }
    form._melTicketSuggestTimer = window.setTimeout(function () {
      form._melTicketSuggestTimer = null;
      applyTicketLinkSuggestions(form);
    }, 900);
  }

  function parseLocationRow(form) {
    var raw = val(form, 'mel[field_location]');
    if (!raw) {
      return null;
    }
    try {
      var o = JSON.parse(raw);
      return o && typeof o === 'object' ? o : null;
    } catch (e) {
      return null;
    }
  }

  function formatAddressFromRow(row) {
    if (!row) {
      return '';
    }
    var parts = [];
    if (row.address_line1) {
      parts.push(String(row.address_line1));
    }
    if (row.address_line2) {
      parts.push(String(row.address_line2));
    }
    var cityLine = [row.locality, row.administrative_area, row.postal_code].filter(Boolean).join(' ');
    if (cityLine) {
      parts.push(cityLine);
    }
    if (row.country_code && String(row.country_code).toUpperCase() !== 'AU') {
      parts.push(String(row.country_code));
    }
    return parts.join(', ').trim();
  }

  function parseHiddenLocation(form) {
    return formatAddressFromRow(parseLocationRow(form));
  }

  function ticketTypeLabel(tt, str) {
    if (tt === 'paid') {
      return str.typePaid || 'Paid tickets';
    }
    if (tt === 'external') {
      return str.typeExternal || 'External link';
    }
    return str.typeRsvp || 'Free RSVP';
  }

  /** Strip Drupal autocomplete suffix "Label (123)" -> label */
  function autocompleteLabel(raw) {
    var s = String(raw || '').trim();
    if (!s) {
      return '';
    }
    var m = s.match(/^(.*)\s\((\d+)\)\s*$/);
    return m ? m[1].trim() : s;
  }

  function countAutocompleteTags(raw) {
    if (!raw || !String(raw).trim()) {
      return 0;
    }
    return String(raw)
      .split(',')
      .map(function (p) {
        return p.trim();
      })
      .filter(Boolean).length;
  }

  function getBuilderTable(form) {
    return form.querySelector('table[data-mel-ticket-builder-table]');
  }

  function getHighlightsTable(form) {
    return form.querySelector('table[data-mel-highlights-table]');
  }

  function getHighlightIconOptions() {
    var s = getSettings();
    return (s && s.highlightIconOptions) || {};
  }

  function getHighlightErrorStrings() {
    var s = getSettings().highlightErrors || {};
    return {
      max: s.max || 'You can add at most 6 highlights.',
      iconNoText: s.iconNoText || 'Add text for each highlight that has an icon.',
      json: s.json || 'Highlights data could not be read. Reset the list or reload the page.',
    };
  }

  function getHighlightsErrorEl() {
    return document.getElementById('mel-highlights-editor-errors');
  }

  function setHighlightsError(form, message) {
    var box = getHighlightsErrorEl();
    var textEl = box ? box.querySelector('.mel-highlights-editor__errors-text') : null;
    if (!box || !textEl) {
      return;
    }
    var msg = (message || '').trim();
    if (msg === '') {
      textEl.textContent = '';
      box.setAttribute('hidden', 'hidden');
      return;
    }
    textEl.textContent = msg;
    box.removeAttribute('hidden');
  }

  function validateHighlightRows(rows) {
    var err = getHighlightErrorStrings();
    if (rows.length > HIGHLIGHT_MAX) {
      return { ok: false, message: err.max };
    }
    var i;
    for (i = 0; i < rows.length; i++) {
      var r = rows[i];
      var icon = r && r.icon != null ? String(r.icon).trim() : '';
      var text = r && r.text != null ? String(r.text).trim() : '';
      if (icon !== '' && text === '') {
        return { ok: false, message: err.iconNoText };
      }
    }
    return { ok: true };
  }

  function parseHighlightsHiddenWithStatus(raw) {
    if (!raw || !String(raw).trim()) {
      return { rows: [], parseError: false };
    }
    try {
      var d = JSON.parse(raw);
      if (!Array.isArray(d)) {
        return { rows: [], parseError: true };
      }
      return {
        rows: d.filter(function (x) {
          return x && typeof x === 'object';
        }),
        parseError: false,
      };
    } catch (e) {
      return { rows: [], parseError: true };
    }
  }

  function collectHighlightsFromDom(form) {
    var table = getHighlightsTable(form);
    if (!table) {
      return [];
    }
    var rows = table.querySelectorAll('tbody .mel-highlight-row');
    var out = [];
    rows.forEach(function (tr) {
      var sel = tr.querySelector('.mel-highlight-icon');
      var ta = tr.querySelector('.mel-highlight-text');
      var icon = sel ? String(sel.value || '').trim() : '';
      var text = ta ? String(ta.value || '').trim() : '';
      out.push({ icon: icon, text: text });
    });
    return out;
  }

  function createHighlightRow(row, index, totalCount) {
    var tr = document.createElement('tr');
    tr.className = 'mel-highlight-row';
    tr.setAttribute('data-highlight-index', String(index));
    var icons = getHighlightIconOptions();
    var tdIcon = document.createElement('td');
    var sel = document.createElement('select');
    sel.className = 'mel-input mel-highlight-icon';
    sel.setAttribute('aria-label', Drupal.t('Highlight icon'));
    var optEmpty = document.createElement('option');
    optEmpty.value = '';
    optEmpty.textContent = Drupal.t('None');
    sel.appendChild(optEmpty);
    Object.keys(icons).forEach(function (k) {
      var o = document.createElement('option');
      o.value = k;
      o.textContent = icons[k];
      sel.appendChild(o);
    });
    if (row && row.icon) {
      sel.value = row.icon;
    }
    tdIcon.appendChild(sel);

    var tdText = document.createElement('td');
    var ta = document.createElement('textarea');
    ta.className = 'mel-input mel-highlight-text';
    ta.rows = 2;
    ta.setAttribute('aria-label', Drupal.t('Highlight text'));
    ta.value = row && row.text != null ? String(row.text) : '';

    tdText.appendChild(ta);

    var tdOrder = document.createElement('td');
    tdOrder.className = 'mel-highlight-order';
    var up = document.createElement('button');
    up.type = 'button';
    up.className = 'mel-btn mel-btn--secondary mel-btn--touch mel-highlight-move mel-highlight-move--up';
    up.setAttribute('aria-label', Drupal.t('Move highlight @n up', { '@n': String(index + 1) }));
    up.textContent = Drupal.t('Up');
    var down = document.createElement('button');
    down.type = 'button';
    down.className = 'mel-btn mel-btn--secondary mel-btn--touch mel-highlight-move mel-highlight-move--down';
    down.setAttribute('aria-label', Drupal.t('Move highlight @n down', { '@n': String(index + 1) }));
    down.textContent = Drupal.t('Down');
    var isFirst = index === 0;
    var isLast = index >= totalCount - 1;
    up.disabled = isFirst;
    down.disabled = isLast;
    tdOrder.appendChild(up);
    tdOrder.appendChild(down);

    var tdAct = document.createElement('td');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mel-btn mel-btn--secondary mel-btn--touch mel-highlight-remove';
    btn.setAttribute(
      'aria-label',
      Drupal.t('Remove highlight @n', { '@n': String(index + 1) }),
    );
    btn.textContent = Drupal.t('Remove');
    tdAct.appendChild(btn);

    tr.appendChild(tdIcon);
    tr.appendChild(tdText);
    tr.appendChild(tdOrder);
    tr.appendChild(tdAct);
    return tr;
  }

  function updateHighlightAddButton(form, rowCount) {
    var addBtn = document.getElementById('mel-add-event-highlight');
    if (!addBtn) {
      return;
    }
    if (rowCount >= HIGHLIGHT_MAX) {
      addBtn.setAttribute('disabled', 'disabled');
    } else {
      addBtn.removeAttribute('disabled');
    }
  }

  function updateHighlightErrors(form) {
    if (form.getAttribute('data-mel-highlights-json-error') === '1') {
      return;
    }
    var rows = collectHighlightsFromDom(form);
    var v = validateHighlightRows(rows);
    if (v.ok) {
      setHighlightsError(form, '');
    } else {
      setHighlightsError(form, v.message);
    }
  }

  function renderHighlightRows(form, table, rows) {
    if (!table) {
      return;
    }
    var tbody = table.querySelector('tbody');
    if (!tbody) {
      tbody = document.createElement('tbody');
      table.appendChild(tbody);
    }
    tbody.innerHTML = '';
    var n = rows.length;
    rows.forEach(function (row, index) {
      tbody.appendChild(createHighlightRow(row, index, n));
    });
    updateHighlightAddButton(form, rows.length);
    syncHighlightsFromDomToHidden(form);
    updateHighlightErrors(form);
  }

  function syncHighlightsFromDomToHidden(form) {
    var hidden = form.querySelector('input[data-mel-highlights-state]');
    var table = getHighlightsTable(form);
    if (!hidden || !table) {
      return;
    }
    var rows = collectHighlightsFromDom(form);
    hidden.value = JSON.stringify(rows);
  }

  function forceSyncHighlightsBeforeSubmit(form) {
    try {
      syncHighlightsFromDomToHidden(form);
    } catch (e) {}
  }

  function initHighlightsBuilder(form) {
    var hidden = form.querySelector('input[data-mel-highlights-state]');
    var table = getHighlightsTable(form);
    if (!hidden || !table) {
      return;
    }
    var parsed = parseHighlightsHiddenWithStatus(hidden.value);
    var rows = parsed.rows;
    if (parsed.parseError) {
      hidden.value = '[]';
      rows = [];
      form.setAttribute('data-mel-highlights-json-error', '1');
    }
    renderHighlightRows(form, table, rows);
    if (parsed.parseError) {
      var jsonErr = getHighlightErrorStrings();
      setHighlightsError(form, jsonErr.json);
    }

    form.addEventListener(
      'click',
      function (e) {
        if (e.target.closest('#mel-add-event-highlight')) {
          e.preventDefault();
          var cur = collectHighlightsFromDom(form);
          if (cur.length >= HIGHLIGHT_MAX) {
            var maxErr = getHighlightErrorStrings();
            setHighlightsError(form, maxErr.max);
            var box = getHighlightsErrorEl();
            if (box) {
              if (!box.hasAttribute('tabindex')) {
                box.setAttribute('tabindex', '-1');
              }
              box.focus();
            }
            return;
          }
          setHighlightsError(form, '');
          cur.push({ icon: '', text: '' });
          renderHighlightRows(form, table, cur);
          setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
          refreshIntelligence(form);
          return;
        }
        var rm = e.target.closest('.mel-highlight-remove');
        if (rm && table.contains(rm)) {
          e.preventDefault();
          var tr = rm.closest('tr');
          if (!tr) {
            return;
          }
          var arr = collectHighlightsFromDom(form);
          var rowList = table.querySelectorAll('tbody .mel-highlight-row');
          var found = -1;
          rowList.forEach(function (r, i) {
            if (r === tr) {
              found = i;
            }
          });
          if (found >= 0 && found < arr.length) {
            arr.splice(found, 1);
          }
          renderHighlightRows(form, table, arr);
          setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
          refreshIntelligence(form);
          return;
        }
        var up = e.target.closest('.mel-highlight-move--up');
        var down = e.target.closest('.mel-highlight-move--down');
        if (!up && !down) {
          return;
        }
        var trMove = (up || down).closest('tr');
        if (!trMove || !table.contains(trMove)) {
          return;
        }
        e.preventDefault();
        var arr = collectHighlightsFromDom(form);
        var rowList = table.querySelectorAll('tbody .mel-highlight-row');
        var idx = -1;
        rowList.forEach(function (r, i) {
          if (r === trMove) {
            idx = i;
          }
        });
        if (idx < 0) {
          return;
        }
        if (up && idx > 0) {
          var t = arr[idx - 1];
          arr[idx - 1] = arr[idx];
          arr[idx] = t;
        }
        if (down && idx < arr.length - 1) {
          var t2 = arr[idx + 1];
          arr[idx + 1] = arr[idx];
          arr[idx] = t2;
        }
        renderHighlightRows(form, table, arr);
        setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
        refreshIntelligence(form);
      },
      true,
    );

    form.addEventListener(
      'input',
      function (e) {
        if (!e.target.closest('.mel-highlight-row')) {
          return;
        }
        if (form.getAttribute('data-mel-highlights-json-error') === '1') {
          form.removeAttribute('data-mel-highlights-json-error');
        }
        syncHighlightsFromDomToHidden(form);
        updateHighlightErrors(form);
      },
      true,
    );
    form.addEventListener(
      'change',
      function (e) {
        if (!e.target.closest('.mel-highlight-row')) {
          return;
        }
        if (form.getAttribute('data-mel-highlights-json-error') === '1') {
          form.removeAttribute('data-mel-highlights-json-error');
        }
        syncHighlightsFromDomToHidden(form);
        updateHighlightErrors(form);
      },
      true,
    );
  }

  function parseTiersHidden(raw) {
    if (!raw || !String(raw).trim()) {
      return [];
    }
    try {
      var d = JSON.parse(raw);
      if (!Array.isArray(d)) {
        return [];
      }
      return d.filter(function (x) {
        return x && typeof x === 'object';
      });
    } catch (e) {
      return [];
    }
  }

  function getRenderedTicketTableKind(table) {
    if (!table) {
      return '';
    }
    if (table.classList.contains('mel-ticket-builder-table--paid')) {
      return 'paid';
    }
    if (table.classList.contains('mel-ticket-builder-table--external')) {
      return 'external';
    }
    if (table.classList.contains('mel-ticket-builder-table--rsvp')) {
      return 'rsvp';
    }
    return '';
  }

  function normalizeTierCapacity(raw) {
    var n = parseInt(raw, 10);
    if (isNaN(n) || n < 1) {
      return 1;
    }
    return n;
  }

  function collectTiersFromDom(form) {
    var table = getBuilderTable(form);
    if (!table) {
      return [];
    }
    var rendered = getRenderedTicketTableKind(table);
    var formKind = valRadio(form, 'mel[field_event_type]');
    var rows = table.querySelectorAll('tbody .mel-tier-row');
    var out = [];
    rows.forEach(function (tr) {
      var id = parseInt(tr.getAttribute('data-tier-id'), 10);
      if (isNaN(id)) {
        id = 0;
      }
      var titleEl = tr.querySelector('.mel-tier-title');
      var title = titleEl ? String(titleEl.value || '').trim() : '';
      var capEl = tr.querySelector('.mel-tier-capacity');
      var capRaw = capEl ? capEl.value : '';
      var capacity = normalizeTierCapacity(capRaw);
      var o = {
        title: title,
        ticket_kind: formKind,
        capacity: capacity,
      };
      if (id > 0) {
        o.id = id;
      }
      if (rendered === 'paid') {
        var pe = tr.querySelector('.mel-tier-price');
        o.price_number = pe ? String(pe.value || '').trim() : '';
        o.price_currency = tr.getAttribute('data-tier-currency') || getSettings().defaultCurrency || 'AUD';
      }
      if (rendered === 'external') {
        var ee = tr.querySelector('.mel-tier-external');
        o.external_uri = ee ? String(ee.value || '').trim() : '';
      }
      if (formKind === 'paid' && o.price_number === undefined) {
        o.price_number = '';
        o.price_currency = getSettings().defaultCurrency || 'AUD';
      }
      if (formKind === 'external' && o.external_uri === undefined) {
        o.external_uri = '';
      }
      if (formKind !== 'paid') {
        delete o.price_number;
        delete o.price_currency;
      }
      if (formKind !== 'external') {
        delete o.external_uri;
      }
      out.push(o);
    });
    return out;
  }

  function updateTicketBuilderTableClass(table, kind) {
    if (!table) {
      return;
    }
    table.classList.remove(
      'mel-ticket-builder-table--rsvp',
      'mel-ticket-builder-table--paid',
      'mel-ticket-builder-table--external',
    );
    table.classList.add('mel-ticket-builder-table--' + kind);
    var ths = table.querySelectorAll('thead th');
    var midLabel = Drupal.t('—');
    if (kind === 'paid') {
      midLabel = Drupal.t('Price');
    } else if (kind === 'external') {
      midLabel = Drupal.t('Booking link');
    }
    if (ths.length > 3) {
      ths[1].textContent = midLabel;
    }
  }

  function createTierRow(formKind, tier, index) {
    var tr = document.createElement('tr');
    tr.className = 'mel-tier-row';
    tr.setAttribute('data-tier-index', String(index));
    var id = tier.id != null ? parseInt(tier.id, 10) : 0;
    if (!isNaN(id) && id > 0) {
      tr.setAttribute('data-tier-id', String(id));
    }
    if (tier.price_currency) {
      tr.setAttribute('data-tier-currency', String(tier.price_currency));
    }

    var tdTitle = document.createElement('td');
    var inpTitle = document.createElement('input');
    inpTitle.type = 'text';
    inpTitle.className = 'mel-input mel-tier-title';
    inpTitle.setAttribute('autocomplete', 'off');
    inpTitle.value = tier.title != null ? String(tier.title) : '';
    tdTitle.appendChild(inpTitle);

    var tdMid = document.createElement('td');
    tdMid.className = 'mel-tier-mid';
    if (formKind === 'paid') {
      var inpPrice = document.createElement('input');
      inpPrice.type = 'number';
      inpPrice.className = 'mel-input mel-tier-price';
      inpPrice.step = '0.01';
      inpPrice.min = '0';
      inpPrice.placeholder = '0.00';
      inpPrice.value =
        tier.price_number != null && String(tier.price_number) !== ''
          ? String(tier.price_number)
          : '';
      tdMid.appendChild(inpPrice);
    } else if (formKind === 'external') {
      var inpExt = document.createElement('input');
      inpExt.type = 'url';
      inpExt.className = 'mel-input mel-tier-external';
      inpExt.placeholder = 'https://';
      inpExt.value = tier.external_uri != null ? String(tier.external_uri) : '';
      tdMid.appendChild(inpExt);
    } else {
      var span = document.createElement('span');
      span.className = 'mel-tier-mid-placeholder';
      span.textContent = '—';
      tdMid.appendChild(span);
    }

    var tdCap = document.createElement('td');
    var inpCap = document.createElement('input');
    inpCap.type = 'number';
    inpCap.className = 'mel-input mel-tier-capacity';
    inpCap.min = '0';
    inpCap.step = '1';
    var capNum =
      tier.capacity != null && tier.capacity !== ''
        ? parseInt(tier.capacity, 10)
        : NaN;
    inpCap.value = !isNaN(capNum) && capNum > 0 ? String(capNum) : '';
    tdCap.appendChild(inpCap);

    var tdAct = document.createElement('td');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mel-btn mel-btn--secondary mel-tier-remove';
    btn.textContent = Drupal.t('Remove');
    tdAct.appendChild(btn);

    tr.appendChild(tdTitle);
    tr.appendChild(tdMid);
    tr.appendChild(tdCap);
    tr.appendChild(tdAct);
    return tr;
  }

  function renderTicketBuilderRows(form, table, tiers) {
    if (!table) {
      return;
    }
    var kind = valRadio(form, 'mel[field_event_type]') || 'rsvp';
    updateTicketBuilderTableClass(table, kind);
    var tbody = table.querySelector('tbody');
    if (!tbody) {
      tbody = document.createElement('tbody');
      table.appendChild(tbody);
    }
    tbody.innerHTML = '';
    tiers.forEach(function (tier, index) {
      tbody.appendChild(createTierRow(kind, tier, index));
    });
  }

  function syncTicketTiersFromDomToHidden(form) {
    var hidden = form.querySelector('input[name="mel[studio_ticket_tiers]"]');
    if (!hidden) {
      return;
    }

    var tiers = collectTiersFromDom(form);

    // Always persist UI → hidden
    hidden.value = JSON.stringify(tiers);
  }

  function forceSyncTicketTiersBeforeSubmit(form) {
    try {
      syncTicketTiersFromDomToHidden(form);
    } catch (e) {}
  }

  function syncTicketBuilderEventType(form) {
    var hidden = form.querySelector('input[name="mel[studio_ticket_tiers]"]');
    var table = getBuilderTable(form);
    if (!hidden || !table) {
      return;
    }
    var tt = valRadio(form, 'mel[field_event_type]');
    if (!form.hasAttribute('data-mel-last-ticket-type')) {
      form.setAttribute('data-mel-last-ticket-type', tt);
      return;
    }
    var prev = form.getAttribute('data-mel-last-ticket-type');
    if (prev === tt) {
      return;
    }
    form.setAttribute('data-mel-last-ticket-type', tt);
    var tiers = collectTiersFromDom(form);
    if (tiers.length === 0) {
      if (tt === 'rsvp') {
        tiers.push({ title: 'RSVP', ticket_kind: 'rsvp', capacity: 1 });
      } else if (tt === 'paid') {
        tiers.push({
          title: 'General Admission',
          ticket_kind: 'paid',
          price_number: '',
          capacity: 1,
          price_currency: getSettings().defaultCurrency || 'AUD',
        });
      } else if (tt === 'external') {
        tiers.push({ title: '', ticket_kind: 'external', external_uri: '', capacity: 1 });
      }
    }
    renderTicketBuilderRows(form, table, tiers);
    hidden.value = JSON.stringify(tiers);
  }

  function syncPaidTierWarning(form) {
    var warn = document.getElementById('mel-ticket-tiers-warn');
    if (!warn) {
      return;
    }
    var tt = valRadio(form, 'mel[field_event_type]');
    if (tt !== 'paid') {
      warn.setAttribute('hidden', 'hidden');
      return;
    }
    var table = getBuilderTable(form);
    var n = table ? table.querySelectorAll('tbody .mel-tier-row').length : 0;
    if (n < 1) {
      warn.removeAttribute('hidden');
    } else {
      warn.setAttribute('hidden', 'hidden');
    }
  }

  function initTicketBuilder(form) {
    var hidden = form.querySelector('input[name="mel[studio_ticket_tiers]"]');
    var table = getBuilderTable(form);
    if (!hidden || !table) {
      return;
    }
    var tiers = parseTiersHidden(hidden.value);
    var tt = valRadio(form, 'mel[field_event_type]');
    if (!tiers.length) {
      if (tt === 'rsvp') {
        tiers = [{ title: 'RSVP', ticket_kind: 'rsvp', capacity: 1 }];
      } else if (tt === 'paid') {
        tiers = [
          {
            title: 'General Admission',
            ticket_kind: 'paid',
            price_number: '',
            capacity: 1,
            price_currency: getSettings().defaultCurrency || 'AUD',
          },
        ];
      } else if (tt === 'external') {
        tiers = [{ title: '', ticket_kind: 'external', external_uri: '', capacity: 1 }];
      }
    }
    renderTicketBuilderRows(form, table, tiers);
    hidden.value = JSON.stringify(tiers);
    form.setAttribute('data-mel-last-ticket-type', tt);

    form.addEventListener(
      'click',
      function (e) {
        if (e.target.closest('#mel-add-ticket-tier')) {
          e.preventDefault();
          var tiersNow = collectTiersFromDom(form);
          var nt = valRadio(form, 'mel[field_event_type]');
          var row = { title: '', ticket_kind: nt, capacity: 1 };
          if (nt === 'paid') {
            row.price_number = '';
            row.price_currency = getSettings().defaultCurrency || 'AUD';
          }
          if (nt === 'external') {
            row.external_uri = '';
          }
          tiersNow.push(row);
          renderTicketBuilderRows(form, table, tiersNow);
          hidden.value = JSON.stringify(tiersNow);
          setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
          refreshIntelligence(form);
          return;
        }
        var rm = e.target.closest('.mel-tier-remove');
        if (!rm || !table.contains(rm)) {
          return;
        }
        e.preventDefault();
        var tr = rm.closest('tr');
        if (!tr) {
          return;
        }
        var tiersArr = collectTiersFromDom(form);
        var rowList = table.querySelectorAll('tbody .mel-tier-row');
        var found = -1;
        rowList.forEach(function (r, i) {
          if (r === tr) {
            found = i;
          }
        });
        if (found >= 0 && found < tiersArr.length) {
          tiersArr.splice(found, 1);
        }
        renderTicketBuilderRows(form, table, tiersArr);
        hidden.value = JSON.stringify(tiersArr);
        setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
        refreshIntelligence(form);
      },
      true,
    );
  }

  function syncCategoryChips(form) {
    var host = document.getElementById('mel-category-chips');
    if (!host) {
      return;
    }
    var sel = form.querySelector('select[name="mel[field_category][]"]');
    if (sel) {
      var chips = [];
      for (var i = 0; i < sel.selectedOptions.length; i++) {
        chips.push(
          '<span class="mel-chip">' +
            Drupal.checkPlain(sel.selectedOptions[i].text) +
            '</span>',
        );
      }
      host.innerHTML = chips.join('');
      return;
    }
    var raw = val(form, 'mel[field_category]');
    if (!raw) {
      host.innerHTML = '';
      return;
    }
    var parts = raw.split(',');
    var chips = [];
    parts.forEach(function (p) {
      var label = autocompleteLabel(p.trim());
      if (label) {
        chips.push(
          '<span class="mel-chip">' + Drupal.checkPlain(label) + '</span>',
        );
      }
    });
    host.innerHTML = chips.join('');
  }

  function syncCoverPreview(form) {
    var wrap = document.getElementById('mel-cover-preview');
    var img = document.getElementById('mel-cover-preview-img');
    var empty = document.getElementById('mel-cover-preview-empty');
    if (!wrap || !img || !empty) {
      return;
    }

    var mediaRoot = form.querySelector('.mel-identity-media');
    var link =
      (mediaRoot && mediaRoot.querySelector('.form-managed-file a[href*="files/"]')) ||
      form.querySelector('.form-managed-file a[href*="files/"]');
    if (link && link.href) {
      img.src = link.href;
      img.removeAttribute('hidden');
      empty.setAttribute('hidden', 'hidden');
      return;
    }

    img.removeAttribute('src');
    img.setAttribute('hidden', 'hidden');
    empty.removeAttribute('hidden');
  }

  function syncPreviewCardImage(form) {
    var img = document.getElementById('mel-preview-card-img');
    var ph = document.getElementById('mel-preview-card-placeholder');
    if (!img || !ph) {
      return;
    }
    var mediaRoot = form.querySelector('.mel-identity-media');
    var link =
      (mediaRoot && mediaRoot.querySelector('.form-managed-file a[href*="files/"]')) ||
      form.querySelector('.form-managed-file a[href*="files/"]');
    if (link && link.href) {
      img.src = link.href;
      img.alt = val(form, 'mel[field_event_image_alt]') || '';
      img.removeAttribute('hidden');
      ph.setAttribute('hidden', 'hidden');
    } else {
      img.removeAttribute('src');
      img.setAttribute('hidden', 'hidden');
      ph.removeAttribute('hidden');
    }
  }

  function bindCoverFilePreview(form) {
    var media = form.querySelector('.mel-identity-media');
    if (!media) {
      return;
    }
    once('mel-cover-file', 'input[type="file"]', media).forEach(function (input) {
      input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        if (!f || !f.type || f.type.indexOf('image/') !== 0) {
          return;
        }
        var r = new FileReader();
        r.onload = function () {
          var img = document.getElementById('mel-cover-preview-img');
          var empty = document.getElementById('mel-cover-preview-empty');
          var prevImg = document.getElementById('mel-preview-card-img');
          var ph = document.getElementById('mel-preview-card-placeholder');
          if (img && empty) {
            img.src = r.result;
            img.removeAttribute('hidden');
            empty.setAttribute('hidden', 'hidden');
          }
          if (prevImg && ph) {
            prevImg.src = r.result;
            prevImg.alt = val(form, 'mel[field_event_image_alt]') || '';
            prevImg.removeAttribute('hidden');
            ph.setAttribute('hidden', 'hidden');
          }
        };
        r.readAsDataURL(f);
      });
    });
  }

  function syncLocationDisplay(form) {
    var el = document.getElementById('mel-location-display-value');
    if (!el) {
      return;
    }
    var mode = valRadio(form, 'mel[venue_mode]');
    var formatted = '';
    if (mode === 'saved') {
      var vs = val(form, 'mel[venue_saved]');
      formatted = vs ? autocompleteLabel(vs) : '';
      if (!formatted) {
        el.textContent = Drupal.t('No venue selected yet.');
      } else {
        el.textContent = formatted;
      }
      return;
    }
    if (mode === 'create') {
      var nm = val(form, 'mel[venue_create_name]');
      var row = parseLocationRow(form);
      var addr = formatAddressFromRow(row);
      if (nm && addr) {
        el.textContent = nm + ' — ' + addr;
      } else if (nm) {
        el.textContent = nm + ' — ' + Drupal.t('Add an address using search.');
      } else if (addr) {
        el.textContent = addr;
      } else {
        el.textContent = Drupal.t('Enter a venue name and search for an address.');
      }
      return;
    }
    formatted = parseHiddenLocation(form);
    if (formatted) {
      el.textContent = formatted;
    } else if (val(form, 'mel[location_search]')) {
      el.textContent = Drupal.t('Refine your selection — pick a result from search to lock the address.');
    } else {
      el.textContent = Drupal.t('Nothing selected yet — search or pick a venue above.');
    }
  }

  function syncTicketPaidShell(form) {
    var shell = document.getElementById('mel-ticket-paid-shell');
    if (!shell) {
      return;
    }
    var tt = valRadio(form, 'mel[field_event_type]');
    if (tt === 'paid') {
      shell.removeAttribute('hidden');
    } else {
      shell.setAttribute('hidden', 'hidden');
    }
  }

  function syncTicketProductPanel(form) {
    var emptyBlock = document.getElementById('mel-ticket-product-empty');
    var okBlock = document.getElementById('mel-ticket-product-ok');
    var msgEl = document.getElementById('mel-ticket-product-empty-msg');
    var sumEl = document.getElementById('mel-ticket-product-summary');
    var typesEl = document.getElementById('mel-ticket-types-summary');
    var cta = document.getElementById('mel-ticket-cta-tickets');
    if (!emptyBlock || !okBlock) {
      return;
    }

    var tt = valRadio(form, 'mel[field_event_type]');
    if (tt !== 'paid') {
      return;
    }

    var productRaw = val(form, 'mel[field_product_target]');
    var hasProduct = productRaw !== '';
    var typesRaw = val(form, 'mel[field_ticket_types]');
    var typeCount = countAutocompleteTags(typesRaw);

    var nid = getNid(form);
    var url = ticketsWorkspaceUrl(nid);
    if (cta) {
      if (url) {
        cta.setAttribute('href', url);
        cta.removeAttribute('aria-disabled');
        cta.classList.remove('is-disabled');
      } else {
        cta.setAttribute('href', '#');
        cta.setAttribute('aria-disabled', 'true');
        cta.classList.add('is-disabled');
      }
    }

    if (hasProduct) {
      emptyBlock.setAttribute('hidden', 'hidden');
      okBlock.removeAttribute('hidden');
      if (sumEl) {
        sumEl.textContent = autocompleteLabel(productRaw) || productRaw;
      }
      if (typesEl) {
        if (typeCount > 0) {
          typesEl.textContent =
            typeCount === 1
              ? Drupal.t('1 ticket type linked for checkout setup.')
              : Drupal.t('@count ticket types linked for checkout setup.', {
                  '@count': String(typeCount),
                });
        } else {
          typesEl.textContent = Drupal.t('No extra ticket types linked — you can add them in the Tickets workspace.');
        }
      }
    } else {
      okBlock.setAttribute('hidden', 'hidden');
      emptyBlock.removeAttribute('hidden');
      if (msgEl) {
        msgEl.textContent = Drupal.t(
          'Paid events need a ticket product so checkout can run. Create types in the workspace, then link the product below.',
        );
      }
    }
  }

  function syncTicketSummary(form, str) {
    var body = document.getElementById('mel-ticket-summary-body');
    if (!body) {
      return;
    }
    var tt = valRadio(form, 'mel[field_event_type]');
    var capRaw = val(form, 'mel[rsvp_capacity]');
    var collect = !!form.querySelector('[name="mel[collect_attendee_questions]"]')?.checked;
    var ext = val(form, 'mel[external_url]');
    var productRaw = val(form, 'mel[field_product_target]');
    var hasProduct = productRaw !== '';

    var typeLabel = ticketTypeLabel(tt, str);
    var cap =
      tt === 'rsvp'
        ? capRaw !== ''
          ? capRaw
          : str.unlimited || 'Unlimited'
        : '—';
    var collectLabel =
      tt === 'rsvp' || tt === 'paid' ? (collect ? str.yes || 'Yes' : str.no || 'No') : '—';
    var extLabel =
      tt === 'external' ? (ext !== '' ? str.yes || 'Set' : str.no || 'Not set') : '—';

    var warn = '';
    if (tt === 'paid' && !hasProduct) {
      warn +=
        '<li class="mel-ticket-summary__warn">' +
        Drupal.t('Link a ticket product above, or finish in the Tickets workspace.') +
        '</li>';
    }
    var sTable = getBuilderTable(form);
    var sTierRows = sTable ? sTable.querySelectorAll('tbody .mel-tier-row').length : 0;
    if (tt === 'paid' && sTierRows < 1) {
      warn +=
        '<li class="mel-ticket-summary__warn">' +
        Drupal.t('Add at least one ticket type.') +
        '</li>';
    }

    body.innerHTML =
      '<ul class="mel-ticket-summary__list">' +
      '<li><span class="mel-ticket-summary__k">' +
      Drupal.t('Current type') +
      '</span> ' +
      typeLabel +
      '</li>' +
      '<li><span class="mel-ticket-summary__k">' +
      Drupal.t('Capacity') +
      '</span> ' +
      cap +
      '</li>' +
      '<li><span class="mel-ticket-summary__k">' +
      Drupal.t('Extra attendee details') +
      '</span> ' +
      collectLabel +
      '</li>' +
      '<li><span class="mel-ticket-summary__k">' +
      Drupal.t('External URL') +
      '</span> ' +
      extLabel +
      '</li>' +
      warn +
      '</ul>';
  }

  function venueSummaryLine(form) {
    var mode = valRadio(form, 'mel[venue_mode]');
    if (mode === 'saved') {
      var vs = val(form, 'mel[venue_saved]');
      return vs ? autocompleteLabel(vs) : Drupal.t('Choose a saved venue');
    }
    if (mode === 'create') {
      var nm = val(form, 'mel[venue_create_name]');
      var addr = parseHiddenLocation(form);
      if (nm === '') {
        return Drupal.t('New venue — add a name');
      }
      return addr ? nm + ' · ' + addr : nm;
    }
    var one = parseHiddenLocation(form);
    return one !== '' ? one : Drupal.t('One-off address — search or enter');
  }

  function scheduleSummary(form) {
    var sd = form.querySelector('[name="mel[start_date][date]"]');
    var st = form.querySelector('[name="mel[start_date][time]"]');
    var ed = form.querySelector('[name="mel[end_date][date]"]');
    var et = form.querySelector('[name="mel[end_date][time]"]');
    var a = (sd && sd.value) || '';
    var b = (st && st.value) || '';
    var c = (ed && ed.value) || '';
    var d = (et && et.value) || '';
    if (!a && !c) {
      return '—';
    }
    var start = a ? a + (b ? ' · ' + b : '') : '';
    var end = c ? c + (d ? ' · ' + d : '') : '';
    if (start && end) {
      return start + ' → ' + end;
    }
    return start || end;
  }

  function scheduleIsoHint(form) {
    var sd = form.querySelector('[name="mel[start_date][date]"]');
    var st = form.querySelector('[name="mel[start_date][time]"]');
    var a = (sd && sd.value) || '';
    var b = (st && st.value) || '';
    if (!a) {
      return '';
    }
    return a + 'T' + (b || '00:00');
  }

  function hasCoverFile(form) {
    return !!(
      form.querySelector('.mel-identity-media .form-managed-file a[href*="files/"]') ||
      form.querySelector('input[name="mel[field_event_image][]"]')?.value
    );
  }

  function getWizardStepIndex(form) {
    var v = form.getAttribute('data-mel-wizard-step');
    var n = v ? parseInt(v, 10) : 0;
    return isNaN(n) ? 0 : n;
  }

  function setWizardStepIndex(form, index) {
    form.setAttribute('data-mel-wizard-step', String(index));
  }

  function calculateScore(form) {
    var score = 0;
    var total = 10;

    if (val(form, 'mel[title]').length >= 3) score++;
    if (val(form, 'mel[summary]').length >= 10) score++;
    if (val(form, 'mel[body]').length >= 40) score++;
    if (categoryFieldHasValue(form)) score++;
    if (hasCoverFile(form)) score++;

    var sd = form.querySelector('[name="mel[start_date][date]"]');
    if (sd && sd.value) score++;

    var loc = parseHiddenLocation(form);
    if (loc) score++;

    var tt = valRadio(form, 'mel[field_event_type]');
    if (tt === 'external' && val(form, 'mel[external_url]')) score++;
    if (tt === 'paid' && val(form, 'mel[field_product_target]')) score++;
    if (tt === 'rsvp') score++;

    return Math.round((score / total) * 100);
  }

  /**
   * @param {number} percent
   * @return {string}
   */
  function strengthLabelForScore(percent) {
    var p = percent;
    if (p < 40) {
      return Drupal.t('Needs work');
    }
    if (p < 70) {
      return Drupal.t('Getting there');
    }
    if (p < 90) {
      return Drupal.t('Almost ready');
    }
    return Drupal.t('Ready to publish');
  }

  function updateProgress(form) {
    var percent = calculateScore(form);

    var fill = document.getElementById('mel-event-strength-fill');
    var scoreEl = document.getElementById('mel-event-strength-score');
    var labelEl = document.getElementById('mel-event-strength-label');

    if (fill) {
      fill.style.width = percent + '%';
    }
    if (scoreEl) {
      scoreEl.textContent = String(percent) + '%';
    }
    if (labelEl) {
      labelEl.textContent = strengthLabelForScore(percent);
    }
  }

  var SEV = { high: 0, medium: 1, low: 2 };

  /**
   * @return {{severity: string, text: string, target: string}[]}
   */
  function buildStructuredInsights(form) {
    var rows = [];
    var tt = valRadio(form, 'mel[field_event_type]');

    function add(severity, text, target) {
      rows.push({ severity: severity, text: text, target: target });
    }

    if (!hasCoverFile(form)) {
      add('high', Drupal.t('Add a cover image to increase visibility in discovery and social previews.'), 'mel[field_event_image][]');
    }

    if (!categoryFieldHasValue(form)) {
      add('high', Drupal.t('Select a category so the right audience can find your event.'), 'mel[field_category]');
    }

    if (val(form, 'mel[title]').length < 3) {
      add('high', Drupal.t('Give your event a clear, specific title — it is the first thing people see.'), 'mel[title]');
    }

    if (val(form, 'mel[summary]').length < 10) {
      add('medium', Drupal.t('Write a short summary: one or two sentences on why someone should come.'), 'mel[summary]');
    }

    if (val(form, 'mel[body]').length < 40) {
      add('medium', Drupal.t('Flesh out the description with timing, vibe, and what to expect.'), 'mel[body]');
    }

    var sd = form.querySelector('[name="mel[start_date][date]"]');
    if (!sd || !sd.value) {
      add('high', Drupal.t('Set a start date and time so calendars and reminders can work.'), 'mel[start_date][date]');
    }

    var ed = form.querySelector('[name="mel[end_date][date]"]');
    if (!ed || !ed.value) {
      add('medium', Drupal.t('Add an end time when you know it — it helps attendees plan their evening.'), 'mel[end_date][date]');
    }

    var mode = valRadio(form, 'mel[venue_mode]');
    if (mode === 'saved' && val(form, 'mel[venue_saved]') === '') {
      add('high', Drupal.t('Pick a saved venue to reuse a trusted address.'), 'mel[venue_saved]');
    } else if (mode === 'create') {
      if (val(form, 'mel[venue_create_name]') === '') {
        add('high', Drupal.t('Name your new venue so it is easy to recognise later.'), 'mel[venue_create_name]');
      }
      if (parseHiddenLocation(form) === '' && val(form, 'mel[location_search]') === '') {
        add('high', Drupal.t('Search and confirm an address for your new venue.'), 'mel[location_search]');
      }
    } else if (parseHiddenLocation(form) === '' && val(form, 'mel[location_search]') === '') {
      add('high', Drupal.t('Add a location so people know where to go.'), 'mel[location_search]');
    }

    if (tt === 'paid') {
      var hasProduct = val(form, 'mel[field_product_target]') !== '';
      if (!hasProduct) {
        add('high', Drupal.t('Link a ticket product for paid events — or create ticket types in the Tickets workspace first.'), 'mel[field_product_target]');
      }
      var tTable = getBuilderTable(form);
      var tierRows = tTable ? tTable.querySelectorAll('tbody .mel-tier-row').length : 0;
      if (tierRows < 1) {
        add('high', Drupal.t('Add at least one ticket type for paid checkout.'), 'mel[studio_ticket_focus]');
      }
    } else if (tt === 'external') {
      var u = val(form, 'mel[external_url]');
      if (u === '') {
        add('high', Drupal.t('Add the external booking or registration URL for this event.'), 'mel[external_url]');
      } else if (!/^https?:\/\//i.test(u)) {
        add('high', Drupal.t('Use a full https:// link so the button works everywhere.'), 'mel[external_url]');
      }
    }

    if (tt === 'rsvp' && form.querySelector('[name="mel[collect_attendee_questions]"]') && form.querySelector('[name="mel[collect_attendee_questions]"]').checked) {
      add('low', Drupal.t('You asked for extra attendee details — add specific questions in the Tickets workspace.'), 'mel[collect_attendee_questions]');
    }

    if (!val(form, 'mel[field_event_image_alt]') && hasCoverFile(form)) {
      add('medium', Drupal.t('Add alt text for your cover image — it helps accessibility and SEO.'), 'mel[field_event_image_alt]');
    }

    if (rows.length === 0) {
      add('low', Drupal.t('Strong start — review publish readiness below, then go live when you are ready.'), '');
    }

    rows.sort(function (a, b) {
      return SEV[a.severity] - SEV[b.severity];
    });

    return rows.slice(0, 12);
  }

  function updateInsightsChecklist(form) {
    var insights = document.getElementById('mel-insights-list');
    if (!insights) {
      return;
    }
    var items = buildStructuredInsights(form);
    insights.innerHTML = items
      .map(function (item) {
        var sev = item.severity || 'low';
        var safe = Drupal.checkPlain(item.text);
        if (item.target) {
          return (
            '<div class="mel-insight-item mel-insight-item--' +
            sev +
            '" role="listitem">' +
            '<button type="button" class="mel-insight-item__jump" data-target="' +
            Drupal.checkPlain(item.target) +
            '">' +
            safe +
            '</button></div>'
          );
        }
        return (
          '<div class="mel-insight-item mel-insight-item--' +
          sev +
          '" role="listitem"><span class="mel-insight-item__text">' +
          safe +
          '</span></div>'
        );
      })
      .join('');
  }

  function updatePreviewHints(form) {
    var host = document.getElementById('mel-preview-hints');
    if (!host) {
      return;
    }
    var hints = [];
    if (!hasCoverFile(form)) {
      hints.push({ k: 0, t: Drupal.t('No cover image yet') });
    }
    var sd = form.querySelector('[name="mel[start_date][date]"]');
    if (!sd || !sd.value) {
      hints.push({ k: 1, t: Drupal.t('No date set') });
    }
    if (!categoryFieldHasValue(form)) {
      hints.push({ k: 2, t: Drupal.t('No category') });
    }
    var mode = valRadio(form, 'mel[venue_mode]');
    var hasLoc = parseHiddenLocation(form) !== '';
    if (mode === 'saved') {
      hasLoc = val(form, 'mel[venue_saved]') !== '';
    } else if (mode === 'create') {
      hasLoc =
        val(form, 'mel[venue_create_name]') !== '' &&
        (parseHiddenLocation(form) !== '' || val(form, 'mel[location_search]') !== '');
    }
    if (!hasLoc) {
      hints.push({ k: 3, t: Drupal.t('No location') });
    }
    var title = val(form, 'mel[title]');
    if (title.length > 70) {
      hints.push({ k: 4, t: Drupal.t('Title is long for cards — consider shortening') });
    }
    hints.sort(function (a, b) {
      return a.k - b.k;
    });
    var top = hints.slice(0, 3);
    if (top.length === 0) {
      host.innerHTML = '';
      return;
    }
    host.innerHTML = top
      .map(function (h) {
        return '<span class="mel-preview-hints__item">' + Drupal.checkPlain(h.t) + '</span>';
      })
      .join('');
  }

  function updateFooterCtaState(form) {
    var footer = form.querySelector('.mel-event-studio__footer-actions');
    var submit = form.querySelector(
      '.mel-event-studio__footer-actions input[type="submit"], .mel-event-studio__footer-actions button[type="submit"]',
    );
    if (!footer) {
      return;
    }
    var score = calculateScore(form);
    var st = form.querySelector('[name="mel[status]"]');
    var pub = !!(st && st.checked);
    footer.classList.remove('mel-footer--draft-focus', 'mel-footer--publish-ready');
    if (pub && score >= 70) {
      footer.classList.add('mel-footer--publish-ready');
    } else {
      footer.classList.add('mel-footer--draft-focus');
    }
    if (submit) {
      submit.classList.toggle('mel-btn--studio-publish', pub && score >= 70);
      submit.classList.toggle('mel-btn--studio-draft', !(pub && score >= 70));
    }
  }

  function isStepComplete(step, form) {
    switch (step) {
      case 'basic':
        return !!(val(form, 'mel[title]') && categoryFieldHasValue(form));
      case 'schedule': {
        var sd = form.querySelector('[name="mel[start_date][date]"]');
        return !!(sd && sd.value);
      }
      case 'location': {
        var vm = valRadio(form, 'mel[venue_mode]');
        if (vm === 'saved') {
          return val(form, 'mel[venue_saved]') !== '';
        }
        if (vm === 'create') {
          return val(form, 'mel[venue_create_name]') !== '' && parseHiddenLocation(form) !== '';
        }
        return parseHiddenLocation(form) !== '';
      }
      case 'tickets': {
        var tt = valRadio(form, 'mel[field_event_type]');
        if (tt === 'external') return !!val(form, 'mel[external_url]');
        if (tt === 'paid') return !!val(form, 'mel[field_product_target]');
        return true;
      }
      default:
        return true;
    }
  }

  function showStep(form, index) {
    var steps = form.querySelectorAll('.mel-wizard .mel-step');
    var nav = form.querySelectorAll('#mel-wizard-nav button');
    var max = steps.length - 1;
    if (index < 0) index = 0;
    if (index > max) index = max;

    steps.forEach(function (el, i) {
      var on = i === index;
      el.style.display = on ? 'block' : 'none';
      if (on) {
        var prevBtn = el.querySelector('.mel-prev');
        var nextBtn = el.querySelector('.mel-next');
        if (prevBtn) prevBtn.disabled = index === 0;
        if (nextBtn) nextBtn.hidden = index >= MEL_STEPS.length - 1;
      }
    });

    nav.forEach(function (btn, i) {
      var on = i === index;
      btn.classList.toggle('active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });

    setWizardStepIndex(form, index);
  }

  function initMelWizard(form) {
    if (!form.querySelector('.mel-wizard') || !form.querySelector('#mel-wizard-nav')) {
      return;
    }

    showStep(form, 0);
    updateProgress(form);

    var nav = form.querySelector('#mel-wizard-nav');
    nav.querySelectorAll('button[data-step]').forEach(function (btn, i) {
      btn.addEventListener('click', function () {
        showStep(form, i);
      });
    });

    form.addEventListener(
      'click',
      function (e) {
        var nextBtn = e.target.closest('.mel-next');
        if (!nextBtn || !form.contains(nextBtn)) return;

        var stepIdx = getWizardStepIndex(form);
        var stepId = MEL_STEPS[stepIdx].id;

        if (!isStepComplete(stepId, form)) {
          alert(Drupal.t('Complete required fields before continuing.'));
          return;
        }

        if (stepIdx >= MEL_STEPS.length - 1) {
          return;
        }

        showStep(form, stepIdx + 1);
      },
      false,
    );

    form.addEventListener(
      'click',
      function (e) {
        var prevBtn = e.target.closest('.mel-prev');
        if (!prevBtn || !form.contains(prevBtn)) return;
        var stepIdx = getWizardStepIndex(form);
        showStep(form, stepIdx - 1);
      },
      false,
    );
  }

  function publishReadiness(form, init, str) {
    var blocking = [];
    var tt = valRadio(form, 'mel[field_event_type]');
    if (tt === 'external' && val(form, 'mel[external_url]') === '') {
      blocking.push(Drupal.t('Booking URL'));
    }
    if (tt === 'paid' && val(form, 'mel[field_product_target]') === '') {
      blocking.push(Drupal.t('Ticket product'));
    }
    var pTable = getBuilderTable(form);
    var paidTierRows = pTable ? pTable.querySelectorAll('tbody .mel-tier-row').length : 0;
    if (tt === 'paid' && paidTierRows < 1) {
      blocking.push(Drupal.t('Ticket types'));
    }
    var mode = valRadio(form, 'mel[venue_mode]');
    if (mode === 'saved' && val(form, 'mel[venue_saved]') === '') {
      blocking.push(Drupal.t('Venue'));
    }
    if (mode === 'create' && (val(form, 'mel[venue_create_name]') === '' || parseHiddenLocation(form) === '')) {
      blocking.push(Drupal.t('New venue details'));
    }
    if (mode === 'one_off' && parseHiddenLocation(form) === '') {
      blocking.push(Drupal.t('Address'));
    }

    var pub = !!form.querySelector('[name="mel[status]"]')?.checked;
    var parts = [];
    parts.push(pub ? str.live || 'Live' : str.draft || 'Draft');
    if (blocking.length) {
      parts.push(
        Drupal.t('Before publish: complete @items.', {
          '@items': blocking.join(', '),
        }),
      );
    } else {
      parts.push(Drupal.t('Core details look ready to publish when you are.'));
    }
    return parts.join(' ');
  }

  function refreshIntelligence(form) {
    var init = getSettings().initial || {};
    var str = getSettings().strings || {};
    var root = formRoot(form);

    syncTicketBuilderEventType(form);
    syncTicketTiersFromDomToHidden(form);
    syncHighlightsFromDomToHidden(form);
    if (form.getAttribute('data-mel-highlights-json-error') !== '1') {
      updateHighlightErrors(form);
    }

    var title = val(form, 'mel[title]') || '—';
    var titleEl = document.getElementById('mel-preview-title');
    if (titleEl) {
      titleEl.textContent = title;
    }

    var sched = scheduleSummary(form);
    var schedEl = document.getElementById('mel-preview-schedule');
    if (schedEl) {
      schedEl.textContent = sched;
      var iso = scheduleIsoHint(form);
      if (iso) {
        schedEl.setAttribute('datetime', iso);
      } else {
        schedEl.removeAttribute('datetime');
      }
    }

    var locLine = venueSummaryLine(form);
    var locEl = document.getElementById('mel-preview-location');
    if (locEl) {
      locEl.textContent = locLine;
    }

    var tickEl = document.getElementById('mel-preview-tickets');
    if (tickEl) {
      tickEl.textContent = ticketTypeLabel(valRadio(form, 'mel[field_event_type]'), str);
    }

    var statEl = document.getElementById('mel-preview-status');
    if (statEl) {
      var pub = !!form.querySelector('[name="mel[status]"]')?.checked;
      statEl.textContent = pub ? str.live || 'Live' : str.draft || 'Draft';
    }

    syncPreviewCardImage(form);
    syncLocationDisplay(form);
    syncCategoryChips(form);
    syncTicketPaidShell(form);
    syncTicketProductPanel(form);
    syncTicketSummary(form, str);
    syncCoverPreview(form);
    syncPaidTierWarning(form);

    updateProgress(form);
    updateInsightsChecklist(form);
    updatePreviewHints(form);
    updateFooterCtaState(form);

    var pr = document.getElementById('mel-publish-readiness');
    if (pr) {
      pr.textContent = publishReadiness(form, init, str);
    }

    if (root) {
      root.removeAttribute('data-mel-score');
    }
  }

  function setFormState(form, state, msg) {
    form.classList.remove('mel-studio--dirty', 'mel-studio--saving', 'mel-studio--saved', 'mel-studio--error');
    if (state) {
      form.classList.add(state);
    }
    var foot = document.getElementById('mel-studio-form-state');
    if (foot && msg) {
      foot.textContent = msg;
    }
  }

  Drupal.behaviors.melEventStudio = {
    attach: function (context) {
      once('mel-event-studio-core', 'form[data-mel-event-studio-form="1"]', context).forEach(function (form) {
        var str = getSettings().strings || {};

        initTicketBuilder(form);
        initHighlightsBuilder(form);
        refreshIntelligence(form);
        bindCoverFilePreview(form);
        initMelWizard(form);

        form.addEventListener(
          'input',
          function (e) {
            if (
              (e.target.matches && e.target.matches('[name="mel[title]"]')) ||
              (e.target.closest && e.target.closest('.mel-tier-row'))
            ) {
              scheduleTicketLinkSuggestions(form);
            }
          },
          true,
        );
        form.addEventListener(
          'change',
          function (e) {
            if (e.target.matches && e.target.matches('[name="mel[field_event_type]"]')) {
              scheduleTicketLinkSuggestions(form);
            }
          },
          true,
        );
        window.setTimeout(function () {
          scheduleTicketLinkSuggestions(form);
        }, 500);

        syncFormToAiControls(form);
        var melAiTone = getAiToneSelect(form);
        var melAiAudience = getAiAudienceSelect(form);
        if (melAiTone) {
          melAiTone.addEventListener('change', function () {
            syncAiControlsToForm(form);
          });
        }
        if (melAiAudience) {
          melAiAudience.addEventListener('change', function () {
            syncAiControlsToForm(form);
          });
        }

        form.addEventListener('submit', function () {
          syncAiControlsToForm(form);
        });

        form.addEventListener('click', function (e) {
          var jumpBtn = e.target.closest('.mel-insight-item__jump');
          if (jumpBtn && form.contains(jumpBtn)) {
            var targetName = jumpBtn.getAttribute('data-target');
            if (targetName) {
              e.preventDefault();
              melJumpToField(form, targetName);
            }
            return;
          }
          var rewriteAboutBtn = e.target.closest('#mel-ai-rewrite-about');
          if (rewriteAboutBtn && form.contains(rewriteAboutBtn)) {
            e.preventDefault();
            melRewriteField(form, 'about');
            return;
          }
          var rewriteExpectBtn = e.target.closest('#mel-ai-rewrite-expect');
          if (rewriteExpectBtn && form.contains(rewriteExpectBtn)) {
            e.preventDefault();
            melRewriteField(form, 'expect');
            return;
          }
          var genBtn = e.target.closest('#mel-ai-generate');
          if (genBtn && form.contains(genBtn)) {
            e.preventDefault();
            syncAiControlsToForm(form);
            var aiUrl = Drupal.url('vendor/events/ai/generate');

            setFormState(form, 'mel-studio--saving', Drupal.t('AI is writing your event…'));

            melGetCsrfToken()
              .then(function (token) {
                if (!token) {
                  return Promise.reject(new Error('empty_csrf_token'));
                }
                return fetch(aiUrl, {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token,
                    Accept: 'application/json',
                  },
                  body: JSON.stringify({
                    title: val(form, 'mel[title]'),
                    summary: val(form, 'mel[summary]'),
                    category: categoryForAiPayload(form),
                    tags: tagsForAiPayload(form),
                    tone: aiToneFromPanel(form),
                    audience: aiAudienceFromPanel(form),
                  }),
                });
              })
              .then(function (response) {
                return response.text().then(function (text) {
                  var data = {};
                  if (text) {
                    try {
                      data = JSON.parse(text);
                    } catch (parseErr) {
                      data = { ok: false, _parseError: true };
                    }
                  }
                  return {
                    ok: response.ok,
                    status: response.status,
                    data: data,
                  };
                });
              })
              .then(function (result) {
                if (!result.ok || !result.data || !result.data.ok) {
                  setFormState(form, 'mel-studio--error', Drupal.t('AI generation failed.'));
                  return;
                }

                if (result.data.title) {
                  var titleInput = form.querySelector('[name="mel[title]"]');
                  if (titleInput) {
                    titleInput.value = result.data.title;
                  }
                }

                if (result.data.summary) {
                  var summaryInput = form.querySelector('[name="mel[summary]"]');
                  if (summaryInput) {
                    summaryInput.value = result.data.summary;
                  }
                }

                form.dispatchEvent(new Event('input', { bubbles: true }));
                refreshIntelligence(form);
                setFormState(form, 'mel-studio--dirty', Drupal.t('AI content applied'));
              })
              .catch(function () {
                setFormState(form, 'mel-studio--error', Drupal.t('AI request failed.'));
              });
            return;
          }
          var a = e.target.closest('.mel-ticket-product-panel__cta.is-disabled');
          if (a) {
            e.preventDefault();
          }
        });

        form.addEventListener(
          'input',
          function () {
            setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
            refreshIntelligence(form);
          },
          true,
        );

        form.addEventListener(
          'change',
          function () {
            setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
            refreshIntelligence(form);
          },
          true,
        );

        var timeoutId = null;
        var url = Drupal.url('vendor/events/autosave');

        form.addEventListener(
          'input',
          function () {
            if (timeoutId !== null) {
              clearTimeout(timeoutId);
            }
            timeoutId = window.setTimeout(function () {
              timeoutId = null;
              setFormState(form, 'mel-studio--saving', Drupal.t('Saving draft…'));
              forceSyncTicketTiersBeforeSubmit(form);
              forceSyncHighlightsBeforeSubmit(form);
              syncTicketTiersFromDomToHidden(form);
              var body = new FormData(form);
              var autosaveTs = Date.now();
              body.append('mel_autosave_ts', String(autosaveTs));
              fetch(url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: {
                  Accept: 'application/json',
                },
              })
                .then(function (response) {
                  return response.json().then(function (data) {
                    return { ok: response.ok, status: response.status, data: data };
                  });
                })
                .then(function (result) {
                  if (result.status === 409) {
                    var latestTs =
                      result.data && result.data.latest_ts !== undefined && result.data.latest_ts !== null
                        ? Number(result.data.latest_ts)
                        : NaN;
                    if (!Number.isNaN(latestTs) && autosaveTs < latestTs) {
                      setFormState(
                        form,
                        'mel-studio--error',
                        Drupal.t('Newer changes exist in another tab. Reload to avoid overwriting.'),
                      );
                      return;
                    }
                    setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
                    return;
                  }
                  if (!result.ok || !result.data || !result.data.ok) {
                    setFormState(form, 'mel-studio--error', Drupal.t('Draft could not be saved.'));
                    return;
                  }
                  var nid = result.data.nid;
                  if (!nid) {
                    return;
                  }
                  var hidden = form.querySelector('input[name="nid"]');
                  if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'nid';
                    form.appendChild(hidden);
                  }
                  hidden.value = String(nid);
                  refreshIntelligence(form);
                  setFormState(form, 'mel-studio--saved', Drupal.t('Draft saved'));
                  window.setTimeout(function () {
                    if (!form.classList.contains('mel-studio--dirty')) {
                      setFormState(form, '', '');
                    }
                  }, 4000);
                })
                .catch(function () {
                  setFormState(form, 'mel-studio--error', Drupal.t('Draft could not be saved.'));
                });
            }, 1000);
          },
          true,
        );
      });

      once('mel-event-studio-location', '.mel-location-search', context).forEach(function (input) {
        input.addEventListener('place_selected', function (e) {
          var d = e.detail || {};
          var comps = d.components || {};
          var place = d.place || {};
          var formatted =
            place.formatted_address ||
            (place.formattedAddressLines && place.formattedAddressLines.length
              ? place.formattedAddressLines.join(', ')
              : '');
          var row = {
            address_line1: comps.address_line1 || formatted || '',
            address_line2: comps.address_line2 || '',
            locality: comps.locality || '',
            administrative_area: comps.administrative_area || '',
            postal_code: comps.postal_code || '',
            country_code: comps.country_code || 'AU',
          };
          var form = input.closest('form');
          if (!form) {
            return;
          }
          var hLoc = form.querySelector('input[name="mel[field_location]"]');
          var hLat = form.querySelector('input[name="mel[field_location_latitude]"]');
          var hLng = form.querySelector('input[name="mel[field_location_longitude]"]');
          if (hLoc) {
            hLoc.value = JSON.stringify(row);
          }
          if (hLat && d.lat != null) {
            hLat.value = String(d.lat);
          }
          if (hLng && d.lng != null) {
            hLng.value = String(d.lng);
          }
          refreshIntelligence(form);
        });
      });
    },
  };

  document.addEventListener(
    'submit',
    function (e) {
      var form = e.target && e.target.closest ? e.target.closest('[data-mel-event-studio-form]') : null;
      if (!form) {
        return;
      }
      forceSyncTicketTiersBeforeSubmit(form);
      forceSyncHighlightsBeforeSubmit(form);
      var rows = collectHighlightsFromDom(form);
      var hv = validateHighlightRows(rows);
      if (!hv.ok) {
        e.preventDefault();
        setHighlightsError(form, hv.message);
        var errBox = getHighlightsErrorEl();
        if (errBox && typeof errBox.focus === 'function') {
          if (!errBox.hasAttribute('tabindex')) {
            errBox.setAttribute('tabindex', '-1');
          }
          errBox.focus();
        }
      }
    },
    true,
  );
})(Drupal, once);
