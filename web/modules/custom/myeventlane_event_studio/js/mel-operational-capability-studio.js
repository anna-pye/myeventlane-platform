/**
 * @file
 * Event Studio operational capability authoring — syncs editors to hidden JSON state.
 */
(function (Drupal, once) {
  'use strict';

  function parseState(input) {
    if (!input || !input.value) {
      return { schema_version: 2, capabilities: {} };
    }
    try {
      return JSON.parse(input.value);
    }
    catch (e) {
      return { schema_version: 2, capabilities: {} };
    }
  }

  function writeState(input, doc) {
    input.value = JSON.stringify(doc);
  }

  function getCapability(doc, type) {
    if (!doc.capabilities) {
      doc.capabilities = {};
    }
    if (!doc.capabilities[type]) {
      doc.capabilities[type] = { capability_type: type, enabled: false };
    }
    return doc.capabilities[type];
  }

  function setDeep(obj, path, value) {
    const parts = path.split('.');
    let cur = obj;
    for (let i = 0; i < parts.length - 1; i++) {
      const p = parts[i];
      if (!cur[p] || typeof cur[p] !== 'object') {
        cur[p] = {};
      }
      cur = cur[p];
    }
    cur[parts[parts.length - 1]] = value;
  }

  function markCapabilitiesStateDirty(root) {
    const dirty = root.querySelector('.js-mel-capabilities-state-dirty');
    if (dirty) {
      dirty.value = '1';
    }
  }

  /**
   * Merges one capability editor DOM into the shared document (mutates doc).
   *
   * @param {object} doc
   * @param {Element} editor
   */
  function applyEditorToDoc(doc, editor) {
    const type = editor.getAttribute('data-capability-type');
    if (!type) {
      return;
    }
    const enabled = editor.querySelector('[data-cap-field="enabled"]');
    const fields = editor.querySelectorAll('[data-cap-field]');
    const row = getCapability(doc, type);
    fields.forEach((field) => {
      const key = field.getAttribute('data-cap-field');
      if (!key || key === 'enabled') {
        return;
      }
      let val;
      if (field.type === 'checkbox') {
        val = field.checked;
      }
      else {
        val = field.value;
      }
      if (key.indexOf('.') !== -1) {
        setDeep(row, key, val);
      }
      else {
        row[key] = val;
      }
    });
    const varWrap = editor.querySelector('.mel-cap-commerce-variations');
    if (varWrap) {
      row.commerce_linkage = row.commerce_linkage || {};
      row.commerce_linkage.variation_ids = [];
      varWrap.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
        if (cb.checked && cb.value) {
          const id = parseInt(cb.value, 10);
          if (!Number.isNaN(id) && id > 0) {
            row.commerce_linkage.variation_ids.push(id);
          }
        }
      });
    }
    if (enabled) {
      row.enabled = enabled.checked;
      row.capability_type = type;
    }
  }

  /**
   * Writes all editor panels into the hidden JSON input (PHP prefers this when dirty).
   *
   * @param {Element} root
   * @param {HTMLInputElement} input
   */
  function syncAllEditors(root, input) {
    const doc = parseState(input);
    root.querySelectorAll('.mel-operational-capability-editor').forEach((editor) => {
      applyEditorToDoc(doc, editor);
    });
    writeState(input, doc);
    markCapabilitiesStateDirty(root);
  }

  function bindEditor(root, input) {
    const editors = root.querySelectorAll('.mel-operational-capability-editor');
    editors.forEach((editor) => {
      const type = editor.getAttribute('data-capability-type');
      if (!type) {
        return;
      }
      const enabled = editor.querySelector('[data-cap-field="enabled"]');
      const fields = editor.querySelectorAll('[data-cap-field]');
      const sync = () => {
        const doc = parseState(input);
        applyEditorToDoc(doc, editor);
        writeState(input, doc);
        markCapabilitiesStateDirty(root);
      };
      fields.forEach((field) => {
        field.addEventListener('change', sync);
        field.addEventListener('input', sync);
      });
      const varWrap = editor.querySelector('.mel-cap-commerce-variations');
      if (varWrap) {
        varWrap.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
          cb.addEventListener('change', sync);
        });
      }
    });

    root.querySelectorAll('.mel-cap-commerce-autocomplete').forEach((ac) => {
      ac.addEventListener('change', () => {
        const m = ac.value.match(/\((\d+)\)\s*$/);
        const editor = ac.closest('.mel-operational-capability-editor');
        const num = editor && editor.querySelector('[data-cap-field="commerce_linkage.product_id"]');
        if (m && num) {
          num.value = m[1];
          num.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    });

    root.querySelectorAll('.js-mel-capability-configure').forEach((button) => {
      button.addEventListener('click', () => {
        const capType = button.getAttribute('data-capability-type');
        const panel = root.querySelector('.mel-operational-capability-editors');
        const editor = root.querySelector('.mel-operational-capability-editor[data-capability-type="' + capType + '"]');
        if (panel) {
          panel.classList.add('is-open');
        }
        root.querySelectorAll('.mel-operational-capability-editor').forEach((el) => {
          el.classList.toggle('is-active', el === editor);
        });
        if (editor) {
          editor.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      });
    });

    const form = root.closest('form');
    if (form) {
      once('mel-operational-capability-studio-submit', form).forEach((el) => {
        el.addEventListener('submit', () => {
          syncAllEditors(root, input);
        });
      });
    }
  }

  Drupal.behaviors.melOperationalCapabilityStudio = {
    attach(context) {
      once('mel-operational-capability-studio', '[data-mel-operational-capability-studio]', context).forEach((root) => {
        const input = root.querySelector('.js-mel-operational-capabilities-state');
        if (!input) {
          return;
        }
        bindEditor(root, input);
      });
    },
  };
})(Drupal, once);
