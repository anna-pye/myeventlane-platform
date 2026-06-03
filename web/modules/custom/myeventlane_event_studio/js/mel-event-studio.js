/**
 * @file
 * Event Studio — preview sync, location display, ticket setup panel, insights.
 */
(function (Drupal, once) {
  'use strict';

  var ATTENDEE_QUESTION_MAX = 5;
  var TICKET_SOFT_MAX = 5;

  function melPrefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /** Sticky header buffer for window scroll (matches .mel-studio-scroll-step scroll-margin). */
  var MEL_SCROLL_OFFSET = 120;

  /**
   * While non-zero epoch ms, wizard IntersectionObserver must not overwrite nav/card state
   * (smooth programmatic scroll crosses multiple steps briefly).
   */
  var melWizardIoNavSilentUntil = 0;

  /**
   * @param {number} [durationMs]
   */
  function melSuppressWizardIoNav(durationMs) {
    var ms = typeof durationMs === 'number' ? durationMs : melPrefersReducedMotion() ? 120 : 750;
    var until = Date.now() + ms;
    if (until > melWizardIoNavSilentUntil) {
      melWizardIoNavSilentUntil = until;
    }
  }

  /**
   * Window scroll only — no nested scroll containers.
   *
   * @param {HTMLElement|null} target
   */
  function scrollToTarget(target) {
    if (!target) {
      return;
    }
    melSuppressWizardIoNav();
    var y = target.getBoundingClientRect().top + window.scrollY - MEL_SCROLL_OFFSET;
    window.scrollTo({
      top: Math.max(0, y),
      behavior: melPrefersReducedMotion() ? 'auto' : 'smooth',
    });
  }

  /**
   * Scroll to a builder card and sync wizard nav to its step.
   *
   * @param {HTMLFormElement} form
   * @param {HTMLElement|null} cardEl
   */
  function melScrollToBuilderCard(form, cardEl) {
    if (!cardEl) {
      return;
    }
    scrollToTarget(cardEl);
    if (!form || !cardEl.closest) {
      return;
    }
    var stepSection = cardEl.closest('section.mel-step[data-step]');
    if (!stepSection) {
      return;
    }
    var sid = stepSection.getAttribute('data-step');
    for (var s = 0; s < MEL_STEPS.length; s++) {
      if (MEL_STEPS[s].id === sid) {
        setWizardNavActive(form, s);
        setWizardStepIndex(form, s);
        break;
      }
    }
  }

  /**
   * True when the element is shown (within the studio form subtree).
   *
   * @param {HTMLFormElement} form
   * @param {Element} el
   * @return {boolean}
   */
  function melIsBuilderCardDomVisible(form, el) {
    if (!form || !el || !form.contains(el)) {
      return false;
    }
    var node = el;
    while (node && node !== form && form.contains(node)) {
      if (node.nodeType === 1) {
        if (node.hidden || node.hasAttribute('hidden')) {
          return false;
        }
        var st = window.getComputedStyle(node);
        if (st.display === 'none' || st.visibility === 'hidden') {
          return false;
        }
      }
      node = node.parentElement;
    }
    return true;
  }

  /**
   * Top-level studio cards in DOM order, excluding collapsed/hidden subtree.
   *
   * @param {HTMLFormElement} form
   * @return {Element[]}
   */
  function melOrderedVisibleBuilderCards(form) {
    if (!form) {
      return [];
    }
    return Array.prototype.slice.call(form.querySelectorAll('.mel-builder-card')).filter(function (card) {
      return melIsBuilderCardDomVisible(form, card);
    });
  }

  /**
   * Next visible builder card after the given one (DOM order inside the form).
   *
   * @param {HTMLFormElement} form
   * @param {Element|null} currentCard
   * @return {Element|null}
   */
  function melNextVisibleBuilderCard(form, currentCard) {
    var cards = melOrderedVisibleBuilderCards(form);
    if (!currentCard) {
      return null;
    }
    var ix = cards.indexOf(currentCard);
    if (ix < 0) {
      return null;
    }
    return cards[ix + 1] || null;
  }

  /**
   * Next card in primary flow — skips optional MEL support (still visible).
   *
   * @param {HTMLFormElement} form
   * @param {Element|null} currentCard
   * @return {Element|null}
   */
  function melNextPrimaryFlowBuilderCard(form, currentCard) {
    var next = melNextVisibleBuilderCard(form, currentCard);
    while (next && next.id === 'mel-card-support') {
      next = melNextVisibleBuilderCard(form, next);
    }
    if (!next && currentCard && currentCard.id === 'mel-card-tickets') {
      return document.getElementById('mel-card-standout');
    }
    return next;
  }

  /**
   * @param {Element|null} card
   * @return {Element|null}
   */
  function melBuilderCardHintHeading(card) {
    if (!card) {
      return null;
    }
    var mh = card.querySelector('#mel-description-heading');
    if (mh) {
      return mh;
    }
    var prog = card.querySelector('.mel-builder-progressive__title:not(.visually-hidden)');
    if (prog) {
      return prog;
    }
    return (
      card.querySelector('.mel-builder-card__title:not(.visually-hidden)') ||
      card.querySelector('h2:not(.visually-hidden), h3:not(.visually-hidden), h4:not(.visually-hidden)') ||
      card.querySelector('h2, h3, h4')
    );
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
    { id: 'identity', label: 'Basics & date' },
    { id: 'tickets', label: 'Tickets or RSVP' },
    { id: 'standout', label: 'Details' },
    { id: 'attendee', label: 'Guest questions' },
    { id: 'preview', label: 'Preview' },
    { id: 'publish', label: 'Publish' },
  ];

  /** @type {WeakMap<HTMLFormElement, number>} */
  var melBuilderCardFocusRafIds = new WeakMap();

  /** @type {WeakMap<HTMLFormElement, Record<string, unknown>>} */
  var melGovernanceRuntimeByForm = new WeakMap();

  /** @type {WeakMap<HTMLFormElement, Set<string>>} */
  var melGovernancePendingComponentsByForm = new WeakMap();

  /** @type {WeakMap<HTMLFormElement, number>} */
  var melGovernanceRefreshGenerationByForm = new WeakMap();

  function getSettings() {
    return (typeof drupalSettings !== 'undefined' && drupalSettings.melEventStudio) || {};
  }

  /**
   * @param {HTMLFormElement|null} [form]
   * @return {Record<string, unknown>}
   */
  function getGovernanceSettings(form) {
    var base =
      (typeof drupalSettings !== 'undefined' && drupalSettings.melEventStudioGovernance) || {};
    if (!form) {
      return base;
    }
    var overlay = melGovernanceRuntimeByForm.get(form);
    if (!overlay) {
      return base;
    }
    return /** @type {Record<string, unknown>} */ (Object.assign({}, base, overlay));
  }

  /**
   * @param {HTMLFormElement} form
   * @param {Record<string, unknown>} delta
   */
  function mergeGovernanceRuntime(form, delta) {
    if (!form || !delta || typeof delta !== 'object') {
      return;
    }
    var cur = melGovernanceRuntimeByForm.get(form) || {};
    melGovernanceRuntimeByForm.set(form, Object.assign({}, cur, delta));
  }

  /**
   * @param {HTMLFormElement} form
   * @return {Record<string, unknown>}
   */
  function governanceBaselineSnapshot(form) {
    var base =
      (typeof drupalSettings !== 'undefined' && drupalSettings.melEventStudioGovernance) || {};
    var overlay = melGovernanceRuntimeByForm.get(form) || {};
    return /** @type {Record<string, unknown>} */ (Object.assign({}, base, overlay));
  }

  /**
   * @param {HTMLFormElement} form
   * @return {Promise<void>}
   */
  function fetchGovernanceIncrementalRefresh(form) {
    var studio = getSettings();
    var url = studio.urls && studio.urls.governanceRefresh;
    if (!url || !form) {
      return Promise.resolve();
    }
    var baseGov =
      (typeof drupalSettings !== 'undefined' && drupalSettings.melEventStudioGovernance) || {};
    if (!baseGov.enabled) {
      return Promise.resolve();
    }
    var baseline = governanceBaselineSnapshot(form);
    return melGetCsrfToken()
      .then(function (token) {
        if (!token) {
          return Promise.reject(new Error('empty_csrf_token'));
        }
        return fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
          },
          body: JSON.stringify({ baseline: baseline }),
        });
      })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, status: response.status, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok || !result.data || !result.data.ok) {
          return;
        }
        var d = result.data.delta;
        if (d && typeof d === 'object') {
          mergeGovernanceRuntime(form, /** @type {Record<string, unknown>} */ (d));
        }
      })
      .catch(function (err) {
        if (window.console && window.console.warn) {
          window.console.warn('Event Studio governance refresh failed.', err);
        }
      });
  }

  /**
   * @param {HTMLFormElement} form
   * @param {Record<string, unknown>} urls
   */
  function updateGovernanceUrls(form, urls) {
    if (!form || !urls || typeof urls !== 'object') {
      return;
    }
    var studio = getSettings();
    studio.urls = studio.urls || {};
    if (urls.governanceRefresh) {
      studio.urls.governanceRefresh = String(urls.governanceRefresh);
    }
    if (urls.governanceComponents && typeof urls.governanceComponents === 'object') {
      studio.urls.governanceComponents = Object.assign(
        {},
        studio.urls.governanceComponents || {},
        urls.governanceComponents,
      );
    }
  }

  /**
   * @param {HTMLFormElement} form
   * @param {string[]} components
   */
  function markGovernanceComponentsDirty(form, components) {
    if (!form || !components || !components.length) {
      return;
    }
    var set = melGovernancePendingComponentsByForm.get(form);
    if (!set) {
      set = new Set();
      melGovernancePendingComponentsByForm.set(form, set);
    }
    components.forEach(function (component) {
      set.add(component);
    });
  }

  /**
   * @param {Event} event
   * @return {string[]}
   */
  function governanceComponentsForEvent(event) {
    var target = event && event.target && event.target.nodeType === 1 ? event.target : null;
    if (!target) {
      return ['state', 'workflow'];
    }
    var name = String(target.getAttribute('name') || target.id || '');
    if (/(ticket|product|external_url|field_event_type|rsvp|donation)/.test(name)) {
      return ['workflow', 'state'];
    }
    if (/(venue|location|start_date|end_date|sales|publish)/.test(name)) {
      return ['workflow', 'state'];
    }
    if (/(onboarding|payout|vendor)/.test(name)) {
      return ['continuity', 'workflow'];
    }
    if (/(moderation|status|visibility|summary|body|title|category|image|highlight)/.test(name)) {
      return ['state', 'intelligence'];
    }
    return ['state', 'intelligence'];
  }

  /**
   * @param {HTMLFormElement} form
   * @return {string[]}
   */
  function consumeGovernanceComponents(form) {
    var set = melGovernancePendingComponentsByForm.get(form);
    if (!set || !set.size) {
      return ['state', 'workflow'];
    }
    var out = Array.from(set);
    set.clear();
    return out;
  }

  /**
   * @param {HTMLFormElement} form
   * @param {string[]} components
   * @return {Promise<void>}
   */
  function fetchGovernanceComponents(form, components) {
    var gov = getGovernanceSettings(form);
    if (!form || !gov.enabled || !components || !components.length) {
      return Promise.resolve();
    }
    var studio = getSettings();
    var urls = (studio.urls && studio.urls.governanceComponents) || {};
    if (!urls || typeof urls !== 'object') {
      return Promise.resolve();
    }
    var generation = (melGovernanceRefreshGenerationByForm.get(form) || 0) + 1;
    melGovernanceRefreshGenerationByForm.set(form, generation);
    var unique = Array.from(new Set(components)).filter(function (component) {
      return !!urls[component];
    });
    if (!unique.length) {
      return Promise.resolve();
    }

    return melGetCsrfToken()
      .then(function (token) {
        if (!token) {
          return Promise.reject(new Error('empty_csrf_token'));
        }
        return Promise.all(
          unique.map(function (component) {
            return fetch(String(urls[component]), {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                Accept: 'application/json',
                'X-CSRF-Token': token,
              },
            })
              .then(function (response) {
                return response.json().then(function (data) {
                  return { ok: response.ok, status: response.status, data: data };
                });
              })
              .then(function (result) {
                if (melGovernanceRefreshGenerationByForm.get(form) !== generation) {
                  return;
                }
                if (!result.ok || !result.data || !result.data.ok || !result.data.html) {
                  return;
                }
                replaceGovernanceComponent(form, String(result.data.component || component), String(result.data.html));
              });
          }),
        );
      })
      .then(function () {})
      .catch(function (err) {
        if (window.console && window.console.warn) {
          window.console.warn('Event Studio governance component refresh failed.', err);
        }
      });
  }

  /**
   * @param {HTMLFormElement} form
   * @param {string} component
   * @param {string} html
   */
  function replaceGovernanceComponent(form, component, html) {
    if (!component || !html) {
      return;
    }
    var shell = form ? form.closest('[data-mel-studio-shell="1"]') : document;
    var current = shell.querySelector('[data-mel-governance-component="' + component + '"]');
    if (!current) {
      return;
    }
    var active = document.activeElement;
    var focusId = active && current.contains(active) ? active.id || '' : '';
    var template = document.createElement('template');
    template.innerHTML = html.trim();
    var next = template.content.firstElementChild;
    if (!next) {
      return;
    }
    current.replaceWith(next);
    if (focusId) {
      var replacementFocus = document.getElementById(focusId);
      if (replacementFocus && replacementFocus.focus) {
        replacementFocus.focus({ preventScroll: true });
      }
    }
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
    if (!el && fieldName === 'mel[field_event_image][0][fids]') {
      el = form.querySelector('input[name="mel[field_event_image][0][fids]"]');
    }
    if (!el && fieldName === 'mel[field_event_image][0][upload]') {
      el = form.querySelector('input[name="mel[field_event_image][0][upload]"]');
    }
    if (!el && fieldName === 'mel[studio_ticket_focus]') {
      el = form.querySelector('#mel-add-ticket-tier') || form.querySelector('.mel-tier-title');
    }
    if (!el) {
      return;
    }
    var hostingCard = el.closest('.mel-builder-card');
    if (hostingCard && form.contains(hostingCard) && hostingCard.id !== 'mel-card-publish') {
      melSetActiveBuilderCard(form, hostingCard);
    }
    var progD = el.closest('details');
    while (progD && form.contains(progD)) {
      progD.open = true;
      var progP = progD.parentElement;
      progD = progP ? progP.closest('details') : null;
    }
    var scrollTarget =
      el.closest('.mel-builder-card') ||
      el.closest('.js-form-item, .form-item, .fieldset, .mel-step') ||
      el;
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

  /**
   * Alt text for hero: image widget delta first when that control exists, else legacy field.
   *
   * @param {HTMLFormElement} form
   * @returns {string}
   */
  function melHeroAltValue(form) {
    if (form.querySelector('[name="mel[field_event_image][0][alt]"]')) {
      return val(form, 'mel[field_event_image][0][alt]') || val(form, 'mel[field_event_image_alt]');
    }
    return val(form, 'mel[field_event_image_alt]');
  }

  /**
   * Name= attribute for the active hero alt control (must align with {@link melHeroAltValue}).
   *
   * @param {HTMLFormElement} form
   * @returns {string}
   */
  function melHeroAltFieldName(form) {
    if (form.querySelector('[name="mel[field_event_image][0][alt]"]')) {
      return 'mel[field_event_image][0][alt]';
    }
    return 'mel[field_event_image_alt]';
  }

  /**
   * Jump target for "add cover" — legacy managed_file vs image widget controls.
   *
   * @param {HTMLFormElement} form
   * @returns {string}
   */
  function melCoverImageJumpTarget(form) {
    if (form.querySelector('input[name="mel[field_event_image][]"]')) {
      return 'mel[field_event_image][]';
    }
    if (form.querySelector('input[name="mel[field_event_image][0][fids]"]')) {
      return 'mel[field_event_image][0][fids]';
    }
    if (form.querySelector('input[name="mel[field_event_image][0][upload]"]')) {
      return 'mel[field_event_image][0][upload]';
    }
    return 'mel[field_event_image][]';
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
   * Fills an empty ticket product autocomplete field from server suggestions.
   *
   * @param {HTMLFormElement} form
   */
  function applyTicketLinkSuggestions(form) {
    if (valRadio(form, 'mel[field_event_type]') !== 'paid') {
      return;
    }
    var productInp = form.querySelector('[name="mel[field_product_target]"]');
    if (!productInp) {
      return;
    }
    var hasProduct = String(productInp.value || '').trim() !== '';
    if (hasProduct) {
      return;
    }
    var eventTitle = val(form, 'mel[title]');
    if (eventTitle.length < 2) {
      return;
    }
    var tierTitles = [];

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
        if (changed) {
          productInp.dispatchEvent(new Event('change', { bubbles: true }));
          productInp.dispatchEvent(new Event('input', { bubbles: true }));
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

  function getBuilderList(form) {
    return form.querySelector('[data-mel-ticket-card-list="draft"]');
  }

  /**
   * First direct child ticket card in a list is the “hero” tier (studio UX).
   *
   * @param {HTMLElement|null} list
   */
  function syncPrimaryTicketClassInList(list) {
    if (!list || !list.children) {
      return;
    }
    var cards = [];
    var i;
    for (i = 0; i < list.children.length; i++) {
      var el = list.children[i];
      if (el.classList && el.classList.contains('js-mel-ticket-card')) {
        cards.push(el);
      }
    }
    for (i = 0; i < cards.length; i++) {
      cards[i].classList.toggle('mel-ticket--primary', i === 0);
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

    var mgrWrap = form.querySelector('[data-mel-ticket-manager-form="1"]');
    if (mgrWrap) {
      var mgrCount = 0;
      mgrWrap.querySelectorAll('.mel-ticket-manager-row').forEach(function (row) {
        var del = row.querySelector('input[type="checkbox"][name*="[more][delete]"]');
        if (del && del.checked) {
          return;
        }
        var activeEl = row.querySelector('input[type="checkbox"][name*="[active]"]');
        if (activeEl && !activeEl.checked) {
          return;
        }
        var titleEl = row.querySelector('input[name*="[title]"]');
        var priceEl = row.querySelector('input[name*="[price]"]');
        var title = titleEl ? String(titleEl.value || '').trim() : '';
        var price = priceEl ? String(priceEl.value || '').trim() : '';
        var priceOk = price !== '' && !isNaN(parseFloat(price)) && parseFloat(price) > 0;
        if (title !== '' && priceOk) {
          mgrCount++;
        }
      });
      counts.push(mgrCount);
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
    var row = { title: '', ticket_kind: kind, capacity: null, field_is_best_value: 0 };
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
    var bestValueEl = card.querySelector('.mel-tier-best-value');
    o.field_is_best_value = bestValueEl && bestValueEl.checked ? 1 : 0;
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

  function fieldWrap(labelText, control, descriptionText, extraClass) {
    var wrap = document.createElement('label');
    wrap.className = 'mel-ticket-card__field';
    if (extraClass) {
      wrap.classList.add(extraClass);
    }
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

    var display = document.createElement('div');
    display.className = 'mel-ticket-card__display mel-ticket-card__display--draft';
    display.setAttribute('data-mel-ticket-outcome-display', '1');
    var displayTitle = document.createElement('h4');
    displayTitle.className = 'mel-ticket-card__display-title';
    displayTitle.textContent = tier.title ? String(tier.title) : Drupal.t('Untitled ticket');
    var displayPrice = document.createElement('span');
    displayPrice.className = 'mel-ticket-card__price';
    displayPrice.setAttribute('data-mel-ticket-display-price', '');
    displayPrice.textContent =
      kind === 'paid'
        ? tier.price_number && String(tier.price_number).trim() !== '' && parseFloat(String(tier.price_number)) > 0
          ? String(tier.price_currency || 'AUD').toUpperCase().slice(0, 3) + ' ' + String(tier.price_number)
          : '—'
        : kind === 'rsvp'
          ? Drupal.t('$0')
          : Drupal.t('External');
    display.appendChild(displayTitle);
    display.appendChild(displayPrice);

    var state = document.createElement('span');
    state.className = 'mel-badge mel-badge--inactive';
    state.textContent = Drupal.t('Draft');

    var headRow = document.createElement('div');
    headRow.className = 'mel-ticket-card__header mel-ticket-card__header--outcome';
    headRow.appendChild(display);
    headRow.appendChild(state);
    card.appendChild(headRow);

    var fields = document.createElement('div');
    fields.className = 'mel-ticket-card__fields mel-ticket-card__fields--inline mel-ticket-card__edit-layer';

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
      null,
      'mel-ticket-card__field--title',
    ));

    var price = document.createElement('input');
    price.type = 'number';
    price.className = 'mel-input mel-tier-price';
    price.step = '0.01';
    price.min = '0';
    price.placeholder = '0.00';
    price.value = tier.price_number != null ? String(tier.price_number) : '';
    fields.appendChild(fieldWrap(Drupal.t('Price'), price, null, 'mel-ticket-card__field--price'));

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

    var bestValue = document.createElement('input');
    bestValue.type = 'checkbox';
    bestValue.className = 'mel-tier-best-value';
    bestValue.checked = !!parseInt(tier.field_is_best_value || 0, 10);
    fields.appendChild(fieldWrap(
      Drupal.t('Mark as best value'),
      bestValue,
      Drupal.t('Shows a Best value badge to buyers. Only one ticket per event can be marked best value.'),
    ));

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
    var title = card.querySelector('.mel-ticket-card__display-title');
    var priceLbl = card.querySelector('.mel-ticket-card__display--draft .mel-ticket-card__price, .mel-ticket-card__display .mel-ticket-card__price');
    if (title) {
      var label = titleEl ? String(titleEl.value || '').trim() : '';
      title.textContent = label || Drupal.t('Untitled ticket');
    }
    if (priceLbl) {
      if (kind === 'paid') {
        var priceInput = card.querySelector('.mel-tier-price');
        var cur = card.getAttribute('data-tier-currency') || getSettings().defaultCurrency || 'AUD';
        var amt = priceInput ? String(priceInput.value || '').trim() : '';
        priceLbl.textContent =
          amt !== '' && !isNaN(parseFloat(amt)) && parseFloat(amt) > 0
            ? String(cur).toUpperCase().slice(0, 3) + ' ' + amt
            : '\u2014';
      }
      else if (kind === 'rsvp') {
        priceLbl.textContent = Drupal.t('$0');
      }
      else {
        priceLbl.textContent = Drupal.t('External');
      }
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
    card.classList.toggle('mel-ticket--complete', errors.length === 0);
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
    syncPrimaryTicketClassInList(list);
    syncTicketTiersFromDomToHidden(form);
    syncTicketModelWarnings(form);
  }

  function syncTicketTiersFromDomToHidden(form) {
    return;
  }

  function forceSyncTicketTiersBeforeSubmit(form) {
    try {
      syncTicketTiersFromDomToHidden(form);
    } catch (e) {}
  }

  function syncTicketBuilderEventType(form) {
    var list = getBuilderList(form);
    if (!list) {
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

  function countDraftJoinableTicketTiers(form) {
    var list = getBuilderList(form);
    if (!list) {
      return 0;
    }
    var count = 0;
    list.querySelectorAll('.mel-tier-row').forEach(function (card) {
      if (['paid', 'rsvp'].indexOf(getDraftCardKind(card)) !== -1) {
        count += 1;
      }
    });
    return count;
  }

  function draftTicketsHaveBestValue(form) {
    var list = getBuilderList(form);
    if (!list) {
      return false;
    }
    return !!list.querySelector('.mel-tier-row .mel-tier-best-value:checked');
  }

  function syncTicketModelWarnings(form) {
    var warn = document.getElementById('mel-ticket-tiers-warn');
    var tt = valRadio(form, 'mel[field_event_type]');
    var list = getBuilderList(form);
    var n = list ? list.querySelectorAll('.mel-tier-row').length : 0;
    if (warn) {
      var warnText = warn.querySelector('.mel-ticket-tiers-warn__text');
      var joinableCount = countDraftJoinableTicketTiers(form);
      var needsBestValue = joinableCount > 1 && !draftTicketsHaveBestValue(form);
      var needsPaidTicket = tt === 'paid' && n < 1;
      if (needsBestValue) {
        if (warnText) {
          warnText.textContent = Drupal.t('Choose one Best value ticket when you offer more than one paid or RSVP ticket.');
        }
        warn.removeAttribute('hidden');
      } else if (needsPaidTicket) {
        if (warnText) {
          warnText.textContent = Drupal.t('Add at least one ticket type.');
        }
        warn.removeAttribute('hidden');
      } else {
        warn.setAttribute('hidden', 'hidden');
      }
    }

    var simplicityWarn = document.getElementById('mel-ticket-simplicity-warn');
    if (simplicityWarn) {
      var totalSignals = paidTicketTierSignalCount(form);
      if (totalSignals > TICKET_SOFT_MAX) {
        simplicityWarn.removeAttribute('hidden');
      } else {
        simplicityWarn.setAttribute('hidden', 'hidden');
      }
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
    var list = getBuilderList(form);
    if (!list) {
      return;
    }
    var tiers = [];
    renderTicketBuilderRows(form, list, tiers);
    form.setAttribute('data-mel-last-ticket-type', normalizeTicketKind(valRadio(form, 'mel[field_event_type]') || 'rsvp'));

    var dragged = null;

    form.addEventListener(
      'click',
      function (e) {
        if (e.target.closest('#mel-add-ticket-tier')) {
          e.preventDefault();
          var tiersNow = collectTiersFromDom(form);
          var nextTier = createDefaultTier(valRadio(form, 'mel[field_event_type]') || 'rsvp');
          var hasBestValue = tiersNow.some(function (tier) {
            return ['paid', 'rsvp'].indexOf(normalizeTicketKind(tier.ticket_kind)) !== -1 && !!parseInt(tier.field_is_best_value || 0, 10);
          });
          if (tiersNow.length > 0 && !hasBestValue && ['paid', 'rsvp'].indexOf(normalizeTicketKind(nextTier.ticket_kind)) !== -1) {
            nextTier.field_is_best_value = 1;
          }
          tiersNow.push(nextTier);
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
        if (e.target.matches && e.target.matches('.mel-tier-best-value') && e.target.checked) {
          list.querySelectorAll('.mel-tier-best-value').forEach(function (input) {
            if (input !== e.target) {
              input.checked = false;
            }
          });
        }
        updateDraftTicketCardUi(card);
        syncTicketTiersFromDomToHidden(form);
        syncTicketModelWarnings(form);
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
      syncPrimaryTicketClassInList(list);
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

  /**
   * Finds the anchor for an uploaded image inside Drupal managed_file markup.
   *
   * @param {Element|null} root
   * @return {HTMLAnchorElement|null}
   */
  function melFindManagedFileImageLink(root) {
    if (!root || !root.querySelectorAll) {
      return null;
    }
    var candidates = root.querySelectorAll('.form-managed-file a[href]');
    var i;
    for (i = 0; i < candidates.length; i++) {
      var a = candidates[i];
      var href = a.getAttribute('href') || '';
      if (!href || href.indexOf('javascript:') === 0) {
        continue;
      }
      if (
        href.indexOf('/files/') !== -1 ||
        href.indexOf('/system/files') !== -1 ||
        href.indexOf('files/') !== -1 ||
        /\.(png|jpe?g|gif|webp|svg)(\?|#|$)/i.test(href)
      ) {
        return a;
      }
    }
    return null;
  }

  /**
   * True when managed_file has non-empty fids for mel[field_event_image].
   *
   * @param {HTMLFormElement} form
   * @return {boolean}
   */
  function melHeroImageFidsPresent(form) {
    var inp =
      form.querySelector('input[name="mel[field_event_image][fids]"]') ||
      form.querySelector('input[name="mel[field_event_image][0][fids]"]') ||
      form.querySelector('input[name*="field_event_image"][name*="fids"]');
    if (!inp) {
      return false;
    }
    return String(inp.value || '').trim() !== '';
  }

  function syncCoverPreview(form) {
    var wrap = document.getElementById('mel-cover-preview');
    var img = document.getElementById('mel-cover-preview-img');
    var empty = document.getElementById('mel-cover-preview-empty');
    if (!wrap || !img || !empty) {
      return;
    }

    var mediaRoot = form.querySelector('.mel-identity-media');
    var url =
      (mediaRoot && melFindManagedFileImageUrl(mediaRoot)) || melFindManagedFileImageUrl(form);
    if (url) {
      img.src = url;
      img.removeAttribute('hidden');
      empty.setAttribute('hidden', 'hidden');
      return;
    }

    var existingSrc = (img.getAttribute('src') || '').trim();
    var fidsPresent = melHeroImageFidsPresent(form);

    // Fids or SSR preview: do not show the empty-state placeholder while a file
    // is still attached, even if Gin/theme omitted a matching file link.
    if (fidsPresent || existingSrc !== '') {
      if (existingSrc === '' && fidsPresent) {
        var fallback = melCoverPlaceholderSrc(wrap);
        if (fallback) {
          img.src = fallback;
          existingSrc = fallback;
        }
      }
      if (existingSrc !== '') {
        img.removeAttribute('hidden');
        empty.setAttribute('hidden', 'hidden');
      } else {
        // Attached file but no preview URL yet — keep empty visible (never hide both).
        img.setAttribute('hidden', 'hidden');
        empty.removeAttribute('hidden');
      }
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
    var url =
      (mediaRoot && melFindManagedFileImageUrl(mediaRoot)) || melFindManagedFileImageUrl(form);
    if (url) {
      img.src = url;
      img.alt = melHeroAltValue(form) || '';
      img.removeAttribute('hidden');
      ph.setAttribute('hidden', 'hidden');
      return;
    }

    var existingSrc = (img.getAttribute('src') || '').trim();
    if (hasCoverFile(form)) {
      if (existingSrc === '' && melHeroImageFidsPresent(form)) {
        var coverWrap = document.getElementById('mel-cover-preview');
        var fallback = melCoverPlaceholderSrc(coverWrap);
        if (fallback) {
          img.src = fallback;
          existingSrc = fallback;
        }
      }
      if (existingSrc !== '') {
        img.alt = melHeroAltValue(form) || '';
        img.removeAttribute('hidden');
        ph.setAttribute('hidden', 'hidden');
      } else {
        img.setAttribute('hidden', 'hidden');
        ph.removeAttribute('hidden');
      }
      return;
    }

    img.removeAttribute('src');
    img.setAttribute('hidden', 'hidden');
    ph.removeAttribute('hidden');
  }

  function applyCoverFileDataUrl(form, dataUrl) {
    var img = document.getElementById('mel-cover-preview-img');
    var empty = document.getElementById('mel-cover-preview-empty');
    var prevImg = document.getElementById('mel-preview-card-img');
    var ph = document.getElementById('mel-preview-card-placeholder');
    if (img && empty) {
      img.src = dataUrl;
      img.removeAttribute('hidden');
      empty.setAttribute('hidden', 'hidden');
    }
    if (prevImg && ph) {
      prevImg.src = dataUrl;
      prevImg.alt = melHeroAltValue(form) || '';
      prevImg.removeAttribute('hidden');
      ph.setAttribute('hidden', 'hidden');
    }
    scheduleApplyLivePreview(form, false);
  }

  function bindCoverFilePreview(form) {
    // Delegate on the form so every .mel-identity-media file input is covered,
    // including inputs added after AJAX managed_file refreshes.
    once('mel-cover-file', form).forEach(function () {
      form.addEventListener('change', function (e) {
        var input = e.target;
        if (
          !input ||
          input.type !== 'file' ||
          !form.contains(input) ||
          !input.closest('.mel-identity-media')
        ) {
          return;
        }
        var f = input.files && input.files[0];
        if (!f || !f.type || f.type.indexOf('image/') !== 0) {
          return;
        }
        var r = new FileReader();
        r.onload = function () {
          applyCoverFileDataUrl(form, r.result);
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
        typesEl.textContent = Drupal.t('Manage ticket types in the Tickets workspace.');
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

  /**
   * One-line summary for a collapsed completed builder card.
   *
   * @param {HTMLElement} card
   * @param {HTMLFormElement} form
   * @return {string}
   */
  function melBuildCardSummary(card, form) {
    if (!card || !form) {
      return '';
    }
    var cid = card.id || '';
    if (cid === 'mel-card-identity') {
      var titleRaw = val(form, 'mel[title]').trim();
      return '\uD83C\uDF89 ' + (titleRaw ? titleRaw : Drupal.t('Untitled event'));
    }
    if (cid === 'mel-card-schedule') {
      return scheduleSummary(form) + ' \u00b7 ' + venueSummaryLine(form);
    }
    if (cid === 'mel-card-tickets') {
      var n = form.querySelectorAll('.js-mel-ticket-card').length;
      return Drupal.formatPlural(n, '@count ticket type', '@count ticket types');
    }
    if (cid === 'mel-card-standout') {
      var teaser = val(form, 'mel[summary]').trim();
      if (teaser.length >= 10) {
        return teaser.length > 72 ? teaser.slice(0, 72) + '\u2026' : teaser;
      }
      var about = val(form, 'mel[body]').trim();
      if (about.length >= 40) {
        return about.length > 72 ? about.slice(0, 72) + '\u2026' : about;
      }
      var intro = val(form, 'mel[field_event_intro]').trim();
      if (intro.length >= 20) {
        return intro.length > 72 ? intro.slice(0, 72) + '\u2026' : intro;
      }
      return Drupal.t('Listing details added');
    }
    if (cid === 'mel-card-attendee') {
      var aq = 0;
      try {
        aq = parseAttendeeQuestionsState(form).length;
      } catch (eAq) {
        aq = 0;
      }
      if (aq > 0) {
        var qlabel = aq === 1 ? Drupal.t('custom question') : Drupal.t('custom questions');
        return String(aq) + ' ' + qlabel;
      }
      return Drupal.t('No extra questions');
    }
    if (cid === 'mel-card-preview') {
      return Drupal.t('Preview card');
    }
    if (cid === 'mel-card-publish') {
      return Drupal.t('Ready to publish');
    }
    return Drupal.t('Completed');
  }

  /**
   * @param {Element|null} card
   * @return {boolean}
   */
  function melIsPublishBuilderCard(card) {
    return !!(card && card.id === 'mel-card-publish');
  }

  /** Collapses all accordion cards except publish (always expanded). */
  function melCollapseAllBuilderCardsExceptPublish(form) {
    if (!form) {
      return;
    }
    delete form.dataset.melBuilderActiveCardId;

    form.querySelectorAll('.mel-builder-card').forEach(function (card) {
      var body = card.querySelector(':scope > .mel-builder-card__body');
      var summary = card.querySelector(':scope > .mel-builder-card__summary');
      if (!body || !summary) {
        return;
      }

      if (melIsPublishBuilderCard(card)) {
        body.style.display = '';
        summary.hidden = true;
        card.classList.add('mel-builder-card--body-expanded');
        return;
      }

      card.classList.remove('mel-builder-card--expanded-edit');
      card.classList.remove('mel-builder-card--body-expanded');
      body.style.display = 'none';
      summary.hidden = false;
      var summaryContent = summary.querySelector('.mel-builder-card__summary-content');
      if (summaryContent) {
        summaryContent.textContent = melBuildCardSummary(card, form);
      }
    });

    melUpdateBuilderCardCloseButtons(form);
    melRequestBuilderCardActiveSync(form);
  }

  /**
   * Ensures a footer row exists for in-card Continue + Close section controls.
   *
   * @param {HTMLElement} foot
   * @return {HTMLElement}
   */
  function melBuilderCardEnsureFooterToolbar(foot) {
    var toolbar = foot.querySelector('[data-mel-builder-footer-toolbar]');
    if (!toolbar) {
      toolbar = document.createElement('div');
      toolbar.className = 'mel-builder-card__footer-toolbar';
      toolbar.setAttribute('data-mel-builder-footer-toolbar', '1');
      foot.appendChild(toolbar);
    }
    return toolbar;
  }

  /**
   * Shows or hides footer "Close section" controls (complete + expanded only).
   *
   * @param {HTMLFormElement} form
   */
  function melUpdateBuilderCardCloseButtons(form) {
    if (!form) {
      return;
    }
    form.querySelectorAll('.mel-builder-card').forEach(function (card) {
      if (melIsPublishBuilderCard(card)) {
        return;
      }
      var btn = card.querySelector('[data-mel-builder-close-section]');
      if (!btn) {
        return;
      }
      var expanded = card.classList.contains('mel-builder-card--body-expanded');
      var complete = card.classList.contains('is-complete');
      var show = expanded && complete;
      btn.hidden = !show;
      btn.setAttribute('aria-hidden', show ? 'false' : 'true');
    });
  }

  /**
   * Ensures card footers have a toolbar row: Continue (next-step) + Close section.
   *
   * Safe to call repeatedly (e.g. after AJAX) — reconciles DOM order.
   *
   * @param {HTMLFormElement} form
   */
  function melEnsureCollapseDoneButtons(form) {
    if (!form) {
      return;
    }
    form.querySelectorAll('.mel-builder-card footer.mel-builder-card__footer').forEach(function (foot) {
      var card = foot.closest('.mel-builder-card');
      if (!card || melIsPublishBuilderCard(card)) {
        return;
      }
      var toolbar = melBuilderCardEnsureFooterToolbar(foot);
      var nextBlock = foot.querySelector(':scope > [data-mel-builder-next-step-block]');
      if (nextBlock) {
        toolbar.insertBefore(nextBlock, toolbar.firstChild);
      }
      if (!foot.querySelector('[data-mel-builder-close-section]')) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mel-btn mel-btn--ghost mel-builder-card__close-section';
        btn.setAttribute('data-mel-builder-close-section', '1');
        btn.hidden = true;
        btn.setAttribute('aria-hidden', 'true');
        btn.textContent = Drupal.t('Close section');
        toolbar.appendChild(btn);
      }
      else {
        var existingClose = foot.querySelector('[data-mel-builder-close-section]');
        if (existingClose && existingClose.parentNode !== toolbar) {
          toolbar.appendChild(existingClose);
        }
      }
    });
  }

  /**
   * Single active builder card: expand target, collapse others (publish card stays fully open).
   *
   * @param {HTMLFormElement} form
   * @param {HTMLElement|null} targetCard
   */
  function melSetActiveBuilderCard(form, targetCard) {
    if (!form || !targetCard) {
      return;
    }
    if (targetCard.id) {
      form.dataset.melBuilderActiveCardId = targetCard.id;
    } else {
      delete form.dataset.melBuilderActiveCardId;
    }

    form.querySelectorAll('.mel-builder-card').forEach(function (card) {
      var body = card.querySelector(':scope > .mel-builder-card__body');
      var summary = card.querySelector(':scope > .mel-builder-card__summary');
      if (!body || !summary) {
        return;
      }

      if (melIsPublishBuilderCard(card)) {
        body.style.display = '';
        summary.hidden = true;
        card.classList.add('mel-builder-card--body-expanded');
        return;
      }

      var isTarget = card === targetCard;
      card.classList.remove('mel-builder-card--expanded-edit');

      if (isTarget) {
        body.style.display = '';
        summary.hidden = true;
        card.classList.add('mel-builder-card--body-expanded');
      }
      else {
        card.classList.remove('mel-builder-card--body-expanded');
        body.style.display = 'none';
        summary.hidden = false;
        var summaryContent = summary.querySelector('.mel-builder-card__summary-content');
        if (summaryContent) {
          summaryContent.textContent = melBuildCardSummary(card, form);
        }
      }
    });

    melUpdateBuilderCardCloseButtons(form);
    melRequestBuilderCardActiveSync(form);
  }

  /**
   * Apply accordion state: expands only dataset.mel-builder-active-card, else collapses all.
   *
   * @param {HTMLFormElement} form
   * @param {boolean} [allowActiveOutsideWizardStep=false] When true, keep active card ID even if
   *   the card sits outside the wizard step indicated by data-mel-wizard-step (e.g. advancing
   *   to next section's primary card immediately after marking the previous complete).
   */
  function melApplyBuilderCardCollapseState(form, allowActiveOutsideWizardStep) {
    if (!form) {
      return;
    }
    melEnsureCollapseDoneButtons(form);

    var relaxStepContainment = !!allowActiveOutsideWizardStep;

    var activeId = form.dataset.melBuilderActiveCardId;
    var activeCard = activeId ? document.getElementById(activeId) : null;
    var stepIdx = getWizardStepIndex(form);
    var stepEl =
      stepIdx >= 0 && stepIdx < MEL_STEPS.length
        ? document.getElementById('mel-step-' + MEL_STEPS[stepIdx].id)
        : null;
    if (
      !activeCard ||
      !form.contains(activeCard) ||
      !activeCard.closest ||
      typeof activeCard.closest !== 'function' ||
      !activeCard.closest('section.mel-step')
    ) {
      activeCard = null;
    }
    else if (stepEl && !stepEl.contains(activeCard) && !relaxStepContainment) {
      activeCard = null;
    }

    if (!activeCard && stepEl) {
      activeCard = stepEl.querySelector('.mel-builder-card');
      if (activeCard && activeCard.id) {
        form.dataset.melBuilderActiveCardId = activeCard.id;
      }
    }

    if (activeCard) {
      melSetActiveBuilderCard(form, activeCard);
    } else {
      melCollapseAllBuilderCardsExceptPublish(form);
    }
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
    var media = form.querySelector('.mel-identity-media');
    var managedLegacy = form.querySelector('input[name="mel[field_event_image][]"]');
    return !!(
      (media && melFindManagedFileImageLink(media)) ||
      melFindManagedFileImageLink(form) ||
      (managedLegacy && managedLegacy.value) ||
      melHeroImageFidsPresent(form)
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
    if (getGovernanceSettings(form).enabled) {
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
    if (getGovernanceSettings(form).enabled) {
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
        melCoverImageJumpTarget(form),
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

    if (!melHeroAltValue(form) && hasCoverFile(form)) {
      add(
        'medium',
        Drupal.t('Add alt text for your cover image — it helps accessibility and SEO.'),
        melHeroAltFieldName(form),
      );
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
    if (getGovernanceSettings(form).enabled) {
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
    var sidebarSave = document.getElementById('mel-builder-save-sidebar');
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
    var publishReady = pub && score >= 70;
    if (submit) {
      submit.classList.toggle('mel-btn--studio-publish', publishReady);
      submit.classList.toggle('mel-btn--studio-draft', !publishReady);
    }
    if (sidebarSave && form.contains(sidebarSave)) {
      sidebarSave.classList.toggle('mel-btn--studio-publish', publishReady);
      sidebarSave.classList.toggle('mel-btn--studio-draft', !publishReady);
    }
  }

  function setWizardNavActive(form, activeIndex) {
    var nav = form.querySelector('#mel-wizard-nav');
    if (nav) {
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
          try {
            var navRect = nav.getBoundingClientRect();
            var linkRect = activeLink.getBoundingClientRect();
            var clipped =
              linkRect.top < navRect.top + 2 || linkRect.bottom > navRect.bottom - 2;
            if (!clipped) {
              return;
            }
          } catch (eSidebarMeasure) {}
          activeLink.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
            behavior: 'auto',
          });
        }, 60);
      }
    }
    melSyncBuilderCardActiveState(form, activeIndex);
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

  function titleCompleteForBuilderBadge(form) {
    var titleVal = val(form, 'mel[title]').trim();
    return titleVal !== '' && titleVal !== Drupal.t('Untitled event');
  }

  function scheduleCompleteForBuilderBadge(form) {
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

  /**
   * Stand out builder card: enough listing/description substance (matches progressive unlock signal).
   *
   * @param {HTMLFormElement} form
   * @return {boolean}
   */
  function standoutCompleteForBuilderBadge(form) {
    return melDescriptionSectorSatisfied(form);
  }

  /**
   * Attendee questions card: settled — either collecting with ≥1 row, or not collecting extras.
   *
   * @param {HTMLFormElement} form
   * @return {boolean}
   */
  function attendeeCompleteForBuilderBadge(form) {
    var collectHidden = form.querySelector('[name="mel[collect_attendee_questions]"]');
    var wantsCollect = !!(collectHidden && String(collectHidden.value || '') === '1');
    var rowCount = 0;
    try {
      rowCount = parseAttendeeQuestionsState(form).length;
    } catch (eRows) {
      rowCount = 0;
    }
    if (rowCount > 0) {
      return true;
    }
    return !wantsCollect;
  }

  /**
   * True when standout description-like fields carry enough substance to unlock the attendee card.
   *
   * @param {HTMLFormElement} form
   * @return {boolean}
   */
  function melDescriptionSectorSatisfied(form) {
    if (val(form, 'mel[summary]').trim().length >= 10) {
      return true;
    }
    if (val(form, 'mel[body]').trim().length >= 40) {
      return true;
    }
    if (val(form, 'mel[field_event_intro]').trim().length >= 20) {
      return true;
    }
    try {
      var hlHidden = form.querySelector('input[data-mel-highlights-state]');
      if (hlHidden && hlHidden.value) {
        var rowsHl = JSON.parse(hlHidden.value);
        if (
          Array.isArray(rowsHl) &&
          rowsHl.some(function (hr) {
            return hr && String(hr.text || '').trim().length > 0;
          })
        ) {
          return true;
        }
      }
    } catch (eHlSat) {}
    return false;
  }

  /** @param {HTMLFormElement} form */
  function melAttendeeSectorBusy(form) {
    try {
      if (parseAttendeeQuestionsState(form).length > 0) {
        return true;
      }
    } catch (eAtt) {}
    var syncCollect = form.querySelector('[data-mel-collect-attendee-sync="1"]');
    return !!(syncCollect && String(syncCollect.value || '') === '1');
  }

  /**
   * One-time programmatic open per slot; resetting when prerequisites lapse (user edits back).
   *
   * @param {HTMLFormElement} form
   * @param {HTMLDetailsElement|null} detailsEl
   * @param {boolean} chainOk
   * @param {boolean} intentOpen
   */
  function melProgressiveCollapseAutoOpen(form, detailsEl, chainOk, intentOpen) {
    if (!form || !detailsEl || detailsEl.tagName !== 'DETAILS') {
      return;
    }
    var mk = 'data-mel-progressive-chain-opened';
    if (!chainOk) {
      detailsEl.removeAttribute(mk);
      return;
    }
    if (!intentOpen) {
      return;
    }
    if (!detailsEl.getAttribute(mk)) {
      detailsEl.open = true;
      detailsEl.setAttribute(mk, '1');
    }
  }

  /** @param {HTMLFormElement} form */
  function melSyncProgressiveCollapseChain(form) {
    var ticketOk = isStepComplete('tickets', form);
    var descriptionOk = melDescriptionSectorSatisfied(form);
    var attendeeBusy = melAttendeeSectorBusy(form);

    var descSlot = form.querySelector('#mel-progressive-description');
    var attSlot = form.querySelector('#mel-progressive-attendee');
    var advSlot = form.querySelector('#mel-builder-more-options');

    melProgressiveCollapseAutoOpen(form, descSlot, ticketOk, ticketOk);
    melProgressiveCollapseAutoOpen(form, attSlot, ticketOk && descriptionOk, descriptionOk);
    melProgressiveCollapseAutoOpen(
      form,
      advSlot,
      ticketOk && descriptionOk && attendeeBusy,
      attendeeBusy,
    );
  }

  /**
   * Builder root for focus-mode styling (toggle .mel-builder--focus-mode).
   *
   * @param {HTMLFormElement} form
   * @return {HTMLElement|null}
   */
  function melGetBuilderShell(form) {
    return form && form.querySelector ? form.querySelector('[data-mel-builder="1"]') : null;
  }

  /**
   * Sections that act as stacked builder cards inside a wizard step.
   *
   * @param {HTMLElement} stepEl
   * @return {HTMLElement[]}
   */
  function melCardsInWizardStep(stepEl) {
    if (!stepEl || !stepEl.querySelectorAll) {
      return [];
    }
    return Array.prototype.slice.call(stepEl.querySelectorAll('section.mel-builder-card'));
  }

  /**
   * Prefer the card hosting the visible focus ring; fallback to viewport center.
   *
   * @param {HTMLElement} stepEl
   * @param {HTMLFormElement} form
   * @return {HTMLElement|null}
   */
  function melResolveFocusedBuilderCardInStep(stepEl, form) {
    var candidates = melCardsInWizardStep(stepEl);
    if (!candidates.length) {
      return null;
    }

    try {
      var ae = document.activeElement;
      if (
        ae &&
        ae !== document.body &&
        form.contains(ae) &&
        typeof ae.closest === 'function'
      ) {
        var focusHost = ae.closest('section.mel-builder-card');
        if (focusHost && stepEl.contains(focusHost)) {
          return focusHost;
        }
      }
    } catch (eFocus) {}

    var centerY = typeof window.innerHeight === 'number' ? window.innerHeight * 0.5 : 0;
    var best = candidates[0];
    var bestDist = Infinity;
    candidates.forEach(function (card) {
      try {
        var r = card.getBoundingClientRect();
        var mid = (r.top + r.bottom) / 2;
        var d = Math.abs(mid - centerY);
        if (d < bestDist) {
          bestDist = d;
          best = card;
        }
      } catch (eGeom) {}
    });
    return best || null;
  }

  /** @param {HTMLFormElement} form */
  function melRequestBuilderCardActiveSync(form) {
    if (!form) {
      return;
    }
    var pending = melBuilderCardFocusRafIds.get(form);
    if (
      pending !== undefined &&
      typeof pending === 'number' &&
      typeof window.cancelAnimationFrame === 'function'
    ) {
      window.cancelAnimationFrame(pending);
    }
    if (typeof window.requestAnimationFrame !== 'function') {
      melSyncBuilderCardActiveState(form, getWizardStepIndex(form));
      return;
    }
    var rid = window.requestAnimationFrame(function () {
      melBuilderCardFocusRafIds.delete(form);
      melSyncBuilderCardActiveState(form, getWizardStepIndex(form));
    });
    melBuilderCardFocusRafIds.set(form, rid);
  }

  /**
   * Single focused builder card inside the wizard step (+ focus-mode on root shell).
   *
   * @param {HTMLFormElement} form
   * @param {number} activeIndex
   */
  function melSyncBuilderCardActiveState(form, activeIndex) {
    if (!form) {
      return;
    }
    form.querySelectorAll('.mel-builder-card').forEach(function (card) {
      card.classList.remove('is-active');
    });

    var root = melGetBuilderShell(form);
    if (root) {
      root.classList.remove('mel-builder--focus-mode');
    }

    if (activeIndex < 0 || activeIndex >= MEL_STEPS.length) {
      return;
    }

    var stepEl = document.getElementById('mel-step-' + MEL_STEPS[activeIndex].id);
    if (!stepEl) {
      return;
    }

    var focused = melResolveFocusedBuilderCardInStep(stepEl, form);
    if (focused && root) {
      focused.classList.add('is-active');
      root.classList.add('mel-builder--focus-mode');
    }
  }

  /**
   * All primary builder sections satisfied (published nodes excluded).
   *
   * @param {HTMLFormElement} form
   * @return {boolean}
   */
  function melPublishCardSectionsAllReady(form) {
    if (!form) {
      return false;
    }
    var initialPub = getSettings().initial || {};
    if (initialPub && initialPub.published) {
      return false;
    }
    return (
      titleCompleteForBuilderBadge(form) &&
      scheduleCompleteForBuilderBadge(form) &&
      isStepComplete('tickets', form) &&
      standoutCompleteForBuilderBadge(form) &&
      attendeeCompleteForBuilderBadge(form)
    );
  }

  /** @param {HTMLFormElement} form */
  function melSyncPublishCardReadyState(form) {
    var pubCard = form.querySelector('#mel-card-publish');
    if (!pubCard) {
      return;
    }
    var celebrate = pubCard.querySelector('[data-mel-publish-ready-celebrate]');
    var canPublishUi = !!form.querySelector('#mel-publish-now');
    var ready = melPublishCardSectionsAllReady(form) && canPublishUi;
    pubCard.classList.toggle('is-ready', ready);
    if (celebrate) {
      celebrate.hidden = !ready;
    }
  }

  function melSyncBuilderCardCompletionBadges(form) {
    if (!form) {
      return;
    }
    var momentumByKey = {
      'identity-title': Drupal.t('Nice — this looks great ✨'),
      schedule: Drupal.t('Nice — this looks great ✨'),
      tickets: Drupal.t('Awesome — people can now book your event 🎉'),
      standout: Drupal.t('Your listing is starting to shine ✨'),
      attendee: Drupal.t('Attendee checkout questions look good ✓'),
      'pre-publish': Drupal.t("You're almost ready to go live 🚀"),
    };
    /** Keys that show the DOM-order "Continue" hint after the momentum message. */
    var nextStepHintKeys = {
      'identity-title': true,
      schedule: true,
      tickets: true,
      standout: true,
      attendee: true,
    };
    /** @type {HTMLElement|null} */
    var pendingCompletionScrollCard = null;
    form.querySelectorAll('[data-mel-track-complete]').forEach(function (card) {
      var key = card.getAttribute('data-mel-track-complete');
      var badge = card.querySelector('[data-mel-builder-complete-msg]');
      var doneBadge = card.querySelector('[data-mel-builder-done-badge]');
      if (!badge) {
        return;
      }
      var done = false;
      if (key === 'identity-title') {
        done = titleCompleteForBuilderBadge(form);
      } else if (key === 'schedule') {
        done = scheduleCompleteForBuilderBadge(form);
      } else if (key === 'tickets') {
        done = isStepComplete('tickets', form);
      } else if (key === 'standout') {
        done = standoutCompleteForBuilderBadge(form);
      } else if (key === 'attendee') {
        done = attendeeCompleteForBuilderBadge(form);
      } else if (key === 'pre-publish') {
        var initialPub = getSettings().initial || {};
        var alreadyLive = !!initialPub.published;
        done =
          !alreadyLive &&
          isStepComplete('identity', form) &&
          isStepComplete('tickets', form);
      }
      var momentumText = momentumByKey[key];
      badge.textContent = done && momentumText ? momentumText : '';
      badge.hidden = !(done && momentumText);

      var footer = badge.closest('.mel-builder-card__footer');
      var allowNextHint = !!nextStepHintKeys[key];
      var nextCardEl = allowNextHint ? melNextPrimaryFlowBuilderCard(form, card) : null;
      var heading = nextCardEl ? melBuilderCardHintHeading(nextCardEl) : null;
      var hintLineText = '';
      if (heading) {
        var titleForHint = (heading.innerText || '').trim();
        if (titleForHint) {
          hintLineText = Drupal.t('Next: @title', {
            '@title': titleForHint
          });
        }
      }
      var nextBlock = footer ? footer.querySelector('[data-mel-builder-next-step-block]') : null;
      var showNext = !!(done && momentumText && footer && allowNextHint && nextCardEl && hintLineText);

      if (footer && allowNextHint && !nextBlock) {
        nextBlock = document.createElement('div');
        nextBlock.className = 'mel-builder-card__next-step';
        nextBlock.setAttribute('data-mel-builder-next-step-block', '');
        nextBlock.hidden = true;
        var hintEl = document.createElement('p');
        hintEl.className = 'mel-builder-card__next-hint';
        var hintId = 'mel-next-hint-' + String(key).replace(/[^a-z0-9-]+/gi, '-');
        hintEl.id = hintId;
        var btnEl = document.createElement('button');
        btnEl.type = 'button';
        btnEl.className = 'mel-next-step-btn';
        btnEl.textContent = Drupal.t('Continue →');
        btnEl.setAttribute('aria-describedby', hintId);
        nextBlock.appendChild(hintEl);
        nextBlock.appendChild(btnEl);
        var insertToolbar = melBuilderCardEnsureFooterToolbar(footer);
        insertToolbar.insertBefore(nextBlock, insertToolbar.firstChild);
      }

      if (nextBlock && nextBlock.parentNode) {
        var hostFooter = nextBlock.closest('.mel-builder-card__footer');
        if (hostFooter && nextBlock.parentNode === hostFooter) {
          var tBar = melBuilderCardEnsureFooterToolbar(hostFooter);
          tBar.insertBefore(nextBlock, tBar.firstChild);
        }
      }

      if (nextBlock) {
        var hintLine = nextBlock.querySelector('.mel-builder-card__next-hint');
        var nextBtn = nextBlock.querySelector('.mel-next-step-btn');
        if (showNext && hintLine && nextBtn) {
          hintLine.textContent = hintLineText;
          nextBlock.hidden = false;
        } else {
          nextBlock.hidden = true;
        }
      }

      var wasComplete = card.dataset.wasComplete === 'true';
      var isComplete = done;
      if (!wasComplete && isComplete && nextStepHintKeys[key]) {
        var nextAfterComplete = melNextPrimaryFlowBuilderCard(form, card);
        if (nextAfterComplete) {
          pendingCompletionScrollCard = nextAfterComplete;
        }
      }
      card.dataset.wasComplete = isComplete ? 'true' : 'false';

      if (done) {
        card.classList.add('is-complete');
        if (doneBadge) {
          doneBadge.hidden = key === 'pre-publish';
        }
      } else {
        card.classList.remove('is-complete');
        if (doneBadge) {
          doneBadge.hidden = true;
        }
      }
    });
    melSyncPublishCardReadyState(form);
    if (pendingCompletionScrollCard) {
      melSetActiveBuilderCard(form, pendingCompletionScrollCard);
    }
    melApplyBuilderCardCollapseState(form, !!pendingCompletionScrollCard);
    if (pendingCompletionScrollCard && form) {
      var scrollTargetCard = pendingCompletionScrollCard;
      window.setTimeout(function () {
        melScrollToBuilderCard(form, scrollTargetCard);
      }, 0);
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
    form.querySelectorAll('.mel-builder-card--expanded-edit').forEach(function (node) {
      node.classList.remove('mel-builder-card--expanded-edit');
    });
    if (stepIdx >= MEL_STEPS.length - 1) {
      return;
    }
    var nextIdx = stepIdx + 1;
    delete form.dataset.melBuilderActiveCardId;
    scrollToStudioSection(form, nextIdx);
    melApplyBuilderCardCollapseState(form);
  }

  function initMelWizard(form) {
    if (!form.querySelector('.mel-wizard') || !form.querySelector('#mel-wizard-nav')) {
      return;
    }

    var steps = form.querySelectorAll('section.mel-step[data-step]');
    setWizardStepIndex(form, 0);
    setWizardNavActive(form, 0);
    updateProgress(form);

    if (!form.dataset.melBuilderAccordionInteractionsBound) {
      form.dataset.melBuilderAccordionInteractionsBound = '1';
      melEnsureCollapseDoneButtons(form);

      form.addEventListener(
        'click',
        function (e) {
          var closeBtn = e.target.closest('[data-mel-builder-close-section]');
          if (!closeBtn || !form.contains(closeBtn)) {
            return;
          }
          e.preventDefault();
          delete form.dataset.melBuilderActiveCardId;
          melApplyBuilderCardCollapseState(form);
        },
        false,
      );

      form.addEventListener(
        'click',
        function (e) {
          var hdr = e.target.closest('.mel-builder-card > .mel-builder-card__header');
          if (!hdr || !form.contains(hdr)) {
            return;
          }
          if (e.target.closest('button, a, input, select, textarea, label')) {
            return;
          }
          var headerCard = hdr.closest('.mel-builder-card');
          if (!headerCard || melIsPublishBuilderCard(headerCard)) {
            return;
          }
          if (headerCard.classList.contains('mel-builder-card--body-expanded')) {
            return;
          }
          e.preventDefault();
          melSetActiveBuilderCard(form, headerCard);
        },
        false,
      );
    }

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
          melApplyBuilderCardCollapseState(form);
        }
      });
    });

    form.addEventListener(
      'click',
      function (e) {
        var summaryStrip = e.target.closest('.mel-builder-card__summary');
        if (summaryStrip && form.contains(summaryStrip)) {
          var card = summaryStrip.closest('.mel-builder-card');
          if (card && form.contains(card)) {
            e.preventDefault();
            melSetActiveBuilderCard(form, card);
          }
          return;
        }

        var jumpPrev = e.target.closest('#mel-studio-jump-preview, #mel-jump-to-preview-card, .mel-studio-jump-preview');
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
          melApplyBuilderCardCollapseState(form);
        }
      },
      false,
    );

    if (window.IntersectionObserver && steps.length) {
      var io = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (Date.now() < melWizardIoNavSilentUntil) {
              return;
            }
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
              var prevStep = getWizardStepIndex(form);
              setWizardNavActive(form, idx);
              setWizardStepIndex(form, idx);
              if (prevStep !== idx) {
                melApplyBuilderCardCollapseState(form);
              }
            }
          });
        },
        { rootMargin: '-40% 0px -50% 0px', threshold: 0.1 },
      );
      steps.forEach(function (s) {
        io.observe(s);
      });
    }

    if (!form.dataset.melBuilderFocusSyncUiBound) {
      form.dataset.melBuilderFocusSyncUiBound = '1';
      var syncCardsOnViewport = function () {
        melRequestBuilderCardActiveSync(form);
      };
      window.addEventListener('scroll', syncCardsOnViewport, { passive: true, capture: true });
      window.addEventListener('resize', syncCardsOnViewport, false);
      form.addEventListener(
        'focusin',
        function () {
          melSyncBuilderCardActiveState(form, getWizardStepIndex(form));
        },
        true,
      );
    }
  }

  var PREVIEW_DEBOUNCE_MS = 300;

  function collectManagerTiersFromDom(form) {
    var wrap = form.querySelector('[data-mel-ticket-manager-form="1"]');
    if (!wrap) {
      return [];
    }
    var out = [];
    wrap.querySelectorAll('.mel-ticket-manager-row').forEach(function (row) {
      var del = row.querySelector('input[type="checkbox"][name*="[more][delete]"]');
      if (del && del.checked) {
        return;
      }
      var activeEl = row.querySelector('input[type="checkbox"][name*="[active]"]');
      if (activeEl && !activeEl.checked) {
        return;
      }
      var titleEl = row.querySelector('input[name*="[title]"]');
      var priceEl = row.querySelector('input[name*="[price]"]');
      var currencyEl = row.querySelector('input[name*="[currency]"]');
      var title = titleEl ? String(titleEl.value || '').trim() : '';
      var price = priceEl ? String(priceEl.value || '').trim() : '';
      if (title === '' && (price === '' || isNaN(parseFloat(price)))) {
        return;
      }
      out.push({
        ticket_kind: 'paid',
        title: title,
        price_number: price,
        price_currency: currencyEl ? String(currencyEl.value || '').trim() : '',
      });
    });
    return out;
  }

  function mergePreviewTiersForPricing(form) {
    var out = collectTiersFromDom(form);
    var mgr = collectManagerTiersFromDom(form);
    if (mgr.length) {
      out = out.concat(mgr);
    }
    return out;
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
          (val(form, 'mel[field_product_target]') !== '' &&
            (paidTicketTierSignalCount(form) >= 1 || init.paidBookingReady === true)))
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
      var paidReady = init.paidBookingReady === true;
      if (productOk && (tiersOk || paidReady) && book) {
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
    var governanceEnabled = !!getGovernanceSettings(form).enabled;

    syncTicketBuilderEventType(form);
    syncTicketTiersFromDomToHidden(form);
    if (Drupal.melEventStudioHighlights) {
      if (typeof Drupal.melEventStudioHighlights.syncFromDom === 'function') {
        Drupal.melEventStudioHighlights.syncFromDom(form);
      }
      if (
        form.getAttribute('data-mel-highlights-json-error') !== '1' &&
        typeof Drupal.melEventStudioHighlights.updateErrors === 'function'
      ) {
        Drupal.melEventStudioHighlights.updateErrors(form);
      }
    }

    syncLocationDisplay(form);
    syncCategoryChips(form);
    syncTicketPaidShell(form);
    syncTicketProductPanel(form);
    syncTicketSummary(form, str);
    syncCoverPreview(form);
    syncTicketModelWarnings(form);

    updateProgress(form);
    updateInsightsChecklist(form);
    melUpdateNextBest(form);
    melUpdatePrimaryCta(form);
    updatePreviewHints(form);
    syncPublishActionCardUi(form);
    updateFooterCtaState(form);

    var pr = document.getElementById('mel-publish-readiness');
    if (pr && !governanceEnabled) {
      pr.textContent = publishReadiness(form, init, str);
    }

    if (root) {
      root.removeAttribute('data-mel-score');
    }

    syncCollectHiddenFromAttendees(form);
    melProgressiveRevealBuilder(form);
    melSyncProgressiveCollapseChain(form);
    melSyncBuilderCardCompletionBadges(form);

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
    syncAttendeeQuestionLimitWarning(form);
    var str = getSettings().strings || {};
    syncTicketSummary(form, str);
    melProgressiveRevealBuilder(form);
    melMaybeOpenAttendeeDetails(form, rows.length > 0);
    melSyncProgressiveCollapseChain(form);
    updateInsightsChecklist(form);
    melUpdateNextBest(form);
    updateFooterCtaState(form);
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

  function getAttendeeQuestionLimitWarning(form) {
    return form.querySelector('#mel-attendee-questions-limit-warn');
  }

  function syncAttendeeQuestionLimitWarning(form) {
    var warning = getAttendeeQuestionLimitWarning(form);
    if (!warning) {
      return;
    }
    var rows = parseAttendeeQuestionsState(form);
    var show = rows.length > 0;
    warning.hidden = !show;
    warning.setAttribute('aria-hidden', show ? 'false' : 'true');

    var addButton = form.querySelector('#mel-attendee-add-question');
    var libraryButton = form.querySelector('[data-mel-attendee-library-add="1"]');
    [addButton, libraryButton].forEach(function (button) {
      if (!button) {
        return;
      }
      button.disabled = rows.length >= ATTENDEE_QUESTION_MAX;
      button.setAttribute('aria-disabled', rows.length >= ATTENDEE_QUESTION_MAX ? 'true' : 'false');
    });
  }

  function canAddAttendeeQuestion(form) {
    if (parseAttendeeQuestionsState(form).length < ATTENDEE_QUESTION_MAX) {
      return true;
    }
    syncAttendeeQuestionLimitWarning(form);
    var warning = getAttendeeQuestionLimitWarning(form);
    if (warning && typeof warning.focus === 'function') {
      if (!warning.hasAttribute('tabindex')) {
        warning.setAttribute('tabindex', '-1');
      }
      warning.focus();
    }
    return false;
  }

  function melMaybeOpenAttendeeDetails(form, shouldOpen) {
    if (!shouldOpen) {
      return;
    }
    var shell = form.querySelector('#mel-progressive-attendee');
    if (shell && shell.tagName === 'DETAILS') {
      shell.open = true;
    }
    var det = form.querySelector('#mel-attendee-questions-details');
    if (!det || det.tagName !== 'DETAILS') {
      return;
    }
    det.open = true;
  }

  /** Legacy reveal class removal (progressive collapse uses <details> instead of display:none gates). */
  function melProgressiveRevealBuilder(form) {
    try {
      form.querySelectorAll('.mel-builder-reveal--hidden').forEach(function (el) {
        el.classList.remove('mel-builder-reveal--hidden');
        el.removeAttribute('aria-hidden');
      });
    } catch (errReveal) {
      if (typeof console !== 'undefined' && console.warn) {
        console.warn('MelEventStudio: progressive reveal cleanup fallback.', errReveal);
      }
    }
  }

  function attendeeStudioTypeNeedsOptions(type) {
    type = String(type || '').trim();
    return type === 'select' || type === 'checkboxes' || type === 'radios';
  }

  function melAttendeeNormalizeType(type) {
    type = String(type || '').trim();
    var aliases = {
      text: 'textfield',
      checkbox: 'checkboxes',
      radio: 'radios',
    };
    return aliases[type] || type || 'textfield';
  }

  function collectAttendeeQuestionsFromDom(form) {
    var host = form.querySelector('[data-mel-attendee-question-list="1"]');
    if (!host) {
      return [];
    }
    var prev = parseAttendeeQuestionsState(form);
    var rows = [];
    var pi = 0;
    host.querySelectorAll(':scope > .mel-attendee-row').forEach(function (wrap) {
      var old = prev[pi] || {};
      pi += 1;
      if (wrap.querySelector('.mel-attendee-row__lib')) {
        var vid = parseInt(wrap.getAttribute('data-mel-attendee-vendor-qid') || '0', 10);
        if (!isNaN(vid) && vid > 0) {
          rows.push({ vendor_question_id: vid });
        }
        return;
      }
      var labelInp = wrap.querySelector('.mel-attendee-label');
      var typeSel = wrap.querySelector('.mel-attendee-type');
      var req = wrap.querySelector('.mel-attendee-required');
      var libSave = wrap.querySelector('.mel-attendee-save-lib');
      var label = labelInp ? String(labelInp.value || '').trim() : '';
      var type = typeSel ? melAttendeeNormalizeType(typeSel.value) : 'textfield';
      var opts = [];
      if (attendeeStudioTypeNeedsOptions(type)) {
        wrap.querySelectorAll('.mel-attendee-option-input').forEach(function (inp) {
          var t = String(inp.value || '').trim();
          if (t !== '') {
            opts.push(t);
          }
        });
      }
      var row = {
        label: label,
        type: type,
        required: req ? !!req.checked : false,
        save_to_library: libSave ? !!libSave.checked : false,
      };
      if (old.id) {
        row.id = old.id;
      }
      if (opts.length) {
        row.options = opts;
      }
      if (old.machine_name) {
        row.machine_name = old.machine_name;
      }
      if (old.status) {
        row.status = old.status;
      }
      if (old.applicability) {
        row.applicability = old.applicability;
      }
      if (old.ticket_type_ids) {
        row.ticket_type_ids = old.ticket_type_ids;
      }
      rows.push(row);
    });
    return rows;
  }

  function melAttendeePreviewHtml(label, type, options) {
    var lab = String(label || '').trim();
    var opts = Array.isArray(options)
      ? options.filter(function (x) {
          return String(x || '').trim() !== '';
        })
      : [];
    var esc = Drupal.checkPlain;
    if (!lab && opts.length === 0) {
      return (
        '<p class="mel-attendee-preview__empty">' +
        esc(Drupal.t('Preview appears as you add details.')) +
        '</p>'
      );
    }
    var head = lab ? '<p class="mel-attendee-preview__label">' + esc(lab) + '</p>' : '';
    type = melAttendeeNormalizeType(type);
    if (type === 'checkboxes') {
      var cb = opts
        .map(function (o) {
          return (
            '<label class="mel-attendee-preview__pick"><input type="checkbox" disabled /> <span>' +
            esc(o) +
            '</span></label>'
          );
        })
        .join('');
      return '<div class="mel-attendee-preview mel-attendee-preview--checkboxes">' + head + cb + '</div>';
    }
    if (type === 'radios') {
      var nm = 'mel-att-prev-' + String(Math.random()).slice(2);
      var rd = opts
        .map(function (o) {
          return (
            '<label class="mel-attendee-preview__pick"><input type="radio" disabled name="' +
            nm +
            '" /> <span>' +
            esc(o) +
            '</span></label>'
          );
        })
        .join('');
      return '<div class="mel-attendee-preview mel-attendee-preview--radios">' + head + rd + '</div>';
    }
    if (type === 'select') {
      var inner = '<option value="">' + esc(Drupal.t('Choose…')) + '</option>';
      opts.forEach(function (o) {
        inner += '<option>' + esc(o) + '</option>';
      });
      return (
        '<div class="mel-attendee-preview mel-attendee-preview--select">' +
        head +
        '<select class="mel-input" disabled="disabled">' +
        inner +
        '</select></div>'
      );
    }
    if (type === 'textarea') {
      return (
        '<div class="mel-attendee-preview mel-attendee-preview--textarea">' +
        head +
        '<textarea class="mel-input" disabled rows="2" placeholder="' +
        esc(Drupal.t('Long answer')) +
        '"></textarea></div>'
      );
    }
    return (
      '<div class="mel-attendee-preview mel-attendee-preview--text">' +
      head +
      '<input type="text" class="mel-input" disabled placeholder="' +
      esc(Drupal.t('Short answer')) +
      '" /></div>'
    );
  }

  function refreshAttendeeRowPreview(wrap) {
    var box = wrap.querySelector('[data-mel-attendee-preview="1"]');
    if (!box) {
      return;
    }
    var labelInp = wrap.querySelector('.mel-attendee-label');
    var typeSel = wrap.querySelector('.mel-attendee-type');
    var label = labelInp ? String(labelInp.value || '').trim() : '';
    var type = typeSel ? melAttendeeNormalizeType(typeSel.value) : 'textfield';
    var opts = [];
    if (attendeeStudioTypeNeedsOptions(type)) {
      wrap.querySelectorAll('.mel-attendee-option-input').forEach(function (inp) {
        var t = String(inp.value || '').trim();
        if (t !== '') {
          opts.push(t);
        }
      });
    }
    box.innerHTML = melAttendeePreviewHtml(label, type, opts);
  }

  function toggleAttendeeOptionsUi(wrap, typeRaw) {
    var shell = wrap.querySelector('[data-mel-attendee-options-shell="1"]');
    if (!shell) {
      return;
    }
    var show = attendeeStudioTypeNeedsOptions(melAttendeeNormalizeType(typeRaw));
    shell.hidden = !show;
    shell.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  function melAttendeeTypeSelectOptions(selectedRaw) {
    var selected = melAttendeeNormalizeType(selectedRaw);
    var pairs = [
      ['textfield', Drupal.t('Short answer')],
      ['textarea', Drupal.t('Long answer')],
      ['select', Drupal.t('Dropdown')],
      ['checkboxes', Drupal.t('Checkboxes')],
      ['radios', Drupal.t('Multiple choice')],
      ['email', Drupal.t('Email')],
      ['tel', Drupal.t('Phone number')],
    ];
    var html = '';
    var i;
    for (i = 0; i < pairs.length; i++) {
      var v = pairs[i][0];
      var sel = v === selected ? ' selected' : '';
      html +=
        '<option value="' +
        Drupal.checkPlain(v) +
        '"' +
        sel +
        '>' +
        Drupal.checkPlain(pairs[i][1]) +
        '</option>';
    }
    return html;
  }

  function patchAttendeeRowState(form, wrap) {
    var rows = collectAttendeeQuestionsFromDom(form);
    writeAttendeeQuestionsState(form, rows, false);
    refreshAttendeeRowPreview(wrap);
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
        wrap.setAttribute('data-mel-attendee-vendor-qid', String(row.vendor_question_id));
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
        host.appendChild(wrap);
        return;
      }

      var qId = 'mel-attendee-q-' + String(idx);
      var typeId = 'mel-attendee-type-' + String(idx);
      var libId = 'mel-attendee-lib-' + String(idx);
      var reqId = 'mel-attendee-req-' + String(idx);
      var prevId = 'mel-attendee-preview-' + String(idx);

      var primary = document.createElement('div');
      primary.className = 'mel-attendee-row__primary';

      var qWell = document.createElement('div');
      qWell.className = 'js-form-item form-item mel-builder-field-well';
      var qLab = document.createElement('label');
      qLab.setAttribute('for', qId);
      qLab.className = 'form-item__label';
      qLab.textContent = Drupal.t('Question');
      var qInp = document.createElement('input');
      qInp.type = 'text';
      qInp.id = qId;
      qInp.className = 'mel-input mel-attendee-label';
      qInp.setAttribute('data-idx', String(idx));
      qInp.value = row.label || '';
      qWell.appendChild(qLab);
      qWell.appendChild(qInp);

      var typeWell = document.createElement('div');
      typeWell.className = 'js-form-item form-item mel-builder-field-well';
      var typeLab = document.createElement('label');
      typeLab.setAttribute('for', typeId);
      typeLab.className = 'form-item__label';
      typeLab.textContent = Drupal.t('Answer type');
      var typeSel = document.createElement('select');
      typeSel.id = typeId;
      typeSel.className = 'mel-input mel-attendee-type';
      typeSel.setAttribute('data-idx', String(idx));
      typeSel.innerHTML = melAttendeeTypeSelectOptions(row.type || 'textfield');
      typeWell.appendChild(typeLab);
      typeWell.appendChild(typeSel);

      var optShell = document.createElement('div');
      optShell.className = 'mel-attendee-options-shell mel-builder-field-well';
      optShell.setAttribute('data-mel-attendee-options-shell', '1');
      var normType = melAttendeeNormalizeType(row.type || 'textfield');
      var needsOpt = attendeeStudioTypeNeedsOptions(normType);
      optShell.hidden = !needsOpt;
      optShell.setAttribute('aria-hidden', needsOpt ? 'false' : 'true');

      var optLab = document.createElement('label');
      optLab.className = 'form-item__label';
      optLab.textContent = Drupal.t('Choices');
      optShell.appendChild(optLab);

      var optList = document.createElement('div');
      optList.className = 'mel-attendee-option-list';
      optList.setAttribute('data-mel-attendee-option-list', '1');
      var optLines = Array.isArray(row.options) && row.options.length ? row.options : [''];
      optLines.forEach(function (lineText) {
        var lineWrap = document.createElement('div');
        lineWrap.className = 'mel-attendee-option-line';
        var oi = document.createElement('input');
        oi.type = 'text';
        oi.className = 'mel-input mel-attendee-option-input';
        oi.value = lineText;
        lineWrap.appendChild(oi);
        optList.appendChild(lineWrap);
      });
      optShell.appendChild(optList);

      var addOptBtn = document.createElement('button');
      addOptBtn.type = 'button';
      addOptBtn.className = 'mel-btn mel-btn--secondary mel-attendee-add-option';
      addOptBtn.textContent = Drupal.t('Add choice');
      optShell.appendChild(addOptBtn);

      var cards = document.createElement('div');
      cards.className = 'mel-option-cards mel-option-cards--attendee-inline';
      cards.innerHTML =
        '<div class="mel-option-card">' +
        '<input type="checkbox" id="' +
        libId +
        '" class="form-checkbox mel-attendee-save-lib" data-idx="' +
        String(idx) +
        '"' +
        (row.save_to_library ? ' checked' : '') +
        ' />' +
        '<div class="mel-option-card__content">' +
        '<label class="mel-option-card__label" for="' +
        libId +
        '"><strong>' +
        Drupal.t('Save to library') +
        '</strong></label>' +
        '<p class="mel-option-card__desc">' +
        Drupal.t('Keep this wording in your organizer library for reuse.') +
        '</p>' +
        '</div></div>' +
        '<div class="mel-option-card">' +
        '<input type="checkbox" id="' +
        reqId +
        '" class="form-checkbox mel-attendee-required" data-idx="' +
        String(idx) +
        '"' +
        (row.required ? ' checked' : '') +
        ' />' +
        '<div class="mel-option-card__content">' +
        '<label class="mel-option-card__label" for="' +
        reqId +
        '"><strong>' +
        Drupal.t('Required') +
        '</strong></label>' +
        '<p class="mel-option-card__desc">' +
        Drupal.t('Guests must answer before completing checkout or RSVP.') +
        '</p>' +
        '</div></div>';

      var previewWell = document.createElement('div');
      previewWell.className = 'mel-builder-field-well mel-attendee-preview-well';
      var prevLab = document.createElement('label');
      prevLab.className = 'form-item__label';
      prevLab.setAttribute('for', prevId);
      prevLab.textContent = Drupal.t('Guest preview');
      var prevBox = document.createElement('div');
      prevBox.id = prevId;
      prevBox.className = 'mel-attendee-preview-host';
      prevBox.setAttribute('data-mel-attendee-preview', '1');

      previewWell.appendChild(prevLab);
      previewWell.appendChild(prevBox);

      primary.appendChild(qWell);
      primary.appendChild(typeWell);
      primary.appendChild(optShell);
      primary.appendChild(cards);
      primary.appendChild(previewWell);

      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'mel-btn mel-btn--ghost mel-attendee-remove';
      rm.setAttribute('data-mel-attendee-remove', String(idx));
      rm.textContent = Drupal.t('Remove');

      wrap.appendChild(primary);
      wrap.appendChild(rm);
      host.appendChild(wrap);
      refreshAttendeeRowPreview(wrap);
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
    if (!canAddAttendeeQuestion(form)) {
      return;
    }
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
    if (!canAddAttendeeQuestion(form)) {
      return;
    }
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
    syncAttendeeQuestionLimitWarning(form);

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
        var addOpt = e.target.closest('.mel-attendee-add-option');
        if (addOpt && form.contains(addOpt)) {
          e.preventDefault();
          var wrapOpt = addOpt.closest('.mel-attendee-row');
          if (!wrapOpt) {
            return;
          }
          var list = wrapOpt.querySelector('[data-mel-attendee-option-list="1"]');
          if (!list) {
            return;
          }
          var lineWrap = document.createElement('div');
          lineWrap.className = 'mel-attendee-option-line';
          var oi = document.createElement('input');
          oi.type = 'text';
          oi.className = 'mel-input mel-attendee-option-input';
          lineWrap.appendChild(oi);
          list.appendChild(lineWrap);
          patchAttendeeRowState(form, wrapOpt);
          oi.focus();
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
          var wrapIn = e.target.closest('.mel-attendee-row');
          if (!wrapIn || !form.contains(wrapIn) || wrapIn.querySelector('.mel-attendee-row__lib')) {
            return;
          }
          if (
            e.target.classList &&
            (e.target.classList.contains('mel-attendee-label') ||
              e.target.classList.contains('mel-attendee-option-input'))
          ) {
            patchAttendeeRowState(form, wrapIn);
          }
        },
        true,
      );

      form.addEventListener(
        'change',
        function (e) {
          var wrapCh = e.target.closest('.mel-attendee-row');
          if (!wrapCh || !form.contains(wrapCh) || wrapCh.querySelector('.mel-attendee-row__lib')) {
            return;
          }
          if (e.target.classList && e.target.classList.contains('mel-attendee-type')) {
            toggleAttendeeOptionsUi(wrapCh, e.target.value);
          }
          if (
            e.target.classList &&
            (e.target.classList.contains('mel-attendee-required') ||
              e.target.classList.contains('mel-attendee-save-lib') ||
              e.target.classList.contains('mel-attendee-type'))
          ) {
            patchAttendeeRowState(form, wrapCh);
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
      if (!document.documentElement.dataset.melEventStudioAiInsertBridge) {
        document.documentElement.dataset.melEventStudioAiInsertBridge = '1';
        document.addEventListener('melEventStudio:aiInsert', function (event) {
          var form = event.detail && event.detail.form;
          if (!form || form.closest('[data-mel-studio-shell]')) {
            return;
          }
          refreshIntelligence(form, true);
          setFormState(form, 'mel-studio--dirty', Drupal.t('Writing idea used'));
        });
      }

      if (!document.documentElement.dataset.melHighlightsChangedBridge) {
        document.documentElement.dataset.melHighlightsChangedBridge = '1';
        document.addEventListener('melEventStudio:highlightsChanged', function (event) {
          var form = event.detail && event.detail.form;
          if (!form || form.closest('[data-mel-studio-shell]')) {
            return;
          }
          refreshIntelligence(form);
          setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
        });
      }

      once('mel-event-studio-core', 'form[data-mel-event-studio-form="1"]', context).forEach(function (form) {
        var str = getSettings().strings || {};

        initTicketBuilder(form);
        initAttendeeQuestionsBuilder(form);
        refreshIntelligence(form);
        melScrollToFirstFormError(form);
        bindCoverFilePreview(form);
        initMelWizard(form);
        initPreviewDrawer(form);

        if (!form.dataset.melNextStepBtnBound) {
          form.dataset.melNextStepBtnBound = '1';
          form.addEventListener(
            'click',
            function (e) {
              var nextBtn = e.target.closest('.mel-next-step-btn');
              if (!nextBtn || !form.contains(nextBtn)) {
                return;
              }
              var currentCard = nextBtn.closest('.mel-builder-card');
              var targetCard = melNextPrimaryFlowBuilderCard(form, currentCard);
              if (!targetCard || !form.contains(targetCard)) {
                return;
              }
              e.preventDefault();
              melSetActiveBuilderCard(form, targetCard);
              melScrollToBuilderCard(form, targetCard);
            },
            false,
          );
        }

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
          function (e) {
            setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
            markGovernanceComponentsDirty(form, governanceComponentsForEvent(e));
          },
          true,
        );
        form.addEventListener(
          'change',
          function (e) {
            setFormState(form, 'mel-studio--dirty', Drupal.t('Unsaved changes'));
            markGovernanceComponentsDirty(form, governanceComponentsForEvent(e));
          },
          true,
        );

        var melBuilderShell = form.querySelector('[data-mel-builder="1"]');
        if (melBuilderShell && !melBuilderShell.dataset.melBuilderPrimaryCtaBound) {
          melBuilderShell.dataset.melBuilderPrimaryCtaBound = '1';
          melBuilderShell.addEventListener('click', function (e) {
            var primaryCta = e.target.closest('#mel-builder-primary-cta');
            if (!primaryCta || !melBuilderShell.contains(primaryCta)) {
              return;
            }
            e.preventDefault();
            syncAiControlsToForm(form);
            var workflowHref = primaryCta.getAttribute('data-mel-primary-cta-href');
            if (workflowHref) {
              window.location.href = workflowHref;
              return;
            }
            var jumpName = primaryCta.getAttribute('data-mel-insight-target');
            if (jumpName) {
              melJumpToField(form, jumpName);
            } else {
              melScrollToSelector('#mel-step-publish');
            }
          });
        }

        if (!form.dataset.melBuilderValidationUiBound) {
          form.dataset.melBuilderValidationUiBound = '1';
          form.addEventListener(
            'invalid',
            function (e) {
              var t = e.target;
              if (!form.contains(t) || !t.closest) {
                return;
              }
              var fi = t.closest('.form-item, .js-form-item');
              if (fi) {
                fi.classList.add('mel-builder-invalid');
              }
            },
            true,
          );
          form.addEventListener(
            'input',
            function (e) {
              var t = e.target;
              if (!t.closest) {
                return;
              }
              var fi = t.closest('.form-item.mel-builder-invalid, .js-form-item.mel-builder-invalid');
              if (fi && form.contains(fi)) {
                fi.classList.remove('mel-builder-invalid');
              }
            },
            true,
          );
        }

        var saveDraftBtn = document.getElementById('mel-save-draft-studio');
        if (saveDraftBtn && !saveDraftBtn.dataset.melSaveDraftBound) {
          saveDraftBtn.dataset.melSaveDraftBound = '1';
          saveDraftBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var sub = form.querySelector('.mel-builder-save-submit');
            if (sub && typeof sub.click === 'function') {
              sub.click();
            }
          });
        }

        var sidebarSaveBtn = document.getElementById('mel-builder-save-sidebar');
        if (sidebarSaveBtn && form.contains(sidebarSaveBtn) && !sidebarSaveBtn.dataset.melSidebarSaveBound) {
          sidebarSaveBtn.dataset.melSidebarSaveBound = '1';
          sidebarSaveBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var sub = form.querySelector('.mel-builder-save-submit');
            if (sub && typeof sub.click === 'function') {
              sub.click();
            }
          });
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

        if (form.closest('[data-mel-studio-shell]')) {
          return;
        }

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
              if (Drupal.melEventStudioHighlights && typeof Drupal.melEventStudioHighlights.forceSyncBeforeSubmit === 'function') {
                Drupal.melEventStudioHighlights.forceSyncBeforeSubmit(form);
              }
              syncTicketTiersFromDomToHidden(form);
              var body = new FormData(form);
              var autosaveTs = Date.now();
              body.append('mel_autosave_ts', String(autosaveTs));
              melGetCsrfToken()
                .then(function (token) {
                  return fetch(url, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: {
                      Accept: 'application/json',
                      'X-CSRF-Token': token,
                    },
                  });
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
                  if (result.data.governance_urls && typeof result.data.governance_urls === 'object') {
                    updateGovernanceUrls(form, result.data.governance_urls);
                  }
                  var governanceComponents = consumeGovernanceComponents(form);
                  fetchGovernanceIncrementalRefresh(form)
                    .then(function () {
                      return fetchGovernanceComponents(form, governanceComponents);
                    })
                    .finally(function () {
                      refreshIntelligence(form);
                    });
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
          markGovernanceComponentsDirty(form, ['workflow', 'state']);
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