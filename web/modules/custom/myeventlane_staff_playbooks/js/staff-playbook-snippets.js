/**
 * @file
 * Copy-to-clipboard for staff playbook reply snippets.
 * Manual copy only. No storage or tracking.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.staffPlaybookSnippets = {
    attach: function (context, settings) {
      once('staff-playbook-snippets', '[data-snippet-copy]', context).forEach(function (copyBtn) {
        copyBtn.addEventListener('click', function (e) {
          e.preventDefault();
          var snippetEl = copyBtn.closest('.staff-playbooks-panel__snippet');
          var bodyEl = snippetEl ? snippetEl.querySelector('[data-snippet-body]') : null;
          var text = bodyEl ? (bodyEl.textContent || '').trim() : '';

          if (text && navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
              showCopied(copyBtn);
            }).catch(function () {
              fallbackCopy(text, copyBtn);
            });
          } else if (text) {
            fallbackCopy(text, copyBtn);
          }
        });
      });
    }
  };

  function fallbackCopy(text, copyBtn) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
      document.execCommand('copy');
      showCopied(copyBtn);
    } catch (err) {
      // Silently fail.
    }
    document.body.removeChild(textarea);
  }

  function showCopied(copyBtn) {
    var original = copyBtn.textContent;
    copyBtn.textContent = Drupal.t('Copied');
    copyBtn.setAttribute('aria-label', Drupal.t('Copied to clipboard'));
    setTimeout(function () {
      copyBtn.textContent = original;
      copyBtn.setAttribute('aria-label', Drupal.t('Copy snippet'));
    }, 1500);
  }

})(Drupal, once);
