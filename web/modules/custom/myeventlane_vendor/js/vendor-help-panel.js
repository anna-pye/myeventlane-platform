/**
 * @file
 * Guided actions for the vendor help panel (modal dispatch + auto-fix POST).
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * @param {string} text
   * @returns {string}
   */
  function t(text) {
    return Drupal.t(text);
  }

  /**
   * @param {HTMLElement} root
   */
  function attachModalTriggers(root) {
    root.querySelectorAll('[data-modal]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const name = btn.getAttribute('data-modal');
        if (!name) {
          return;
        }
        document.dispatchEvent(
          new CustomEvent('mel-help-open-modal', { detail: { modal: name } }),
        );
      });
    });
  }

  /**
   * @param {HTMLElement} root
   * @param {{ csrfToken?: string, eventId?: number, helpActionBaseUrl?: string }} settings
   */
  function attachAutoFix(root, settings) {
    const base = settings.helpActionBaseUrl || '/api/help-action/';
    const token = settings.csrfToken || '';
    const eventId = settings.eventId;

    root.querySelectorAll('.mel-auto-fix').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const action = btn.getAttribute('data-action');
        if (!action || !token || !eventId) {
          return;
        }
        btn.textContent = t('Applying…');
        btn.setAttribute('disabled', 'disabled');
        try {
          const res = await fetch(base + encodeURIComponent(action), {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              'X-CSRF-Token': token,
            },
            body: JSON.stringify({ event: eventId }),
          });
          const data = await res.json().catch(() => ({}));
          if (data.success) {
            btn.textContent = t('Done ✓');
          } else {
            btn.textContent = t('Try again');
            btn.removeAttribute('disabled');
          }
        } catch (e) {
          btn.textContent = t('Try again');
          btn.removeAttribute('disabled');
        }
      });
    });
  }

  Drupal.behaviors.melVendorHelpPanel = {
    attach(context) {
      const roots = once('mel-vendor-help-panel', '[data-mel-help-panel]', context);
      roots.forEach((root) => {
        attachModalTriggers(root);
        const settings = drupalSettings.melVendorHelp || {};
        if (settings.csrfToken && settings.eventId) {
          attachAutoFix(root, settings);
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
