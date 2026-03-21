/**
 * @file
 * Live countdown for waitlist offer panel (display only).
 */
(function (Drupal, once) {
  'use strict';

  /**
   * @param {number} totalSeconds
   * @returns {string}
   */
  function formatHms(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const r = s % 60;
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(h)}:${pad(m)}:${pad(r)}`;
  }

  /**
   * @param {HTMLElement} root
   */
  function setup(root) {
    const expiresRaw = root.getAttribute('data-offer-expires-at');
    const expiresAt = expiresRaw ? parseInt(expiresRaw, 10) : 0;
    const timerEl = root.querySelector('.mel-offer-countdown__timer');
    const titleEl = root.querySelector('.mel-offer-countdown__title');
    const ledeEl = root.querySelector('.mel-offer-countdown__lede');
    const helperEl = root.querySelector('.mel-offer-countdown__helper');
    const warningEl = root.querySelector('.mel-offer-countdown__warning');
    const statsEl = root.querySelector('.mel-offer-countdown__stats');
    const expiredTitle = root.getAttribute('data-expired-title') || '';
    const expiredBody = root.getAttribute('data-expired-body') || '';
    const expiredHint = root.getAttribute('data-expired-hint') || '';

    if (!timerEl || !expiresAt) {
      return;
    }

    let intervalId = null;

    const applyExpired = () => {
      if (intervalId) {
        window.clearInterval(intervalId);
        intervalId = null;
      }
      root.classList.remove('mel-offer-countdown--active');
      root.classList.add('mel-offer-countdown--expired');
      timerEl.textContent = '00:00:00';
      if (titleEl) {
        titleEl.textContent = expiredTitle;
      }
      if (ledeEl) {
        ledeEl.textContent = expiredBody;
      }
      if (helperEl) {
        helperEl.textContent = expiredHint;
      }
      if (warningEl) {
        warningEl.setAttribute('hidden', 'hidden');
      }
      if (statsEl) {
        statsEl.classList.add('mel-offer-countdown__stats--expired');
      }
    };

    const tick = () => {
      const now = Math.floor(Date.now() / 1000);
      const left = Math.max(0, expiresAt - now);
      if (left <= 0) {
        applyExpired();
        return;
      }
      timerEl.textContent = formatHms(left);
    };

    tick();
    intervalId = window.setInterval(tick, 1000);
  }

  Drupal.behaviors.melWaitlistOfferCountdown = {
    attach(context) {
      once('mel-waitlist-offer-countdown', '.mel-offer-countdown', context).forEach((el) => {
        if (el instanceof HTMLElement) {
          setup(el);
        }
      });
    },
  };
})(Drupal, once);
