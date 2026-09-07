/**
 * @file
 * Mobile drawer accessibility and in-drawer navigation behaviour.
 */

import { closeAllMobileOverlays, syncMobileOverlayLayout, isMobileViewport, notifyOverlayClosed } from './mel-mobile-overlays.js';

/**
 * Toggle Organisers (and other) dropdown sections inside the drawer.
 *
 * @param {HTMLDetailsElement} drawer
 */
function initDrawerDropdowns(drawer) {
  drawer.querySelectorAll('.mel-nav-dropdown-toggle').forEach((toggle) => {
    if (toggle.getAttribute('data-mel-drawer-dropdown-init') === '1') {
      return;
    }
    toggle.setAttribute('data-mel-drawer-dropdown-init', '1');

    const item = toggle.closest('.mel-nav-dropdown');
    if (!item) {
      return;
    }

    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      const isOpen = item.classList.contains('is-open');
      drawer.querySelectorAll('.mel-nav-dropdown.is-open').forEach((openItem) => {
        if (openItem !== item) {
          openItem.classList.remove('is-open');
          const openToggle = openItem.querySelector('.mel-nav-dropdown-toggle');
          if (openToggle) {
            openToggle.setAttribute('aria-expanded', 'false');
          }
        }
      });
      item.classList.toggle('is-open', !isOpen);
      toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
    });
  });
}

/**
 * Initialize mobile drawer accessibility.
 *
 * @param {Document|Element} context
 */
export function initMobileDrawer(context) {
  const scope = context || document;
  const drawers = scope.querySelectorAll('.mobile-drawer');

  drawers.forEach((drawer) => {
    if (drawer.getAttribute('data-mel-drawer-init') === '1') {
      return;
    }
    drawer.setAttribute('data-mel-drawer-init', '1');

    const summary = drawer.querySelector('summary');
    const panel = drawer.querySelector('.mobile-drawer__panel');
    if (!summary || !panel) {
      return;
    }

    initDrawerDropdowns(drawer);

    let previousActiveElement = null;

    drawer.addEventListener('toggle', () => {
      syncMobileOverlayLayout();

      if (drawer.open) {
        const mobile = isMobileViewport();
        if (mobile) {
          closeAllMobileOverlays('drawer');
          panel.setAttribute('aria-modal', 'true');
        }
        else {
          panel.removeAttribute('aria-modal');
        }
        previousActiveElement = document.activeElement;
        summary.setAttribute('aria-expanded', 'true');
        drawer.classList.add('is-open');
        document.body.classList.add('mel-drawer-open');

        const firstFocusable = panel.querySelector('a[href], button:not([disabled])');
        if (firstFocusable) {
          requestAnimationFrame(() => firstFocusable.focus());
        }
      }
      else {
        panel.removeAttribute('aria-modal');
        summary.setAttribute('aria-expanded', 'false');
        drawer.classList.remove('is-open');
        document.body.classList.remove('mel-drawer-open');
        notifyOverlayClosed('drawer');

        const returnTarget = previousActiveElement || summary;
        if (returnTarget && typeof returnTarget.focus === 'function') {
          requestAnimationFrame(() => returnTarget.focus());
        }
      }
    });
  });

  syncMobileOverlayLayout();
  window.addEventListener('resize', syncMobileOverlayLayout);
}
