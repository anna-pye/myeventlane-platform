/**
 * @file
 * Staff escalation AI draft: button, modal, insert into reply textarea.
 * Uses async job + polling.
 */

(function (Drupal, drupalSettings, fetch) {
  'use strict';

  const POLL_INTERVAL = 1500;
  const MAX_POLLS = 20;

  const TEXTAREA_SELECTORS = [
    '#mel-reply-textarea',
    '[data-mel-reply-textarea="true"]',
    '#edit-comment-body-0-value',
    '#edit-message',
    'form[data-drupal-selector*="comment"] textarea',
    'form[data-drupal-selector*="reply"] textarea',
    '#myeventlane-escalations-portal-reply-form textarea',
    '.mel-escalation-reply textarea',
  ];

  function findReplyTextarea() {
    for (const sel of TEXTAREA_SELECTORS) {
      const el = document.querySelector(sel);
      if (el) return el;
    }
    return null;
  }

  function getEscalationId() {
    const root = document.getElementById('mel-ai-draft-root');
    return root ? root.getAttribute('data-escalation-id') : null;
  }

  function getDraftUrl(escalationId) {
    return `/admin/myeventlane/escalations/${escalationId}/ai/instant-draft`;
  }

  function getPollUrl(jobId) {
    return `/ai/job/${jobId}`;
  }

  function showModal(draft, onInsert) {
    const id = 'mel-ai-draft-modal';
    let dialog = document.getElementById(id);
    if (!dialog) {
      dialog = document.createElement('div');
      dialog.id = id;
      dialog.setAttribute('role', 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.setAttribute('aria-labelledby', 'mel-ai-draft-title');
      dialog.className = 'mel-ai-draft-modal';
      dialog.innerHTML = `
        <div class="mel-ai-draft-modal__backdrop" data-close></div>
        <div class="mel-ai-draft-modal__content">
          <h2 id="mel-ai-draft-title" class="mel-ai-draft-modal__title">Draft response</h2>
          <div class="mel-ai-draft-modal__body">
            <pre id="mel-ai-draft-text" class="mel-ai-draft-modal__text"></pre>
          </div>
          <div class="mel-ai-draft-modal__actions">
            <button type="button" class="button button--primary mel-ai-draft-modal__insert">Insert into reply</button>
            <button type="button" class="button mel-ai-draft-modal__close">Close</button>
          </div>
        </div>
      `;
      document.body.appendChild(dialog);

      const close = () => {
        dialog.classList.remove('is-open');
        dialog.setAttribute('aria-hidden', 'true');
      };

      dialog.querySelector('[data-close]').addEventListener('click', close);
      dialog.querySelector('.mel-ai-draft-modal__close').addEventListener('click', close);
      dialog.querySelector('.mel-ai-draft-modal__insert').addEventListener('click', () => {
        onInsert();
        close();
      });

      dialog.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') close();
      });
    }

    document.getElementById('mel-ai-draft-text').textContent = draft;
    dialog.classList.add('is-open');
    dialog.removeAttribute('aria-hidden');
    dialog.querySelector('.mel-ai-draft-modal__insert').focus();
  }

  function pollForDraft(jobId, onDone, onError) {
    let attempts = 0;

    const tick = () => {
      attempts += 1;
      if (attempts > MAX_POLLS) {
        onError(Drupal.t('Request timed out. Please try again.'));
        return;
      }

      fetch(getPollUrl(jobId), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
      })
        .then((res) => res.json())
        .then((data) => {
          if (data.status === 'done') {
            onDone(data.result_text || '');
          } else if (data.status === 'error') {
            onError(data.error_message || Drupal.t('Could not generate draft.'));
          } else {
            setTimeout(tick, POLL_INTERVAL);
          }
        })
        .catch(() => {
          if (attempts > MAX_POLLS) {
            onError(Drupal.t('Request failed. Please try again.'));
          } else {
            setTimeout(tick, POLL_INTERVAL);
          }
        });
    };

    setTimeout(tick, POLL_INTERVAL);
  }

  function init() {
    const root = document.getElementById('mel-ai-draft-root');
    if (!root) return;

    const escalationId = getEscalationId();
    if (!escalationId) return;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'button button--small mel-ai-draft__trigger';
    btn.textContent = Drupal.t('Draft response');
    btn.setAttribute('aria-label', Drupal.t('Generate AI draft reply'));

    btn.addEventListener('click', async () => {
      btn.disabled = true;
      btn.textContent = Drupal.t('Generating…');

      try {
        const res = await fetch(getDraftUrl(escalationId), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
        });

        const data = await res.json();

        if (data.ok && data.job_id) {
          pollForDraft(data.job_id, (draft) => {
            showModal(draft, () => {
              const textarea = findReplyTextarea();
              if (textarea) {
                const before = textarea.value;
                const after = before ? before + '\n\n' + draft : draft;
                textarea.value = after;
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
              }
            });
          }, (err) => {
            alert(err);
          });
        } else {
          alert(data.error || Drupal.t('Could not generate draft. Please try again.'));
        }
      } catch (e) {
        alert(Drupal.t('Request failed. Please try again.'));
      } finally {
        btn.disabled = false;
        btn.textContent = Drupal.t('Draft response');
      }
    });

    root.appendChild(btn);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})(Drupal, drupalSettings, window.fetch);
