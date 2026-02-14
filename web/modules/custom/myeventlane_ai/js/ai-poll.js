/**
 * @file
 * Polls AI job status and injects result when done.
 * Used by Vendor AI and Help Centre AI forms.
 */

(function (Drupal) {
  'use strict';

  const POLL_INTERVAL = 1500;
  const MAX_POLLS = 20;

  function findAnswerContainer(container) {
    const parent = container.closest('.mel-ai-panel__result, .help-centre-ai-result, [class*="result"]');
    return parent ? parent.querySelector('.mel-ai-panel__answer, .help-centre-ai-result__answer, [class*="answer"]') : null;
  }

  function poll() {
    const container = document.querySelector('[data-job-id][data-poll-url]');
    if (!container) return;

    const jobId = container.getAttribute('data-job-id');
    const pollUrl = container.getAttribute('data-poll-url');
    if (!jobId || !pollUrl) return;

    let attempts = 0;

    const tick = () => {
      attempts += 1;
      if (attempts > MAX_POLLS) {
        const refreshLink = '<a href="#" class="mel-ai-poll-refresh" onclick="location.reload(); return false;">' +
          Drupal.t('Check again') + '</a>';
        container.innerHTML = '<p>' + Drupal.formatString(
          Drupal.t('Still working. !link to see if your result is ready.'),
          { '!link': refreshLink }
        ) + '</p>';
        return;
      }

      fetch(pollUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
      })
        .then((res) => res.json())
        .then((data) => {
          if (data.status === 'done') {
            container.setAttribute('hidden', 'true');
            container.setAttribute('aria-hidden', 'true');
            const answerEl = findAnswerContainer(container);
            if (answerEl) {
              answerEl.innerHTML = '<div class="mel-ai-panel__answer-text">' +
                (data.result_text ? data.result_text.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>') : '') +
                '</div>';
              answerEl.removeAttribute('hidden');
              answerEl.removeAttribute('aria-hidden');
            }
          } else if (data.status === 'error') {
            container.innerHTML = '<p class="mel-ai-panel__error">' +
              (data.error_message ? data.error_message.replace(/</g, '&lt;') : Drupal.t('An error occurred.')) +
              '</p>';
          } else {
            setTimeout(tick, POLL_INTERVAL);
          }
        })
        .catch(() => {
          if (attempts > MAX_POLLS) {
            const refreshLink = '<a href="#" class="mel-ai-poll-refresh" onclick="location.reload(); return false;">' +
              Drupal.t('Check again') + '</a>';
            container.innerHTML = '<p>' + Drupal.formatString(
              Drupal.t('Request failed. !link to try again.'),
              { '!link': refreshLink }
            ) + '</p>';
          } else {
            setTimeout(tick, POLL_INTERVAL);
          }
        });
    };

    setTimeout(tick, POLL_INTERVAL);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', poll);
  } else {
    poll();
  }
})(Drupal);
