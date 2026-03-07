(function (Drupal) {
  'use strict';

  function isFilled(value) {
    return typeof value === 'string' && value.trim().length > 0;
  }

  function firstValue(form, selectors) {
    for (var i = 0; i < selectors.length; i += 1) {
      var field = form.querySelector(selectors[i]);
      if (field && isFilled(field.value || '')) {
        return field.value;
      }
    }
    return '';
  }

  function setCheckState(panel, check, valid) {
    var item = panel.querySelector('[data-check="' + check + '"]');
    if (!item) {
      return false;
    }

    item.classList.remove('is-pending', 'is-missing', 'is-complete');
    item.classList.add(valid ? 'is-complete' : 'is-missing');
    return valid;
  }

  function renderWarnings(panel, missingChecks) {
    var warnings = panel.querySelector('[data-mel-publish-warnings]');
    if (!warnings) {
      return;
    }

    var messages = [];
    if (missingChecks.indexOf('title') !== -1) {
      messages.push('Add an event title.');
    }
    if (missingChecks.indexOf('date') !== -1) {
      messages.push('Add an event date.');
    }
    if (missingChecks.indexOf('location') !== -1) {
      messages.push('Add an event location.');
    }
    if (missingChecks.indexOf('banner') !== -1) {
      messages.push('Add a banner image.');
    }

    warnings.innerHTML = '';
    if (!messages.length) {
      warnings.hidden = true;
      return;
    }

    var list = document.createElement('ul');
    list.className = 'mel-publish-warnings__list';

    messages.forEach(function (message) {
      var item = document.createElement('li');
      item.textContent = message;
      list.appendChild(item);
    });

    warnings.appendChild(list);
    warnings.hidden = false;
  }

  function updatePublishPanel(panel, form) {
    var titleValue = firstValue(form, [
      '[name="title[0][value]"]',
      '[name="title"]'
    ]);
    var dateValue = firstValue(form, [
      '[name="field_event_start[0][value]"]',
      '[name="field_event_start[0][value][date]"]'
    ]);
    var locationValue = firstValue(form, [
      '[name="field_location[0][target_id]"]',
      '[name="field_location[0][value]"]',
      '[name="field_venue_name[0][value]"]'
    ]);
    var bannerValue = firstValue(form, [
      '[name="field_event_image[0][target_id]"]',
      '[name="field_event_image[0][fids]"]'
    ]);

    var checklist = {
      title: isFilled(titleValue),
      date: isFilled(dateValue),
      location: isFilled(locationValue),
      banner: isFilled(bannerValue)
    };

    var missingChecks = [];
    Object.keys(checklist).forEach(function (check) {
      if (!setCheckState(panel, check, checklist[check])) {
        missingChecks.push(check);
      }
      else if (!checklist[check]) {
        missingChecks.push(check);
      }
    });

    renderWarnings(panel, missingChecks);

    var submit = panel.querySelector('.mel-submit-review');
    if (submit) {
      submit.disabled = missingChecks.length > 0;
      submit.setAttribute('aria-disabled', submit.disabled ? 'true' : 'false');
    }
  }

  function attachToPanel(panel) {
    var form = document.querySelector('form.node-event-form') ||
      document.querySelector('form[data-drupal-selector^="node-event-"]');

    if (!form) {
      return;
    }

    var refresh = function refresh() {
      updatePublishPanel(panel, form);
    };

    refresh();

    if (form.dataset.melPublishPanelBound === 'true') {
      return;
    }

    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    form.dataset.melPublishPanelBound = 'true';
  }

  Drupal.behaviors.melPublishPanel = {
    attach: function attach(context) {
      var panels = context.querySelectorAll
        ? context.querySelectorAll('[data-mel-publish-panel]')
        : [];

      if (!panels.length && context.matches && context.matches('[data-mel-publish-panel]')) {
        panels = [context];
      }

      for (var i = 0; i < panels.length; i += 1) {
        attachToPanel(panels[i]);
      }
    }
  };
})(Drupal);
