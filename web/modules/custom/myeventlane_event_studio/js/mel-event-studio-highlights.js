/**
 * @file
 * Event Studio highlights builder (legacy monolith + workspace content).
 */
(function (Drupal, once) {
  'use strict';

  var HIGHLIGHT_MAX = 6;

  function getSettings() {
    return (typeof drupalSettings !== 'undefined' && drupalSettings.melEventStudio) || {};
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
    if (!submitter) {
      return false;
    }
    if (submitter.classList && submitter.classList.contains('mel-ticket-manager-save-sync')) {
      return true;
    }
    if (!submitter.name) {
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

  function getHighlightIconPickerItems() {
    var s = getSettings();
    var raw = s.highlightIconPicker;
    if (Array.isArray(raw)) {
      return raw;
    }
    if (raw && typeof raw === 'object') {
      return Object.keys(raw)
        .filter(function (k) {
          return /^\d+$/.test(k);
        })
        .sort(function (a, b) {
          return Number(a) - Number(b);
        })
        .map(function (k) {
          return raw[k];
        });
    }
    return [];
  }

  function getHighlightIconSelectOptionPairs() {
    var pairs = [{ value: '', label: Drupal.t('No icon') }];
    var pickerItems = getHighlightIconPickerItems();
    if (pickerItems.length > 0) {
      var pi;
      for (pi = 0; pi < pickerItems.length; pi++) {
        var it = pickerItems[pi];
        var key = it && it.value != null ? String(it.value) : '';
        if (key === '') {
          continue;
        }
        var lbl = it && it.label != null ? String(it.label) : key;
        pairs.push({ value: key, label: lbl });
      }
      return pairs;
    }
    var icons = getHighlightIconOptions();
    Object.keys(icons).forEach(function (k) {
      pairs.push({ value: k, label: String(icons[k] || k) });
    });
    return pairs;
  }

  function collectHighlightsFromDom(form) {
    var table = getHighlightsTable(form);
    if (!table) {
      return [];
    }
    var rows = table.querySelectorAll('tbody .mel-highlight-row');
    var out = [];
    rows.forEach(function (tr) {
      var sel = tr.querySelector('select.mel-highlight-icon');
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

    var tdIcon = document.createElement('td');
    tdIcon.className = 'mel-highlight-icon-cell';

    var sel = document.createElement('select');
    sel.className = 'mel-input mel-highlight-icon';
    sel.setAttribute('aria-label', Drupal.t('Highlight icon'));

    var pairs = getHighlightIconSelectOptionPairs();
    var rowIcon = row && row.icon != null ? String(row.icon).trim() : '';
    var matched = false;
    var i;
    for (i = 0; i < pairs.length; i++) {
      var opt = document.createElement('option');
      opt.value = pairs[i].value;
      opt.textContent = pairs[i].label;
      if (pairs[i].value === rowIcon) {
        opt.selected = true;
        matched = true;
      }
      sel.appendChild(opt);
    }
    if (!matched && rowIcon !== '') {
      var legacy = document.createElement('option');
      legacy.value = rowIcon;
      legacy.textContent = rowIcon;
      legacy.selected = true;
      sel.insertBefore(legacy, sel.options[1] || null);
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

  function notifyHighlightsChanged(form) {
    try {
      form.dispatchEvent(new CustomEvent('melEventStudio:highlightsChanged', { bubbles: true, detail: { form: form } }));
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
          notifyHighlightsChanged(form);
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
          notifyHighlightsChanged(form);
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
        notifyHighlightsChanged(form);
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

  Drupal.melEventStudioHighlights = {
    syncFromDom: syncHighlightsFromDomToHidden,
    updateErrors: updateHighlightErrors,
    forceSyncBeforeSubmit: forceSyncHighlightsBeforeSubmit,
  };

  if (!document.documentElement.dataset.melHighlightsSubmitGate) {
    document.documentElement.dataset.melHighlightsSubmitGate = '1';
    document.addEventListener(
      'submit',
      function (e) {
        var form = e.target && e.target.closest ? e.target.closest('[data-mel-event-studio-form]') : null;
        if (!form || !form.querySelector('input[data-mel-highlights-state]')) {
          return;
        }
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
        }
      },
      true,
    );
  }

  Drupal.behaviors.melEventStudioHighlights = {
    attach: function (context) {
      once('mel-event-studio-highlights', 'form[data-mel-event-studio-form="1"]', context).forEach(function (form) {
        if (!form.querySelector('input[data-mel-highlights-state]')) {
          return;
        }
        initHighlightsBuilder(form);
      });
    },
  };
})(Drupal, once);
