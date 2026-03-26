(function (Drupal, once, drupalSettings) {
  'use strict';

  /**
   * @param {string} text
   * @returns {string}
   */
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * @param {string} text
   * @returns {string}
   */
  function escapeAttr(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  /**
   * @param {object} payload
   * @param {string} helpHomeUrl
   * @returns {string}
   */
  function renderAiPanel(payload, helpHomeUrl) {
    const answer = escapeHtml(payload.answer || '');
    const steps = Array.isArray(payload.steps) ? payload.steps : [];
    const actions = Array.isArray(payload.actions) ? payload.actions : [];
    const sources = Array.isArray(payload.sources)
      ? payload.sources
      : Array.isArray(payload.articles)
        ? payload.articles
        : [];
    const followups = Array.isArray(payload.followups) ? payload.followups : [];
    const contextDisplay =
      payload.context_display && typeof payload.context_display === 'object' ? payload.context_display : {};

    const mergedActions = actions.slice();
    if (mergedActions.length === 0) {
      mergedActions.push({
        label: Drupal.t('Browse the Help Centre'),
        url: helpHomeUrl || '/help',
      });
    }

    let html = '<div class="mel-support-ai__inner">';

    if (contextDisplay.event_title) {
      html +=
        '<div class="mel-support-ai__context"><span>' +
        escapeHtml(Drupal.t('Helping with:')) +
        ' ' +
        escapeHtml(String(contextDisplay.event_title)) +
        '</span></div>';
    }

    if (answer) {
      html += '<div class="mel-support-ai__answer"><p>' + answer + '</p></div>';
    }

    if (steps.length) {
      html +=
        '<div class="mel-support-ai__steps"><strong>' +
        escapeHtml(Drupal.t('What to do next')) +
        '</strong><ol>';
      steps.forEach((step) => {
        html += '<li>' + escapeHtml(String(step)) + '</li>';
      });
      html += '</ol></div>';
    }

    html += '<div class="mel-support-ai__actions"><strong>' + escapeHtml(Drupal.t('Actions')) + '</strong><div class="mel-support-ai__action-row">';
    mergedActions.forEach((a) => {
      const label = escapeHtml(a.label || '');
      const url = escapeHtml(a.url || '');
      if (!label || !url) {
        return;
      }
      html += '<a class="mel-support-ai__button" href="' + url + '">' + label + '</a>';
    });
    html += '</div></div>';

    if (sources.length) {
      html +=
        '<div class="mel-support-ai__sources"><span>' +
        escapeHtml(Drupal.t('Sources')) +
        '</span><ul>';
      sources.forEach((s) => {
        const title = escapeHtml(s.title || '');
        const url = escapeHtml(s.url || '');
        if (!title || !url) {
          return;
        }
        html += '<li><a href="' + url + '">' + title + '</a></li>';
      });
      html += '</ul></div>';
    }

    if (followups.length) {
      html +=
        '<div class="mel-support-ai__followups"><span>' +
        escapeHtml(Drupal.t('Try asking')) +
        '</span><div class="mel-support-ai__chips">';
      followups.forEach((q) => {
        const text = String(q);
        html +=
          '<button type="button" class="mel-support-ai__chip" data-question="' +
          escapeAttr(text) +
          '">' +
          escapeHtml(text) +
          '</button>';
      });
      html += '</div></div>';
    }

    const conf = String(payload.confidence || 'low').toLowerCase();
    html +=
      '<p class="mel-support-ai__confidence mel-support-ai__confidence--' +
      escapeAttr(conf) +
      '" data-confidence="' +
      escapeAttr(conf) +
      '"><span class="mel-support-ai__confidence-label">' +
      escapeHtml(Drupal.t('Match confidence: @c', { '@c': conf })) +
      '</span></p>';

    if (payload.message) {
      html += '<p class="mel-support-ai__message">' + escapeHtml(String(payload.message)) + '</p>';
    }

    html += '</div>';
    return html;
  }

  /**
   * @param {object} payload
   * @param {Array} searchResults
   * @returns {boolean}
   */
  function shouldShowContact(payload, searchResults) {
    const conf = String(payload.confidence || 'low').toLowerCase();
    const status = String(payload.status || '');
    const noHits = !Array.isArray(searchResults) || searchResults.length === 0;
    const badStatus = status === 'error' || status === 'disabled';
    return noHits || conf === 'low' || badStatus;
  }

  Drupal.behaviors.myeventlaneSupportHub = {
    attach(context) {
      once('mel-support-hub', '.mel-support', context).forEach((root) => {
        const input = root.querySelector('#mel-support-input');
        const ai = root.querySelector('#mel-support-ai');
        const results = root.querySelector('#mel-support-results');
        const contact = root.querySelector('#mel-support-contact');
        if (!input || !ai || !results || !contact) {
          return;
        }

        const settings = drupalSettings.myeventlaneSupport || {};
        const searchEndpoint = settings.searchEndpoint || '/support/search-api';
        const assistantEndpoint = settings.assistantEndpoint || '/help/assistant';
        const helpHomeUrl = settings.helpHomeUrl || '/help';
        const pageContext = settings.pageContext && typeof settings.pageContext === 'object' ? settings.pageContext : {};

        let timeoutId = 0;
        let requestGeneration = 0;

        root.addEventListener('click', (e) => {
          const chip = e.target.closest('.mel-support-ai__chip[data-question]');
          if (!chip || !root.contains(chip)) {
            return;
          }
          e.preventDefault();
          const q = chip.getAttribute('data-question') || '';
          if (q) {
            input.value = q;
            input.dispatchEvent(new Event('keyup', { bubbles: true }));
          }
        });

        input.addEventListener('keyup', () => {
          window.clearTimeout(timeoutId);
          const query = input.value.trim();
          if (!query) {
            ai.classList.add('mel-support-panel--hidden');
            results.classList.add('mel-support-panel--hidden');
            contact.classList.add('mel-support-panel--hidden');
            ai.innerHTML = '';
            results.innerHTML = '';
            return;
          }

          timeoutId = window.setTimeout(() => {
            const generation = ++requestGeneration;
            ai.classList.remove('mel-support-panel--hidden');
            results.classList.remove('mel-support-panel--hidden');
            ai.innerHTML =
              '<p class="mel-support-ai__loading" aria-live="polite">' + escapeHtml(Drupal.t('Thinking…')) + '</p>';

            let searchResults = [];
            let aiPayload = {
              answer: '',
              confidence: 'low',
              status: 'error',
              escalation_recommended: true,
              sources: [],
              articles: [],
              steps: [],
              actions: [],
              followups: [],
            };

            const searchPromise = fetch(searchEndpoint + '?q=' + encodeURIComponent(query), {
              credentials: 'same-origin',
              headers: { Accept: 'application/json' },
            })
              .then((r) => r.json())
              .then((data) => {
                if (generation !== requestGeneration) {
                  return;
                }
                searchResults = Array.isArray(data.results) ? data.results : [];
                if (searchResults.length) {
                  results.innerHTML = searchResults
                    .map(
                      (r) =>
                        '<div class="mel-support-result"><a href="' +
                        escapeAttr(r.url || '#') +
                        '">' +
                        escapeHtml(r.title || '') +
                        '</a></div>',
                    )
                    .join('');
                } else {
                  results.innerHTML =
                    '<p class="mel-support-results__empty">' +
                    escapeHtml(Drupal.t('No extra article matches in search.')) +
                    '</p>';
                }
              })
              .catch(() => {
                if (generation !== requestGeneration) {
                  return;
                }
                searchResults = [];
                results.innerHTML =
                  '<p class="mel-support-results__empty">' +
                  escapeHtml(Drupal.t('Search is temporarily unavailable.')) +
                  '</p>';
              });

            const aiPromise = fetch(assistantEndpoint, {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
              body: JSON.stringify({ question: query, context: pageContext }),
            })
              .then(async (r) => {
                let body = {};
                try {
                  body = await r.json();
                } catch (e) {
                  body = {};
                }
                return { ok: r.ok, body };
              })
              .then(({ ok, body }) => {
                if (generation !== requestGeneration) {
                  return;
                }
                if (!ok || !body || typeof body !== 'object') {
                  const msg =
                    typeof body.message === 'string' && body.message
                      ? body.message
                      : Drupal.t('The Help Assistant could not complete this request.');
                  aiPayload = {
                    answer: msg,
                    confidence: 'low',
                    status: 'error',
                    escalation_recommended: true,
                    sources: [],
                    articles: [],
                    steps: [],
                    actions: [],
                    followups: [],
                  };
                  return;
                }
                aiPayload = body;
              })
              .catch(() => {
                if (generation !== requestGeneration) {
                  return;
                }
                aiPayload = {
                  answer: Drupal.t('The Help Assistant is temporarily unavailable.'),
                  confidence: 'low',
                  status: 'error',
                  escalation_recommended: true,
                  sources: [],
                  articles: [],
                  steps: [],
                  actions: [],
                  followups: [],
                };
              });

            Promise.all([searchPromise, aiPromise]).then(() => {
              if (generation !== requestGeneration) {
                return;
              }
              ai.innerHTML = renderAiPanel(aiPayload, helpHomeUrl);
              if (shouldShowContact(aiPayload, searchResults)) {
                contact.classList.remove('mel-support-panel--hidden');
              } else {
                contact.classList.add('mel-support-panel--hidden');
              }
            });
          }, 400);
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
