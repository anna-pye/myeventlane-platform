/**
 * @file
 * Event Studio tickets — update preview mode badge from booking model radios.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var MODE_MAP = {
    rsvp: 'rsvp',
    paid: 'paid',
    external: 'external',
  };

  /**
   * @param {object} settings
   * @return {object}
   */
  function previewSettings(settings) {
    return settings && settings.melEventTicketPreview
      ? settings.melEventTicketPreview
      : {};
  }

  /**
   * @param {HTMLElement} preview
   * @param {string} mode
   * @param {object} cfg
   */
  function applyModeBadge(preview, mode, cfg) {
    var badge = preview.querySelector('[data-mel-ticket-preview-mode-badge]');
    var empty = preview.querySelector('[data-mel-ticket-preview-empty]');
    var labels = cfg.modeLabels || {};
    var state = MODE_MAP[mode] || 'not_configured';
    preview.dataset.previewState = state;
    if (badge) {
      badge.textContent = labels[state] || labels.not_configured || '';
    }
    if (empty) {
      empty.hidden = state !== 'not_configured';
    }
  }

  Drupal.behaviors.melEventTicketPreview = {
    attach: function attach(context, settings) {
      var cfg = previewSettings(settings);
      once('mel-event-ticket-preview', '[data-mel-ticket-preview]', context).forEach(
        function initPreview(preview) {
          var stack = preview.closest('.mel-event-studio-section__form-stack');
          if (!stack) {
            return;
          }

          var syncFromRadios = function syncFromRadios() {
            var checked = stack.querySelector(
              'input[name="mel[field_event_type]"]:checked'
            );
            applyModeBadge(preview, checked ? checked.value : '', cfg);
          };

          stack.addEventListener('change', function onChange(event) {
            var target = event.target;
            if (
              target
              && target.matches
              && target.matches('input[name="mel[field_event_type]"]')
            ) {
              syncFromRadios();
            }
          });

          syncFromRadios();
        }
      );
    },
  };
})(Drupal, drupalSettings, once);
