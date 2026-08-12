(function (Drupal, once, drupalSettings) {
  'use strict';

  function expandReplyShell() {
    const shell = document.querySelector('[data-mel-reply-shell]');
    if (shell) {
      shell.setAttribute('data-expanded', '1');
    }
  }

  function findReplyTextarea(context) {
    // Preferred: explicit MEL markers (set by myeventlane_escalations_ai_draft form_alter).
    let el = context.querySelector('#mel-reply-textarea');
    if (el) return el;

    el = context.querySelector('[data-mel-reply-textarea]');
    if (el) return el;

    // Inside reply shell form wrapper (portal wraps comment form here).
    el = context.querySelector('[data-mel-reply-form] textarea');
    if (el) return el;

    // Common Drupal comment field name (admin comment form).
    el = context.querySelector('textarea[name="comment_body[0][value]"]');
    if (el) return el;

    // Form in reply shell.
    el = context.querySelector('.mel-reply-shell textarea');
    if (el) return el;

    // Last resort: any textarea that looks like a reply/comment field.
    el = context.querySelector('textarea[name*="comment_body"]') ||
      context.querySelector('form[data-drupal-selector*="comment"] textarea') ||
      context.querySelector('textarea');
    return el || null;
  }

  function applyTone(text, tone) {
    if (!text) return text;
    if (tone === 'calm') return text;
    if (tone === 'direct') {
      var firstDot = text.indexOf('.');
      if (firstDot >= 0) {
        return text.slice(firstDot + 1).replace(/^\s+/, '');
      }
      return text;
    }
    if (tone === 'formal') {
      return 'Thank you for your correspondence.\n\n' + text;
    }
    return text;
  }

  Drupal.behaviors.myeventlaneAiAssistant = {
    attach: function (context) {
      once('mel-ai-assistant', '[data-mel-ai-insights]', context).forEach(function (wrap) {
        wrap.setAttribute('data-expanded', '0');

        const toggle = wrap.querySelector('[data-mel-ai-toggle]');
        if (toggle) {
          toggle.addEventListener('click', function () {
            const expanded = wrap.getAttribute('data-expanded') === '1';
            wrap.setAttribute('data-expanded', expanded ? '0' : '1');
            toggle.textContent = expanded ? 'Show previous suggestions' : 'Hide previous suggestions';
          });
        }

        const assistant = wrap.closest('.mel-ai-assistant');
        const drawer = assistant ? assistant.querySelector('[data-mel-ai-drawer]') : null;
        const editor = drawer ? drawer.querySelector('[data-mel-ai-editor]') : null;
        const toneSelect = drawer ? drawer.querySelector('[data-mel-ai-tone]') : null;

        if (!drawer || !editor) return;

        let baseText = '';

        function closeDrawer() {
          if (!drawer) return;
          drawer.setAttribute('hidden', '');
          document.body.classList.remove('mel-ai-drawer-open');
        }

        function openDrawer(draftText) {
          if (!drawer || !editor) return;
          baseText = draftText;
          editor.value = baseText;
          if (toneSelect) toneSelect.value = 'calm';
          drawer.removeAttribute('hidden');
          document.body.classList.add('mel-ai-drawer-open');
        }

        drawer.querySelectorAll('[data-mel-ai-close]').forEach(function (btn) {
          btn.addEventListener('click', closeDrawer);
        });

        if (toneSelect) {
          toneSelect.addEventListener('change', function () {
            var tone = toneSelect.value;
            editor.value = applyTone(baseText, tone);
          });
        }

        drawer.querySelectorAll('[data-mel-ai-apply]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            expandReplyShell();
            var textarea = findReplyTextarea(document);
            if (textarea) {
              textarea.value = editor.value;
              closeDrawer();
              textarea.focus();
            } else {
              alert('Reply box not found. Please paste the text manually into the reply field.');
            }
          });
        });

        wrap.querySelectorAll('[data-mel-ai-open-drawer]').forEach(function (openBtn) {
          openBtn.addEventListener('click', function () {
            var card = openBtn.closest('[data-mel-ai-card]');
            var draftEl = card ? card.querySelector('[data-mel-ai-draft]') : null;
            if (draftEl) {
              openDrawer(draftEl.textContent.trim());
            }
          });
        });
      });

      once('mel-ai-generate', '[data-mel-ai-generate]', context).forEach(function (button) {
        button.addEventListener('click', async function () {
          const url = button.getAttribute('data-mel-ai-generate-url');
          const tokenUrl = drupalSettings.myeventlaneEscalationsAi?.csrfTokenUrl || '/session/token';
          if (!url) return;

          button.disabled = true;
          try {
            const tokenResponse = await fetch(tokenUrl, {credentials: 'same-origin'});
            const token = await tokenResponse.text();
            const response = await fetch(url, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': token,
                'X-Requested-With': 'XMLHttpRequest'
              }
            });
            if (!response.ok) throw new Error('request_failed');
            window.location.reload();
          } catch (error) {
            window.alert(Drupal.t('Could not queue an AI draft. Please try again.'));
            button.disabled = false;
          }
        });
      });
    }
  };
})(Drupal, once, drupalSettings);
