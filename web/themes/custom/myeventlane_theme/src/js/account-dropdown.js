/**
 * @file
 * Account dropdown — desktop flyout only below md (768px).
 *
 * Mobile account sheets are owned by mel-mobile-overlays.js.
 */

(function () {
  'use strict';

  const DESKTOP_MQ = window.matchMedia('(min-width: 768px)');

  /**
   * @returns {boolean}
   */
  function isDesktopViewport() {
    return DESKTOP_MQ.matches;
  }

  function initAccountDropdown(context) {
    context = context || document;
    const dropdowns = context.querySelectorAll('.mel-account-dropdown');

    dropdowns.forEach(function (dropdown) {
      if (dropdown.hasAttribute('data-dropdown-initialized')) {
        return;
      }

      dropdown.setAttribute('data-dropdown-initialized', 'true');
      const toggle = dropdown.querySelector('.mel-account-toggle');
      const menu = dropdown.querySelector('.mel-account-menu');

      if (!toggle || !menu) {
        console.warn('Account dropdown missing toggle or menu');
        return;
      }

      const newToggle = toggle.cloneNode(true);
      toggle.parentNode.replaceChild(newToggle, toggle);
      const newMenu = dropdown.querySelector('.mel-account-menu');

      newToggle.addEventListener('click', function (e) {
        if (!isDesktopViewport()) {
          return;
        }

        e.preventDefault();
        e.stopPropagation();

        const isOpen = dropdown.classList.contains('is-open');

        if (isOpen) {
          dropdown.classList.remove('is-open');
          newToggle.setAttribute('aria-expanded', 'false');
          newMenu.setAttribute('aria-hidden', 'true');
        }
        else {
          document.querySelectorAll('.mel-account-dropdown.is-open').forEach(function (other) {
            if (other !== dropdown) {
              other.classList.remove('is-open');
              const otherToggle = other.querySelector('.mel-account-toggle');
              const otherMenu = other.querySelector('.mel-account-menu');
              if (otherToggle) {
                otherToggle.setAttribute('aria-expanded', 'false');
              }
              if (otherMenu) {
                otherMenu.setAttribute('aria-hidden', 'true');
              }
            }
          });

          dropdown.classList.add('is-open');
          newToggle.setAttribute('aria-expanded', 'true');
          newMenu.setAttribute('aria-hidden', 'false');
        }
      });

      document.addEventListener('click', function (e) {
        if (!isDesktopViewport()) {
          return;
        }
        if (!dropdown.contains(e.target) && dropdown.classList.contains('is-open')) {
          dropdown.classList.remove('is-open');
          newToggle.setAttribute('aria-expanded', 'false');
          newMenu.setAttribute('aria-hidden', 'true');
        }
      }, true);

      document.addEventListener('keydown', function (e) {
        if (!isDesktopViewport()) {
          return;
        }
        if (e.key === 'Escape' && dropdown.classList.contains('is-open')) {
          dropdown.classList.remove('is-open');
          newToggle.setAttribute('aria-expanded', 'false');
          newMenu.setAttribute('aria-hidden', 'true');
          newToggle.focus();
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initAccountDropdown();
    });
  }
  else {
    setTimeout(function () {
      initAccountDropdown();
    }, 50);
  }

  if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
    Drupal.behaviors.myeventlaneAccountDropdownDirect = {
      attach: function (context) {
        initAccountDropdown(context);
      },
    };
  }
})();
