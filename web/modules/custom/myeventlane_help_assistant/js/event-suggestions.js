/**
 * @file
 * MEL Event Wizard: quality score + grouped suggestions (debounced, non-blocking).
 */
(function (Drupal, once) {
  'use strict';

  const DEBOUNCE_MS = 400;
  const STORAGE_PREFIX = 'mel-sug-dismiss-';

  function storageKey(eventId) {
    return STORAGE_PREFIX + (eventId || '0');
  }

  function getDismissed(eventId) {
    try {
      const raw = localStorage.getItem(storageKey(eventId));
      if (!raw) {
        return [];
      }
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function setDismissed(eventId, ids) {
    try {
      localStorage.setItem(storageKey(eventId), JSON.stringify(ids));
    } catch (e) {
      // Private mode or quota; ignore.
    }
  }

  /**
   * @param {HTMLFormElement} form
   * @param {string[]} names
   * @returns {string}
   */
  function readNamedValue(form, names) {
    for (let i = 0; i < names.length; i += 1) {
      const el = form.elements.namedItem(names[i]);
      if (el && 'value' in el && !el.disabled) {
        return String(el.value ?? '');
      }
    }
    return '';
  }

  /**
   * @param {HTMLFormElement} form
   * @returns {number[]}
   */
  function collectTicketPrices(form) {
    const inputs = form.querySelectorAll('input[name*="field_ticket_price"]');
    const prices = [];
    inputs.forEach((input) => {
      if (input.disabled) {
        return;
      }
      const v = String(input.value ?? '').trim();
      if (v !== '' && !Number.isNaN(Number(v))) {
        prices.push(Number(v));
      }
    });
    return prices;
  }

  /**
   * @param {HTMLFormElement} form
   * @returns {boolean}
   */
  function hasLocationValue(form) {
    const inputs = form.querySelectorAll('input[name^="field_location"], select[name^="field_location"]');
    for (let i = 0; i < inputs.length; i += 1) {
      const el = inputs[i];
      if (el.disabled) {
        continue;
      }
      const v = String(el.value ?? '').trim();
      if (v !== '') {
        return true;
      }
    }
    return false;
  }

  /**
   * @param {HTMLFormElement} form
   * @returns {boolean}
   */
  function hasEventStartValue(form) {
    const els = form.querySelectorAll('[name*="field_event_start"]');
    for (let i = 0; i < els.length; i += 1) {
      const el = els[i];
      if (el.disabled) {
        continue;
      }
      const v = String(el.value ?? '').trim();
      if (v !== '') {
        return true;
      }
    }
    return false;
  }

  /**
   * @param {HTMLFormElement} form
   * @param {string|number|null} eventId
   * @param {string} wizardStep
   * @returns {object}
   */
  function buildPayload(form, eventId, wizardStep) {
    const payload = {
      title: readNamedValue(form, ['title[0][value]', 'title']),
      body: readNamedValue(form, ['body[0][value]', 'body']),
      field_event_intro: readNamedValue(form, [
        'field_event_intro[0][value]',
        'field_event_intro',
      ]),
      field_event_type: readNamedValue(form, [
        'field_event_type[0][value]',
        'field_event_type',
      ]),
      field_capacity: readNamedValue(form, [
        'field_capacity[0][value]',
        'field_capacity',
      ]),
      ticket_prices: collectTicketPrices(form),
      field_event_start: readNamedValue(form, [
        'field_event_start[0][value][date]',
        'field_event_start[0][value]',
      ]),
      field_event_start_has_value: hasEventStartValue(form),
      field_location_has_value: hasLocationValue(form),
      wizard_step: wizardStep || '',
    };
    if (eventId !== null && eventId !== undefined && String(eventId) !== '') {
      payload.event_id = Number(eventId);
    }
    return payload;
  }

  /**
   * @param {string} text
   * @returns {string}
   */
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  const GROUP_HEADINGS = {
    critical: Drupal.t('Improve your setup'),
    revenue: Drupal.t('Increase ticket sales'),
    tips: Drupal.t('Helpful tips'),
  };

  /**
   * @param {HTMLElement} root
   * @param {string} state
   */
  function setPanelState(root, state) {
    root.classList.remove(
      'mel-ai-panel--loading',
      'mel-ai-panel--ready',
      'mel-ai-panel--good',
      'mel-ai-panel--error',
      'mel-ai-panel--empty'
    );
    if (state) {
      root.classList.add(`mel-ai-panel--${state}`);
    }
  }

  /**
   * @param {number|string} score
   * @returns {number}
   */
  function clampScore(score) {
    const n = Number(score);
    if (Number.isNaN(n)) {
      return 0;
    }
    return Math.max(0, Math.min(100, Math.round(n)));
  }

  /**
   * @param {HTMLElement} root
   * @param {HTMLElement} bodyEl
   * @param {object} data
   * @param {Array} suggestionsAll
   * @param {string|number|null} eventId
   */
  function renderPanelBody(root, bodyEl, data, suggestionsAll, eventId) {
    const score = clampScore(data.score ?? 0);
    const label = escapeHtml(String(data.score_label ?? ''));
    const summary = escapeHtml(String(data.score_summary ?? ''));
    const dismissed = new Set(getDismissed(eventId));
    const suggestions = suggestionsAll.filter((s) => s && s.id && !dismissed.has(s.id));

    const srScore = Drupal.t('Event quality score: @pct percent. @label.', {
      '@pct': String(score),
      '@label': String(data.score_label ?? ''),
    });

    const ringStyle = `--mel-score-pct: ${String(score)}`;
    let html = '';
    html += '<div class="mel-ai-panel__top">';
    html += '<div class="mel-ai-score">';
    html += `<div class="mel-ai-score__ring" style="${ringStyle}" role="img" aria-hidden="true">`;
    html += `<span class="mel-ai-score__value">${escapeHtml(String(score))}%</span>`;
    html += '</div>';
    html += '<div class="mel-ai-score__meta">';
    html += `<h3 class="mel-ai-score__title">${Drupal.t('Event quality score')}</h3>`;
    html += `<p class="mel-ai-score__label" aria-hidden="true">${label}</p>`;
    html += `<p class="mel-ai-score__summary">${summary}</p>`;
    html += `<p class="visually-hidden">${escapeHtml(srScore)}</p>`;
    html += '</div></div></div>';

    html += `<div class="mel-ai-progress" aria-hidden="true"><span class="mel-ai-progress__bar" style="width: ${String(score)}%"></span></div>`;

    html += '<div class="mel-ai-panel__groups">';

    const groups = data.groups && typeof data.groups === 'object' ? data.groups : {};
    const order = ['critical', 'revenue', 'tips'];
    let cardCount = 0;

    order.forEach((gkey) => {
      if (cardCount >= 3) {
        return;
      }
      const items = Array.isArray(groups[gkey]) ? groups[gkey] : [];
      const visible = items.filter((s) => s && s.id && !dismissed.has(s.id));
      if (!visible.length) {
        return;
      }
      const heading = GROUP_HEADINGS[gkey] || gkey;
      const sectionClass = `mel-ai-group mel-ai-group--${escapeHtml(gkey)}`;
      html += `<section class="${sectionClass}" aria-label="${escapeHtml(heading)}">`;
      html += `<h4 class="mel-ai-group__title">${escapeHtml(heading)}</h4>`;
      html += '<div class="mel-ai-group__cards">';
      visible.forEach((s) => {
        if (cardCount >= 3) {
          return;
        }
        cardCount += 1;
        const type = ['warning', 'success', 'info'].includes(s.type) ? s.type : 'info';
        const title = escapeHtml(String(s.title ?? ''));
        const msg = escapeHtml(String(s.message ?? ''));
        const cta =
          s.cta && s.cta.url && s.cta.label
            ? `<a class="mel-ai-suggestion-card__cta" href="${escapeHtml(s.cta.url)}">${escapeHtml(s.cta.label)}</a>`
            : '';
        const icon =
          type === 'warning' ? '!' : type === 'success' ? '✓' : '◆';
        html += `<article class="mel-ai-suggestion-card mel-ai-suggestion-card--${type}" data-suggestion-id="${escapeHtml(String(s.id))}">`;
        html += `<div class="mel-ai-suggestion-card__icon" aria-hidden="true">${icon}</div>`;
        html += '<div class="mel-ai-suggestion-card__content">';
        html += `<h5 class="mel-ai-suggestion-card__title">${title}</h5>`;
        html += `<p class="mel-ai-suggestion-card__text">${msg}</p>`;
        if (cta) {
          html += `<div class="mel-ai-suggestion-card__cta-wrap">${cta}</div>`;
        }
        html += '</div>';
        html += `<button type="button" class="mel-ai-suggestion-card__dismiss" aria-label="${Drupal.t('Dismiss')}" data-dismiss-id="${escapeHtml(String(s.id))}">×</button>`;
        html += '</article>';
      });
      html += '</div></section>';
    });

    if (cardCount === 0) {
      const goodMsg = Drupal.t('No changes needed right now — your setup looks solid.');
      html += `<section class="mel-ai-group mel-ai-group--good-state" aria-label="${Drupal.t('Status')}">`;
      html += `<p class="mel-ai-panel__good-msg">${escapeHtml(goodMsg)}</p>`;
      html += '</section>';
    }

    html += '</div>';

    bodyEl.innerHTML = html;

    if (cardCount === 0) {
      root.classList.add('mel-ai-panel--good');
    } else {
      root.classList.remove('mel-ai-panel--good');
    }

    bodyEl.querySelectorAll('[data-dismiss-id]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const sid = btn.getAttribute('data-dismiss-id');
        if (!sid) {
          return;
        }
        const next = getDismissed(eventId).concat([sid]);
        setDismissed(eventId, next);
        renderPanelBody(root, bodyEl, data, suggestionsAll, eventId);
      });
    });
  }

  /**
   * @param {HTMLElement} bodyEl
   * @param {string} message
   */
  function renderErrorBody(bodyEl, message) {
    bodyEl.innerHTML = `<div class="mel-ai-panel__error" role="status"><p class="mel-ai-panel__error-text">${escapeHtml(message)}</p></div>`;
  }

  /**
   * @param {Document|HTMLElement} context
   */
  function attachBehavior(context) {
    once('mel-event-suggestions', '#mel-ai-suggestions-root', context).forEach((root) => {
      const settings = drupalSettings.melEventSuggestions || {};
      const endpoint = settings.endpoint || '/mel/event-suggestions';
      const csrfToken = settings.csrfToken || '';
      const eventId = settings.eventId ?? null;
      const wizardStep = settings.wizardStep || '';

      const bodyEl = root.querySelector('#mel-ai-suggestions-panel');
      const form = root.closest('form');
      if (!bodyEl || !form || !csrfToken) {
        return;
      }

      let timer = null;
      let seq = 0;
      let abortCtl = null;
      let lastData = null;
      let firstRequest = true;

      const setLoadingUi = () => {
        setPanelState(root, 'loading');
        bodyEl.setAttribute('aria-busy', 'true');
        const loadingText = firstRequest
          ? Drupal.t('Checking your event setup…')
          : Drupal.t('Updating suggestions…');
        firstRequest = false;
        bodyEl.innerHTML = `<p class="mel-ai-panel__loading-text">${escapeHtml(loadingText)}</p>`;
      };

      const applyResponse = (payload, suggestionsSource) => {
        lastData = payload;
        setPanelState(root, 'ready');
        bodyEl.removeAttribute('aria-busy');
        renderPanelBody(root, bodyEl, payload, suggestionsSource, eventId);
      };

      const requestUpdate = () => {
        if (timer) {
          clearTimeout(timer);
        }
        timer = window.setTimeout(() => {
          timer = null;
          setLoadingUi();
          const mySeq = ++seq;
          if (abortCtl) {
            abortCtl.abort();
          }
          abortCtl = new AbortController();
          const payload = JSON.stringify(buildPayload(form, eventId, wizardStep));
          fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrfToken,
              Accept: 'application/json',
            },
            body: payload,
            signal: abortCtl.signal,
          })
            .then((res) => {
              if (mySeq !== seq) {
                return null;
              }
              if (res.status === 429) {
                return { __error: 'rate', suggestions: [], score: lastData ? lastData.score : 0 };
              }
              if (!res.ok) {
                return { __error: 'http', suggestions: [] };
              }
              return res.json();
            })
            .then((data) => {
              if (mySeq !== seq || !data) {
                return;
              }
              if (data.__error) {
                setPanelState(root, 'error');
                bodyEl.removeAttribute('aria-busy');
                const calm = Drupal.t(
                  "We couldn’t refresh suggestions just now. You can keep editing and try again in a moment.",
                );
                renderErrorBody(bodyEl, calm);
                return;
              }
              const list = Array.isArray(data.suggestions) ? data.suggestions : [];
              applyResponse(data, list);
            })
            .catch((err) => {
              if (err.name === 'AbortError') {
                return;
              }
              if (mySeq !== seq) {
                return;
              }
              setPanelState(root, 'error');
              bodyEl.removeAttribute('aria-busy');
              const calm = Drupal.t(
                "We couldn’t refresh suggestions just now. You can keep editing and try again in a moment.",
              );
              renderErrorBody(bodyEl, calm);
            });
        }, DEBOUNCE_MS);
      };

      const selector =
        'input[name*="title"], textarea[name*="body"], select[name*="field_event_type"], input[name*="field_event_type"], input[name*="field_capacity"], input[name*="field_ticket_price"], textarea[name*="field_event_intro"], input[name*="field_event_start"], input[name^="field_location"], select[name^="field_location"]';
      form.querySelectorAll(selector).forEach((el) => {
        el.addEventListener('input', requestUpdate);
        el.addEventListener('change', requestUpdate);
      });

      requestUpdate();
    });
  }

  Drupal.behaviors.melEventSuggestions = {
    attach(context) {
      attachBehavior(context);
    },
  };
})(Drupal, once);
