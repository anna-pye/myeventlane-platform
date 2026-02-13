/**
 * @file
 * Sticky reply shell: collapse/expand toggle and auto-expand on focus/errors.
 */

(function (Drupal, once) {
  'use strict';

  function initReplyShell(shell) {
    const bar = shell.querySelector('[data-mel-reply-toggle]');
    const formEl = shell.querySelector('[data-mel-reply-form]');
    if (!bar || !formEl) {
      return;
    }

    function expand() {
      shell.setAttribute('data-expanded', '1');
    }

    function collapse() {
      shell.setAttribute('data-expanded', '0');
    }

    function toggle() {
      const expanded = shell.getAttribute('data-expanded') === '1';
      shell.setAttribute('data-expanded', expanded ? '0' : '1');
    }

    // Ensure collapsed by default (in case markup had different initial state).
    if (shell.getAttribute('data-expanded') !== '1') {
      shell.setAttribute('data-expanded', '0');
    }

    bar.addEventListener('click', toggle);

    // Auto-expand when any textarea/input inside form receives focus.
    const inputs = formEl.querySelectorAll('textarea, input[type="text"], input[type="email"], input:not([type])');
    inputs.forEach(function (input) {
      input.addEventListener('focus', expand);
    });

    // Auto-expand if form has validation errors.
    const hasErrors = formEl.querySelector('.messages--error') ||
      formEl.querySelector('[aria-invalid="true"]');
    if (hasErrors) {
      expand();
    }
  }

  Drupal.behaviors.melEscalationReplySticky = {
    attach: function (context) {
      once('mel-reply-sticky', '[data-mel-reply-shell]', context).forEach(initReplyShell);
    },
  };
})(Drupal, once);
