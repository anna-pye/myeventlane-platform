/**
 * @file
 * Auth pages: password Show/Hide toggle and accessibility.
 *
 * Attached only on user.login, user.register, user.pass.
 * Uses once() on wrappers; real <button type="button">; aria-pressed.
 * Works for both login and register forms.
 */

(function (Drupal, once) {
  'use strict';

  function addToggle(wrapper, input) {
    if (!wrapper || !input || wrapper.querySelector('.mel-auth-password-toggle')) {
      return;
    }
    wrapper.classList.add('mel-auth-password-wrapper');
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'mel-auth-password-toggle';
    toggle.setAttribute('aria-label', Drupal.t('Show password'));
    toggle.setAttribute('aria-pressed', 'false');
    toggle.textContent = Drupal.t('Show');

    function updateState() {
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      toggle.textContent = isPassword ? Drupal.t('Hide') : Drupal.t('Show');
      toggle.setAttribute('aria-label', isPassword ? Drupal.t('Hide password') : Drupal.t('Show password'));
      toggle.setAttribute('aria-pressed', isPassword ? 'false' : 'true');
    }

    toggle.addEventListener('click', updateState);
    wrapper.style.position = 'relative';
    wrapper.appendChild(toggle);
  }

  Drupal.behaviors.melAuthPages = {
    attach: function (context) {
      // Once per wrapper that contains .mel-auth-password-input.
      once('mel-auth-password-wrapper', '.mel-auth-password-wrapper', context).forEach(function (wrapper) {
        const input = wrapper.querySelector('input[type="password"]');
        if (input) {
          input.classList.add('mel-auth-password-input');
          addToggle(wrapper, input);
        }
      });

      // Fallback: once per password input in login/register forms (wrap and add button).
      once('mel-auth-password-form', '.user-login-form input[type="password"], .user-register-form input[type="password"]', context).forEach(function (input) {
        const formItem = input.closest('.form-item');
        if (!formItem) {
          return;
        }
        if (formItem.querySelector('.mel-auth-password-toggle')) {
          return;
        }
        input.classList.add('mel-auth-password-input');
        formItem.classList.add('mel-auth-password-wrapper');
        addToggle(formItem, input);
      });
    },
  };
})(Drupal, once);
