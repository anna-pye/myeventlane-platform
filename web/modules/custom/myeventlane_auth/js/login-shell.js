/**
 * @file
 * Small, accessible interactions for MEL public authentication shells.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melLoginShell = {
    attach(context) {
      once('mel-login-password-toggle', '.mel-auth-shell input[type="password"]', context).forEach((input) => {
        const control = document.createElement('span');
        control.className = 'mel-auth-password-control';
        input.parentNode.insertBefore(control, input);
        control.appendChild(input);

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'mel-auth-password-toggle';
        toggle.textContent = Drupal.t('Show');
        toggle.setAttribute('aria-label', Drupal.t('Show password'));
        toggle.setAttribute('aria-pressed', 'false');
        if (input.id) {
          toggle.setAttribute('aria-controls', input.id);
        }

        toggle.addEventListener('click', () => {
          const showPassword = input.type === 'password';
          input.type = showPassword ? 'text' : 'password';
          toggle.textContent = showPassword ? Drupal.t('Hide') : Drupal.t('Show');
          toggle.setAttribute(
            'aria-label',
            showPassword ? Drupal.t('Hide password') : Drupal.t('Show password'),
          );
          toggle.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
        });

        control.appendChild(toggle);
      });
    },
  };
})(Drupal, once);
