/**
 * @file
 * Event Studio branding tab — sync preview style/colour with form radios.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var STYLE_CLASS_PREFIX = 'mel-event-branding-preview--';
  var PAGE_STYLE_PREFIX = 'mel-event-page--';
  var COLOUR_CLASS = 'mel-event-page--colour-';

  /**
   * @param {HTMLElement} preview
   * @param {string} style
   * @param {string} colour
   */
  function applyPreviewModifiers(preview, style, colour) {
    var allowedStyles = ['classic', 'immersive'];
    var allowedColours = ['coral', 'purple', 'mint', 'gold', 'blue'];
    if (allowedStyles.indexOf(style) === -1) {
      style = 'classic';
    }
    if (allowedColours.indexOf(colour) === -1) {
      colour = 'coral';
    }

    preview.dataset.previewStyle = style;
    preview.dataset.previewColour = colour;

    preview.classList.forEach(function (cls) {
      if (
        cls.indexOf(STYLE_CLASS_PREFIX) === 0
        || cls.indexOf(PAGE_STYLE_PREFIX) === 0
      ) {
        preview.classList.remove(cls);
      }
    });

    preview.classList.add('mel-event-branding-preview--' + style);
    preview.classList.add('mel-event-page--' + style);
    preview.classList.add(COLOUR_CLASS + colour);
  }

  /**
   * @param {object} settings
   * @return {object}
   */
  function previewSettings(settings) {
    return settings && settings.melEventBrandingPreview
      ? settings.melEventBrandingPreview
      : {};
  }

  /**
   * @param {HTMLElement} preview
   * @param {object} cfg
   * @param {string} style
   * @param {string} colour
   */
  function updateSelectionLabels(preview, cfg, style, colour) {
    var styleLabels = cfg.styleLabels || {};
    var colourLabels = cfg.colourLabels || {};
    var styleEl = preview.querySelector('[data-mel-branding-preview-style-label]');
    var colourEl = preview.querySelector('[data-mel-branding-preview-colour-label] .mel-event-branding-preview__accent-chip-label');
    var styleLabel = styleLabels[style] || style;
    var colourLabel = colourLabels[colour] || colour;

    if (styleEl) {
      styleEl.textContent = styleLabel;
      styleEl.setAttribute('data-preview-style-label', styleLabel);
    }
    if (colourEl) {
      colourEl.textContent = colourLabel;
    }
    var colourChip = preview.querySelector('[data-mel-branding-preview-colour-label]');
    if (colourChip) {
      colourChip.setAttribute('title', colourLabel);
      colourChip.setAttribute('data-preview-colour-label', colourLabel);
    }
  }

  /**
   * @param {HTMLElement} preview
   * @param {object} cfg
   * @param {string} style
   * @param {string} colour
   */
  function updateSavedStateNote(preview, cfg, style, colour) {
    var stateEl = preview.querySelector('[data-mel-branding-preview-state]');
    if (!stateEl) {
      return;
    }

    var savedStyle = cfg.savedStyle || preview.dataset.savedStyle || '';
    var savedColour = cfg.savedColour || preview.dataset.savedColour || '';
    var hasUnsavedStyleColour = style !== savedStyle || colour !== savedColour;
    var unsavedText = cfg.stateUnsavedStyleColour
      || 'Unsaved style and colour changes are shown here before saving.';
    var savedMediaText = cfg.stateSavedMedia || 'Preview based on saved event media.';

    stateEl.textContent = hasUnsavedStyleColour ? unsavedText : savedMediaText;
    stateEl.setAttribute(
      'data-mel-branding-preview-state',
      hasUnsavedStyleColour ? 'unsaved' : 'saved',
    );
  }

  /**
   * @param {HTMLElement} preview
   * @param {HTMLFormElement|null} form
   */
  function bindPreview(preview, form) {
    if (!form) {
      return;
    }

    var cfg = previewSettings(drupalSettings);
    var styleName = 'mel[field_mel_page_style]';
    var colourName = 'mel[field_mel_theme_colour]';

    function readRadio(name) {
      var checked = form.querySelector('input[name="' + name + '"]:checked');
      return checked ? String(checked.value || '') : '';
    }

    function syncFromForm() {
      var style = readRadio(styleName);
      var colour = readRadio(colourName);
      applyPreviewModifiers(preview, style, colour);
      updateSelectionLabels(preview, cfg, style, colour);
      updateSavedStateNote(preview, cfg, style, colour);
    }

    form.addEventListener('change', function (event) {
      var target = event.target;
      if (!target || (target.name !== styleName && target.name !== colourName)) {
        return;
      }
      syncFromForm();
    });

    syncFromForm();
  }

  Drupal.behaviors.melEventBrandingPreview = {
    attach: function attach(context) {
      once('mel-event-branding-preview', '[data-mel-branding-preview]', context).forEach(
        function (preview) {
          var form = preview.closest('form');
          bindPreview(preview, form instanceof HTMLFormElement ? form : null);
        },
      );
    },
  };
})(Drupal, drupalSettings, once);
