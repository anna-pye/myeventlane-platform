/**
 * @file
 * Mobile overlay coordinator — one open overlay at a time below md (768px).
 *
 * Portals popover panels to #mel-mobile-overlay-portal to prevent header clipping.
 */

const MOBILE_MQ = window.matchMedia('(max-width: 767px)');
const PORTAL_ID = 'mel-mobile-overlay-portal';

/** @type {string|null} */
let activeOverlay = null;

/** @type {HTMLElement|null} */
let lastFocus = null;

/** @type {WeakMap<HTMLElement, { parent: Node, next: ChildNode|null }>} */
const portalAnchors = new WeakMap();

/**
 * @returns {boolean}
 */
export function isMobileViewport() {
  return MOBILE_MQ.matches;
}

/**
 * Sync layout CSS variables from measured header + safe areas.
 */
export function syncMobileOverlayLayout() {
  const header = document.querySelector('.site-header');
  const bottom = header ? Math.ceil(header.getBoundingClientRect().bottom) : 64;
  document.documentElement.style.setProperty('--mel-site-header-offset', `${bottom}px`);
}

/**
 * @returns {HTMLElement}
 */
function getPortal() {
  let portal = document.getElementById(PORTAL_ID);
  if (!portal) {
    portal = document.createElement('div');
    portal.id = PORTAL_ID;
    portal.className = 'mel-mobile-overlay-portal';
    portal.setAttribute('aria-hidden', 'true');
    document.body.appendChild(portal);
  }
  return portal;
}

/**
 * @param {HTMLElement} panel
 */
function portalPanel(panel) {
  if (panel.dataset.melPortaled === '1') {
    return;
  }
  portalAnchors.set(panel, {
    parent: panel.parentNode,
    next: panel.nextSibling,
  });
  panel.classList.add('mel-mobile-overlay-sheet');
  panel.dataset.melPortaled = '1';
  getPortal().appendChild(panel);
}

/**
 * @param {HTMLElement} panel
 */
function unportalPanel(panel) {
  if (panel.dataset.melPortaled !== '1') {
    panel.classList.remove('mel-mobile-overlay-sheet');
    return;
  }
  const anchor = portalAnchors.get(panel);
  if (anchor?.parent) {
    anchor.parent.insertBefore(panel, anchor.next);
  }
  panel.classList.remove('mel-mobile-overlay-sheet');
  delete panel.dataset.melPortaled;
}

/**
 * @param {boolean} on
 */
function setBodyOverlayState(on) {
  document.body.classList.toggle('mel-mobile-overlay-active', on);
}

/**
 * @param {string} id
 * @param {HTMLElement} trigger
 */
function markOverlayOpen(id, trigger) {
  activeOverlay = id;
  lastFocus = trigger;
  syncMobileOverlayLayout();
  setBodyOverlayState(true);
}

/**
 * @param {string} id
 */
export function notifyOverlayClosed(id) {
  if (activeOverlay === id) {
    activeOverlay = null;
    setBodyOverlayState(false);
  }
}

/**
 * Close all mobile overlays except the named surface.
 *
 * @param {string|null} except
 *   drawer | account | notifications | cart
 */
export function closeAllMobileOverlays(except = null) {
  if (!isMobileViewport()) {
    return;
  }

  document.querySelectorAll('.mobile-drawer[open]').forEach((drawer) => {
    if (except !== 'drawer') {
      drawer.open = false;
    }
  });

  document.querySelectorAll('.mel-account-dropdown.is-open').forEach((dropdown) => {
    if (except !== 'account') {
      dropdown.classList.remove('is-open');
      const toggle = dropdown.querySelector('.mel-account-toggle');
      const menu = dropdown.querySelector('.mel-account-menu');
      if (toggle) {
        toggle.setAttribute('aria-expanded', 'false');
      }
      if (menu) {
        menu.setAttribute('aria-hidden', 'true');
        unportalPanel(menu);
      }
    }
  });

  document.querySelectorAll('[data-mel-notif-bell-panel].is-open').forEach((panel) => {
    if (except !== 'notifications') {
      panel.classList.remove('is-open');
      panel.hidden = true;
      unportalPanel(panel);
      const trigger = document.querySelector(`[aria-controls="${panel.id}"]`)
        || panel.closest('[data-mel-notif-bell]')?.querySelector('[data-mel-notif-bell-trigger]');
      if (trigger) {
        trigger.setAttribute('aria-expanded', 'false');
      }
    }
  });

  document.querySelectorAll('.mel-cart-block.is-open').forEach((block) => {
    if (except !== 'cart') {
      block.classList.remove('is-open');
      const panel = block.querySelector('.mel-mini-cart');
      if (panel) {
        unportalPanel(panel);
      }
    }
  });

  if (!except) {
    activeOverlay = null;
    setBodyOverlayState(false);
  }
  else {
    activeOverlay = except;
    setBodyOverlayState(true);
  }
}

/**
 * @param {HTMLElement} trigger
 */
function closeActiveOverlay(trigger) {
  if (!activeOverlay) {
    return;
  }
  closeAllMobileOverlays();
  if (trigger && typeof trigger.focus === 'function') {
    requestAnimationFrame(() => trigger.focus());
  }
  else if (lastFocus && typeof lastFocus.focus === 'function') {
    requestAnimationFrame(() => lastFocus.focus());
  }
}

/**
 * @param {HTMLElement} panel
 */
function watchNotificationPanel(panel) {
  if (panel.getAttribute('data-mel-notif-overlay-watch') === '1') {
    return;
  }
  panel.setAttribute('data-mel-notif-overlay-watch', '1');

  const observer = new MutationObserver(() => {
    if (!isMobileViewport()) {
      if (panel.dataset.melPortaled === '1') {
        unportalPanel(panel);
      }
      return;
    }

    const isOpen = panel.classList.contains('is-open') && !panel.hidden;
    const trigger = document.querySelector('[data-mel-notif-bell-trigger][aria-controls="mel-notif-bell-panel"]')
      || document.querySelector('[data-mel-notif-bell-trigger]');

    if (isOpen) {
      if (activeOverlay !== 'notifications') {
        closeAllMobileOverlays('notifications');
      }
      portalPanel(panel);
      if (trigger) {
        markOverlayOpen('notifications', trigger);
      }
    }
    else {
      unportalPanel(panel);
      if (activeOverlay === 'notifications') {
        notifyOverlayClosed('notifications');
      }
    }
  });

  observer.observe(panel, { attributes: true, attributeFilter: ['class', 'hidden'] });
}

/**
 * Initialize mobile overlay coordination.
 *
 * @param {Document|Element} context
 */
export function initMobileOverlays(context) {
  const scope = context || document;

  if (scope === document && document.body?.getAttribute('data-mel-mobile-overlays-init') === '1') {
    return;
  }
  if (scope === document) {
    document.body.setAttribute('data-mel-mobile-overlays-init', '1');
  }

  syncMobileOverlayLayout();
  window.addEventListener('resize', syncMobileOverlayLayout);
  window.addEventListener('scroll', syncMobileOverlayLayout, { passive: true });

  scope.querySelectorAll('[data-mel-notif-bell-panel]').forEach(watchNotificationPanel);

  scope.querySelectorAll('.mel-cart-block').forEach((block) => {
    if (block.getAttribute('data-mel-cart-overlay-init') === '1') {
      return;
    }
    block.setAttribute('data-mel-cart-overlay-init', '1');

    const link = block.querySelector('.mel-cart-block-link');
    const panel = block.querySelector('.mel-mini-cart');
    if (!link || !panel) {
      return;
    }

    link.addEventListener('click', (event) => {
      if (!isMobileViewport()) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();

      const opening = !block.classList.contains('is-open');
      if (opening) {
        closeAllMobileOverlays('cart');
        block.classList.add('is-open');
        portalPanel(panel);
        markOverlayOpen('cart', link);
        requestAnimationFrame(() => {
          const focusable = panel.querySelector('a[href], button:not([disabled])');
          if (focusable) {
            focusable.focus();
          }
        });
      }
      else {
        block.classList.remove('is-open');
        unportalPanel(panel);
        closeActiveOverlay(link);
      }
    });
  });

  document.addEventListener('click', (event) => {
    if (!isMobileViewport()) {
      return;
    }

    const toggle = event.target.closest('.mel-account-toggle');
    if (toggle) {
      event.preventDefault();
      event.stopImmediatePropagation();

      const dropdown = toggle.closest('.mel-account-dropdown');
      const menu = dropdown?.querySelector('.mel-account-menu');
      if (!dropdown || !menu) {
        return;
      }

      const opening = !dropdown.classList.contains('is-open');
      if (opening) {
        closeAllMobileOverlays('account');
        dropdown.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        menu.setAttribute('aria-hidden', 'false');
        portalPanel(menu);
        markOverlayOpen('account', toggle);
        requestAnimationFrame(() => {
          const first = menu.querySelector('a[href], button:not([disabled])');
          if (first) {
            first.focus();
          }
        });
      }
      else {
        dropdown.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        menu.setAttribute('aria-hidden', 'true');
        unportalPanel(menu);
        closeActiveOverlay(toggle);
      }
      return;
    }

    const notifTrigger = event.target.closest('[data-mel-notif-bell-trigger]');
    if (notifTrigger) {
      const root = notifTrigger.closest('[data-mel-notif-bell]');
      const panel = root?.querySelector('[data-mel-notif-bell-panel]');
      const opening = panel && !panel.classList.contains('is-open');
      if (opening) {
        closeAllMobileOverlays('notifications');
        lastFocus = notifTrigger;
        // Let mel-notifications-ui.js toggle first, then portal on next frame.
        requestAnimationFrame(() => {
          requestAnimationFrame(() => {
            if (!isMobileViewport() || !panel.classList.contains('is-open') || panel.hidden) {
              return;
            }
            portalPanel(panel);
            markOverlayOpen('notifications', notifTrigger);
            syncMobileOverlayLayout();
          });
        });
      }
      return;
    }

    const drawerToggle = event.target.closest('.mobile-drawer__toggle');
    if (drawerToggle) {
      const drawer = drawerToggle.closest('.mobile-drawer');
      if (drawer && !drawer.open) {
        closeAllMobileOverlays('drawer');
        markOverlayOpen('drawer', drawerToggle);
      }
      return;
    }

    if (
      activeOverlay
      && !event.target.closest('.mel-cart-block, .mel-account-dropdown, [data-mel-notif-bell], .mobile-drawer, #mel-mobile-overlay-portal')
    ) {
      const returnTrigger = lastFocus;
      closeAllMobileOverlays();
      if (returnTrigger && typeof returnTrigger.focus === 'function') {
        requestAnimationFrame(() => returnTrigger.focus());
      }
    }
  }, true);

  document.addEventListener('keydown', (event) => {
    if (!isMobileViewport() || event.key !== 'Escape') {
      return;
    }

    const drawer = document.querySelector('.mobile-drawer[open]');
    if (drawer) {
      event.preventDefault();
      drawer.open = false;
      notifyOverlayClosed('drawer');
      const summary = drawer.querySelector('summary');
      if (summary && typeof summary.focus === 'function') {
        requestAnimationFrame(() => summary.focus());
      }
      return;
    }

    if (!activeOverlay) {
      return;
    }

    event.preventDefault();
    const returnTrigger = lastFocus;
    closeAllMobileOverlays();
    if (returnTrigger && typeof returnTrigger.focus === 'function') {
      requestAnimationFrame(() => returnTrigger.focus());
    }
  });

  if (typeof MOBILE_MQ.addEventListener === 'function') {
    MOBILE_MQ.addEventListener('change', () => {
      if (!MOBILE_MQ.matches) {
        closeAllMobileOverlays();
        document.querySelectorAll('[data-mel-portaled="1"]').forEach((panel) => {
          unportalPanel(panel);
        });
      }
    });
  }
}
