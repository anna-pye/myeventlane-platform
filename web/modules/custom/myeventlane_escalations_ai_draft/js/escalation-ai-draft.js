/**
 * @file
 * Staff escalation AI draft: button, modal, insert into reply textarea.
 */

(function (Drupal, drupalSettings, fetch) {
  'use strict';

  /**
   * Selectors for reply textarea. Tried in order.
   * Admin escalation uses Drupal comment form: edit-comment-body-0-value
   * EscalationReplyForm (vendor/customer): edit-message
   */
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

        if (data.ok && data.draft) {
          showModal(data.draft, () => {
            const textarea = findReplyTextarea();
            if (textarea) {
              const before = textarea.value;
              const after = before ? before + '\n\n' + data.draft : data.draft;
              textarea.value = after;
              textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }
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
