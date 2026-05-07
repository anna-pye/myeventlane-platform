/**
 * @file
 * MELInteractionSystem — progressive enhancement for modals, drawers, disclosures.
 *
 * Drupal AJAX remains infrastructure; this behaviour governs focus and dismissal UX only.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * @returns {boolean}
   */
  function isCheckoutTrustContext() {
    var html = document.documentElement;
    return html && html.getAttribute('data-mel-checkout-trust') === 'true';
  }

  /**
   * @param {HTMLElement} root
   * @returns {HTMLElement[]}
   */
  function focusables(root) {
    var sel =
      'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
    return Array.prototype.slice.call(root.querySelectorAll(sel)).filter(function (el) {
      return el.offsetParent !== null || el === document.activeElement;
    });
  }

  /**
   * @param {HTMLElement} modalRoot
   */
  function trapModalFocus(modalRoot) {
    var dialog = modalRoot.querySelector('[data-mel-modal-dialog]');
    if (!dialog) {
      return function () {};
    }
    var previous = document.activeElement;
    var xs = focusables(dialog);
    if (xs.length) {
      xs[0].focus();
    }

    function onKeyDown(e) {
      if (e.key !== 'Tab') {
        return;
      }
      var nodes = focusables(dialog);
      if (!nodes.length) {
        return;
      }
      var first = nodes[0];
      var last = nodes[nodes.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }

    dialog.addEventListener('keydown', onKeyDown);
    return function release() {
      dialog.removeEventListener('keydown', onKeyDown);
      if (previous && typeof previous.focus === 'function') {
        previous.focus();
      }
    };
  }

  /**
   * @param {HTMLElement} modalRoot
   */
  function bindModal(modalRoot) {
    var overlay = modalRoot.querySelector('[data-mel-modal-overlay]');
    var dismissOverlay =
      modalRoot.getAttribute('data-mel-modal-dismiss-overlay') !== 'false';

    /** @type {function(): void} */
    var releaseTrap = function () {};

    function closeModal() {
      modalRoot.setAttribute('hidden', 'hidden');
      document.body.classList.remove('mel-modal-open');
      releaseTrap();
      releaseTrap = function () {};
    }

    function openModal() {
      modalRoot.removeAttribute('hidden');
      document.body.classList.add('mel-modal-open');
      if (!isCheckoutTrustContext()) {
        releaseTrap = trapModalFocus(modalRoot);
      } else {
        var dialog = modalRoot.querySelector('[data-mel-modal-dialog]');
        var xs = dialog ? focusables(dialog) : [];
        if (xs.length) {
          xs[0].focus();
        }
      }
    }

    modalRoot.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modalRoot.hasAttribute('hidden')) {
        e.preventDefault();
        closeModal();
      }
    });

    if (overlay && dismissOverlay && !isCheckoutTrustContext()) {
      overlay.addEventListener('click', function () {
        if (!modalRoot.hasAttribute('hidden')) {
          closeModal();
        }
      });
    }

    modalRoot.addEventListener('mel-modal:open', function () {
      openModal();
    });
    modalRoot.addEventListener('mel-modal:close', function () {
      closeModal();
    });

    document.querySelectorAll('[data-mel-modal-open="' + modalRoot.id + '"]').forEach(function (btn) {
      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        openModal();
      });
    });
  }

  /**
   * @param {HTMLElement} drawerRoot
   */
  function bindDrawer(drawerRoot) {
    var overlay = drawerRoot.querySelector('[data-mel-drawer-overlay]');
    function closeDrawer() {
      drawerRoot.setAttribute('hidden', 'hidden');
      document.body.classList.remove('mel-drawer-open');
    }
    function openDrawer() {
      drawerRoot.removeAttribute('hidden');
      document.body.classList.add('mel-drawer-open');
    }
    drawerRoot.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !drawerRoot.hasAttribute('hidden')) {
        e.preventDefault();
        closeDrawer();
      }
    });
    if (overlay && !isCheckoutTrustContext()) {
      overlay.addEventListener('click', closeDrawer);
    }
    drawerRoot.addEventListener('mel-drawer:open', openDrawer);
    drawerRoot.addEventListener('mel-drawer:close', closeDrawer);
  }

  /**
   * @param {HTMLElement} section
   */
  function bindDisclosure(section) {
    var toggle = section.querySelector('.mel-disclosure__toggle');
    var panel = section.querySelector('.mel-disclosure__panel');
    if (!toggle || !panel) {
      return;
    }
    toggle.addEventListener('click', function () {
      var expanded = toggle.getAttribute('aria-expanded') === 'true';
      var next = expanded ? 'false' : 'true';
      toggle.setAttribute('aria-expanded', next);
      section.setAttribute('data-mel-disclosure-expanded', next);
      panel.hidden = expanded;
    });
    panel.hidden = toggle.getAttribute('aria-expanded') !== 'true';
  }

  /**
   * Progressive enhancement: ensure live regions expose aria-live from data attributes.
   *
   * @param {HTMLElement} el
   */
  function bindStateLiveRegion(el) {
    var live = el.getAttribute('data-mel-aria-live');
    if (live && !el.getAttribute('aria-live')) {
      el.setAttribute('aria-live', live);
    }
    if (!el.getAttribute('role')) {
      el.setAttribute('role', 'status');
    }
  }

  Drupal.behaviors.melInteractions = {
    attach(context) {
      once('mel-modal', '[data-mel-modal]', context).forEach(bindModal);
      once('mel-drawer', '[data-mel-drawer]', context).forEach(bindDrawer);
      once('mel-disclosure', '[data-mel-disclosure]', context).forEach(bindDisclosure);
      once('mel-state-live', '[data-mel-state-live]', context).forEach(bindStateLiveRegion);
    },
  };
})(Drupal, once);
