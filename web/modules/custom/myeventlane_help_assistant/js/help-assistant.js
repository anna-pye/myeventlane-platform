(function (Drupal, once) {
  'use strict';

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function confidenceLabel(level) {
    const v = (level || 'low').toLowerCase();
    if (v === 'high') {
      return { label: 'High match', className: 'mel-help-assistant__confidence--high' };
    }
    if (v === 'medium') {
      return { label: 'Moderate match', className: 'mel-help-assistant__confidence--medium' };
    }
    return { label: 'Low match', className: 'mel-help-assistant__confidence--low' };
  }

  function renderResponse(container, payload) {
    const answer = escapeHtml(payload.answer || '');
    const conf = confidenceLabel(payload.confidence);
    const message = escapeHtml(payload.message || '');
    const articles = Array.isArray(payload.articles) ? payload.articles : [];
    const hideChat = Boolean(payload.hide_chat_expectations);
    const status = payload.status || '';

    let linksHtml = '';
    if (articles.length > 0) {
      const items = articles
        .map((article) => {
          const title = escapeHtml(article.title || '');
          const url = escapeHtml(article.url || '');
          if (!title || !url) {
            return '';
          }
          return '<li><a href="' + url + '">' + title + '</a></li>';
        })
        .filter(Boolean)
        .join('');
      linksHtml = items
        ? '<div class="mel-help-assistant__sources"><h4 class="mel-help-assistant__sources-title">Based on these articles</h4><ul class="mel-help-assistant__sources-list">' +
          items +
          '</ul></div>'
        : '';
    }

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
      '<div class="mel-help-assistant__answer-block"><p class="mel-help-assistant__answer">' +
      answer +
      '</p></div>' +
      (status === 'ok' || status === 'fallback' || status === 'ai_disabled' ? confHtml : '') +
      linksHtml +
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
        intro.textContent = Drupal.t('Ask a question and get an answer grounded in Help Centre articles.');
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
        const defaultIntro = wrapper.querySelector('.mel-help-assistant__intro')?.textContent?.trim() || '';

        if (!form || !questionField || !responseContainer || !submitButton) {
          return;
        }

        form.addEventListener('submit', async (event) => {
          event.preventDefault();
          const question = (questionField.value || '').trim();
          if (!question) {
            renderResponse(responseContainer, {
              answer: Drupal.t('Please enter a question.'),
              confidence: 'low',
              articles: [],
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
              body: JSON.stringify({ question }),
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
              escalation_recommended: true,
              message: Drupal.t('Please contact support while we resolve this issue.'),
            });
          } finally {
            submitButton.disabled = false;
            submitButton.textContent = Drupal.t('Ask a question');
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
