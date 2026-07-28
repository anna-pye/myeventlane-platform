(function (Drupal, drupalSettings, once) {
  'use strict';

  let tokenPromise = null;
  const stateClasses = ['is-unsaved', 'is-saving', 'is-saved', 'is-error', 'has-draft'];

  function studioSettings() {
    return drupalSettings.myeventlaneEventStudio || {};
  }

  function getCsrfToken() {
    if (!tokenPromise) {
      tokenPromise = fetch(Drupal.url('session/token'), { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`CSRF token request failed with ${response.status}`);
          }
          return response.text();
        })
        .then((token) => {
          const trimmed = token.trim();
          if (!trimmed) {
            throw new Error('CSRF token response was empty.');
          }
          return trimmed;
        })
        .catch((error) => {
          tokenPromise = null;
          throw error;
        });
    }
    return tokenPromise;
  }

  function setStatus(status, message, state) {
    if (!status) {
      return;
    }
    stateClasses.forEach((className) => status.classList.remove(className));
    if (state) {
      status.classList.add(state);
    }
    status.textContent = message;
  }

  function setFormPublishState(form, state) {
    if (!form) {
      return;
    }
    form.dataset.melPublishState = state;
  }

  function sectionForms(shell) {
    if (!shell) {
      return [];
    }
    return Array.from(shell.querySelectorAll('form')).filter((form) => isWritableForm(form));
  }

  function isWritableForm(form) {
    const capabilities = studioSettings().currentSectionCapabilities || {};
    const section = form.closest('[data-mel-section-writable]');
    if (section && section.dataset.melSectionWritable === '0') {
      return false;
    }
    if (form.dataset.melSectionWritable === '0') {
      return false;
    }
    if (studioSettings().currentSectionWritable === false) {
      return false;
    }
    if (capabilities.writable === false || capabilities.readonly === true || capabilities.deferred === true) {
      return false;
    }
    return true;
  }

  function appendMelDonationHidden(form, key, value) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = `mel[${key}]`;
    input.value = value ?? '';
    input.setAttribute('data-mel-donation-sync', '1');
    form.appendChild(input);
  }

  function bookingModeSupportsDonationSync(bookingForm) {
    const modeInput = bookingForm.querySelector('[name="mel[field_event_type]"]:checked')
      || bookingForm.querySelector('[name="mel[field_event_type]"]');
    const mode = modeInput?.value ?? '';
    return mode === 'rsvp';
  }

  function supportsAutosaveForm(form) {
    const capabilities = studioSettings().currentSectionCapabilities || {};
    return isWritableForm(form) && capabilities.supports_autosave !== false;
  }

  function dirtyForms(shell) {
    return sectionForms(shell).filter((form) => {
      const state = form.dataset.melPublishState;
      return state === 'dirty' || state === 'saving' || state === 'error';
    });
  }

  function formValue(form, name) {
    if (!form) {
      return '';
    }
    const input = form.querySelector(`[name="${name}"]`);
    return input ? input.value : '';
  }

  function publishMetadata(shell, button) {
    const forms = sectionForms(shell);
    const source = forms.find((form) => formValue(form, 'mel_studio_changed') || formValue(form, 'mel_studio_revision'));
    const topbarButton = document.querySelector('[data-mel-publish-action]');
    return {
      section: studioSettings().currentSection || button.dataset.melCurrentSection || '',
      changed: button.dataset.melNodeChanged || topbarButton?.dataset.melNodeChanged || formValue(source, 'mel_studio_changed') || studioSettings().nodeChanged || 0,
      revision_id: button.dataset.melNodeRevision || topbarButton?.dataset.melNodeRevision || formValue(source, 'mel_studio_revision') || studioSettings().nodeRevisionId || 0,
      dirty: dirtyForms(shell).length > 0,
    };
  }

  function publishUrlFor(button) {
    const topbarButton = document.querySelector('[data-mel-publish-action]');
    return button.dataset.melPublishUrl || topbarButton?.dataset.melPublishUrl || studioSettings().publishUrl;
  }

  function setPublishButtonState(button, state) {
    if (!button) {
      return;
    }
    button.classList.remove('is-publishing', 'is-published', 'cannot-publish');
    button.disabled = false;
    button.removeAttribute('aria-disabled');
    if (state === 'publishing') {
      button.classList.add('is-publishing');
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
      button.textContent = Drupal.t('Publishing...');
    }
    else if (state === 'published') {
      button.classList.add('is-published');
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
      button.textContent = Drupal.t('Published');
    }
    else if (state === 'cannot_publish') {
      button.classList.add('cannot-publish');
      button.textContent = Drupal.t('Cannot publish');
    }
    else {
      button.textContent = Drupal.t('Publish');
    }
  }

  function updatePublishPanels(shell, published) {
    shell.querySelectorAll('[data-mel-publish-panel="draft"], .mel-publish-action-card__draft').forEach((panel) => {
      panel.hidden = !!published;
      panel.setAttribute('aria-hidden', published ? 'true' : 'false');
    });
    shell.querySelectorAll('[data-mel-publish-panel="live"], .mel-publish-action-card__live').forEach((panel) => {
      panel.hidden = !published;
      panel.setAttribute('aria-hidden', published ? 'false' : 'true');
    });
    shell.querySelectorAll('[name="mel[status]"]').forEach((input) => {
      input.value = published ? '1' : '0';
    });
  }

  /**
   * Refreshes Launch Centre from publish AJAX payload (no second Publish control).
   *
   * When launch_centre is missing, applies a degraded update from published + readiness
   * so the Publishing section does not stay on the pre-action narrative.
   */
  function updateLaunchCentre(shell, launch, result) {
    const root = shell.querySelector('[data-mel-launch-centre]');
    if (!root) {
      return;
    }
    if (!launch) {
      applyDegradedLaunchCentre(root, result || {});
      return;
    }
    root.removeAttribute('data-mel-launch-stale');
    const state = typeof launch.state === 'string' ? launch.state : 'needs_attention';
    const stateClass = state.replace(/_/g, '-');
    root.className = `mel-launch-centre mel-launch-centre--${stateClass}`;
    root.dataset.melLaunchState = state;
    root.dataset.melLaunchReady = launch.ready ? '1' : '0';
    root.dataset.melLaunchPublished = launch.published ? '1' : '0';
    if (launch.degraded) {
      root.dataset.melLaunchStale = '1';
    }

    setText(root, '[data-mel-launch-eyebrow]', launch.eyebrow || '');
    setText(root, '[data-mel-launch-headline]', launch.headline || '');
    const explanation = root.querySelector('[data-mel-launch-explanation]');
    if (explanation) {
      explanation.textContent = launch.explanation || '';
      explanation.hidden = !launch.explanation;
    }
    const heroHint = root.querySelector('[data-mel-launch-hero-hint]');
    if (heroHint) {
      heroHint.textContent = launch.hero_hint || '';
      heroHint.hidden = !launch.hero_hint;
    }

    const checklist = launch.checklist || {};
    const details = root.querySelector('[data-mel-launch-checklist]');
    if (details && checklist.open !== undefined) {
      details.open = !!checklist.open;
    }
    if (checklist.summary) {
      setText(root, '[data-mel-launch-checklist-summary]', checklist.summary);
    }
    const countEl = root.querySelector('[data-mel-launch-checklist-count]');
    if (countEl && checklist.total_count !== undefined) {
      const total = Number(checklist.total_count) || 0;
      if (total > 0) {
        countEl.hidden = false;
        countEl.textContent = `${Number(checklist.complete_count) || 0}/${total}`;
      }
      else {
        countEl.hidden = true;
        countEl.textContent = '';
      }
    }

    const list = root.querySelector('[data-mel-launch-checklist-list]');
    if (list) {
      syncLaunchChecklistList(list, checklist, result && result.readiness, !!launch.ready);
    }

    const visibilityCurrent = launch.visibility && launch.visibility.current_label;
    if (visibilityCurrent) {
      setText(root, '[data-mel-launch-visibility-current]', visibilityCurrent);
    }

    syncLaunchAfterBand(root, launch.after || null, !!launch.published);
  }

  /**
   * Rebuilds checklist rows from payload items, or from readiness when items are omitted.
   *
   * Prevents “All required items complete” while stale blocker rows remain.
   * When rebuilding from readiness, preserves Fix links from the current DOM and
   * resolves missing ones client-side so blockers do not lose “Fix → …” CTAs.
   */
  function syncLaunchChecklistList(list, checklist, readiness, ready) {
    if (Array.isArray(checklist.items)) {
      list.replaceChildren();
      checklist.items.forEach((item) => {
        list.appendChild(buildLaunchChecklistItem(item));
      });
      return;
    }
    if (readiness) {
      const preservedFixLinks = collectLaunchFixLinksFromList(list);
      list.replaceChildren();
      buildLaunchChecklistItemsFromReadiness(readiness, preservedFixLinks).forEach((item) => {
        list.appendChild(buildLaunchChecklistItem(item));
      });
      return;
    }
    if (ready) {
      // Last resort: drop attention blockers so summary cannot contradict the list.
      list.querySelectorAll('.mel-launch-centre__item--attention').forEach((item) => {
        item.remove();
      });
    }
  }

  function normalizeLaunchChecklistLabel(label) {
    return String(label || '').replace(/\.$/, '').trim().toLowerCase();
  }

  /**
   * Snapshots Fix links already rendered in Launch Centre before a readiness rebuild.
   *
   * @return {Object<string, {fix_url: string, fix_label: ?string}>}
   */
  function collectLaunchFixLinksFromList(list) {
    const map = {};
    list.querySelectorAll('.mel-launch-centre__item').forEach((li) => {
      const labelEl = li.querySelector('.mel-launch-centre__item-label');
      const fix = li.querySelector('a.mel-launch-centre__fix');
      if (!labelEl || !fix) {
        return;
      }
      const href = fix.getAttribute('href');
      if (!href) {
        return;
      }
      const clone = labelEl.cloneNode(true);
      clone.querySelectorAll('.visually-hidden').forEach((el) => el.remove());
      const key = normalizeLaunchChecklistLabel(clone.textContent);
      if (!key) {
        return;
      }
      map[key] = {
        fix_url: href,
        fix_label: (fix.textContent || '').trim() || null,
      };
    });
    return map;
  }

  /**
   * Presentation-only deep links — mirrors EventStudioSectionRenderer::resolveLaunchFixLink.
   *
   * Studio section URLs inherit language prefixes from publishUrl. Vendor console
   * paths must do the same — never hardcode bare /vendor/... on multilingual sites.
   *
   * @return {{fix_url: ?string, fix_label: ?string}}
   */
  function resolveLaunchFixLinkClient(label) {
    const lower = String(label || '').toLowerCase();
    const publishUrl = String(studioSettings().publishUrl || '');
    const studioMatch = publishUrl.match(/^(.*\/studio)\/publish\/?(\?.*)?$/);
    const studioBase = studioMatch ? studioMatch[1] : '';

    let path = '';
    let fixLabel = Drupal.t('Fix → Details');
    if (lower.includes('stripe') || lower.includes('payment') || lower.includes('get paid')) {
      path = vendorConsolePathFromPublishUrl(publishUrl, 'payments');
      fixLabel = Drupal.t('Connect Stripe');
    }
    else if (lower.includes('organiser') || lower.includes('terms') || lower.includes('signed in') || lower.includes('profile')) {
      path = vendorConsolePathFromPublishUrl(publishUrl, 'settings');
      fixLabel = Drupal.t('Open account');
    }
    else if (!studioBase) {
      return { fix_url: null, fix_label: null };
    }
    else if (lower.includes('ticket') || lower.includes('capacity')) {
      path = `${studioBase}/tickets`;
      fixLabel = Drupal.t('Fix → Tickets');
    }
    else if (lower.includes('cover') || lower.includes('image') || lower.includes('branding')) {
      path = `${studioBase}/images`;
      fixLabel = Drupal.t('Fix → Images');
    }
    else if (isLaunchScheduleFixLabelClient(lower)) {
      path = `${studioBase}/schedule`;
      fixLabel = Drupal.t('Fix → Schedule');
    }
    else if (lower.includes('question')) {
      path = `${studioBase}/questions`;
      fixLabel = Drupal.t('Fix → Questions');
    }
    else {
      path = `${studioBase}/details`;
    }

    return {
      fix_url: path,
      fix_label: fixLabel,
    };
  }

  /**
   * Builds /vendor/{segment} with the same language prefix as publishUrl.
   *
   * publishUrl comes from Url::fromRoute() and already includes path prefixes
   * (e.g. /en/vendor/events/12/studio/publish). Bare /vendor/payments would skip them.
   */
  function vendorConsolePathFromPublishUrl(publishUrl, segment) {
    const safeSegment = String(segment || '').replace(/^\/+|\/+$/g, '');
    const url = String(publishUrl || '');
    const vendorMatch = url.match(/^(.*?)\/vendor\/events\//);
    if (vendorMatch) {
      return `${vendorMatch[1]}/vendor/${safeSegment}`;
    }
    const pathPrefix = (typeof drupalSettings !== 'undefined'
      && drupalSettings.path
      && typeof drupalSettings.path.pathPrefix === 'string')
      ? drupalSettings.path.pathPrefix.replace(/^\/+|\/+$/g, '')
      : '';
    return pathPrefix
      ? `/${pathPrefix}/vendor/${safeSegment}`
      : `/vendor/${safeSegment}`;
  }

  function isLaunchScheduleFixLabelClient(lower) {
    if (lower.includes('schedule')) {
      return true;
    }
    if (lower.includes('start date') || lower.includes('end date')) {
      return true;
    }
    return /\bdates?\b/.test(lower);
  }

  function buildLaunchChecklistItemsFromReadiness(readiness, preservedFixLinks) {
    const items = [];
    const trimDot = (label) => String(label || '').replace(/\.$/, '');
    const preserved = preservedFixLinks || {};
    const attachFix = (item) => {
      if (item.complete) {
        return item;
      }
      const key = normalizeLaunchChecklistLabel(item.label);
      if (preserved[key] && preserved[key].fix_url) {
        return {
          ...item,
          fix_url: preserved[key].fix_url,
          fix_label: preserved[key].fix_label || item.fix_label || null,
        };
      }
      const resolved = resolveLaunchFixLinkClient(item.label);
      return {
        ...item,
        fix_url: resolved.fix_url,
        fix_label: resolved.fix_label,
      };
    };

    (Array.isArray(readiness.errors) ? readiness.errors : []).forEach((label) => {
      items.push(attachFix({
        label: trimDot(label),
        complete: false,
        tone: 'attention',
      }));
    });
    (Array.isArray(readiness.warnings) ? readiness.warnings : []).forEach((label) => {
      items.push(attachFix({
        label: trimDot(label),
        complete: false,
        tone: 'warning',
      }));
    });
    (Array.isArray(readiness.completed) ? readiness.completed : []).forEach((label) => {
      items.push({
        label: trimDot(label),
        complete: true,
        tone: 'success',
      });
    });
    (Array.isArray(readiness.recommendations) ? readiness.recommendations : []).forEach((label) => {
      items.push(attachFix({
        label: trimDot(label),
        complete: false,
        tone: 'idea',
      }));
    });
    return items;
  }

  /**
   * Updates the aftercare band so live events do not keep pre-launch copy.
   */
  function syncLaunchAfterBand(root, after, published) {
    const afterRoot = root.querySelector('[data-mel-launch-after]');
    if (!afterRoot) {
      return;
    }
    let title = after && after.title ? String(after.title) : '';
    let afterItems = after && Array.isArray(after.items) ? after.items : null;
    if (!title || !afterItems) {
      title = published
        ? Drupal.t('While your event is live')
        : Drupal.t('After you publish');
      afterItems = [
        Drupal.t('Guests can discover your event'),
        Drupal.t('People can RSVP or buy tickets according to your setup'),
        published
          ? Drupal.t('Share your event from the header')
          : Drupal.t("You'll be able to share your event"),
      ];
    }
    afterRoot.hidden = afterItems.length === 0;
    setText(afterRoot, '[data-mel-launch-after-title]', title);
    const afterList = afterRoot.querySelector('[data-mel-launch-after-list]');
    if (afterList) {
      afterList.replaceChildren();
      afterItems.forEach((line) => {
        const li = document.createElement('li');
        li.textContent = String(line);
        afterList.appendChild(li);
      });
    }
  }

  /**
   * Last-resort Launch Centre sync when the server omitted launch_centre.
   */
  function applyDegradedLaunchCentre(root, result) {
    const published = !!result.published;
    const ready = !!(result.readiness && result.readiness.ready);
    const state = !ready ? 'needs_attention' : (published ? 'live' : 'ready');
    const stateClass = state.replace(/_/g, '-');
    root.className = `mel-launch-centre mel-launch-centre--${stateClass}`;
    root.dataset.melLaunchState = state;
    root.dataset.melLaunchReady = ready ? '1' : '0';
    root.dataset.melLaunchPublished = published ? '1' : '0';
    root.dataset.melLaunchStale = '1';

    const eyebrow = !ready
      ? Drupal.t('Needs attention')
      : (published ? Drupal.t('Your event is live') : Drupal.t('Ready to launch'));
    const headline = !ready
      ? (published
        ? Drupal.t('Your event is live — a few things need attention')
        : Drupal.t('A few things left before launching'))
      : (published ? Drupal.t('Your event is live') : Drupal.t('Ready to launch'));
    const heroHint = !ready
      ? (published
        ? Drupal.t('Continue setup from the header to fix what needs attention.')
        : Drupal.t('Publish is unavailable until the checklist is clear. Continue setup from the header.'))
      : (published
        ? Drupal.t('Use Share event in the header to spread the word.')
        : Drupal.t('Use Publish event in the header when you are ready.'));

    setText(root, '[data-mel-launch-eyebrow]', eyebrow);
    setText(root, '[data-mel-launch-headline]', headline);
    const explanation = root.querySelector('[data-mel-launch-explanation]');
    if (explanation) {
      explanation.textContent = published && ready
        ? Drupal.t('People can discover your event and RSVP or buy tickets according to your setup. Share from the header when you are ready.')
        : (!ready
          ? Drupal.t('Finish the checklist below. We never block without explaining why.')
          : Drupal.t("You're ready to go live. Guests will be able to discover this event and RSVP or buy tickets according to your setup."));
      explanation.hidden = false;
    }
    const heroHintEl = root.querySelector('[data-mel-launch-hero-hint]');
    if (heroHintEl) {
      heroHintEl.textContent = heroHint;
      heroHintEl.hidden = false;
    }

    const details = root.querySelector('[data-mel-launch-checklist]');
    if (details) {
      details.open = !ready;
    }
    const summary = ready
      ? Drupal.t('All required items complete')
      : Drupal.t('Finish the checklist before you can launch');
    setText(root, '[data-mel-launch-checklist-summary]', summary);

    const list = root.querySelector('[data-mel-launch-checklist-list]');
    if (list) {
      syncLaunchChecklistList(list, {}, result.readiness || null, ready);
    }

    syncLaunchAfterBand(root, null, published);
  }

  function buildLaunchChecklistItem(item) {
    const tone = (item && item.tone) ? String(item.tone) : 'success';
    const complete = !!(item && item.complete);
    const li = document.createElement('li');
    li.className = `mel-launch-centre__item mel-launch-centre__item--${tone.replace(/_/g, '-')}${complete ? ' is-complete' : ''}`;

    const mark = document.createElement('span');
    mark.className = 'mel-launch-centre__mark';
    mark.setAttribute('aria-hidden', 'true');
    mark.textContent = complete ? '✓' : (tone === 'attention' ? '○' : '·');
    li.appendChild(mark);

    const labelWrap = document.createElement('span');
    labelWrap.className = 'mel-launch-centre__item-label';
    const sr = document.createElement('span');
    sr.className = 'visually-hidden';
    if (complete) {
      sr.textContent = Drupal.t('Complete:');
    }
    else if (tone === 'attention') {
      sr.textContent = Drupal.t('Needs attention:');
    }
    else if (tone === 'warning') {
      sr.textContent = Drupal.t('Warning:');
    }
    else {
      sr.textContent = Drupal.t('Suggestion:');
    }
    labelWrap.appendChild(sr);
    labelWrap.appendChild(document.createTextNode(` ${item && item.label ? String(item.label) : ''}`));
    li.appendChild(labelWrap);

    if (!complete && item && item.fix_url) {
      const fix = document.createElement('a');
      fix.className = 'mel-launch-centre__fix mel-btn mel-btn--ghost';
      fix.href = String(item.fix_url);
      fix.textContent = item.fix_label ? String(item.fix_label) : Drupal.t('Fix');
      li.appendChild(fix);
    }
    return li;
  }

  function updateFormMetadata(shell, result) {
    if (!result) {
      return;
    }
    sectionForms(shell).forEach((form) => {
      if (result.changed !== undefined && result.changed !== null) {
        const changed = form.querySelector('[name="mel_studio_changed"]');
        if (changed) {
          changed.value = String(result.changed);
        }
      }
      if (result.revisionId !== undefined && result.revisionId !== null) {
        const revision = form.querySelector('[name="mel_studio_revision"]');
        if (revision) {
          revision.value = String(result.revisionId);
        }
      }
      setFormPublishState(form, 'clean');
    });
  }

  function setText(root, selector, text) {
    const el = root.querySelector(selector);
    if (el) {
      el.textContent = text;
    }
  }

  /**
   * Home (overview) chrome is topbar + active Boost only — no readiness strip.
   */
  function isHomeShell(shell) {
    return !!(shell && (
      shell.classList.contains('mel-event-studio--home')
      || shell.dataset.currentSectionId === 'overview'
    ));
  }

  const MISSION_CONTROL_DETAILS_STORAGE_KEY = 'mel.eventStudio.missionControl.expanded';

  function readMissionControlExpanded() {
    try {
      return sessionStorage.getItem(MISSION_CONTROL_DETAILS_STORAGE_KEY) === '1';
    }
    catch (e) {
      return false;
    }
  }

  function writeMissionControlExpanded(expanded) {
    try {
      sessionStorage.setItem(MISSION_CONTROL_DETAILS_STORAGE_KEY, expanded ? '1' : '0');
    }
    catch (e) {
      // Session persistence is best-effort only.
    }
  }

  /**
   * Session-scoped expand/collapse for Mission Control details.
   * Default collapsed; once expanded, stays open across Workspace navigations.
   */
  function bindMissionControlDetails(shell) {
    if (!shell) {
      return;
    }
    once('mel-event-studio-mission-control-details', '[data-mel-mc-details]', shell).forEach((details) => {
      details.open = readMissionControlExpanded();
      details.addEventListener('toggle', () => {
        writeMissionControlExpanded(!!details.open);
      });
    });
  }

  function formatMissionControlQualityBadge(quality) {
    const status = typeof quality.status_label === 'string' ? quality.status_label.trim() : '';
    const scoreLabel = typeof quality.score_label === 'string' && quality.score_label
      ? quality.score_label
      : ((typeof quality.score === 'number' ? quality.score : 0) + '%');
    if (status) {
      return status + ' · ' + scoreLabel;
    }
    return scoreLabel;
  }

  function setOptionalText(root, selector, value) {
    const el = root.querySelector(selector);
    if (!el) {
      return;
    }
    const text = typeof value === 'string' ? value : '';
    el.textContent = text;
    el.hidden = text === '';
  }

  function updateMissionControlChecklist(list, items) {
    const rows = Array.isArray(items) ? items : [];
    list.replaceChildren();
    if (rows.length === 0) {
      list.hidden = true;
      return;
    }
    list.hidden = false;
    rows.forEach((item) => {
      if (!item || typeof item.label !== 'string') {
        return;
      }
      const complete = !!item.complete;
      const tone = typeof item.tone === 'string' ? item.tone : '';
      const li = document.createElement('li');
      li.className = 'mel-event-studio-mission-control__check'
        + (tone ? ` mel-event-studio-mission-control__check--${tone}` : '');
      const mark = document.createElement('span');
      mark.className = 'mel-event-studio-mission-control__check-mark';
      mark.setAttribute('aria-hidden', 'true');
      mark.textContent = complete ? '✔' : ((tone === 'warning' || tone === 'idea') ? '◇' : '○');
      const sr = document.createElement('span');
      sr.className = 'visually-hidden';
      if (complete) {
        sr.textContent = Drupal.t('Complete:');
      }
      else if (tone === 'warning') {
        sr.textContent = Drupal.t('Suggested review:');
      }
      else if (tone === 'idea') {
        sr.textContent = Drupal.t('Idea:');
      }
      else {
        sr.textContent = Drupal.t('Required before publishing:');
      }
      const label = document.createElement('span');
      label.setAttribute('data-mel-mc-check-label', '');
      label.textContent = item.label;
      li.appendChild(mark);
      li.appendChild(sr);
      li.appendChild(label);
      list.appendChild(li);
    });
  }

  /**
   * Applies Mission Control ViewModel (Home body or non-Home chrome).
   * CTA mirrors Hero except approved Stripe Connect exception; publish mode
   * defers to Hero publish control.
   *
   * When the server could not attach mission_control, synthesise a degraded
   * card from readiness checklist / strip fields so Hero and Mission Control
   * do not diverge after publish or unpublish.
   */
  function updateMissionControl(shell, readiness) {
    if (!readiness) {
      return;
    }
    let mc = readiness.mission_control
      || (readiness.home && readiness.home.mission_control)
      || null;
    if (!mc) {
      mc = buildDegradedMissionControl(shell, readiness);
    }
    if (!mc) {
      return;
    }
    const root = shell.querySelector('[data-mel-mission-control]');
    if (!root) {
      return;
    }

    const tone = typeof mc.tone === 'string' ? mc.tone : 'success';
    root.classList.remove(
      'mel-event-studio-mission-control--attention',
      'mel-event-studio-mission-control--success',
    );
    root.classList.add(`mel-event-studio-mission-control--${tone}`);
    root.setAttribute('data-mel-mc-tone', tone);

    const next = mc.next_step || {};
    setText(root, '[data-mel-mc-next-title]', next.title || '');
    const whyText = typeof next.message === 'string' ? next.message : '';
    const why = root.querySelector('[data-mel-mc-why]');
    if (why) {
      const whyBody = why.matches('[data-mel-mc-why-text]')
        ? why
        : why.querySelector('[data-mel-mc-why-text]');
      if (whyBody) {
        whyBody.textContent = whyText;
      }
      else {
        why.textContent = whyText;
      }
      why.hidden = whyText === '';
    }

    const mode = typeof next.mode === 'string' ? next.mode : 'link';
    const cta = root.querySelector('[data-mel-mc-cta]');
    let publishHint = root.querySelector('[data-mel-mc-publish-hint]');
    if (mode === 'publish') {
      if (!publishHint) {
        publishHint = document.createElement('p');
        publishHint.className = 'mel-event-studio-mission-control__publish-hint';
        publishHint.setAttribute('data-mel-mc-publish-hint', '');
        publishHint.textContent = Drupal.t('Use Publish in the header when you are ready.');
        if (cta && cta.parentNode) {
          cta.parentNode.insertBefore(publishHint, cta);
        }
        else {
          const nextBlock = root.querySelector('[data-mel-mc-next]');
          nextBlock?.appendChild(publishHint);
        }
      }
      publishHint.hidden = false;
      if (cta) {
        cta.hidden = true;
      }
    }
    else {
      if (publishHint) {
        publishHint.hidden = true;
      }
      if (cta) {
        const label = typeof next.action_label === 'string' ? next.action_label : '';
        const url = typeof next.url === 'string' ? next.url : '';
        const key = typeof next.key === 'string' ? next.key : '';
        cta.setAttribute('data-mel-mc-cta-key', key);
        cta.setAttribute('data-mel-mc-mirrors-hero', next.mirrors_hero === false ? '0' : '1');
        if (label && url) {
          cta.textContent = label;
          cta.setAttribute('href', url);
          cta.hidden = false;
        }
        else {
          cta.hidden = true;
        }
      }
    }

    // Disclosure open state is session-owned — do not reset from ViewModel.
    const details = root.querySelector('[data-mel-mc-details]');
    if (details) {
      details.open = readMissionControlExpanded();
    }

    const improvements = mc.improvements || {};
    const improvementsBlock = root.querySelector('[data-mel-mc-improvements]');
    if (improvementsBlock) {
      setOptionalText(improvementsBlock, '[data-mel-mc-improvements-count]', improvements.complete_label || '');
      setOptionalText(improvementsBlock, '[data-mel-mc-improvements-headline]', improvements.headline || '');
      const list = improvementsBlock.querySelector('[data-mel-mc-checklist]');
      if (list && improvements.items !== undefined) {
        updateMissionControlChecklist(list, improvements.items);
      }
    }

    const quality = mc.event_quality || {};
    const visible = !!quality.visible;
    const ready = !!quality.ready;
    const badge = root.querySelector('[data-mel-mc-quality-badge]');
    if (badge) {
      badge.hidden = !visible;
      badge.classList.toggle('is-ready', ready);
      const badgeText = badge.querySelector('[data-mel-mc-quality-badge-text]');
      if (badgeText) {
        badgeText.textContent = formatMissionControlQualityBadge(quality);
      }
    }

    const qualityBlock = root.querySelector('[data-mel-mc-quality]');
    if (qualityBlock) {
      qualityBlock.hidden = !visible;
      qualityBlock.classList.toggle('is-ready', ready);
      setText(qualityBlock, '[data-mel-mc-quality-score-value]', quality.score_label || ((quality.score || 0) + '%'));
      const bar = qualityBlock.querySelector('[data-mel-mc-quality-bar]');
      const fill = qualityBlock.querySelector('[data-mel-mc-quality-bar-fill]');
      const score = typeof quality.score === 'number' ? quality.score : 0;
      if (bar) {
        bar.setAttribute('aria-valuenow', String(score));
      }
      if (fill) {
        fill.style.width = score + '%';
      }
      const status = qualityBlock.querySelector('[data-mel-mc-quality-status]');
      if (status) {
        const statusLabel = typeof quality.status_label === 'string' ? quality.status_label : '';
        status.textContent = statusLabel;
        status.hidden = statusLabel === '';
      }
      const explain = qualityBlock.querySelector('[data-mel-mc-quality-explain]');
      if (explain) {
        const explanation = typeof quality.explanation === 'string' ? quality.explanation : '';
        explain.textContent = explanation;
        explain.hidden = explanation === '';
      }
    }
  }

  /**
   * Client-side Mission Control when readiness.mission_control is null.
   */
  function buildDegradedMissionControl(shell, readiness) {
    if (!shell || !shell.querySelector('[data-mel-mission-control]')) {
      return null;
    }
    const published = readiness.published === true || shell.dataset.melPublished === '1';
    const ready = !!readiness.ready;
    const checklist = Array.isArray(readiness.checklist) ? readiness.checklist : [];
    const complete = checklist.filter((item) => item && item.complete).length;
    const total = Math.max(1, checklist.length);
    const stripTitle = typeof readiness.strip_title === 'string' ? readiness.strip_title : '';
    let message = typeof readiness.strip_explanation === 'string' ? readiness.strip_explanation : '';
    let title;
    let mode = 'link';
    let key = 'continue_setup';
    let actionLabel = null;
    let url = null;

    if (!ready) {
      title = Drupal.t('Continue setup');
      key = 'continue_setup';
      const publishingLink = shell.querySelector('a[href*="/studio/publishing"]');
      if (publishingLink && publishingLink.getAttribute('href')) {
        actionLabel = Drupal.t('Continue setup');
        url = publishingLink.getAttribute('href');
      }
    }
    else if (!published) {
      title = Drupal.t('Ready when you are');
      mode = 'publish';
      key = 'publish';
      if (!message) {
        message = Drupal.t('Your event looks ready. Publish when you want guests to find it.');
      }
    }
    else {
      title = Drupal.t('Share your event');
      key = 'share';
      if (!message) {
        message = Drupal.t('Your event is live. Share the page or message your attendees.');
      }
      const shareLink = shell.querySelector('[data-mel-primary-cta][data-mel-cta-key="share"], a[href*="/studio/marketing"]');
      if (shareLink && shareLink.getAttribute('href')) {
        actionLabel = Drupal.t('Share');
        url = shareLink.getAttribute('href');
      }
    }

    return {
      tone: ready ? 'success' : 'attention',
      degraded: true,
      next_step: {
        title: title,
        message: message,
        mode: mode,
        key: key,
        action_label: actionLabel,
        url: url,
        mirrors_hero: true,
        publish_is_primary: mode === 'publish',
      },
      improvements: {
        open: checklist.length <= 4,
        complete_label: Drupal.t('@done of @total complete', {
          '@done': complete,
          '@total': total,
        }),
        headline: stripTitle,
        items: checklist,
      },
      event_quality: {
        visible: false,
      },
    };
  }

  function updateReadiness(shell, readiness) {
    if (!readiness) {
      return;
    }
    updateMissionControl(shell, readiness);
  }

  function updateEventHealth() {
    // Event Health chrome retired — Mission Control owns the operational summary.
  }

  function syncTopbarState(shell, stateText) {
    const meta = shell.querySelector('.mel-event-studio-topbar__meta');
    if (!meta) {
      return;
    }
    let stateEl = meta.querySelector('[data-mel-publish-state]');
    if (stateText) {
      if (!stateEl) {
        stateEl = document.createElement('span');
        stateEl.className = 'mel-event-studio-topbar__state';
        stateEl.setAttribute('data-mel-publish-state', '');
        const badge = meta.querySelector('[data-mel-publish-status]');
        if (badge) {
          badge.insertAdjacentElement('afterend', stateEl);
        }
        else {
          meta.prepend(stateEl);
        }
      }
      stateEl.textContent = stateText;
      return;
    }
    stateEl?.remove();
  }

  function syncTopbarLocation(shell, location) {
    const container = shell.querySelector('[data-mel-topbar-location]');
    if (!container) {
      return;
    }
    const primary = container.querySelector('[data-mel-topbar-location-primary]');
    const secondary = container.querySelector('[data-mel-topbar-location-secondary]');
    const warning = container.querySelector('[data-mel-topbar-location-warning]');
    if (!location || !location.configured) {
      container.querySelectorAll('.mel-event-studio-topbar__location-line--primary, .mel-event-studio-topbar__location-line--secondary').forEach((line) => {
        line.remove();
      });
      let warningLine = container.querySelector('.mel-event-studio-topbar__location-line--warning');
      if (!warningLine) {
        warningLine = document.createElement('p');
        warningLine.className = 'mel-event-studio-topbar__location-line mel-event-studio-topbar__location-line--warning';
        warningLine.innerHTML = '<span class="mel-event-studio-topbar__location-icon" aria-hidden="true">⚠</span><span class="mel-event-studio-topbar__location-text" data-mel-topbar-location-warning></span>';
        container.appendChild(warningLine);
      }
      const warningText = warningLine.querySelector('[data-mel-topbar-location-warning]');
      if (warningText) {
        warningText.textContent = location?.warning || 'Venue not yet configured';
      }
      return;
    }
    container.querySelector('.mel-event-studio-topbar__location-line--warning')?.remove();
    if (location.primary_line) {
      let primaryLine = container.querySelector('.mel-event-studio-topbar__location-line--primary');
      if (!primaryLine) {
        primaryLine = document.createElement('p');
        primaryLine.className = 'mel-event-studio-topbar__location-line mel-event-studio-topbar__location-line--primary';
        primaryLine.innerHTML = '<span class="mel-event-studio-topbar__location-icon" aria-hidden="true">📍</span><span class="mel-event-studio-topbar__location-text" data-mel-topbar-location-primary></span>';
        container.prepend(primaryLine);
      }
      const primaryText = primaryLine.querySelector('[data-mel-topbar-location-primary]');
      if (primaryText) {
        primaryText.textContent = location.primary_line;
      }
    }
    else {
      container.querySelector('.mel-event-studio-topbar__location-line--primary')?.remove();
    }
    if (location.secondary_line) {
      let secondaryLine = container.querySelector('.mel-event-studio-topbar__location-line--secondary');
      if (!secondaryLine) {
        secondaryLine = document.createElement('p');
        secondaryLine.className = 'mel-event-studio-topbar__location-line mel-event-studio-topbar__location-line--secondary';
        secondaryLine.innerHTML = '<span class="mel-event-studio-topbar__location-text" data-mel-topbar-location-secondary></span>';
        container.appendChild(secondaryLine);
      }
      const secondaryText = secondaryLine.querySelector('[data-mel-topbar-location-secondary]');
      if (secondaryText) {
        secondaryText.textContent = location.secondary_line;
      }
    }
    else {
      container.querySelector('.mel-event-studio-topbar__location-line--secondary')?.remove();
    }
  }

  function updateTopbar(shell, result) {
    if (!result || !result.topbar) {
      return;
    }
    if (result.published !== undefined) {
      studioSettings().published = !!result.published;
      shell.dataset.melPublished = result.published ? '1' : '0';
    }
    setText(shell, '[data-mel-publish-status]', result.topbar.status || '');
    const statusBadge = shell.querySelector('[data-mel-publish-status]');
    if (statusBadge) {
      const statusKey = typeof result.topbar.status_key === 'string'
        ? result.topbar.status_key
        : (result.published ? 'live' : 'draft');
      statusBadge.className = `mel-event-studio-topbar__badge mel-event-studio-topbar__badge--${statusKey}`;
    }
    syncTopbarState(shell, result.topbar.state || '');
    setText(shell, '[data-mel-publish-last-saved]', result.topbar.lastSaved || '');
    if (result.topbar.location) {
      syncTopbarLocation(shell, result.topbar.location);
    }
    const buttons = shell.querySelectorAll('[data-mel-publish-action], [data-mel-card-publish-action], [data-mel-unpublish-action]');
    if (result.changed !== undefined && result.changed !== null) {
      const changed = String(result.changed);
      buttons.forEach((actionButton) => {
        actionButton.dataset.melNodeChanged = changed;
      });
      studioSettings().nodeChanged = changed;
    }
    if (result.revisionId !== undefined && result.revisionId !== null) {
      const revisionId = String(result.revisionId);
      buttons.forEach((actionButton) => {
        actionButton.dataset.melNodeRevision = revisionId;
      });
      studioSettings().nodeRevisionId = revisionId;
    }
    const button = shell.querySelector('[data-mel-publish-action]');
    if (button) {
      setPublishButtonState(button, result.published ? 'published' : 'idle');
    }
    if (result.topbar && result.topbar.primary_cta) {
      syncHeroPrimaryCta(shell, result.topbar.primary_cta, !!result.published);
    }
    else if (result.published) {
      syncHeroPrimaryCta(shell, { key: 'share', mode: 'link', publish_is_primary: false }, true);
    }
  }

  /**
   * Keeps Workspace Hero to one primary CTA after publish AJAX.
   */
  function syncHeroPrimaryCta(shell, primaryCta, published) {
    const key = (primaryCta && typeof primaryCta.key === 'string')
      ? primaryCta.key
      : (published ? 'share' : 'publish');
    shell.querySelector('[data-mel-studio-topbar]')?.setAttribute('data-mel-hero-primary-key', key);

    const share = shell.querySelector('[data-mel-hero-share]');
    const primaryLink = shell.querySelector('[data-mel-hero-primary-link]');
    const publish = shell.querySelector('[data-mel-publish-action]');

    const setPrimaryClass = (el, isPrimary) => {
      if (!el) {
        return;
      }
      el.classList.toggle('mel-btn--primary', isPrimary);
      el.classList.toggle('mel-btn--ghost', !isPrimary);
      if (isPrimary) {
        el.setAttribute('data-mel-hero-primary', '1');
      }
      else {
        el.removeAttribute('data-mel-hero-primary');
      }
    };

    if (key === 'share') {
      setPrimaryClass(share, true);
      if (primaryLink) {
        primaryLink.hidden = true;
        primaryLink.removeAttribute('data-mel-hero-primary');
        primaryLink.classList.remove('mel-btn', 'mel-btn--primary', 'mel-btn--ghost');
      }
      if (publish) {
        setPrimaryClass(publish, false);
        publish.classList.remove('mel-btn--ghost');
        publish.classList.add('mel-event-studio-topbar__status-action');
      }
      return;
    }

    if (key === 'continue_setup') {
      if (primaryLink && primaryCta && primaryCta.url && primaryCta.label) {
        primaryLink.hidden = false;
        primaryLink.setAttribute('href', primaryCta.url);
        primaryLink.textContent = primaryCta.label;
        primaryLink.classList.add('mel-btn');
        setPrimaryClass(primaryLink, true);
      }
      setPrimaryClass(share, false);
      if (publish) {
        setPrimaryClass(publish, false);
        publish.classList.remove('mel-event-studio-topbar__status-action');
      }
      return;
    }

    // publish
    if (primaryLink) {
      primaryLink.hidden = true;
      primaryLink.removeAttribute('data-mel-hero-primary');
      primaryLink.classList.remove('mel-btn', 'mel-btn--primary', 'mel-btn--ghost');
    }
    setPrimaryClass(share, false);
    if (publish) {
      setPrimaryClass(publish, true);
      publish.classList.remove('mel-event-studio-topbar__status-action');
    }
  }

  function applyMobilePriorities(shell) {
    if (!shell) {
      return;
    }
    shell.querySelectorAll('.mel-event-studio-sidebar__item[data-mobile-priority]').forEach((item) => {
      const priority = Number(item.dataset.mobilePriority || 100);
      if (Number.isFinite(priority)) {
        item.style.order = String(priority);
      }
    });
  }

  function prefersReducedMotion() {
    return typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  // Returns true only when execCommand('copy') reports success.
  function fallbackCopyText(text) {
    const input = document.createElement('textarea');
    input.value = text;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.left = '-9999px';
    document.body.appendChild(input);
    input.focus();
    input.select();
    let ok = false;
    try {
      ok = document.execCommand('copy');
    }
    catch (error) {
      console.error('Event Studio copy link failed.', error);
      ok = false;
    }
    document.body.removeChild(input);
    return ok;
  }

  function setOutcomeTone(feedback, tone) {
    feedback.classList.remove(
      'is-success',
      'is-error',
      'mel-outcome--success',
      'mel-outcome--error',
      'mel-launch-success--enter',
    );

    if (tone === 'success') {
      feedback.classList.add('is-success', 'mel-outcome--success');
    }
    else if (tone === 'error') {
      feedback.classList.add('is-error', 'mel-outcome--error');
    }
  }

  function renderPublishFeedback(shell, title, messages, restoreUrl) {
    const feedback = shell.querySelector('[data-mel-publish-feedback]');
    if (!feedback) {
      return;
    }
    const errorPanel = feedback.querySelector('[data-mel-publish-error]');
    const successPanel = feedback.querySelector('[data-mel-publish-success]');
    if (successPanel) {
      successPanel.hidden = true;
    }
    if (errorPanel) {
      errorPanel.hidden = false;
    }
    setOutcomeTone(feedback, 'error');

    const list = feedback.querySelector('[data-mel-publish-feedback-list]');
    const heading = feedback.querySelector('[data-mel-publish-feedback-title]');
    const restore = feedback.querySelector('[data-mel-publish-restore]');
    if (heading) {
      heading.textContent = title;
    }
    if (list) {
      list.textContent = '';
      (messages || []).forEach((message) => {
        const item = document.createElement('li');
        item.textContent = message;
        list.appendChild(item);
      });
    }
    if (restore) {
      if (restoreUrl) {
        restore.href = restoreUrl;
        restore.hidden = false;
      }
      else {
        restore.hidden = true;
        restore.removeAttribute('href');
      }
    }
    feedback.hidden = false;
    if (heading && typeof heading.focus === 'function') {
      heading.focus({ preventScroll: true });
    }
  }

  function renderPublishSuccessFeedback(shell, handoff) {
    const feedback = shell.querySelector('[data-mel-publish-feedback]');
    if (!feedback || !handoff) {
      return;
    }

    const errorPanel = feedback.querySelector('[data-mel-publish-error]');
    const successPanel = feedback.querySelector('[data-mel-publish-success]');
    if (errorPanel) {
      errorPanel.hidden = true;
    }
    if (!successPanel) {
      renderPublishFeedback(shell, handoff.message || Drupal.t('Your event is now live'), []);
      return;
    }

    const setText = (selector, value) => {
      const el = successPanel.querySelector(selector);
      if (el && value) {
        el.textContent = value;
      }
    };

    const titleText = handoff.title || Drupal.t('Your event is now live');
    setText('[data-mel-publish-success-title]', titleText);
    setText('[data-mel-publish-success-message]', handoff.message || titleText);
    setText('[data-mel-publish-success-announce]', titleText);

    const peopleWrap = successPanel.querySelector('[data-mel-publish-success-people]');
    const peopleIntro = successPanel.querySelector('[data-mel-publish-success-people-intro]');
    const peopleList = successPanel.querySelector('[data-mel-publish-success-people-list]');
    const peopleCan = Array.isArray(handoff.people_can) ? handoff.people_can : [];
    if (peopleWrap && peopleList) {
      peopleList.textContent = '';
      if (peopleIntro && handoff.people_can_intro) {
        peopleIntro.textContent = handoff.people_can_intro;
      }
      peopleCan.forEach((line) => {
        if (!line) {
          return;
        }
        const item = document.createElement('li');
        item.textContent = line;
        peopleList.appendChild(item);
      });
      peopleWrap.hidden = peopleList.childElementCount === 0;
    }

    const recommended = successPanel.querySelector('[data-mel-publish-success-recommended]');
    const shareLink = successPanel.querySelector('[data-mel-publish-success-share]');
    const recommendedEyebrow = successPanel.querySelector('[data-mel-publish-success-recommended-eyebrow]');
    if (recommendedEyebrow && handoff.recommended_eyebrow) {
      recommendedEyebrow.textContent = handoff.recommended_eyebrow;
    }
    if (shareLink) {
      const shareHref = handoff.share_workspace_url || '';
      if (handoff.recommended_label) {
        shareLink.textContent = handoff.recommended_label;
      }
      if (shareHref) {
        shareLink.href = shareHref;
        shareLink.hidden = false;
        if (recommended) {
          recommended.hidden = false;
        }
      }
      else {
        shareLink.removeAttribute('href');
        if (recommended) {
          recommended.hidden = true;
        }
      }
    }

    const secondary = successPanel.querySelector('[data-mel-publish-success-secondary]');
    const copyBtn = successPanel.querySelector('[data-mel-publish-success-copy]');
    const viewLink = successPanel.querySelector('[data-mel-publish-success-view]');
    let secondaryVisible = false;

    if (copyBtn) {
      if (handoff.copy_label) {
        copyBtn.textContent = handoff.copy_label;
      }
      if (handoff.view_url) {
        copyBtn.dataset.melCopyUrl = handoff.view_url;
        copyBtn.hidden = false;
        secondaryVisible = true;
      }
      else {
        delete copyBtn.dataset.melCopyUrl;
        copyBtn.hidden = true;
      }
    }
    if (viewLink) {
      if (handoff.view_label) {
        viewLink.textContent = handoff.view_label;
      }
      if (handoff.view_url) {
        viewLink.href = handoff.view_url;
        viewLink.hidden = false;
        secondaryVisible = true;
      }
      else {
        viewLink.hidden = true;
        viewLink.removeAttribute('href');
      }
    }
    if (secondary) {
      secondary.hidden = !secondaryVisible;
    }

    const boostCard = successPanel.querySelector('[data-mel-publish-success-boost-card]');
    const boostLink = successPanel.querySelector('[data-mel-publish-success-boost]');
    const boostEyebrow = successPanel.querySelector('[data-mel-publish-success-boost-eyebrow]');
    if (boostEyebrow && handoff.boost_eyebrow) {
      boostEyebrow.textContent = handoff.boost_eyebrow;
    }
    if (boostCard) {
      if (handoff.boost_url) {
        boostCard.hidden = false;
        if (boostLink) {
          boostLink.href = handoff.boost_url;
          if (handoff.boost_label) {
            boostLink.textContent = handoff.boost_label;
          }
        }
      }
      else {
        boostCard.hidden = true;
        if (boostLink) {
          boostLink.removeAttribute('href');
        }
      }
    }

    const socialRow = successPanel.querySelector('[data-mel-publish-success-social]');
    if (socialRow) {
      socialRow.textContent = '';
      const share = handoff.share || {};
      const shareLinks = [
        ['facebook', Drupal.t('Facebook')],
        ['linkedin', Drupal.t('LinkedIn')],
        ['twitter', Drupal.t('X')],
      ];
      shareLinks.forEach(([key, label]) => {
        if (!share[key]) {
          return;
        }
        const link = document.createElement('a');
        link.className = 'mel-btn mel-btn--ghost mel-launch-success__social-link';
        link.href = share[key];
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = label;
        socialRow.appendChild(link);
      });
      socialRow.hidden = socialRow.childElementCount === 0;
    }

    successPanel.hidden = false;
    setOutcomeTone(feedback, 'success');
    feedback.classList.toggle('mel-launch-success--enter', !prefersReducedMotion());
    feedback.hidden = false;

    const titleEl = successPanel.querySelector('[data-mel-publish-success-title]');
    if (titleEl && typeof titleEl.focus === 'function') {
      titleEl.setAttribute('tabindex', '-1');
      titleEl.focus({ preventScroll: true });
    }
    if (typeof feedback.scrollIntoView === 'function') {
      feedback.scrollIntoView({
        block: 'nearest',
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
      });
    }
  }

  function hidePublishFeedback(shell) {
    const feedback = shell.querySelector('[data-mel-publish-feedback]');
    if (feedback) {
      feedback.hidden = true;
      setOutcomeTone(feedback, null);
      const errorPanel = feedback.querySelector('[data-mel-publish-error]');
      const successPanel = feedback.querySelector('[data-mel-publish-success]');
      if (errorPanel) {
        errorPanel.hidden = true;
      }
      if (successPanel) {
        successPanel.hidden = true;
      }
    }
  }

  function bindPublishSuccessCopy(context) {
    once('mel-publish-success-copy', '[data-mel-publish-success-copy]', context).forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        const url = button.dataset.melCopyUrl || '';
        if (!url) {
          return;
        }
        const panel = button.closest('[data-mel-publish-success]');
        const feedback = panel ? panel.querySelector('[data-mel-publish-success-copy-feedback]') : null;
        const copied = Drupal.t('Link copied.');
        const failed = Drupal.t('Could not copy link.');
        const announce = (ok) => {
          if (feedback) {
            feedback.textContent = ok ? copied : failed;
          }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(() => announce(true)).catch(() => {
            announce(fallbackCopyText(url));
          });
          return;
        }
        announce(fallbackCopyText(url));
      });
    });
  }

  function bindPublishBoostDismiss(context) {
    once('mel-publish-boost-dismiss', '[data-mel-publish-boost-dismiss]', context).forEach((button) => {
      button.addEventListener('click', () => {
        const card = button.closest('[data-mel-publish-success-boost-card], [data-mel-publish-boost-cta]');
        if (card) {
          card.hidden = true;
        }
      });
    });
  }

  Drupal.behaviors.melEventStudioShellAutosave = {
    attach(context) {
      bindPublishBoostDismiss(context);
      bindPublishSuccessCopy(context);

      once('mel-event-studio-mobile-priority', '[data-mel-studio-shell]', context).forEach((shell) => {
        applyMobilePriorities(shell);
        bindMissionControlDetails(shell);
        const handoff = studioSettings().publishHandoff;
        if (handoff) {
          renderPublishSuccessFeedback(shell, handoff);
        }
      });

      once('mel-event-studio-sidebar-toggle', '[data-mel-studio-sidebar-toggle]', context).forEach((button) => {
        const shell = button.closest('[data-mel-studio-shell]');
        if (!shell) {
          return;
        }
        const overlay = shell.querySelector('[data-mel-studio-sidebar-overlay]');
        const sidebar = shell.querySelector('.mel-event-studio-sidebar');
        const desktopSidebar = window.matchMedia('(min-width: 1121px)');
        const organiserSidebar = document.querySelector('.mel-vendor-shell--studio-focus .mel-sidebar');
        const eventsItem = organiserSidebar?.querySelector('[data-nav-key="events"]');
        const sidebarAnchor = document.createComment('event-studio-sidebar-anchor');
        const buttonAnchor = document.createComment('event-studio-sidebar-toggle-anchor');
        sidebar?.parentNode?.insertBefore(sidebarAnchor, sidebar);
        button.parentNode?.insertBefore(buttonAnchor, button);
        const syncSidebarPlacement = () => {
          if (!sidebar || !eventsItem) {
            return;
          }
          if (desktopSidebar.matches) {
            eventsItem.classList.add('mel-sidebar__item--studio-open');
            eventsItem.append(button, sidebar);
            return;
          }
          eventsItem.classList.remove('mel-sidebar__item--studio-open');
          buttonAnchor.parentNode?.insertBefore(button, buttonAnchor.nextSibling);
          sidebarAnchor.parentNode?.insertBefore(sidebar, sidebarAnchor.nextSibling);
        };
        const syncDesktopButton = () => {
          syncSidebarPlacement();
          if (!desktopSidebar.matches) {
            sidebar?.classList.remove('is-desktop-collapsed');
            button.textContent = Drupal.t('Sections');
            button.setAttribute('aria-expanded', shell.classList.contains('is-sidebar-open') ? 'true' : 'false');
            return;
          }
          const collapsed = shell.classList.contains('is-sidebar-collapsed');
          sidebar?.classList.toggle('is-desktop-collapsed', collapsed);
          button.textContent = collapsed ? Drupal.t('Show event menu') : Drupal.t('Hide event menu');
          button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
          shell.classList.remove('is-sidebar-open');
          if (overlay) {
            overlay.hidden = true;
          }
        };
        const closeSidebar = () => {
          shell.classList.remove('is-sidebar-open');
          if (!desktopSidebar.matches) {
            button.setAttribute('aria-expanded', 'false');
          }
          if (overlay) {
            overlay.hidden = true;
          }
        };
        const openSidebar = () => {
          shell.classList.add('is-sidebar-open');
          button.setAttribute('aria-expanded', 'true');
          if (overlay) {
            overlay.hidden = false;
          }
        };
        closeSidebar();
        syncDesktopButton();
        button.addEventListener('click', () => {
          if (desktopSidebar.matches) {
            shell.classList.toggle('is-sidebar-collapsed');
            syncDesktopButton();
            return;
          }
          if (shell.classList.contains('is-sidebar-open')) {
            closeSidebar();
            return;
          }
          openSidebar();
        });
        desktopSidebar.addEventListener('change', syncDesktopButton);
        overlay?.addEventListener('click', closeSidebar);
        document.addEventListener('click', (event) => {
          if (!shell.classList.contains('is-sidebar-open')) {
            return;
          }
          if (sidebar?.contains(event.target) || button.contains(event.target) || overlay?.contains(event.target)) {
            return;
          }
          closeSidebar();
        });
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && shell.classList.contains('is-sidebar-open')) {
            closeSidebar();
            button.focus();
          }
        });
        shell.querySelectorAll('.mel-event-studio-sidebar__link').forEach((link) => {
          link.addEventListener('click', closeSidebar);
        });
      });

      once('mel-event-studio-publish-form-state', '[data-mel-studio-shell] form', context).forEach((form) => {
        if (!isWritableForm(form)) {
          return;
        }
        setFormPublishState(form, 'clean');
        form.addEventListener('input', () => setFormPublishState(form, 'dirty'), true);
        form.addEventListener('change', () => setFormPublishState(form, 'dirty'), true);
        form.addEventListener('submit', () => setFormPublishState(form, 'clean'));
      });

      once('mel-event-studio-shell-autosave', 'form[data-mel-event-studio-form="1"]', context).forEach((form) => {
        if (!supportsAutosaveForm(form)) {
          return;
        }
        if (form.matches('.mel-event-studio-operational-tickets')) {
          return;
        }
        let timer = null;
        let dirty = false;
        const status = document.getElementById('mel-studio-form-state');
        const delay = Number(studioSettings().autosaveDelay || 12000);
        const autosaveUrl = studioSettings().autosaveUrl;
        const currentSection = studioSettings().currentSection;
        if (!autosaveUrl) {
          return;
        }
        if (studioSettings().draftAvailable) {
          status?.classList.add('has-draft');
        }

        const schedule = () => {
          window.clearTimeout(timer);
          dirty = true;
          setFormPublishState(form, 'dirty');
          setStatus(status, Drupal.t('Unsaved changes'), 'is-unsaved');
          timer = window.setTimeout(async () => {
            const data = new FormData(form);
            data.set('mel_autosave_ts', String(Date.now()));
            if (currentSection) {
              data.set('mel_studio_section', currentSection);
            }
            setFormPublishState(form, 'saving');
            setStatus(status, Drupal.t('Saving...'), 'is-saving');
            try {
              const token = await getCsrfToken();
              const response = await fetch(autosaveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': token },
                body: data,
              });
              const result = await response.json().catch(() => ({}));
              if (response.status === 409) {
                dirty = true;
                setFormPublishState(form, 'error');
                setStatus(
                  status,
                  result.message || Drupal.t('This section was updated elsewhere. Refresh to continue editing safely.'),
                  'is-error',
                );
                return;
              }
              if (!response.ok || !result.ok) {
                throw new Error(`Autosave failed with ${response.status}`);
              }
              dirty = false;
              setFormPublishState(form, 'clean');
              setStatus(status, Drupal.t('Saved just now'), 'is-saved');
            }
            catch (error) {
              console.error('Event Studio autosave failed.', error);
              setFormPublishState(form, 'error');
              setStatus(status, Drupal.t('Draft could not be saved. Retry by editing again.'), 'is-error');
            }
          }, delay);
        };

        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
        form.addEventListener('submit', () => {
          dirty = false;
          setFormPublishState(form, 'clean');
          window.clearTimeout(timer);
        });
        window.addEventListener('beforeunload', (event) => {
          if (!dirty) {
            return;
          }
          event.preventDefault();
          event.returnValue = '';
        });
      });

      once('mel-operational-tickets-donation-sync', 'form.mel-event-studio-operational-tickets', context).forEach((operationalForm) => {
        operationalForm.addEventListener('submit', () => {
          const stack = operationalForm.closest('.mel-event-studio-section__form-stack');
          const bookingForm = stack?.querySelector('form[data-mel-event-studio-form="1"]');
          if (!bookingForm || !bookingModeSupportsDonationSync(bookingForm)) {
            return;
          }
          operationalForm.querySelectorAll('[data-mel-donation-sync]').forEach((element) => element.remove());
          ['donation_amount', 'donation_options', 'donation_label'].forEach((key) => {
            const input = bookingForm.querySelector(`[name="mel[${key}]"]`);
            if (!input) {
              return;
            }
            appendMelDonationHidden(operationalForm, key, input.value);
          });
          const enable = bookingForm.querySelector('[name="mel[enable_donations]"]');
          appendMelDonationHidden(operationalForm, 'enable_donations', enable && enable.checked ? '1' : '0');
        }, true);
      });

      once('mel-event-studio-shell-publish', '[data-mel-publish-action]', context).forEach((button) => {
        const shell = button.closest('[data-mel-studio-shell]');
        if (!shell) {
          return;
        }
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          if (button.disabled) {
            return;
          }
          const publishUrl = publishUrlFor(button);
          if (!publishUrl) {
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Publish action is unavailable. Refresh and try again.')]);
            setPublishButtonState(button, 'cannot_publish');
            return;
          }
          const metadata = publishMetadata(shell, button);
          if (metadata.dirty) {
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Save this section before publishing.')]);
            setPublishButtonState(button, 'cannot_publish');
            return;
          }
          setPublishButtonState(button, 'publishing');
          hidePublishFeedback(shell);
          try {
            const token = await getCsrfToken();
            const response = await fetch(publishUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token,
              },
              body: JSON.stringify(metadata),
            });
            const result = await response.json().catch(() => ({}));
            updateTopbar(shell, result);
            updateReadiness(shell, result.readiness);
            updateLaunchCentre(shell, result.launch_centre, result);
            if (!response.ok || !result.ok) {
              const messages = result.messages && result.messages.length
                ? result.messages
                : (result.readiness && result.readiness.errors) || [Drupal.t('Publish could not complete.')];
              renderPublishFeedback(shell, result.message || Drupal.t('Cannot publish yet'), messages, result.restoreUrl);
              setPublishButtonState(button, 'cannot_publish');
              return;
            }
            if (result.handoff) {
              renderPublishSuccessFeedback(shell, result.handoff);
            }
            else {
              const title = result.message || Drupal.t('Your event is now live');
              renderPublishSuccessFeedback(shell, {
                title,
                message: title,
              });
            }
            setPublishButtonState(button, 'published');
            updatePublishPanels(shell, true);
            updateFormMetadata(shell, result);
          }
          catch (error) {
            console.error('Event Studio publish failed.', error);
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Publish failed. Check your connection and try again.')]);
            setPublishButtonState(button, 'cannot_publish');
          }
        });
      });

      once('mel-event-studio-shell-card-publish', '[data-mel-card-publish-action]', context).forEach((button) => {
        const shell = button.closest('[data-mel-studio-shell]');
        if (!shell) {
          return;
        }
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopImmediatePropagation();
          event.stopPropagation();
          if (button.disabled) {
            return;
          }
          const publishUrl = publishUrlFor(button);
          if (!publishUrl) {
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Publish action is unavailable. Refresh and try again.')]);
            return;
          }
          const metadata = {
            ...publishMetadata(shell, button),
            action: 'publish',
          };
          if (metadata.dirty) {
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Save this section before changing publish state.')]);
            return;
          }
          const originalLabel = button.textContent;
          button.disabled = true;
          button.setAttribute('aria-disabled', 'true');
          button.textContent = Drupal.t('Publishing...');
          hidePublishFeedback(shell);
          try {
            const token = await getCsrfToken();
            const response = await fetch(publishUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token,
              },
              body: JSON.stringify(metadata),
            });
            const result = await response.json().catch(() => ({}));
            updateTopbar(shell, result);
            updateReadiness(shell, result.readiness);
            updateLaunchCentre(shell, result.launch_centre, result);
            if (!response.ok || !result.ok) {
              const messages = result.messages && result.messages.length
                ? result.messages
                : (result.readiness && result.readiness.errors) || [Drupal.t('Publish could not complete.')];
              renderPublishFeedback(shell, result.message || Drupal.t('Cannot publish yet'), messages, result.restoreUrl);
              button.disabled = false;
              button.removeAttribute('aria-disabled');
              button.textContent = originalLabel || Drupal.t('Publish now');
              return;
            }
            if (result.handoff) {
              renderPublishSuccessFeedback(shell, result.handoff);
            }
            else {
              const title = result.message || Drupal.t('Your event is now live');
              renderPublishSuccessFeedback(shell, {
                title,
                message: title,
              });
            }
            updatePublishPanels(shell, true);
            updateFormMetadata(shell, result);
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            button.textContent = originalLabel || Drupal.t('Publish now');
          }
          catch (error) {
            console.error('Event Studio publish failed.', error);
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Publish failed. Check your connection and try again.')]);
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            button.textContent = originalLabel || Drupal.t('Publish now');
          }
        });
      });

      once('mel-event-studio-shell-unpublish', '[data-mel-unpublish-action]', context).forEach((button) => {
        const shell = button.closest('[data-mel-studio-shell]');
        if (!shell) {
          return;
        }
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopImmediatePropagation();
          event.stopPropagation();
          if (button.disabled) {
            return;
          }
          const publishUrl = publishUrlFor(button);
          if (!publishUrl) {
            renderPublishFeedback(shell, Drupal.t('Cannot unpublish yet'), [Drupal.t('Unpublish action is unavailable. Refresh and try again.')]);
            return;
          }
          const metadata = {
            ...publishMetadata(shell, button),
            action: 'unpublish',
          };
          if (metadata.dirty) {
            renderPublishFeedback(shell, Drupal.t('Cannot unpublish yet'), [Drupal.t('Save this section before changing publish state.')]);
            return;
          }
          const originalLabel = button.textContent;
          button.disabled = true;
          button.setAttribute('aria-disabled', 'true');
          button.textContent = Drupal.t('Unpublishing...');
          hidePublishFeedback(shell);
          try {
            const token = await getCsrfToken();
            const response = await fetch(publishUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token,
              },
              body: JSON.stringify(metadata),
            });
            const result = await response.json().catch(() => ({}));
            updateTopbar(shell, result);
            updateReadiness(shell, result.readiness);
            updateLaunchCentre(shell, result.launch_centre, result);
            if (!response.ok || !result.ok) {
              const messages = result.messages && result.messages.length
                ? result.messages
                : [Drupal.t('Unpublish could not complete.')];
              renderPublishFeedback(shell, result.message || Drupal.t('Cannot unpublish yet'), messages, result.restoreUrl);
              button.disabled = false;
              button.removeAttribute('aria-disabled');
              button.textContent = originalLabel || Drupal.t('Unpublish');
              return;
            }
            const title = result.message || Drupal.t('Unpublished successfully');
            renderPublishSuccessFeedback(shell, {
              title,
              message: title,
            });
            updatePublishPanels(shell, false);
            updateFormMetadata(shell, result);
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            button.textContent = originalLabel || Drupal.t('Unpublish');
          }
          catch (error) {
            console.error('Event Studio unpublish failed.', error);
            renderPublishFeedback(shell, Drupal.t('Cannot unpublish yet'), [Drupal.t('Unpublish failed. Check your connection and try again.')]);
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            button.textContent = originalLabel || Drupal.t('Unpublish');
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
