/**
 * @file
 * Event Studio operational capability authoring — syncs editors to hidden JSON state.
 */
(function (Drupal, once) {
  'use strict';

  function parseState(input) {
    if (!input || !input.value) {
      return { schema_version: 1, capabilities: {} };
    }
    try {
      return JSON.parse(input.value);
    }
    catch (e) {
      return { schema_version: 1, capabilities: {} };
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
        const row = getCapability(doc, type);
        fields.forEach((field) => {
          const key = field.getAttribute('data-cap-field');
          if (!key || key === 'enabled') {
            return;
          }
          if (field.type === 'checkbox') {
            row[key] = field.checked;
          }
          else {
            row[key] = field.value;
          }
        });
        if (enabled) {
          row.enabled = enabled.checked;
          row.capability_type = type;
        }
        writeState(input, doc);
      };
      fields.forEach((field) => {
        field.addEventListener('change', sync);
        field.addEventListener('input', sync);
      });
    });

    root.querySelectorAll('.js-mel-capability-configure').forEach((button) => {
      button.addEventListener('click', () => {
        const type = button.getAttribute('data-capability-type');
        const panel = root.querySelector('.mel-operational-capability-editors');
        const editor = root.querySelector('.mel-operational-capability-editor[data-capability-type="' + type + '"]');
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
