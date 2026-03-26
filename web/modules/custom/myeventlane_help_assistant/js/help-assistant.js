(function (Drupal, once) {
  'use strict';

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function escapeAttr(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function confidenceLabel(level) {
    const v = (level || 'low').toLowerCase();
    if (v === 'high') {
      return { label: Drupal.t('High match'), className: 'mel-help-assistant__confidence--high' };
    }
    if (v === 'medium') {
      return { label: Drupal.t('Moderate match'), className: 'mel-help-assistant__confidence--medium' };
    }
    return { label: Drupal.t('Low match'), className: 'mel-help-assistant__confidence--low' };
  }

  /**
   * Builds structured MEL AI response markup (answer, steps, actions, sources, follow-ups).
   *
   * @param {object} payload
   *   JSON from /help/assistant.
   *
   * @return {string}
   *   HTML string.
   */
  function renderStructuredResponse(payload) {
    const answer = escapeHtml(payload.answer || '');
    const steps = Array.isArray(payload.steps) ? payload.steps : [];
    const actions = Array.isArray(payload.actions) ? payload.actions : [];
    const sources = Array.isArray(payload.sources)
      ? payload.sources
      : Array.isArray(payload.articles)
        ? payload.articles
        : [];
    const followups = Array.isArray(payload.followups) ? payload.followups : [];
    const contextDisplay = payload.context_display && typeof payload.context_display === 'object' ? payload.context_display : {};

    let html = '<div class="mel-ai-response">';

    if (contextDisplay.event_title) {
      html +=
        '<div class="mel-ai-context"><span>' +
        escapeHtml(Drupal.t('Helping with:')) +
        ' ' +
        escapeHtml(String(contextDisplay.event_title)) +
        '</span></div>';
    }

    if (answer) {
      html += '<div class="mel-ai-answer"><p>' + answer + '</p></div>';
    }

    if (steps.length) {
      html +=
        '<div class="mel-ai-steps"><strong>' +
        escapeHtml(Drupal.t('What to do next')) +
        '</strong><ol>';
      steps.forEach((step) => {
        html += '<li>' + escapeHtml(String(step)) + '</li>';
      });
      html += '</ol></div>';
    }

    if (actions.length) {
      html += '<div class="mel-ai-actions">';
      actions.forEach((a) => {
        const label = escapeHtml(a.label || '');
        const url = escapeHtml(a.url || '');
        if (!label || !url) {
          return;
        }
        html += '<a href="' + url + '" class="mel-ai-button">' + label + '</a>';
      });
      html += '</div>';
    }

    if (sources.length) {
      html +=
        '<div class="mel-ai-sources"><span>' +
        escapeHtml(Drupal.t('Based on:')) +
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
        '<div class="mel-ai-followups"><span>' +
        escapeHtml(Drupal.t('Try asking:')) +
        '</span><div class="mel-chip-group">';
      followups.forEach((q) => {
        const text = String(q);
        html +=
          '<button type="button" class="mel-chip" data-question="' +
          escapeAttr(text) +
          '">' +
          escapeHtml(text) +
          '</button>';
      });
      html += '</div></div>';
    }

    html += '</div>';
    return html;
  }

  function renderResponse(container, payload) {
    const conf = confidenceLabel(payload.confidence);
    const message = escapeHtml(payload.message || '');
    const hideChat = Boolean(payload.hide_chat_expectations);
    const status = payload.status || '';

    const structuredHtml = renderStructuredResponse(payload);

    const fallbackClass = payload.escalation_recommended ? ' mel-help-assistant__message--fallback' : '';
    const confHtml =
      '<p class="mel-help-assistant__confidence ' +
      conf.className +
      '" data-confidence="' +
      escapeHtml(payload.confidence || 'low') +
      '"><span class="mel-help-assistant__confidence-label">' +
      escapeHtml(conf.label) +
      '</span></p>';

    container.innerHTML =
      structuredHtml +
      (status === 'ok' || status === 'fallback' || status === 'ai_disabled' ? confHtml : '') +
      (message
        ? '<p class="mel-help-assistant__message' + fallbackClass + '">' + message + '</p>'
        : '');
    container.hidden = false;

    const wrapper = container.closest('.js-mel-help-assistant');
    if (wrapper) {
      wrapper.classList.toggle('mel-help-assistant--browse-only', hideChat || status === 'ai_disabled');
      const form = wrapper.querySelector('.js-mel-help-assistant-form');
      const intro = wrapper.querySelector('.mel-help-assistant__intro');
      if (form) {
        form.hidden = hideChat || status === 'ai_disabled';
      }
      if (intro && (hideChat || status === 'ai_disabled')) {
        intro.textContent = Drupal.t('Browse the articles below for guidance from our Help Centre.');
      } else if (intro) {
        intro.textContent = Drupal.t(
          "I'll answer using MyEventLane help content and guide you to the next step.",
        );
      }
    }
  }

  Drupal.behaviors.melHelpAssistant = {
    attach(context) {
      once('mel-help-assistant', '.js-mel-help-assistant', context).forEach((wrapper) => {
        const form = wrapper.querySelector('.js-mel-help-assistant-form');
        const questionField = wrapper.querySelector('.js-mel-help-assistant-question');
        const responseContainer = wrapper.querySelector('.js-mel-help-assistant-response');
        const submitButton = wrapper.querySelector('.js-mel-help-assistant-submit');
        const endpoint = wrapper.getAttribute('data-endpoint') || '/help/assistant';
        const pageContext =
          drupalSettings.myeventlaneHelpAssistant && drupalSettings.myeventlaneHelpAssistant.pageContext
            ? drupalSettings.myeventlaneHelpAssistant.pageContext
            : {};
        const defaultIntro = wrapper.querySelector('.mel-help-assistant__intro')?.textContent?.trim() || '';

        if (!form || !questionField || !responseContainer || !submitButton) {
          return;
        }

        wrapper.addEventListener('click', (e) => {
          const chip = e.target.closest('.mel-chip[data-question]');
          if (!chip || !wrapper.contains(chip)) {
            return;
          }
          e.preventDefault();
          const q = chip.getAttribute('data-question') || '';
          if (q) {
            questionField.value = q;
            questionField.focus();
          }
        });

        once('mel-help-starter', '.js-mel-help-starter', wrapper).forEach((btn) => {
          btn.addEventListener('click', () => {
            const q = btn.getAttribute('data-question') || '';
            questionField.value = q;
            questionField.focus();
          });
        });

        form.addEventListener('submit', async (event) => {
          event.preventDefault();
          const question = (questionField.value || '').trim();
          if (!question) {
            renderResponse(responseContainer, {
              answer: Drupal.t('Please enter a question.'),
              confidence: 'low',
              articles: [],
              sources: [],
              steps: [],
              actions: [],
              followups: [],
              escalation_recommended: true,
            });
            return;
          }

          responseContainer.hidden = false;
          responseContainer.innerHTML =
            '<p class="mel-help-assistant__loading" aria-live="polite">' + Drupal.t('Thinking…') + '</p>';
          submitButton.disabled = true;
          submitButton.textContent = Drupal.t('Thinking…');
          try {
            const response = await fetch(endpoint, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
              },
              credentials: 'same-origin',
              body: JSON.stringify({ question, context: pageContext }),
            });
            const raw = await response.text();
            let payload = {};
            try {
              payload = raw ? JSON.parse(raw) : {};
            } catch (parseErr) {
              payload = {};
            }
            if (!response.ok) {
              renderResponse(responseContainer, {
                answer:
                  (typeof payload.message === 'string' && payload.message) ||
                  (response.status === 429
                    ? Drupal.t('Too many requests. Please wait a little while and try again.')
                    : Drupal.t('The Help Assistant could not complete this request.')),
                confidence: 'low',
                articles: Array.isArray(payload.articles) ? payload.articles : [],
                sources: Array.isArray(payload.sources) ? payload.sources : [],
                steps: [],
                actions: [],
                followups: [],
                escalation_recommended: true,
                message:
                  typeof payload.message === 'string' && payload.message
                    ? ''
                    : Drupal.t('Please try again or contact support if this keeps happening.'),
              });
              return;
            }
            renderResponse(responseContainer, payload);
          } catch (error) {
            renderResponse(responseContainer, {
              answer: Drupal.t('The Help Assistant is temporarily unavailable.'),
              confidence: 'low',
              articles: [],
              sources: [],
              steps: [],
              actions: [],
              followups: [],
              escalation_recommended: true,
              message: Drupal.t('Please contact support while we resolve this issue.'),
            });
          } finally {
            submitButton.disabled = false;
            submitButton.textContent = Drupal.t('Get answer');
            const introEl = wrapper.querySelector('.mel-help-assistant__intro');
            if (introEl && defaultIntro && !wrapper.classList.contains('mel-help-assistant--browse-only')) {
              introEl.textContent = defaultIntro;
            }
          }
        });
      });
    },
  };
})(Drupal, once);
