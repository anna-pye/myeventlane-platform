/**
 * @file
 * MEL Guide behaviour: delayed entrance, session limits, dismissal, a11y.
 */
(function (Drupal, once, drupalSettings) {
  'use strict';

  const STORAGE_HIDE_KEY = 'mel-guide-hidden-until';
  const SESSION_COUNT_KEY = 'mel-guide-session-count';
  const SESSION_DISMISS_KEY = 'mel-guide-dismissed-session';

  /**
   * @returns {boolean}
   */
  function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /**
   * @param {string} key
   * @returns {string|null}
   */
  function readStorage(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (e) {
      return null;
    }
  }

  /**
   * @param {string} key
   * @param {string} value
   */
  function writeStorage(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (e) {
      // Private mode or quota; ignore.
    }
  }

  /**
   * @param {string} key
   */
  function removeStorage(key) {
    try {
      window.localStorage.removeItem(key);
    } catch (e) {
      // Ignore.
    }
  }

  /**
   * @param {string} key
   * @returns {string|null}
   */
  function readSession(key) {
    try {
      return window.sessionStorage.getItem(key);
    } catch (e) {
      return null;
    }
  }

  /**
   * @param {string} key
   * @param {string} value
   */
  function writeSession(key, value) {
    try {
      window.sessionStorage.setItem(key, value);
    } catch (e) {
      // Ignore.
    }
  }

  /**
   * @param {number} hideDays
   * @returns {boolean}
   */
  function isDismissed(hideDays) {
    if (Number.isFinite(hideDays) && hideDays <= 0) {
      return readSession(SESSION_DISMISS_KEY) === '1';
    }

    const raw = readStorage(STORAGE_HIDE_KEY);
    if (!raw) {
      return false;
    }
    const hiddenUntil = Number(raw);
    if (!Number.isFinite(hiddenUntil)) {
      removeStorage(STORAGE_HIDE_KEY);
      return false;
    }
    if (Date.now() < hiddenUntil) {
      return true;
    }
    removeStorage(STORAGE_HIDE_KEY);
    return false;
  }

  /**
   * @param {number} maxPerSession
   * @returns {boolean}
   */
  function hasSessionBudget(maxPerSession) {
    const raw = readSession(SESSION_COUNT_KEY);
    const count = raw ? Number(raw) : 0;
    if (!Number.isFinite(count)) {
      return true;
    }
    return count < maxPerSession;
  }

  /**
   * Records one guide appearance for the current browser session.
   */
  function recordAppearance() {
    const raw = readSession(SESSION_COUNT_KEY);
    const count = raw ? Number(raw) : 0;
    const next = Number.isFinite(count) ? count + 1 : 1;
    writeSession(SESSION_COUNT_KEY, String(next));
  }

  /**
   * @param {number} hideDays
   */
  function persistDismiss(hideDays) {
    if (!Number.isFinite(hideDays) || hideDays <= 0) {
      removeStorage(STORAGE_HIDE_KEY);
      writeSession(SESSION_DISMISS_KEY, '1');
      return;
    }

    removeSession(SESSION_DISMISS_KEY);
    const hiddenUntil = Date.now() + hideDays * 24 * 60 * 60 * 1000;
    writeStorage(STORAGE_HIDE_KEY, String(hiddenUntil));
  }

  /**
   * @param {string} key
   */
  function removeSession(key) {
    try {
      window.sessionStorage.removeItem(key);
    } catch (e) {
      // Ignore.
    }
  }

  /**
   * @param {HTMLElement} root
   */
  function hideEntireGuide(root) {
    root.classList.add('mel-guide--dismissed');
    root.setAttribute('aria-hidden', 'true');
    closePanel(root);
  }

  /**
   * @param {HTMLElement} root
   * @param {boolean} [restoreFocus]
   */
  function closePanel(root, restoreFocus) {
    const panel = root.querySelector('[data-mel-guide-panel]');
    const character = root.querySelector('[data-mel-guide-character]');
    root.classList.remove('mel-guide--panel-open');
    if (panel) {
      panel.hidden = true;
    }
    if (character) {
      character.setAttribute('aria-expanded', 'false');
      if (restoreFocus) {
        character.focus({ preventScroll: true });
      }
    }
  }

  /**
   * @param {HTMLElement} root
   * @param {boolean} countAppearance
   */
  function openPanel(root, countAppearance) {
    const panel = root.querySelector('[data-mel-guide-panel]');
    const character = root.querySelector('[data-mel-guide-character]');
    root.classList.add('mel-guide--panel-open');
    if (panel) {
      panel.hidden = false;
    }
    if (character) {
      character.setAttribute('aria-expanded', 'true');
    }
    if (countAppearance) {
      recordAppearance();
    }
  }

  /**
   * @param {HTMLElement} root
   * @param {object} settings
   */
  function initGuide(root, settings) {
    const delay = Number(settings.appearanceDelay ?? 3000);
    const maxPerSession = Number(settings.maxMessagesPerSession ?? 1);
    const hideDays = Number(settings.hideDaysAfterDismiss ?? 30);
    const debug = Boolean(settings.debugForceDisplay);

    if (!debug && isDismissed(hideDays)) {
      hideEntireGuide(root);
      return;
    }

    const panel = root.querySelector('[data-mel-guide-panel]');
    const closeBtn = root.querySelector('[data-mel-guide-close]');
    const hideBtn = root.querySelector('[data-mel-guide-hide]');
    const character = root.querySelector('[data-mel-guide-character]');

    /**
     * @param {Event} event
     */
    function onClose(event) {
      event.preventDefault();
      closePanel(root, true);
    }

    /**
     * @param {Event} event
     */
    function onHide(event) {
      event.preventDefault();
      persistDismiss(hideDays);
      hideEntireGuide(root);
    }

    /**
     * @param {KeyboardEvent} event
     */
    function onKeydown(event) {
      if (event.key !== 'Escape') {
        return;
      }
      if (!root.contains(document.activeElement) && !root.classList.contains('mel-guide--panel-open')) {
        return;
      }
      closePanel(root, true);
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', onClose);
    }
    if (hideBtn) {
      hideBtn.addEventListener('click', onHide);
    }
    if (character) {
      character.addEventListener('click', function (event) {
        event.preventDefault();
        if (panel && panel.hidden) {
          openPanel(root, false);
        } else {
          closePanel(root, false);
        }
      });
    }

    document.addEventListener('keydown', onKeydown);

    if (!debug && !hasSessionBudget(maxPerSession)) {
      return;
    }

    window.setTimeout(function () {
      if (!debug && !hasSessionBudget(maxPerSession)) {
        return;
      }
      openPanel(root, true);
    }, Math.max(0, delay));
  }

  Drupal.behaviors.melGuide = {
    attach(context) {
      const settings = drupalSettings.melGuide;
      if (!settings) {
        return;
      }

      once('mel-guide', '[data-mel-guide]', context).forEach(function (root) {
        initGuide(root, settings);
      });
    },
  };
})(Drupal, once, drupalSettings);
