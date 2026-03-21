(function (Drupal, once) {
  'use strict';

  function setStatus(scope, message, level) {
    const node = scope.querySelector('[data-mel-status]');
    if (!node) {
      return;
    }
    node.textContent = message || '';
    node.classList.remove('is-error', 'is-success', 'is-info');
    if (level) {
      node.classList.add('is-' + level);
    }
  }

  function setDisabledState(node, disabled) {
    if (!node) {
      return;
    }
    if ('disabled' in node) {
      node.disabled = disabled;
    }
    node.classList.toggle('is-disabled', disabled);
    if (node.tagName === 'A') {
      node.setAttribute('aria-disabled', disabled ? 'true' : 'false');
      if (disabled) {
        node.setAttribute('tabindex', '-1');
      }
      else {
        node.removeAttribute('tabindex');
      }
    }
  }

  function updateChecklist(scope, checklist) {
    ['title', 'date', 'location', 'banner'].forEach((key) => {
      const item = scope.querySelector('[data-mel-check="' + key + '"]');
      if (!item) {
        return;
      }
      const ok = !!(checklist && checklist[key]);
      item.classList.remove('is-complete', 'is-missing');
      item.classList.add(ok ? 'is-complete' : 'is-missing');
      item.textContent = (ok ? '✓ ' : '• ') + item.textContent.replace(/^[✓•]\s*/, '');
    });
  }

  function updateActions(scope, eventData) {
    const previewButtons = [
      scope.querySelector('[data-mel-action-preview]'),
      scope.querySelector('[data-mel-inspector-preview]')
    ];
    const viewButtons = [
      scope.querySelector('[data-mel-action-view]'),
      scope.querySelector('[data-mel-inspector-view]')
    ];

    const previewUrl = eventData && eventData.quick_actions ? eventData.quick_actions.preview_url : '';
    const viewUrl = eventData && eventData.quick_actions ? eventData.quick_actions.view_url : '';

    previewButtons.forEach((button) => {
      if (button && button.tagName === 'A') {
        button.setAttribute('href', previewUrl || '#');
      }
      setDisabledState(button, !previewUrl);
    });

    viewButtons.forEach((button) => {
      if (button && button.tagName === 'A') {
        button.setAttribute('href', viewUrl || '#');
      }
      setDisabledState(button, !viewUrl);
    });
  }

  function updateCardFromEvent(card, eventData) {
    if (!card || !eventData) {
      return;
    }
    card.dataset.title = eventData.title || '';
    card.dataset.summary = eventData.field_event_summary || '';
    card.dataset.eventType = eventData.field_event_type || 'rsvp';
    card.dataset.tickets = String(eventData.tickets_metric || 0);
    card.dataset.rsvps = String(eventData.rsvps_metric || 0);
    card.dataset.revenue = String(eventData.revenue_metric || 0);

    const titleNode = card.querySelector('.mel-event-title');
    if (titleNode) {
      titleNode.textContent = eventData.title || '';
    }
    const statusNode = card.querySelector('.mel-event-status');
    if (statusNode && eventData.status) {
      statusNode.textContent = String(eventData.status).charAt(0).toUpperCase() + String(eventData.status).slice(1);
    }
  }

  function updateWizardFrame(scope, eventData, selectedCard) {
    const frame = scope.querySelector('[data-mel-wizard-frame]');
    if (!frame) {
      return;
    }

    let wizardUrl = '';
    if (eventData && eventData.wizard_url) {
      wizardUrl = String(eventData.wizard_url);
    }
    else if (selectedCard && selectedCard.dataset && selectedCard.dataset.wizardUrl) {
      wizardUrl = String(selectedCard.dataset.wizardUrl);
    }

    if (wizardUrl !== '' && frame.getAttribute('src') !== wizardUrl) {
      frame.setAttribute('src', wizardUrl);
    }
  }

  function applyTabEndpoints(scope, endpointMap) {
    const normalized = {
      overview: endpointMap && endpointMap.overviewSave ? endpointMap.overviewSave : '',
      tickets: endpointMap && endpointMap.ticketsSave ? endpointMap.ticketsSave : '',
      attendees: endpointMap && endpointMap.attendeesSave ? endpointMap.attendeesSave : '',
      promotion: endpointMap && endpointMap.promotionSave ? endpointMap.promotionSave : '',
      settings: endpointMap && endpointMap.settingsSave ? endpointMap.settingsSave : '',
      publish: endpointMap && endpointMap.publish ? endpointMap.publish : ''
    };

    scope.dataset.endpointOverview = normalized.overview;
    scope.dataset.endpointTickets = normalized.tickets;
    scope.dataset.endpointAttendees = normalized.attendees;
    scope.dataset.endpointPromotion = normalized.promotion;
    scope.dataset.endpointSettings = normalized.settings;
    scope.dataset.endpointPublish = normalized.publish;

    scope.querySelectorAll('[data-studio-tab]').forEach((tab) => {
      const key = tab.dataset.studioTab;
      if (!Object.prototype.hasOwnProperty.call(normalized, key)) {
        return;
      }
      tab.dataset.endpoint = normalized[key];
    });
  }

  function collectCardEndpoints(card) {
    return {
      overviewSave: card && card.dataset.overviewSaveUrl ? card.dataset.overviewSaveUrl : (card && card.dataset.saveUrl ? card.dataset.saveUrl : ''),
      ticketsSave: card && card.dataset.ticketsSaveUrl ? card.dataset.ticketsSaveUrl : '',
      attendeesSave: card && card.dataset.attendeesSaveUrl ? card.dataset.attendeesSaveUrl : '',
      promotionSave: card && card.dataset.promotionSaveUrl ? card.dataset.promotionSaveUrl : '',
      settingsSave: card && card.dataset.settingsSaveUrl ? card.dataset.settingsSaveUrl : '',
      publish: card && card.dataset.publishUrl ? card.dataset.publishUrl : ''
    };
  }

  function resolveTabEndpoint(scope, tabKey) {
    const mapping = {
      overview: scope.dataset.endpointOverview || '',
      tickets: scope.dataset.endpointTickets || '',
      attendees: scope.dataset.endpointAttendees || '',
      promotion: scope.dataset.endpointPromotion || '',
      settings: scope.dataset.endpointSettings || '',
      publish: scope.dataset.endpointPublish || ''
    };
    return mapping[tabKey] || '';
  }

  function parseJsonInput(node, label) {
    const raw = node ? String(node.value || '').trim() : '';
    if (raw === '') {
      return {};
    }
    try {
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        return parsed;
      }
      throw new Error(label + ' must be a JSON object.');
    }
    catch (error) {
      throw new Error(label + ' contains invalid JSON.');
    }
  }

  function buildTabPayload(scope, tabKey) {
    if (tabKey === 'tickets') {
      return parseJsonInput(scope.querySelector('[data-mel-tickets-config]'), 'Tickets configuration');
    }
    if (tabKey === 'attendees') {
      const parsed = parseJsonInput(scope.querySelector('[data-mel-attendees-config]'), 'Attendees configuration');
      if (!Array.isArray(parsed.questions)) {
        throw new Error('Attendees configuration must use { "questions": [] }.');
      }
      const questions = parsed.questions.map((question, index) => {
        if (!question || typeof question !== 'object' || Array.isArray(question)) {
          throw new Error('Each attendee question must be an object. Invalid item at index ' + index + '.');
        }

        const label = String(question.label || '').trim();
        const type = String(question.type || '').trim();
        const required = !!question.required;

        if (label === '') {
          throw new Error('Attendee question label is required for item ' + index + '.');
        }
        if (type === '') {
          throw new Error('Attendee question type is required for item ' + index + '.');
        }

        return {
          label,
          type,
          required
        };
      });
      return { questions };
    }
    if (tabKey === 'promotion') {
      return parseJsonInput(scope.querySelector('[data-mel-promotion-config]'), 'Promotion configuration');
    }
    if (tabKey === 'settings') {
      const capacityNode = scope.querySelector('[data-mel-settings-capacity]');
      const visibilityNode = scope.querySelector('[data-mel-settings-visibility]');
      const capacityRaw = capacityNode ? String(capacityNode.value || '').trim() : '0';
      const capacity = Math.max(0, Number.parseInt(capacityRaw || '0', 10) || 0);
      const visibility = visibilityNode ? String(visibilityNode.value || '').trim() : '';
      return {
        capacity,
        visibility
      };
    }
    return {};
  }

  const DYNAMIC_FIELD_RENDERERS = {
    string: buildTextControl,
    string_long: buildTextareaControl,
    email: buildEmailControl,
    telephone: buildTelephoneControl,
    link: buildLinkControl,
    text: buildTextLongControl,
    text_long: buildTextLongControl,
    text_with_summary: buildTextWithSummaryControl,
    integer: buildIntegerControl,
    decimal: buildDecimalControl,
    boolean: buildBooleanControl,
    datetime: buildDatetimeControl,
    list_string: buildListStringControl
  };

  function getDynamicMount(scope) {
    const mount = scope.querySelector('#studio-dynamic-form[data-mel-dynamic-schema-form]');
    if (!mount) {
      const message = 'Studio dynamic form mount #studio-dynamic-form is missing.';
      console.error(message);
      setStatus(scope, message, 'error');
      return null;
    }
    return mount;
  }

  function getDynamicForm(scope) {
    const mount = getDynamicMount(scope);
    if (!mount) {
      return null;
    }
    return mount.querySelector('form[data-mel-dynamic-form]');
  }

  function markControlDirty(control) {
    if (!control) {
      return;
    }
    control.dataset.melDirty = '1';
  }

  function wireDirtyTracking(control) {
    if (!control) {
      return;
    }
    control.dataset.melDirty = '0';
    control.addEventListener('change', () => markControlDirty(control));
    control.addEventListener('input', () => markControlDirty(control));
  }

  function buildTextControl() {
    const input = document.createElement('input');
    input.type = 'text';
    return input;
  }

  function buildTextareaControl() {
    const textarea = document.createElement('textarea');
    textarea.rows = 4;
    return textarea;
  }

  function buildIntegerControl() {
    const input = document.createElement('input');
    input.type = 'number';
    input.step = '1';
    return input;
  }

  function buildEmailControl() {
    const input = document.createElement('input');
    input.type = 'email';
    return input;
  }

  function buildTelephoneControl() {
    const input = document.createElement('input');
    input.type = 'tel';
    return input;
  }

  function buildLinkControl() {
    const input = document.createElement('input');
    input.type = 'url';
    return input;
  }

  function buildTextLongControl() {
    const textarea = document.createElement('textarea');
    textarea.rows = 6;
    return textarea;
  }

  function buildTextWithSummaryControl() {
    const textarea = document.createElement('textarea');
    textarea.rows = 8;
    return textarea;
  }

  function buildDecimalControl() {
    const input = document.createElement('input');
    input.type = 'number';
    input.step = '0.01';
    return input;
  }

  function buildBooleanControl() {
    const input = document.createElement('input');
    input.type = 'checkbox';
    return input;
  }

  function buildDatetimeControl() {
    const input = document.createElement('input');
    input.type = 'datetime-local';
    return input;
  }

  function buildListStringControl(field) {
    if (!Array.isArray(field.allowed_values) || field.allowed_values.length === 0) {
      return null;
    }
    const select = document.createElement('select');
    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = '- Select -';
    select.appendChild(blank);
    field.allowed_values.forEach((item) => {
      if (!item || typeof item !== 'object') {
        return;
      }
      const option = document.createElement('option');
      option.value = String(item.value || '');
      option.textContent = String(item.label || item.value || '');
      select.appendChild(option);
    });
    return select;
  }

  function buildUnsupportedNotice(field, reason) {
    const container = document.createElement('div');
    container.className = 'mel-field-group';

    const label = document.createElement('label');
    label.textContent = String(field.label || field.name || 'Unsupported field');
    container.appendChild(label);

    const input = document.createElement('input');
    input.type = 'text';
    input.disabled = true;
    input.value = 'Unsupported field';
    container.appendChild(input);

    const notice = document.createElement('p');
    notice.className = 'mel-event-meta';
    notice.textContent = 'Unsupported in Studio: ' + reason + '.';
    container.appendChild(notice);

    return container;
  }

  function buildReadOnlyReferenceNotice(field) {
    const container = document.createElement('div');
    container.className = 'mel-field-group';

    const label = document.createElement('label');
    label.textContent = String(field.label || field.name || 'Reference field');
    container.appendChild(label);

    const input = document.createElement('input');
    input.type = 'text';
    input.disabled = true;
    const targetType = String(field.target_type || 'entity');
    input.value = 'Read-only (' + targetType + ' reference)';
    container.appendChild(input);

    const notice = document.createElement('p');
    notice.className = 'mel-event-meta';
    const description = String(field.description || '').trim();
    notice.textContent = description !== ''
      ? description
      : 'This entity reference is read-only in Studio.';
    container.appendChild(notice);

    return container;
  }

  function appendFieldDescription(wrapper, field) {
    const description = String(field.description || '').trim();
    if (description === '') {
      return;
    }
    const hint = document.createElement('p');
    hint.className = 'mel-event-meta';
    hint.textContent = description;
    wrapper.appendChild(hint);
  }

  function orderFieldsBySchemaGroups(schema) {
    const fields = Array.isArray(schema.fields) ? schema.fields : [];
    const fieldMap = new Map();
    fields.forEach((field) => {
      const name = field && field.name ? String(field.name) : '';
      if (name !== '') {
        fieldMap.set(name, field);
      }
    });

    const ordered = [];
    const seen = new Set();
    if (Array.isArray(schema.groups)) {
      schema.groups.forEach((group) => {
        if (!group || !Array.isArray(group.fields)) {
          return;
        }
        group.fields.forEach((name) => {
          const key = String(name || '');
          if (key === '' || seen.has(key) || !fieldMap.has(key)) {
            return;
          }
          seen.add(key);
          ordered.push(fieldMap.get(key));
        });
      });
    }

    fields.forEach((field) => {
      const name = field && field.name ? String(field.name) : '';
      if (name !== '' && !seen.has(name)) {
        ordered.push(field);
      }
    });

    return ordered;
  }

  async function loadEventSchema(scope) {
    const mount = getDynamicMount(scope);
    if (!mount) {
      return;
    }

    const endpoint = scope.dataset.melSchemaEndpoint || '/vendor/studio/schema/event';
    try {
      const response = await fetch(endpoint, {
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json'
        }
      });
      const schema = await response.json();
      if (!response.ok || !schema.success) {
        throw new Error((schema && schema.message) || 'Could not load field schema.');
      }
      buildStudioForm(scope, schema);
    }
    catch (error) {
      setStatus(scope, (error && error.message) || 'Could not load field schema.', 'error');
    }
  }

  function buildStudioForm(scope, schema) {
    const mount = getDynamicMount(scope);
    if (!mount || !schema || !Array.isArray(schema.fields)) {
      return;
    }

    mount.innerHTML = '';
    const form = document.createElement('form');
    form.id = 'studio-form';
    form.setAttribute('data-mel-dynamic-form', 'true');
    form.className = 'mel-dynamic-studio-form';
    mount.appendChild(form);

    const orderedFields = orderFieldsBySchemaGroups(schema);
    orderedFields.forEach((field) => {
      if (!field) {
        return;
      }

      const name = String(field.name || '').trim();
      if (name === '') {
        return;
      }

      const fieldType = String(field.type || '').trim();
      const cardinality = Number(field.cardinality);

      if (field.supported === false) {
        form.appendChild(buildUnsupportedNotice(field, String(field.unsupported_reason || 'unsupported')));
        return;
      }

      if (fieldType === 'entity_reference' || field.input_mode === 'readonly' || field.read_only === true) {
        form.appendChild(buildReadOnlyReferenceNotice(field));
        return;
      }

      if (!Number.isNaN(cardinality) && cardinality !== 1) {
        form.appendChild(buildUnsupportedNotice(field, 'multi_value_not_supported'));
        return;
      }

      if (!Object.prototype.hasOwnProperty.call(DYNAMIC_FIELD_RENDERERS, fieldType)) {
        form.appendChild(buildUnsupportedNotice(field, 'field_type_not_supported:' + fieldType));
        return;
      }

      const renderer = DYNAMIC_FIELD_RENDERERS[fieldType];
      const control = renderer(field);
      if (!control) {
        form.appendChild(buildUnsupportedNotice(field, 'missing_allowed_values'));
        return;
      }

      const wrapper = document.createElement('div');
      wrapper.className = 'mel-field-group';

      const label = document.createElement('label');
      const inputId = 'mel-dynamic-' + name;
      label.setAttribute('for', inputId);
      label.textContent = String(field.label || name);

      control.id = inputId;
      control.name = name;
      control.required = !!field.required;
      control.dataset.fieldType = fieldType;
      control.dataset.melDynamicField = 'true';
      if (control.tagName !== 'SELECT' && control.type !== 'checkbox') {
        control.placeholder = String(field.label || name);
      }
      wireDirtyTracking(control);

      wrapper.appendChild(label);
      wrapper.appendChild(control);
      appendFieldDescription(wrapper, field);
      form.appendChild(wrapper);
    });
  }

  async function saveEvent(scope, eventId) {
    const form = getDynamicForm(scope);
    if (!form) {
      throw new Error('Studio form is not available.');
    }

    const values = {};
    form.querySelectorAll('[data-mel-dynamic-field="true"][data-mel-dirty="1"]').forEach((input) => {
      const name = String(input.name || '').trim();
      const fieldType = String(input.dataset.fieldType || '');
      if (name === '') {
        return;
      }

      if (input.required) {
        if (fieldType === 'boolean') {
          // Required boolean can only be true when checked.
          if (!input.checked) {
            throw new Error('Field "' + name + '" must be checked.');
          }
        }
        else if (String(input.value || '').trim() === '') {
          throw new Error('Field "' + name + '" is required.');
        }
      }

      if (fieldType === 'boolean') {
        values[name] = !!input.checked;
        return;
      }

      if (fieldType === 'integer') {
        const parsed = Number.parseInt(String(input.value || '').trim(), 10);
        if (Number.isNaN(parsed)) {
          throw new Error('Field "' + name + '" must be an integer.');
        }
        values[name] = parsed;
        return;
      }

      if (fieldType === 'decimal') {
        const parsed = Number.parseFloat(String(input.value || '').trim());
        if (Number.isNaN(parsed)) {
          throw new Error('Field "' + name + '" must be a decimal.');
        }
        values[name] = parsed;
        return;
      }

      if (fieldType === 'link') {
        values[name] = String(input.value || '').trim();
        return;
      }

      if (fieldType === 'email') {
        values[name] = String(input.value || '').trim();
        return;
      }

      if (fieldType === 'telephone') {
        values[name] = String(input.value || '').trim();
        return;
      }

      values[name] = input.value;
    });

    if (Object.keys(values).length === 0) {
      throw new Error('No dynamic field changes to save.');
    }

    const endpointTemplate = scope.dataset.melGenericSaveEndpoint || '/vendor/studio/event/{event}/save';
    const endpoint = endpointTemplate.replace('{event}', String(eventId));
    const token = scope.dataset.melOverviewSaveToken || '';

    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': token
      },
      body: JSON.stringify({ values })
    });

    const data = await response.json();
    if (!response.ok || !data.success) {
      throw new Error((data && data.message) || 'Save failed.');
    }

    return data;
  }

  async function postTabAction(scope, tabKey, payload, progressMessage, successMessage) {
    const endpoint = resolveTabEndpoint(scope, tabKey);
    if (!endpoint) {
      setStatus(scope, 'No endpoint configured for ' + tabKey + '.', 'error');
      return;
    }

    const selectedCard = getSelectedCard(scope);
    if (!selectedCard) {
      setStatus(scope, 'Select an event first.', 'error');
      return;
    }

    const token = scope.dataset.melOverviewSaveToken || '';
    setStatus(scope, progressMessage, 'info');

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-Token': token
        },
        body: JSON.stringify(payload || {})
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error((data && data.message) || 'Request failed.');
      }
      if (data.event) {
        renderEvent(scope, data.event, selectedCard);
      }
      setStatus(scope, (data && data.message) || successMessage, 'success');
    }
    catch (error) {
      setStatus(scope, (error && error.message) || 'Request failed.', 'error');
    }
  }

  function renderEvent(scope, eventData, selectedCard) {
    if (!eventData) {
      return;
    }

    const title = scope.querySelector('[data-mel-editor-title]');
    const titleInput = scope.querySelector('[data-mel-overview-title]');
    const summaryInput = scope.querySelector('[data-mel-overview-summary]');
    const typeInput = scope.querySelector('[data-mel-overview-type]');
    const tickets = scope.querySelector('[data-mel-metric-tickets]');
    const rsvps = scope.querySelector('[data-mel-metric-rsvps]');
    const revenue = scope.querySelector('[data-mel-metric-revenue]');
    const health = scope.querySelector('[data-mel-metric-health]');
    const inspectorState = scope.querySelector('[data-mel-inspector-state]');
    const inspectorHealth = scope.querySelector('[data-mel-inspector-health]');

    if (title) {
      title.textContent = eventData.title || '';
    }
    if (titleInput) {
      titleInput.value = eventData.title || '';
    }
    if (summaryInput) {
      summaryInput.value = eventData.field_event_summary || '';
    }
    if (typeInput) {
      typeInput.value = eventData.field_event_type || 'rsvp';
    }
    if (tickets) {
      tickets.textContent = String(eventData.tickets_metric || 0);
    }
    if (rsvps) {
      rsvps.textContent = String(eventData.rsvps_metric || 0);
    }
    if (revenue) {
      revenue.textContent = '$' + String(eventData.revenue_metric || '0');
    }
    if (health) {
      health.textContent = eventData.health_label || 'No data';
    }
    if (inspectorState) {
      inspectorState.textContent = eventData.status ? String(eventData.status).charAt(0).toUpperCase() + String(eventData.status).slice(1) : 'Draft';
    }
    if (inspectorHealth) {
      inspectorHealth.textContent = eventData.health_label || 'No data';
    }

    updateChecklist(scope, eventData.checklist || {});
    updateActions(scope, eventData);
    updateCardFromEvent(selectedCard, eventData);
    updateWizardFrame(scope, eventData, selectedCard);
    const ticketsInput = scope.querySelector('[data-mel-tickets-config]');
    const attendeesInput = scope.querySelector('[data-mel-attendees-config]');
    const promotionInput = scope.querySelector('[data-mel-promotion-config]');
    const settingsCapacityInput = scope.querySelector('[data-mel-settings-capacity]');
    const settingsVisibilityInput = scope.querySelector('[data-mel-settings-visibility]');

    if (ticketsInput && eventData.tickets_config) {
      ticketsInput.value = JSON.stringify(eventData.tickets_config, null, 2);
    }
    if (attendeesInput && eventData.attendees_config) {
      attendeesInput.value = JSON.stringify(eventData.attendees_config, null, 2);
    }
    if (promotionInput && eventData.promotion_config) {
      promotionInput.value = JSON.stringify(eventData.promotion_config, null, 2);
    }
    if (settingsCapacityInput && eventData.settings_config && Object.prototype.hasOwnProperty.call(eventData.settings_config, 'capacity')) {
      settingsCapacityInput.value = String(eventData.settings_config.capacity || 0);
    }
    if (settingsVisibilityInput && eventData.settings_config && Object.prototype.hasOwnProperty.call(eventData.settings_config, 'visibility')) {
      settingsVisibilityInput.value = String(eventData.settings_config.visibility || 'public');
    }

    const endpoints = eventData && eventData.endpoints ? {
      overviewSave: eventData.endpoints.overview_save || eventData.save_url || '',
      ticketsSave: eventData.endpoints.tickets_save || '',
      attendeesSave: eventData.endpoints.attendees_save || '',
      promotionSave: eventData.endpoints.promotion_save || '',
      settingsSave: eventData.endpoints.settings_save || '',
      publish: eventData.endpoints.publish || ''
    } : collectCardEndpoints(selectedCard);
    applyTabEndpoints(scope, endpoints);
  }

  function setSelectedCard(scope, card) {
    scope.querySelectorAll('[data-event-card], [data-mel-studio-card]').forEach((item) => {
      item.classList.remove('is-selected', 'active');
      item.setAttribute('aria-pressed', 'false');
    });
    if (card) {
      card.classList.add('active');
      card.setAttribute('aria-pressed', 'true');
      scope.dataset.melSelectedEventId = card.dataset.eventId || '';
    }
  }

  async function fetchEventData(scope, card) {
    if (!card || !card.dataset.readUrl) {
      return;
    }
    setStatus(scope, 'Loading event...', 'info');
    setSelectedCard(scope, card);

    try {
      const response = await fetch(card.dataset.readUrl, {
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json'
        }
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error((data && data.message) || 'Could not load event.');
      }
      renderEvent(scope, data.event, card);
      setStatus(scope, '', '');
    }
    catch (error) {
      setStatus(scope, (error && error.message) || 'Could not load event.', 'error');
    }
  }

  function getSelectedCard(scope) {
    return scope.querySelector('[data-event-card].active, [data-mel-studio-card].active, [data-mel-studio-card].is-selected');
  }

  async function saveOverview(scope) {
    const selectedCard = getSelectedCard(scope);
    if (!selectedCard || !selectedCard.dataset.saveUrl) {
      setStatus(scope, 'Select an event first.', 'error');
      return;
    }

    const titleInput = scope.querySelector('[data-mel-overview-title]');
    const summaryInput = scope.querySelector('[data-mel-overview-summary]');
    const typeInput = scope.querySelector('[data-mel-overview-type]');
    const saveButton = scope.querySelector('[data-mel-overview-save]');
    const token = scope.dataset.melOverviewSaveToken || '';

    const payload = {
      title: titleInput ? titleInput.value : '',
      field_event_summary: summaryInput ? summaryInput.value : '',
      field_event_type: typeInput ? typeInput.value : ''
    };

    setDisabledState(saveButton, true);
    setStatus(scope, 'Saving changes...', 'info');

    try {
      const response = await fetch(selectedCard.dataset.saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-Token': token
        },
        body: JSON.stringify(payload)
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error((data && data.message) || 'Save failed.');
      }
      renderEvent(scope, data.event, selectedCard);
      setStatus(scope, data.message || 'Overview saved.', 'success');
    }
    catch (error) {
      setStatus(scope, (error && error.message) || 'Save failed.', 'error');
    }
    finally {
      setDisabledState(saveButton, false);
    }
  }

  function setupTabs(scope) {
    const tabs = scope.querySelectorAll('[data-studio-tab]');
    const panels = scope.querySelectorAll('[data-studio-panel]');
    const queryTab = new URLSearchParams(window.location.search).get('tab') || '';
    const requestedTab = String(scope.dataset.melActiveTab || queryTab).trim();
    if (requestedTab !== '') {
      tabs.forEach((tab) => {
        const isActive = tab.dataset.studioTab === requestedTab;
        tab.classList.toggle('active', isActive);
      });
      panels.forEach((panel) => {
        const isActive = panel.dataset.studioPanel === requestedTab;
        panel.classList.toggle('active', isActive);
      });
    }

    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        const target = tab.dataset.studioTab;
        tabs.forEach((item) => {
          item.classList.remove('active');
        });
        tab.classList.add('active');

        panels.forEach((panel) => {
          if (panel.dataset.studioPanel === target) {
            panel.classList.add('active');
          }
          else {
            panel.classList.remove('active');
          }
        });
      });
    });
  }

  function setupTabActions(scope) {
    scope.querySelectorAll('[data-mel-tab-save]').forEach((button) => {
      button.addEventListener('click', async () => {
        const tabKey = button.dataset.melTabSave || '';
        if (!tabKey) {
          return;
        }
        let payload = {};
        try {
          payload = buildTabPayload(scope, tabKey);
        }
        catch (error) {
          setStatus(scope, (error && error.message) || 'Invalid payload.', 'error');
          return;
        }
        setDisabledState(button, true);
        await postTabAction(
          scope,
          tabKey,
          payload,
          'Saving ' + tabKey + '...',
          tabKey.charAt(0).toUpperCase() + tabKey.slice(1) + ' saved.'
        );
        setDisabledState(button, false);
      });
    });

    const publishButton = scope.querySelector('[data-mel-publish-event]');
    if (publishButton) {
      publishButton.addEventListener('click', async () => {
        setDisabledState(publishButton, true);
        await postTabAction(scope, 'publish', {}, 'Submitting event for review...', 'Event submitted for review.');
        setDisabledState(publishButton, false);
      });
    }
  }

  function setupSearch(scope) {
    const input = scope.querySelector('[data-mel-studio-search]');
    const cards = scope.querySelectorAll('[data-mel-studio-card]');
    if (!input) {
      return;
    }

    input.addEventListener('input', () => {
      const term = String(input.value || '').trim().toLowerCase();
      cards.forEach((card) => {
        const title = String(card.dataset.title || '').toLowerCase();
        const visible = term === '' || title.includes(term);
        card.style.display = visible ? '' : 'none';
      });
    });
  }

  Drupal.behaviors.melVendorStudio = {
    attach(context) {
      once('melVendorStudio', '[data-mel-studio]', context).forEach((scope) => {
        setupTabs(scope);
        setupTabActions(scope);
        setupSearch(scope);
        if (scope.querySelector('[data-mel-dynamic-save]')) {
          loadEventSchema(scope);
        }

        const cards = scope.querySelectorAll('[data-event-card], [data-mel-studio-card]');
        cards.forEach((card) => {
          card.addEventListener('click', () => {
            const studioLink = String(card.dataset.studioLink || '').trim();
            if (studioLink !== '') {
              window.location.assign(studioLink);
              return;
            }
            fetchEventData(scope, card);
          });
        });

        const saveButton = scope.querySelector('[data-mel-overview-save]');
        if (saveButton) {
          saveButton.addEventListener('click', () => saveOverview(scope));
        }

        const dynamicSaveButton = scope.querySelector('[data-mel-dynamic-save]');
        if (dynamicSaveButton) {
          dynamicSaveButton.addEventListener('click', async () => {
            const selectedCard = getSelectedCard(scope);
            const eventId = selectedCard ? (selectedCard.dataset.eventId || '') : '';
            if (!eventId) {
              setStatus(scope, 'Select an event first.', 'error');
              return;
            }
            setDisabledState(dynamicSaveButton, true);
            setStatus(scope, 'Saving dynamic fields...', 'info');
            try {
              const data = await saveEvent(scope, eventId);
              if (data && data.event && selectedCard) {
                renderEvent(scope, data.event, selectedCard);
              }
              setStatus(scope, (data && data.message) || 'Dynamic fields saved.', 'success');
            }
            catch (error) {
              setStatus(scope, (error && error.message) || 'Save failed.', 'error');
            }
            finally {
              setDisabledState(dynamicSaveButton, false);
            }
          });
        }

        const initialId = scope.dataset.melSelectedEventId || '';
        const initialCard = Array.from(cards).find((card) => (card.dataset.eventId || '') === initialId);
        updateWizardFrame(scope, null, initialCard || cards[0] || null);
        applyTabEndpoints(scope, collectCardEndpoints(initialCard || cards[0] || null));
        if (initialCard) {
          fetchEventData(scope, initialCard);
        }
      });
    }
  };
})(Drupal, once);
