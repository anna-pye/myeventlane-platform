/**
 * @file
 * Event Studio — preview sync, location display, ticket setup panel, insights.
 */
(function (Drupal, once) {
  'use strict';

  var HIGHLIGHT_MAX = 6;

  function melPrefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /** Sticky header buffer for window scroll (matches .mel-studio-scroll-step scroll-margin). */
  var MEL_SCROLL_OFFSET = 120;

  /**
   * Window scroll only — no nested scroll containers.
   *
   * @param {HTMLElement|null} target
   */
  function scrollToTarget(target) {
    if (!target) {
      return;
    }
    var y = target.getBoundingClientRect().top + window.scrollY - MEL_SCROLL_OFFSET;
    window.scrollTo({
      top: Math.max(0, y),
      behavior: melPrefersReducedMotion() ? 'auto' : 'smooth',
    });
  }

  /**
   * @param {string|HTMLElement} sel
   */
  function melScrollToSelector(sel) {
    var el = typeof sel === 'string' ? document.querySelector(sel) : sel;
    if (!el) {
      return;
    }
    scrollToTarget(el);
  }

  /**
   * Guided wizard step model (order is fixed). IDs match #mel-step-{id} in Twig.
   *
   * @type {{id: string, label: string}[]}
   */
  var MEL_STEPS = [
    { id: 'identity', label: 'Event identity' },
    { id: 'tickets', label: 'How people join' },
    { id: 'attendee', label: 'Attendee questions' },
    { id: 'standout', label: 'Stand out' },
    { id: 'preview', label: 'Preview' },
    { id: 'publish', label: 'Publish' },
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
    var progHost = el.closest('[data-mel-reveal-section="mel-description"], [data-mel-reveal-section="mel-advanced"]');
    if (progHost && progHost.classList.contains('mel-builder-reveal--hidden')) {
      progHost.classList.remove('mel-builder-reveal--hidden');
      progHost.setAttribute('aria-hidden', 'false');
    }
    var scrollTarget = el.closest('.js-form-item, .form-item, .fieldset, .mel-step') || el;
    scrollToTarget(scrollTarget);
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
   * Tier titles from the inline ticket builder draft cards.
   *
   * @param {HTMLFormElement} form
   * @return {string[]}
   */
  function collectTierTitlesFromDom(form) {
    var list = getBuilderList(form);
    if (!list) {
      return [];
    }
    var rows = list.querySelectorAll('.mel-tier-row');
    var out = [];
    rows.forEach(function (card) {
      var titleEl = card.querySelector('.mel-tier-title');
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

  function getBuilderList(form) {
    return form.querySelector('[data-mel-ticket-card-list="draft"]');
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

  /**
   * Ticket builder controls submit the same Event Studio form as Save; highlight
   * validation must not block those AJAX submits (only the main publish/save).
   */
  function isTicketBuilderSubmitter(submitter) {
    if (!submitter || !submitter.name) {
      return false;
    }
    var n = submitter.name;
    if (n.indexOf('ticket_') === 0) {
      return true;
    }
    if (/^save_\d+$/.test(n)) {
      return true;
    }
    if (/^cancel_\d+$/.test(n)) {
      return true;
    }
    if (/^edit_\d+$/.test(n)) {
      return true;
    }
    return false;
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

  /**
   * Count of ticket tier "signals" the user can perceive as configured.
   *
   * Reads from draft cards, saved AJAX cards, and hidden JSON so readiness and
   * insights agree before and after the first event save.
   *
   * @param {HTMLFormElement} form
   * @return {number}
   */
  function paidTicketTierSignalCount(form) {
    var counts = [];
    var list = getBuilderList(form);
    if (list) {
      counts.push(list.querySelectorAll('.mel-tier-row').length);
    }

    var ajaxShell = document.getElementById('mel-ticket-builder-ajax-wrapper');
    if (ajaxShell) {
      counts.push(ajaxShell.querySelectorAll('.js-mel-ticket-card[data-ticket-id]').length);
    }

    var hidden = form.querySelector('input[name="mel[studio_ticket_tiers]"]');
    if (hidden) {
      counts.push(parseTiersHidden(hidden.value).length);
    }

    if (counts.length === 0) {
      return 0;
    }
    return Math.max.apply(null, counts);
  }

  function normalizeTicketKind(kind) {
    kind = String(kind || '').trim();
    if (kind === 'paid' || kind === 'external' || kind === 'rsvp') {
      return kind;
    }
    return 'rsvp';
  }

  function normalizeTierCapacity(raw) {
    if (raw === null || raw === undefined || String(raw).trim() === '') {
      return null;
    }
    var text = String(raw).trim();
    if (!/^\d+$/.test(text)) {
      return 0;
    }
    var n = parseInt(text, 10);
    if (isNaN(n)) {
      return 0;
    }
    return n;
  }

  function getDraftCardKind(card) {
    var kindEl = card.querySelector('.mel-tier-kind');
    return normalizeTicketKind(kindEl ? kindEl.value : card.getAttribute('data-mel-ticket-kind'));
  }

  function createDefaultTier(kind) {
    kind = normalizeTicketKind(kind);
    var row = { title: '', ticket_kind: kind, capacity: null };
    if (kind === 'paid') {
      row.price_number = '';
      row.price_currency = getSettings().defaultCurrency || 'AUD';
    }
    if (kind === 'external') {
      row.external_uri = '';
    }
    return row;
  }

  function buildDraftTierFromCard(card) {
    var id = parseInt(card.getAttribute('data-tier-id'), 10);
    if (isNaN(id)) {
      id = 0;
    }
    var kind = getDraftCardKind(card);
    var titleEl = card.querySelector('.mel-tier-title');
    var capEl = card.querySelector('.mel-tier-capacity');
    var o = {
      title: titleEl ? String(titleEl.value || '').trim() : '',
      ticket_kind: kind,
      capacity: normalizeTierCapacity(capEl ? capEl.value : ''),
    };
    if (id > 0) {
      o.id = id;
    }
    if (kind === 'paid') {
      var pe = card.querySelector('.mel-tier-price');
      o.price_number = pe ? String(pe.value || '').trim() : '';
      o.price_currency = card.getAttribute('data-tier-currency') || getSettings().defaultCurrency || 'AUD';
    }
    if (kind === 'external') {
      var ee = card.querySelector('.mel-tier-external');
      o.external_uri = ee ? String(ee.value || '').trim() : '';
    }
    return o;
  }

  function collectTiersFromDom(form) {
    var list = getBuilderList(form);
    if (!list) {
      return [];
    }
    var out = [];
    list.querySelectorAll('.mel-tier-row').forEach(function (card) {
      out.push(buildDraftTierFromCard(card));
    });
    return out;
  }

  function setRadioValue(form, name, value) {
    var radios = form.querySelectorAll('[name="' + name + '"]');
    radios.forEach(function (radio) {
      radio.checked = radio.value === value;
    });
  }

  function syncTicketKindToEventType(form, kind) {
    setRadioValue(form, 'mel[field_event_type]', normalizeTicketKind(kind));
    form.setAttribute('data-mel-last-ticket-type', normalizeTicketKind(kind));
  }

  function fieldWrap(labelText, control, descriptionText) {
    var wrap = document.createElement('label');
    wrap.className = 'mel-ticket-card__field';
    var label = document.createElement('span');
    label.className = 'mel-ticket-card__field-label';
    label.textContent = labelText;
    wrap.appendChild(label);
    wrap.appendChild(control);
    if (descriptionText) {
      var description = document.createElement('span');
      description.className = 'mel-ticket-card__field-help';
      description.textContent = descriptionText;
      wrap.appendChild(description);
    }
    return wrap;
  }

  function createTextInput(className, value, placeholder) {
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'mel-input ' + className;
    input.value = value != null ? String(value) : '';
    if (placeholder) {
      input.placeholder = placeholder;
    }
    input.setAttribute('autocomplete', 'off');
    return input;
  }

  function createDraftTicketCard(form, tier, index) {
    tier = tier || createDefaultTier(valRadio(form, 'mel[field_event_type]') || 'rsvp');
    var kind = normalizeTicketKind(tier.ticket_kind || valRadio(form, 'mel[field_event_type]') || 'rsvp');
    var card = document.createElement('article');
    card.className = 'mel-card mel-ticket-card mel-ticket-card--draft mel-tier-row js-mel-ticket-card is-editing';
    card.setAttribute('data-tier-index', String(index));
    card.setAttribute('data-mel-ticket-kind', kind);
    card.setAttribute('draggable', 'true');
    if (tier.id != null && parseInt(tier.id, 10) > 0) {
      card.setAttribute('data-tier-id', String(parseInt(tier.id, 10)));
    }
    if (tier.price_currency) {
      card.setAttribute('data-tier-currency', String(tier.price_currency));
    }

    var drag = document.createElement('span');
    drag.className = 'mel-ticket-card__drag mel-ticket-drag-handle js-mel-ticket-drag-handle';
    drag.setAttribute('role', 'button');
    drag.setAttribute('tabindex', '0');
    drag.setAttribute('aria-label', Drupal.t('Drag to reorder ticket'));
    drag.setAttribute('title', Drupal.t('Drag to reorder'));
    drag.setAttribute('draggable', 'true');
    card.appendChild(drag);

    var header = document.createElement('div');
    header.className = 'mel-ticket-card__header';
    var titleGroup = document.createElement('div');
    titleGroup.className = 'mel-ticket-card__title-group';
    var title = document.createElement('div');
    title.className = 'mel-ticket-card__title';
    title.textContent = tier.title ? String(tier.title) : Drupal.t('Untitled ticket');
    titleGroup.appendChild(title);
    var state = document.createElement('span');
    state.className = 'mel-badge mel-badge--inactive';
    state.textContent = Drupal.t('Draft');
    header.appendChild(titleGroup);
    header.appendChild(state);
    card.appendChild(header);

    var fields = document.createElement('div');
    fields.className = 'mel-ticket-card__fields mel-ticket-card__fields--inline';

    var kindSelect = document.createElement('select');
    kindSelect.className = 'mel-input mel-tier-kind';
    [
      ['rsvp', Drupal.t('RSVP')],
      ['paid', Drupal.t('Paid')],
      ['external', Drupal.t('External')],
    ].forEach(function (pair) {
      var opt = document.createElement('option');
      opt.value = pair[0];
      opt.textContent = pair[1];
      kindSelect.appendChild(opt);
    });
    kindSelect.value = kind;
    fields.appendChild(fieldWrap(Drupal.t('Type'), kindSelect));

    fields.appendChild(fieldWrap(
      Drupal.t('Title'),
      createTextInput('mel-tier-title', tier.title, Drupal.t('General Admission')),
    ));

    var price = document.createElement('input');
    price.type = 'number';
    price.className = 'mel-input mel-tier-price';
    price.step = '0.01';
    price.min = '0';
    price.placeholder = '0.00';
    price.value = tier.price_number != null ? String(tier.price_number) : '';
    fields.appendChild(fieldWrap(Drupal.t('Price'), price));

    var ext = document.createElement('input');
    ext.type = 'url';
    ext.className = 'mel-input mel-tier-external';
    ext.placeholder = 'https://';
    ext.value = tier.external_uri != null ? String(tier.external_uri) : '';
    fields.appendChild(fieldWrap(Drupal.t('Booking link'), ext));

    var cap = document.createElement('input');
    cap.type = 'number';
    cap.className = 'mel-input mel-tier-capacity';
    cap.min = '1';
    cap.step = '1';
    cap.placeholder = Drupal.t('Leave empty for unlimited tickets');
    cap.value = tier.capacity != null && String(tier.capacity) !== '' ? String(tier.capacity) : '';
    fields.appendChild(fieldWrap(Drupal.t('Capacity'), cap, Drupal.t('Leave empty for unlimited tickets')));

    card.appendChild(fields);

    var validation = document.createElement('p');
    validation.className = 'mel-ticket-card__validation';
    validation.setAttribute('aria-live', 'polite');
    card.appendChild(validation);

    var actions = document.createElement('div');
    actions.className = 'mel-ticket-card__actions';
    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'mel-btn mel-btn--danger mel-tier-remove';
    remove.textContent = Drupal.t('Remove');
    actions.appendChild(remove);
    card.appendChild(actions);

    updateDraftTicketCardUi(card);
    return card;
  }

  function validateDraftTicketCard(card) {
    var kind = getDraftCardKind(card);
    var titleEl = card.querySelector('.mel-tier-title');
    var capEl = card.querySelector('.mel-tier-capacity');
    var errors = [];
    if (!titleEl || String(titleEl.value || '').trim() === '') {
      errors.push(Drupal.t('Add a ticket title.'));
    }
    var normalizedCapacity = normalizeTierCapacity(capEl ? capEl.value : '');
    if ((kind === 'paid' || kind === 'rsvp') && normalizedCapacity !== null && normalizedCapacity < 1) {
      errors.push(Drupal.t('Capacity must be empty for unlimited or at least 1.'));
    }
    if (kind === 'paid') {
      var price = card.querySelector('.mel-tier-price');
      var amount = price ? String(price.value || '').trim() : '';
      if (amount === '' || isNaN(parseFloat(amount)) || parseFloat(amount) <= 0) {
        errors.push(Drupal.t('Paid tickets need a price greater than zero.'));
      }
    }
    if (kind === 'external') {
      var external = card.querySelector('.mel-tier-external');
      var uri = external ? String(external.value || '').trim().toLowerCase() : '';
      if (uri.indexOf('https://') !== 0) {
        errors.push(Drupal.t('External tickets need an https URL.'));
      }
    }
    return errors;
  }

  function updateDraftTicketCardUi(card) {
    var kind = getDraftCardKind(card);
    card.setAttribute('data-mel-ticket-kind', kind);
    var titleEl = card.querySelector('.mel-tier-title');
    var title = card.querySelector('.mel-ticket-card__title');
    if (title) {
      var label = titleEl ? String(titleEl.value || '').trim() : '';
      title.textContent = label || Drupal.t('Untitled ticket');
    }
    card.querySelectorAll('.mel-tier-price, .mel-tier-external, .mel-tier-capacity').forEach(function (el) {
      var field = el.closest('.mel-ticket-card__field');
      if (!field) {
        return;
      }
      field.hidden =
        (el.classList.contains('mel-tier-price') && kind !== 'paid') ||
        (el.classList.contains('mel-tier-external') && kind !== 'external') ||
        (el.classList.contains('mel-tier-capacity') && kind === 'external');
    });
    var errors = validateDraftTicketCard(card);
    var validation = card.querySelector('.mel-ticket-card__validation');
    if (validation) {
      validation.textContent = errors.join(' ');
    }
    card.classList.toggle('is-invalid', errors.length > 0);
  }

  function updateDraftEmptyState(form, count) {
    var empty = form.querySelector('[data-mel-ticket-empty]');
    if (!empty) {
      return;
    }
    if (count > 0) {
      empty.setAttribute('hidden', 'hidden');
    }
    else {
      empty.removeAttribute('hidden');
    }
  }

  function renderTicketBuilderRows(form, list, tiers) {
    if (!list) {
      return;
    }
    list.innerHTML = '';
    tiers.forEach(function (tier, index) {
      list.appendChild(createDraftTicketCard(form, tier, index));
    });
    updateDraftEmptyState(form, tiers.length);
    syncTicketTiersFromDomToHidden(form);
    syncPaidTierWarning(form);
  }

  function syncTicketTiersFromDomToHidden(form) {
    var hidden = form.querySelector('input[name="mel[studio_ticket_tiers]"]');
    if (!hidden || !getBuilderList(form)) {
      return;
    }
    hidden.value = JSON.stringify(collectTiersFromDom(form));
  }

  function forceSyncTicketTiersBeforeSubmit(form) {
    try {
      syncTicketTiersFromDomToHidden(form);
    } catch (e) {}
  }

  function syncTicketBuilderEventType(form) {
    var hidden = form.querySelector('input[name="mel[studio_ticket_tiers]"]');
    var list = getBuilderList(form);
    if (!hidden || !list) {
      return;
    }
    var tt = normalizeTicketKind(valRadio(form, 'mel[field_event_type]') || 'rsvp');
    if (!form.hasAttribute('data-mel-last-ticket-type')) {
      form.setAttribute('data-mel-last-ticket-type', tt);
      return;
    }
    var prev = form.getAttribute('data-mel-last-ticket-type');
    if (prev === tt) {
      return;
    }
    form.setAttribute('data-mel-last-ticket-type', tt);
    var tiers = collectTiersFromDom(form).map(function (tier) {
      tier.ticket_kind = tt;
      if (tt !== 'paid') {
        delete tier.price_number;
        delete tier.price_currency;
      }
      else if (tier.price_number === undefined) {
        tier.price_number = '';
        tier.price_currency = getSettings().defaultCurrency || 'AUD';
      }
      if (tt !== 'external') {
        delete tier.external_uri;
      }
      else if (tier.external_uri === undefined) {
        tier.external_uri = '';
      }
      return tier;
    });
    renderTicketBuilderRows(form, list, tiers);
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
    var list = getBuilderList(form);
    var n = list ? list.querySelectorAll('.mel-tier-row').length : 0;
    if (n < 1) {
      warn.removeAttribute('hidden');
    } else {
      warn.setAttribute('hidden', 'hidden');
    }
  }

  function getDraftDragAfterElement(container, y) {
    var cards = Array.prototype.slice.call(
      container.querySelectorAll('.mel-tier-row:not(.is-dragging)'),
    );
    return cards.reduce(
      function (closest, child) {
        var box = child.getBoundingClientRect();
        var offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
          return { offset: offset, element: child };
        }
        return closest;
      },
      { offset: Number.NEGATIVE_INFINITY, element: null },
    ).element;
  }

  function initTicketBuilder(form) {
    var hidden = form.querySelector('input[name="mel[studio_ticket_tiers]"]');
    var list = getBuilderList(form);
    if (!hidden || !list) {
      return;
    }
    var tiers = parseTiersHidden(hidden.value);
    renderTicketBuilderRows(form, list, tiers);
    form.setAttribute('data-mel-last-ticket-type', normalizeTicketKind(valRadio(form, 'mel[field_event_type]') || 'rsvp'));

    var dragged = null;

    form.addEventListener(
      'click',
      function (e) {
        if (e.target.closest('#mel-add-ticket-tier')) {
          e.preventDefault();
          var tiersNow = collectTiersFromDom(form);
          tiersNow.push(createDefaultTier(valRadio(form, 'mel[field_event_type]') || 'rsvp'));
          renderTicketBuilderRows(form, list, tiersNow);
          var newCard = list.querySelector('.mel-tier-row:last-child .mel-tier-title');
          if (newCard && typeof newCard.focus === 'function') {
            newCard.focus();
          }
          setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
          refreshIntelligence(form);
          return;
        }
        var rm = e.target.closest('.mel-tier-remove');
        if (!rm || !list.contains(rm)) {
          return;
        }
        e.preventDefault();
        var card = rm.closest('.mel-tier-row');
        if (!card) {
          return;
        }
        var tiersArr = collectTiersFromDom(form);
        var rowList = list.querySelectorAll('.mel-tier-row');
        var found = -1;
        rowList.forEach(function (r, i) {
          if (r === card) {
            found = i;
          }
        });
        if (found >= 0 && found < tiersArr.length) {
          tiersArr.splice(found, 1);
        }
        renderTicketBuilderRows(form, list, tiersArr);
        setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
        refreshIntelligence(form);
      },
      true,
    );

    form.addEventListener(
      'input',
      function (e) {
        var card = e.target.closest && e.target.closest('.mel-tier-row');
        if (!card || !list.contains(card)) {
          return;
        }
        updateDraftTicketCardUi(card);
        syncTicketTiersFromDomToHidden(form);
        setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
        if (
          (e.target.matches && e.target.matches('.mel-tier-title')) ||
          (e.target.closest && e.target.closest('.mel-tier-row'))
        ) {
          scheduleTicketLinkSuggestions(form);
        }
        refreshIntelligence(form);
      },
      true,
    );

    form.addEventListener(
      'change',
      function (e) {
        var card = e.target.closest && e.target.closest('.mel-tier-row');
        if (!card || !list.contains(card)) {
          return;
        }
        if (e.target.matches && e.target.matches('.mel-tier-kind')) {
          syncTicketKindToEventType(form, e.target.value);
        }
        updateDraftTicketCardUi(card);
        syncTicketTiersFromDomToHidden(form);
        syncPaidTierWarning(form);
        setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
        refreshIntelligence(form);
      },
      true,
    );

    list.addEventListener('dragstart', function (e) {
      var origin = e.target && e.target.nodeType === Node.ELEMENT_NODE ? e.target : e.target.parentElement;
      var handle = origin && origin.closest ? origin.closest('.js-mel-ticket-drag-handle') : null;
      if (!handle || !list.contains(handle)) {
        e.preventDefault();
        return;
      }
      dragged = handle.closest('.mel-tier-row');
      if (!dragged) {
        e.preventDefault();
        return;
      }
      dragged.classList.add('is-dragging');
      list.classList.add('is-reordering');
      e.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragend', function () {
      if (dragged) {
        dragged.classList.remove('is-dragging');
      }
      dragged = null;
      list.classList.remove('is-reordering');
    });
    list.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (!dragged) {
        return;
      }
      var after = getDraftDragAfterElement(list, e.clientY);
      if (after == null) {
        list.appendChild(dragged);
      }
      else {
        list.insertBefore(dragged, after);
      }
    });
    list.addEventListener('drop', function (e) {
      e.preventDefault();
      syncTicketTiersFromDomToHidden(form);
      setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
      refreshIntelligence(form);
    });
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
          scheduleApplyLivePreview(form, false);
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
    var collectHidden = form.querySelector('[name="mel[collect_attendee_questions]"]');
    var hasAttendeeRows = parseAttendeeQuestionsState(form).length > 0;
    var collect =
      hasAttendeeRows ||
      !!(collectHidden && String(collectHidden.value || '') === '1');
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
    if (tt === 'paid' && paidTicketTierSignalCount(form) < 1) {
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

  function melUpdateNextBest(form) {
    var el = document.getElementById('mel-builder-next-best');
    if (!el || !form) {
      return;
    }
    var rows = buildStructuredInsights(form);
    if (!rows || !rows.length || !rows[0].text) {
      el.textContent = '';
      return;
    }
    el.textContent = rows[0].text || '';
    return;
  }

  function melUpdatePrimaryCta(form) {
    var btn = document.getElementById('mel-builder-primary-cta');
    if (!btn || !form) {
      return;
    }
    var rows = buildStructuredInsights(form);
    var first = rows && rows[0];
    if (first && first.target) {
      btn.textContent = Drupal.t('Go to this step');
      btn.setAttribute('data-mel-insight-target', first.target);
    } else {
      btn.textContent = Drupal.t('Review publish');
      btn.removeAttribute('data-mel-insight-target');
    }
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
      add(
        'high',
        Drupal.t('Events with images get more visibility — add a cover now so your card pops in discovery and shares.'),
        'mel[field_event_image][]',
      );
    }

    if (!categoryFieldHasValue(form)) {
      add(
        'high',
        Drupal.t('Pick a category that matches your audience — it powers filters and “you might like” placements.'),
        'mel[field_category]',
      );
    }

    if (val(form, 'mel[title]').length < 3) {
      add('high', Drupal.t('Lead with a specific title — people decide in seconds, so name the who, what, or where.'), 'mel[title]');
    }

    if (val(form, 'mel[summary]').length < 10) {
      add(
        'medium',
        Drupal.t('Add a punchy one- or two-line summary — it appears under your title on lists and social previews.'),
        'mel[summary]',
      );
    }

    if (val(form, 'mel[body]').length < 40) {
      add(
        'medium',
        Drupal.t('Expand the story with timing, vibe, and who should come — detail converts browsers into bookings.'),
        'mel[body]',
      );
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
      if (paidTicketTierSignalCount(form) < 1) {
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

  function melIsPublishSelected(form) {
    var el = form.querySelector('[name="mel[status]"]');
    if (!el) {
      return false;
    }
    if (el.type === 'checkbox') {
      return !!el.checked;
    }
    return String(el.value) === '1';
  }

  function syncPublishActionCardUi(form) {
    var card = form.querySelector('[data-mel-publish-card="1"]');
    if (!card) {
      return;
    }
    var pub = melIsPublishSelected(form);
    var draft = card.querySelector('[data-mel-publish-panel="draft"]');
    var live = card.querySelector('[data-mel-publish-panel="live"]');
    if (draft) {
      draft.hidden = pub;
    }
    if (live) {
      live.hidden = !pub;
    }
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
    var pub = melIsPublishSelected(form);
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

  function setWizardNavActive(form, activeIndex) {
    var nav = form.querySelector('#mel-wizard-nav');
    if (!nav) {
      return;
    }
    var links = nav.querySelectorAll('a.mel-nav-link');
    links.forEach(function (link) {
      link.classList.remove('is-active');
      link.classList.remove('active');
    });
    if (activeIndex >= 0 && activeIndex < links.length) {
      links[activeIndex].classList.add('is-active');
      links[activeIndex].classList.add('active');
    }
    links.forEach(function (link, i) {
      link.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');
    });
    var activeLink = links[activeIndex];
    if (activeLink && activeLink.scrollIntoView) {
      window.setTimeout(function () {
        activeLink.scrollIntoView({
          block: 'nearest',
          inline: 'nearest',
          behavior: melPrefersReducedMotion() ? 'auto' : 'smooth',
        });
      }, 60);
    }
  }

  function isStepComplete(step, form) {
    switch (step) {
      case 'identity': {
        var titleVal = val(form, 'mel[title]').trim();
        if (titleVal === '' || titleVal === Drupal.t('Untitled event')) {
          return false;
        }
        var sd = form.querySelector('[name="mel[start_date][date]"]');
        if (!sd || !sd.value) {
          return false;
        }
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
        var tt2 = valRadio(form, 'mel[field_event_type]');
        if (tt2 === 'external') {
          return val(form, 'mel[external_url]') !== '';
        }
        if (tt2 === 'paid') {
          if (val(form, 'mel[field_product_target]') === '') {
            return false;
          }
          return paidTicketTierSignalCount(form) >= 1;
        }
        return true;
      }
      case 'attendee':
      case 'standout':
      case 'preview':
      case 'publish':
      default:
        return true;
    }
  }

  function scrollToStudioSection(form, index) {
    if (index < 0 || index >= MEL_STEPS.length) {
      return;
    }
    var step = MEL_STEPS[index];
    var el = document.getElementById('mel-step-' + step.id);
    scrollToTarget(el);
    setWizardNavActive(form, index);
    setWizardStepIndex(form, index);
  }

  /**
   * @param {HTMLFormElement} form
   * @param {HTMLElement} cont Continue control (.mel-continue-button).
   */
  function melContinueToNextSection(form, cont) {
    var section = cont && cont.closest ? cont.closest('section.mel-step[data-step]') : null;
    var stepIdx = -1;
    if (section) {
      var sid = section.getAttribute('data-step');
      for (var s = 0; s < MEL_STEPS.length; s++) {
        if (MEL_STEPS[s].id === sid) {
          stepIdx = s;
          break;
        }
      }
    }
    if (stepIdx < 0) {
      stepIdx = getWizardStepIndex(form);
    }
    if (stepIdx < 0 || stepIdx >= MEL_STEPS.length) {
      return;
    }
    var validateId = MEL_STEPS[stepIdx].id;
    if (!isStepComplete(validateId, form)) {
      alert(Drupal.t('Complete required fields before continuing.'));
      return;
    }
    if (stepIdx >= MEL_STEPS.length - 1) {
      return;
    }
    scrollToStudioSection(form, stepIdx + 1);
  }

  function initMelWizard(form) {
    if (!form.querySelector('.mel-wizard') || !form.querySelector('#mel-wizard-nav')) {
      return;
    }

    var steps = form.querySelectorAll('section.mel-step[data-step]');
    setWizardStepIndex(form, 0);
    setWizardNavActive(form, 0);
    updateProgress(form);

    form.querySelectorAll('a.mel-nav-link').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var href = link.getAttribute('href') || '';
        var id = href.replace(/^#/, '');
        var target = id ? document.getElementById(id) : null;
        scrollToTarget(target);
        var stepAttr = link.getAttribute('data-step');
        var idx = -1;
        for (var s = 0; s < MEL_STEPS.length; s++) {
          if (MEL_STEPS[s].id === stepAttr) {
            idx = s;
            break;
          }
        }
        if (idx >= 0) {
          setWizardNavActive(form, idx);
          setWizardStepIndex(form, idx);
        }
      });
    });

    form.addEventListener(
      'click',
      function (e) {
        var jumpPrev = e.target.closest('#mel-studio-jump-preview, #mel-jump-to-preview-card');
        if (jumpPrev && form.contains(jumpPrev)) {
          e.preventDefault();
          openLivePreviewDrawer();
          melScrollToSelector('#mel-preview-card');
          return;
        }
      },
      false,
    );

    form.addEventListener(
      'click',
      function (e) {
        var cont = e.target.closest('.mel-continue-button');
        if (!cont || !form.contains(cont)) {
          return;
        }
        // Wizard "Continue" is always type=button. Never intercept real submits.
        if (cont.tagName !== 'BUTTON' || String(cont.type || '').toLowerCase() !== 'button') {
          return;
        }
        e.preventDefault();
        e.stopPropagation();
        melContinueToNextSection(form, cont);
      },
      true,
    );

    form.addEventListener(
      'click',
      function (e) {
        var prevBtn = e.target.closest('.mel-prev');
        if (!prevBtn || !form.contains(prevBtn)) {
          return;
        }
        var stepIdx = getWizardStepIndex(form);
        if (stepIdx > 0) {
          scrollToStudioSection(form, stepIdx - 1);
        }
      },
      false,
    );

    if (window.IntersectionObserver && steps.length) {
      var io = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (!entry.isIntersecting) {
              return;
            }
            var id = entry.target.getAttribute('data-step');
            var idx = -1;
            for (var s = 0; s < MEL_STEPS.length; s++) {
              if (MEL_STEPS[s].id === id) {
                idx = s;
                break;
              }
            }
            if (idx >= 0 && entry.intersectionRatio >= 0.1) {
              setWizardNavActive(form, idx);
              setWizardStepIndex(form, idx);
            }
          });
        },
        { rootMargin: '-40% 0px -50% 0px', threshold: 0.1 },
      );
      steps.forEach(function (s) {
        io.observe(s);
      });
    }
  }

  var PREVIEW_DEBOUNCE_MS = 300;

  function mergePreviewTiersForPricing(form) {
    var domTiers = collectTiersFromDom(form);
    if (domTiers.length) {
      return domTiers;
    }
    var hidden = form.querySelector('input[name="mel[studio_ticket_tiers]"]');
    return hidden ? parseTiersHidden(hidden.value) : [];
  }

  function formatPreviewMoney(amount, currencyCode) {
    var code = (currencyCode || 'AUD').toUpperCase();
    try {
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: code,
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
      }).format(amount);
    } catch (e) {
      return String(amount) + ' ' + code;
    }
  }

  function resolverMatchesPublishedFormTicketType(tt, pr, initPublished) {
    if (!initPublished || !pr || typeof pr.bookingMode !== 'string') {
      return false;
    }
    if (tt !== pr.bookingMode) {
      return false;
    }
    return true;
  }

  /**
   * Guest-facing ticket line: BookingFlowResolver via drupalSettings when the
   * form matches the saved published node; paid mode uses tier merge live.
   *
   * @param {HTMLFormElement} form
   * @return {string}
   */
  function buildPreviewPricingSummary(form) {
    var ps = getSettings().previewStrings || {};
    var init = getSettings().initial || {};
    var tt = valRadio(form, 'mel[field_event_type]');
    var pr = getSettings().previewResolver;

    // Paid tiers are always merged from DOM / hidden tiers so unpublished edits stay live.
    if (
      resolverMatchesPublishedFormTicketType(tt, pr, !!init.published) &&
      tt !== 'paid' &&
      pr &&
      pr.pricing &&
      typeof pr.pricing.label === 'string' &&
      pr.pricing.label !== ''
    ) {
      return pr.pricing.label;
    }

    if (tt === 'rsvp') {
      return ps.freeRsvp || 'Free RSVP';
    }
    if (tt === 'external') {
      return ps.external || 'External';
    }
    if (tt !== 'paid') {
      return '—';
    }
    var tiers = mergePreviewTiersForPricing(form);
    var defCur = getSettings().defaultCurrency || 'AUD';
    var prices = [];
    tiers.forEach(function (t) {
      if (normalizeTicketKind(t.ticket_kind) !== 'paid') {
        return;
      }
      var raw = t.price_number;
      if (raw === null || raw === undefined || String(raw).trim() === '') {
        return;
      }
      var n = parseFloat(String(raw).replace(',', '.'));
      if (isNaN(n)) {
        return;
      }
      var c = String(t.price_currency || defCur).toUpperCase();
      prices.push({ n: n, c: c });
    });
    if (prices.length === 0) {
      return ps.paidIncomplete || '';
    }
    var currency = prices[0].c;
    var mixedCurrency = prices.some(function (p) {
      return p.c !== currency;
    });
    if (mixedCurrency) {
      return ps.multiplePrices || 'Multiple prices';
    }
    var nums = prices.map(function (p) {
      return p.n;
    });
    nums.sort(function (a, b) {
      return a - b;
    });
    var lowest = nums[0];
    var hasMultiple = nums[nums.length - 1] > lowest + 1e-9;
    var lowestNonZero = null;
    for (var i = 0; i < nums.length; i++) {
      if (nums[i] > 1e-9) {
        lowestNonZero = nums[i];
        break;
      }
    }
    if (lowest <= 1e-9) {
      if (lowestNonZero != null) {
        return Drupal.t('From @price', { '@price': formatPreviewMoney(lowestNonZero, currency) });
      }
      return ps.free || 'Free';
    }
    if (hasMultiple) {
      return Drupal.t('From @price', { '@price': formatPreviewMoney(lowest, currency) });
    }
    return formatPreviewMoney(lowest, currency);
  }

  function applyPreviewCtaButton(form) {
    var a = document.getElementById('mel-preview-cta');
    if (!a) {
      return;
    }
    var ps = getSettings().previewStrings || {};
    var urls = getSettings().urls || {};
    var book = urls.book || '';
    var tt = valRadio(form, 'mel[field_event_type]');
    var ext = val(form, 'mel[external_url]');
    var nid = getNid(form);
    var init = getSettings().initial || {};
    var pr = getSettings().previewResolver;

    a.classList.remove('is-disabled');
    a.removeAttribute('aria-disabled');
    a.removeAttribute('target');
    a.removeAttribute('rel');

    if (
      resolverMatchesPublishedFormTicketType(tt, pr, !!init.published) &&
      tt !== 'external' &&
      pr &&
      typeof pr.cta === 'object' &&
      pr.cta !== null &&
      typeof pr.cta.label === 'string' &&
      pr.cta.label !== '' &&
        (tt !== 'paid' ||
          (val(form, 'mel[field_product_target]') !== '' && paidTicketTierSignalCount(form) >= 1))
    ) {
      var cta = pr.cta;
      var type = typeof cta.type === 'string' ? cta.type : '';
      var href = '';
      if (!cta.disabled && type === 'internal' && typeof cta.url === 'string' && /^https?:\/\//i.test(cta.url)) {
        href = cta.url;
      }
      if (href) {
        a.setAttribute('href', href);
      } else {
        a.setAttribute('href', '#');
      }
      if (cta.disabled) {
        a.classList.add('is-disabled');
        a.setAttribute('aria-disabled', 'true');
      }
      a.textContent = cta.label;
      return;
    }

    if (tt === 'external') {
      if (ext && /^https?:\/\//i.test(ext)) {
        a.setAttribute('href', ext);
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
        a.textContent = ps.ctaExternal || 'View details';
      } else {
        a.setAttribute('href', '#');
        a.textContent = ps.ctaDisabled || 'Complete ticket setup';
        a.classList.add('is-disabled');
        a.setAttribute('aria-disabled', 'true');
      }
      return;
    }

    if (!nid) {
      a.setAttribute('href', '#');
      a.textContent = ps.ctaSaveFirst || 'Save event to enable booking link';
      a.classList.add('is-disabled');
      a.setAttribute('aria-disabled', 'true');
      return;
    }

    if (tt === 'rsvp') {
      if (book) {
        a.setAttribute('href', book);
        a.textContent = ps.ctaRsvp || 'RSVP free';
      } else {
        a.setAttribute('href', '#');
        a.textContent = ps.ctaDisabled || 'Complete ticket setup';
        a.classList.add('is-disabled');
        a.setAttribute('aria-disabled', 'true');
      }
      return;
    }

    if (tt === 'paid') {
      var productOk = val(form, 'mel[field_product_target]') !== '';
      var tiersOk = paidTicketTierSignalCount(form) >= 1;
      if (productOk && tiersOk && book) {
        a.setAttribute('href', book);
        a.textContent = ps.ctaTickets || 'Get your tickets';
      } else {
        a.setAttribute('href', '#');
        a.textContent = ps.ctaDisabled || 'Complete ticket setup';
        a.classList.add('is-disabled');
        a.setAttribute('aria-disabled', 'true');
      }
      return;
    }

    a.setAttribute('href', '#');
    a.textContent = ps.ctaDisabled || 'Complete ticket setup';
    a.classList.add('is-disabled');
    a.setAttribute('aria-disabled', 'true');
  }

  function applyLivePreview(form) {
    var str = getSettings().strings || {};

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

    var modeEl = document.getElementById('mel-preview-booking-mode');
    if (modeEl) {
      modeEl.textContent = ticketTypeLabel(valRadio(form, 'mel[field_event_type]'), str);
    }

    var pricingEl = document.getElementById('mel-preview-pricing');
    if (pricingEl) {
      pricingEl.textContent = buildPreviewPricingSummary(form);
    }

    var statEl = document.getElementById('mel-preview-status');
    if (statEl) {
      var pub = melIsPublishSelected(form);
      statEl.textContent = pub ? str.live || 'Live' : str.draft || 'Draft';
    }

    syncPreviewCardImage(form);
    applyPreviewCtaButton(form);
  }

  /**
   * @param {HTMLFormElement} form
   * @param {boolean} useDebounce
   */
  function scheduleApplyLivePreview(form, useDebounce) {
    if (!form._melPreviewTimer) {
      form._melPreviewTimer = { id: null };
    }
    var st = form._melPreviewTimer;
    if (st.id !== null) {
      window.clearTimeout(st.id);
      st.id = null;
    }
    if (!useDebounce) {
      applyLivePreview(form);
      return;
    }
    st.id = window.setTimeout(function () {
      st.id = null;
      applyLivePreview(form);
    }, PREVIEW_DEBOUNCE_MS);
  }

  function initPreviewDrawer(form) {
    var root = document.getElementById('mel-live-preview');
    var toggle = document.getElementById('mel-preview-drawer-toggle');
    if (!root || !toggle || toggle.dataset.melBound === '1') {
      return;
    }
    toggle.dataset.melBound = '1';
    var mq = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 767px)') : null;
    var ps = getSettings().previewStrings || {};

    function syncAria() {
      var open = root.classList.contains('mel-preview--drawer-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? (ps.drawerClose || 'Hide preview') : (ps.drawerOpen || 'Show preview'));
    }

    function isMobile() {
      return mq ? mq.matches : window.innerWidth < 768;
    }

    function setOpen(open) {
      root.classList.toggle('mel-preview--drawer-open', !!open);
      syncAria();
    }

    toggle.addEventListener('click', function () {
      if (!isMobile()) {
        return;
      }
      setOpen(!root.classList.contains('mel-preview--drawer-open'));
    });

    if (mq && typeof mq.addEventListener === 'function') {
      mq.addEventListener('change', function () {
        if (!mq.matches) {
          setOpen(true);
        }
      });
    } else if (mq && typeof mq.addListener === 'function') {
      mq.addListener(function () {
        if (!mq.matches) {
          setOpen(true);
        }
      });
    }

    syncAria();
    if (!isMobile()) {
      setOpen(true);
    } else {
      setOpen(false);
    }
  }

  function openLivePreviewDrawer() {
    var root = document.getElementById('mel-live-preview');
    var toggle = document.getElementById('mel-preview-drawer-toggle');
    if (!root) {
      return;
    }
    root.classList.add('mel-preview--drawer-open');
    if (toggle) {
      var ps = getSettings().previewStrings || {};
      toggle.setAttribute('aria-expanded', 'true');
      toggle.setAttribute('aria-label', ps.drawerClose || 'Hide preview');
    }
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
    if (tt === 'paid' && paidTicketTierSignalCount(form) < 1) {
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

    var pub = melIsPublishSelected(form);
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

  function refreshIntelligence(form, debouncePreview) {
    if (debouncePreview === undefined) {
      debouncePreview = false;
    }
    var init = getSettings().initial || {};
    var str = getSettings().strings || {};
    var root = formRoot(form);

    syncTicketBuilderEventType(form);
    syncTicketTiersFromDomToHidden(form);
    syncHighlightsFromDomToHidden(form);
    if (form.getAttribute('data-mel-highlights-json-error') !== '1') {
      updateHighlightErrors(form);
    }

    syncLocationDisplay(form);
    syncCategoryChips(form);
    syncTicketPaidShell(form);
    syncTicketProductPanel(form);
    syncTicketSummary(form, str);
    syncCoverPreview(form);
    syncPaidTierWarning(form);

    updateProgress(form);
    updateInsightsChecklist(form);
    melUpdateNextBest(form);
    melUpdatePrimaryCta(form);
    updatePreviewHints(form);
    syncPublishActionCardUi(form);
    updateFooterCtaState(form);

    var pr = document.getElementById('mel-publish-readiness');
    if (pr) {
      pr.textContent = publishReadiness(form, init, str);
    }

    if (root) {
      root.removeAttribute('data-mel-score');
    }

    syncCollectHiddenFromAttendees(form);
    melProgressiveRevealBuilder(form);

    scheduleApplyLivePreview(form, debouncePreview);
  }

  function parseAttendeeQuestionsState(form) {
    var h = form.querySelector('[data-mel-attendee-questions-state="1"]');
    if (!h || !String(h.value || '').trim()) {
      return [];
    }
    try {
      var d = JSON.parse(h.value);
      return Array.isArray(d) ? d : [];
    } catch (err) {
      return [];
    }
  }

  function writeAttendeeQuestionsState(form, rows, rerender) {
    var h = form.querySelector('[data-mel-attendee-questions-state="1"]');
    if (!h) {
      return;
    }
    try {
      h.value = JSON.stringify(rows);
    } catch (err) {
      h.value = '[]';
    }
    syncCollectHiddenFromAttendees(form);
    var str = getSettings().strings || {};
    syncTicketSummary(form, str);
    melProgressiveRevealBuilder(form);
    updateInsightsChecklist(form);
    melUpdateNextBest(form);
    updateFooterCtaState(form);
    melMaybeOpenAttendeeDetails(form, rows.length > 0);
    if (rerender) {
      renderAttendeeQuestionRows(form);
    }
  }

  function syncCollectHiddenFromAttendees(form) {
    var sync = form.querySelector('[data-mel-collect-attendee-sync="1"]');
    if (!sync) {
      return;
    }
    var n = parseAttendeeQuestionsState(form).length;
    sync.value = n > 0 ? '1' : '0';
  }

  function melMaybeOpenAttendeeDetails(form, shouldOpen) {
    var det = form.querySelector('#mel-attendee-questions-details');
    if (!det || det.tagName !== 'DETAILS') {
      return;
    }
    if (shouldOpen) {
      det.open = true;
    }
  }

  function melProgressiveRevealBuilder(form) {
    var identityComplete = isStepComplete('identity', form);

    var descEl = form.querySelector('[data-mel-reveal-section="mel-description"]');
    var advEl = form.querySelector('[data-mel-reveal-section="mel-advanced"]');
    var ticketsEl = form.querySelector('[data-mel-reveal-section="tickets"]');
    var attendeeEl = form.querySelector('[data-mel-reveal-section="attendee"]');

    function setRevealHidden(el, hide) {
      if (!el) {
        return;
      }
      el.classList.toggle('mel-builder-reveal--hidden', !!hide);
      el.setAttribute('aria-hidden', hide ? 'true' : 'false');
    }

    var suppressProgressive = !identityComplete;
    try {
      setRevealHidden(descEl, suppressProgressive);
      setRevealHidden(advEl, suppressProgressive);
    } catch (errReveal) {
      setRevealHidden(descEl, false);
      setRevealHidden(advEl, false);
      if (typeof console !== 'undefined' && console.warn) {
        console.warn('MelEventStudio: progressive reveal fallback.', errReveal);
      }
    }

    // Tickets, attendee questions, donations, and listing extras stay visible — no identity gate.
    setRevealHidden(ticketsEl, false);
    setRevealHidden(attendeeEl, false);

    [ticketsEl, attendeeEl].forEach(function (el) {
      if (!el || !el.classList.contains('mel-builder-reveal--hidden')) {
        return;
      }
      el.classList.remove('mel-builder-reveal--hidden');
      el.setAttribute('aria-hidden', 'false');
    });
  }

  function renderAttendeeQuestionRows(form) {
    var host = form.querySelector('[data-mel-attendee-question-list="1"]');
    var hState = form.querySelector('[data-mel-attendee-questions-state="1"]');
    if (!host || !hState) {
      return;
    }
    var rows = parseAttendeeQuestionsState(form);
    host.innerHTML = '';
    rows.forEach(function (row, idx) {
      var wrap = document.createElement('div');
      wrap.className = 'mel-attendee-row';
      wrap.setAttribute('data-mel-attendee-row-idx', String(idx));

      if (row.vendor_question_id) {
        var lib = null;
        var libList = getSettings().vendorQuestionLibrary || [];
        for (var qi = 0; qi < libList.length; qi++) {
          if (String(libList[qi].id) === String(row.vendor_question_id)) {
            lib = libList[qi];
            break;
          }
        }
        wrap.innerHTML =
          '<p class="mel-attendee-row__lib"><span class="mel-attendee-row__label">' +
          Drupal.checkPlain(lib ? lib.label : 'Library question') +
          '</span> <button type="button" class="mel-btn mel-btn--ghost mel-attendee-remove" data-mel-attendee-remove="' +
          String(idx) +
          '">' +
          Drupal.t('Remove') +
          '</button></p>';
      } else {
        var req = row.required ? ' checked' : '';
        var libSave = row.save_to_library ? ' checked' : '';
        wrap.innerHTML =
          '<div class="mel-form-grid mel-attendee-row__grid">' +
          '<label class="form-item"><span class="form-item__label">' +
          Drupal.t('Question') +
          '</span><input type="text" class="mel-input mel-attendee-label" data-idx="' +
          String(idx) +
          '" value="' +
          Drupal.checkPlain(row.label || '') +
          '" /></label>' +
          '<label class="form-item"><span class="form-item__label">' +
          Drupal.t('Save to library') +
          '</span><input type="checkbox" class="mel-attendee-save-lib" data-idx="' +
          String(idx) +
          '"' +
          libSave +
          ' /></label>' +
          '</div>' +
          '<label class="form-item"><input type="checkbox" class="mel-attendee-required" data-idx="' +
          String(idx) +
          '"' +
          req +
          ' /> ' +
          Drupal.t('Required') +
          '</label>' +
          '<button type="button" class="mel-btn mel-btn--ghost mel-attendee-remove" data-mel-attendee-remove="' +
          String(idx) +
          '">' +
          Drupal.t('Remove') +
          '</button>';
      }
      host.appendChild(wrap);
    });
  }

  function attendeePresetDefinitions() {
    return {
      dietary: {
        label: Drupal.t('Dietary requirements'),
        type: 'textarea',
        machine_name: 'dietary_requirements',
      },
      accessibility: {
        label: Drupal.t('Accessibility needs'),
        type: 'textarea',
        machine_name: 'accessibility_needs',
      },
      phone: { label: Drupal.t('Phone number'), type: 'tel', machine_name: 'phone_number' },
    };
  }

  function melAttendeeAddPreset(form, key) {
    var defs = attendeePresetDefinitions();
    var def = defs[key];
    if (!def) {
      if (key === 'custom') {
        var t = window.prompt(Drupal.t('Question text'), '');
        if (!t || !String(t).trim()) {
          return;
        }
        def = {
          label: String(t).trim(),
          type: 'textfield',
          machine_name: '',
        };
      } else {
        return;
      }
    }
    var rows = parseAttendeeQuestionsState(form);
    var machine = def.machine_name || '';
    var dup = machine && rows.some(function (r) { return r.machine_name === machine; });
    if (dup) {
      return;
    }
    rows.push({
      label: def.label,
      type: def.type,
      required: false,
      save_to_library: false,
      machine_name: machine,
    });
    writeAttendeeQuestionsState(form, rows, true);
  }

  function melAttendeeAddFromLibrary(form) {
    var sel = form.querySelector('[data-mel-attendee-library-select="1"]');
    if (!sel || !sel.value) {
      return;
    }
    var id = parseInt(sel.value, 10);
    if (isNaN(id) || id < 1) {
      return;
    }
    var rows = parseAttendeeQuestionsState(form);
    if (rows.some(function (r) { return r.vendor_question_id === id; })) {
      return;
    }
    rows.push({ vendor_question_id: id });
    writeAttendeeQuestionsState(form, rows, true);
  }

  function initAttendeeQuestionsBuilder(form) {
    if (!form.querySelector('[data-mel-attendee-questions-state="1"]')) {
      return;
    }
    renderAttendeeQuestionRows(form);
    melMaybeOpenAttendeeDetails(form, parseAttendeeQuestionsState(form).length > 0);

    if (!form.dataset.melAttendeeQuestionsBound) {
      form.dataset.melAttendeeQuestionsBound = '1';

      form.addEventListener('click', function (e) {
        var addBtn = e.target.closest('#mel-attendee-add-question');
        var menu = form.querySelector('#mel-attendee-preset-menu');
        if (addBtn && form.contains(addBtn) && menu) {
          e.preventDefault();
          var hidden = menu.hasAttribute('hidden');
          if (hidden) {
            menu.removeAttribute('hidden');
            addBtn.setAttribute('aria-expanded', 'true');
          } else {
            menu.setAttribute('hidden', 'hidden');
            addBtn.setAttribute('aria-expanded', 'false');
          }
          return;
        }
        var preset = e.target.closest('[data-mel-attendee-preset]');
        if (preset && form.contains(preset) && menu) {
          e.preventDefault();
          menu.setAttribute('hidden', 'hidden');
          var ab = form.querySelector('#mel-attendee-add-question');
          if (ab) {
            ab.setAttribute('aria-expanded', 'false');
          }
          melAttendeeAddPreset(form, preset.getAttribute('data-mel-attendee-preset'));
          return;
        }
        var libAdd = e.target.closest('[data-mel-attendee-library-add="1"]');
        if (libAdd && form.contains(libAdd)) {
          e.preventDefault();
          melAttendeeAddFromLibrary(form);
          return;
        }
        var rm = e.target.closest('[data-mel-attendee-remove]');
        if (rm && form.contains(rm)) {
          e.preventDefault();
          var idx = parseInt(rm.getAttribute('data-mel-attendee-remove'), 10);
          var rows = parseAttendeeQuestionsState(form);
          if (!isNaN(idx) && idx >= 0 && idx < rows.length) {
            rows.splice(idx, 1);
            writeAttendeeQuestionsState(form, rows, true);
          }
        }
      });

      form.addEventListener(
        'input',
        function (e) {
          if (e.target.classList && e.target.classList.contains('mel-attendee-label')) {
            var idx = parseInt(e.target.getAttribute('data-idx'), 10);
            var rows = parseAttendeeQuestionsState(form);
            if (!isNaN(idx) && rows[idx]) {
              rows[idx].label = e.target.value;
              writeAttendeeQuestionsState(form, rows, false);
            }
          }
        },
        true,
      );

      form.addEventListener(
        'change',
        function (e) {
          var idx;
          if (e.target.classList && e.target.classList.contains('mel-attendee-required')) {
            idx = parseInt(e.target.getAttribute('data-idx'), 10);
            var rows2 = parseAttendeeQuestionsState(form);
            if (!isNaN(idx) && rows2[idx]) {
              rows2[idx].required = !!e.target.checked;
              writeAttendeeQuestionsState(form, rows2, false);
            }
          }
          if (e.target.classList && e.target.classList.contains('mel-attendee-save-lib')) {
            idx = parseInt(e.target.getAttribute('data-idx'), 10);
            var rows3 = parseAttendeeQuestionsState(form);
            if (!isNaN(idx) && rows3[idx]) {
              rows3[idx].save_to_library = !!e.target.checked;
              writeAttendeeQuestionsState(form, rows3, false);
            }
          }
        },
        true,
      );
    }
  }

  function melScrollToFirstFormError(form) {
    var msg = form.closest('.mel-event-studio') || document;
    var errBox = msg.querySelector('.messages--error');
    if (errBox) {
      scrollToTarget(errBox);
    }
    var bad = form.querySelector('.form-item--error input, .form-item--error select, .form-item--error textarea');
    if (bad && bad.name) {
      melJumpToField(form, bad.name);
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
        initAttendeeQuestionsBuilder(form);
        refreshIntelligence(form);
        melScrollToFirstFormError(form);
        bindCoverFilePreview(form);
        initMelWizard(form);
        initPreviewDrawer(form);

        if (!form.dataset.melPublishCardBound) {
          form.dataset.melPublishCardBound = '1';
          form.addEventListener('click', function (e) {
            var pubBtn = e.target.closest('#mel-publish-now');
            if (pubBtn && form.contains(pubBtn) && !pubBtn.disabled) {
              e.preventDefault();
              var inp = form.querySelector('[name="mel[status]"]');
              if (inp && inp.type === 'checkbox') {
                inp.checked = true;
              } else if (inp) {
                inp.value = '1';
              }
              form.dispatchEvent(new Event('input', { bubbles: true }));
              form.dispatchEvent(new Event('change', { bubbles: true }));
              refreshIntelligence(form);
              return;
            }
            var revBtn = e.target.closest('#mel-revert-draft');
            if (revBtn && form.contains(revBtn)) {
              e.preventDefault();
              var inp2 = form.querySelector('[name="mel[status]"]');
              if (inp2 && inp2.type === 'checkbox') {
                inp2.checked = false;
              } else if (inp2) {
                inp2.value = '0';
              }
              form.dispatchEvent(new Event('input', { bubbles: true }));
              form.dispatchEvent(new Event('change', { bubbles: true }));
              refreshIntelligence(form);
            }
          });
        }

        form.addEventListener(
          'input',
          function () {
            setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
          },
          true,
        );
        form.addEventListener(
          'change',
          function () {
            setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
          },
          true,
        );

        var melBuilderShell = form.querySelector('[data-mel-builder="1"]');
        if (melBuilderShell) {
          var primaryCta = melBuilderShell.querySelector('#mel-builder-primary-cta');
          if (primaryCta && !primaryCta.dataset.melBuilderBound) {
            primaryCta.dataset.melBuilderBound = '1';
            primaryCta.addEventListener('click', function (e) {
              e.preventDefault();
              syncAiControlsToForm(form);
              var jumpName = primaryCta.getAttribute('data-mel-insight-target');
              if (jumpName) {
                melJumpToField(form, jumpName);
              } else {
                melScrollToSelector('#mel-step-publish');
              }
            });
          }
        }

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
          setFormState(form, 'mel-studio--saving', Drupal.t('Saving…'));
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
            refreshIntelligence(form, true);
          },
          true,
        );

        form.addEventListener(
          'change',
          function () {
            setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
            refreshIntelligence(form, true);
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

  /**
   * Legacy overflow behavior intentionally no longer moves ticket card actions.
   *
   * The ticket card UI keeps Edit / Duplicate / Archive visible so organisers do
   * not have to discover destructive or cloning actions behind a secondary menu.
   */
  Drupal.behaviors.melEventStudioTicketOverflow = {
    attach: function (context) {
      once('mel-ticket-overflow', '.mel-event-studio .js-mel-ticket-card.is-view .mel-ticket-card__actions', context).forEach(
        function (actions) {
          actions.querySelectorAll('details.mel-ticket-card__overflow').forEach(function (details) {
            Array.prototype.slice.call(details.children).forEach(function (child) {
              if (child.tagName && child.tagName.toLowerCase() !== 'summary') {
                actions.insertBefore(child, details);
              }
            });
            details.remove();
          });
        },
      );
    },
  };

  function setStudioTicketEditMode(card, editing) {
    if (!card) {
      return;
    }
    var view = card.querySelector('[data-mel-ticket-view]');
    var edit = card.querySelector('[data-mel-ticket-edit]');
    card.classList.toggle('is-editing', editing);
    card.classList.toggle('is-view', !editing);
    card.querySelectorAll('[data-mel-ticket-edit-toggle]').forEach(function (toggle) {
      toggle.setAttribute('aria-expanded', editing ? 'true' : 'false');
    });
    if (view) {
      view.hidden = editing;
    }
    if (edit) {
      edit.hidden = !editing;
    }
    if (editing && edit) {
      var field = edit.querySelector('input:not([type="hidden"]):not([type="submit"]), select, textarea');
      if (field && typeof field.focus === 'function') {
        field.focus({ preventScroll: true });
      }
    }
  }

  document.addEventListener(
    'click',
    function (e) {
      var target = e.target && e.target.closest ? e.target : null;
      if (!target) {
        return;
      }
      var editToggle = target.closest('.mel-event-studio [data-mel-ticket-edit-toggle]');
      if (editToggle) {
        var editCard = editToggle.closest('.js-mel-ticket-card');
        if (editCard) {
          e.preventDefault();
          e.stopImmediatePropagation();
          setStudioTicketEditMode(editCard, true);
        }
        return;
      }
      var cancel = target.closest('.mel-event-studio [data-mel-ticket-cancel]');
      if (cancel) {
        var cancelCard = cancel.closest('.js-mel-ticket-card');
        if (cancelCard) {
          e.preventDefault();
          e.stopImmediatePropagation();
          setStudioTicketEditMode(cancelCard, false);
        }
      }
    },
    true,
  );

  document.addEventListener(
    'submit',
    function (e) {
      var form = e.target && e.target.closest ? e.target.closest('[data-mel-event-studio-form]') : null;
      if (!form) {
        return;
      }
      forceSyncTicketTiersBeforeSubmit(form);
      forceSyncHighlightsBeforeSubmit(form);
      if (isTicketBuilderSubmitter(e.submitter)) {
        return;
      }
      var rows = collectHighlightsFromDom(form);
      var hv = validateHighlightRows(rows);
      if (!hv.ok) {
        e.preventDefault();
        setHighlightsError(form, hv.message);
        var hlRoot = form.querySelector('[data-mel-highlights-builder]');
        if (hlRoot && typeof hlRoot.scrollIntoView === 'function') {
          hlRoot.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        var errBox = getHighlightsErrorEl();
        if (errBox && typeof errBox.focus === 'function') {
          if (!errBox.hasAttribute('tabindex')) {
            errBox.setAttribute('tabindex', '-1');
          }
          errBox.focus();
        }
        return;
      }
    },
    true,
  );

  function melCelebrateFallbackCopy(url) {
    var ta = document.createElement('textarea');
    ta.value = url || '';
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
      document.execCommand('copy');
    } finally {
      document.body.removeChild(ta);
    }
  }

  Drupal.behaviors.melEventStudioCelebrateShare = {
    attach: function (context) {
      once('mel-event-studio-celebrate-copy', '[data-mel-celebrate-copy]', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var url = btn.getAttribute('data-mel-celebrate-url') || '';
          if (!url) {
            return;
          }
          var announce = document.getElementById('mel-celebrate-feedback');
          var copied = Drupal.t ? Drupal.t('Link copied.') : 'Link copied.';
          function announceDone(ok) {
            if (announce) {
              announce.textContent = ok ? copied : '';
            }
          }
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () { announceDone(true); }).catch(function () {
              melCelebrateFallbackCopy(url);
              announceDone(true);
            });
          }
          else {
            melCelebrateFallbackCopy(url);
            announceDone(true);
          }
        });
      });

      once('mel-event-studio-celebrate-scroll', '[data-mel-celebrate-panel]', context).forEach(function (panel) {
        if (typeof panel.scrollIntoView !== 'function') {
          return;
        }
        if (melPrefersReducedMotion()) {
          panel.focus({ preventScroll: true });
          return;
        }
        panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        if (panel.focus) {
          panel.focus({ preventScroll: true });
        }
      });
    },
  };
})(Drupal, once);
