(function (Drupal, once) {
  'use strict';

  const WARNING_SECONDS = 5 * 60;
  const URGENT_SECONDS = 2 * 60;

  Drupal.behaviors.melCartTicketHold = {
    attach(context) {
      once('mel-cart-ticket-hold', '[data-mel-ticket-hold]', context).forEach((hold) => {
        attachTimer(hold);
      });
    },
  };

  function attachTimer(hold) {
    const initialState = hold.dataset.holdState || 'expired';
    const expiresAt = toPositiveNumber(hold.dataset.expiresAt);
    const serverNow = toPositiveNumber(hold.dataset.serverNow);
    const duration = toPositiveNumber(hold.dataset.holdDuration) || 900;
    const startedAt = window.performance.now();
    let lastAnnouncement = null;
    let interval = null;

    const remaining = () => {
      if (initialState !== 'active' || !expiresAt || !serverNow) {
        return 0;
      }
      const elapsed = Math.floor((window.performance.now() - startedAt) / 1000);
      return Math.max(0, expiresAt - serverNow - elapsed);
    };

    const update = () => {
      const seconds = remaining();
      renderState(hold, seconds, duration);
      announceThreshold(hold, seconds, lastAnnouncement, (threshold) => {
        lastAnnouncement = threshold;
      });

      if (seconds <= 0 && interval !== null) {
        window.clearInterval(interval);
        interval = null;
      }
    };

    update();
    if (remaining() > 0) {
      interval = window.setInterval(update, 1000);
    }

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        update();
      }
    });
  }

  function renderState(hold, seconds, duration) {
    const expired = seconds <= 0;
    const warning = !expired && seconds <= WARNING_SECONDS;
    const urgent = !expired && seconds <= URGENT_SECONDS;
    const minutes = Math.floor(seconds / 60);
    const remainder = seconds % 60;
    const time = hold.querySelector('[data-mel-ticket-hold-time]');
    const heading = hold.querySelector('[data-mel-hold-heading]');
    const progress = hold.querySelector('[data-mel-ticket-hold-progress]');
    const activeCopy = hold.querySelector('[data-mel-hold-active-copy]');
    const expiredCopy = hold.querySelector('[data-mel-hold-expired-copy]');

    hold.classList.toggle('is-active', !expired);
    hold.classList.toggle('is-warning', warning);
    hold.classList.toggle('is-urgent', urgent);
    hold.classList.toggle('is-expired', expired);
    hold.dataset.holdState = expired ? 'expired' : 'active';
    hold.style.setProperty(
      '--mel-ticket-hold-progress',
      `${Math.max(0, Math.min(100, (seconds / duration) * 100))}%`
    );

    if (time) {
      time.textContent = `${pad(minutes)}:${pad(remainder)}`;
    }
    if (heading) {
      heading.textContent = expired
        ? Drupal.t('Your ticket hold has ended')
        : Drupal.t('We’re holding your tickets');
    }
    if (progress) {
      progress.setAttribute('aria-valuenow', String(seconds));
    }
    if (activeCopy) {
      activeCopy.hidden = expired;
    }
    if (expiredCopy) {
      expiredCopy.hidden = !expired;
    }

    const shell = hold.closest('.mel-cart, .mel-checkout-shell') || document;
    shell.querySelectorAll('.mel-ticket-hold-protected-action').forEach((action) => {
      action.disabled = expired;
      action.setAttribute('aria-disabled', expired ? 'true' : 'false');
    });
    shell.querySelectorAll('[data-mel-ticket-hold-renew]').forEach((action) => {
      action.hidden = !expired;
    });
  }

  function announceThreshold(hold, seconds, previous, remember) {
    const live = hold.querySelector('[data-mel-ticket-hold-live]');
    if (!live) {
      return;
    }

    let threshold = null;
    let message = '';
    if (seconds <= 0) {
      threshold = 0;
      message = Drupal.t('Your ticket hold has ended. Check availability before continuing.');
    }
    else if (seconds <= 60) {
      threshold = 60;
      message = Drupal.t('Less than one minute remains on your ticket hold.');
    }
    else if (seconds <= URGENT_SECONDS) {
      threshold = URGENT_SECONDS;
      message = Drupal.t('Two minutes remain on your ticket hold.');
    }
    else if (seconds <= WARNING_SECONDS) {
      threshold = WARNING_SECONDS;
      message = Drupal.t('Five minutes remain on your ticket hold.');
    }

    if (threshold !== null && threshold !== previous) {
      live.textContent = message;
      remember(threshold);
    }
  }

  function toPositiveNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? number : 0;
  }

  function pad(value) {
    return String(value).padStart(2, '0');
  }
})(Drupal, once);
