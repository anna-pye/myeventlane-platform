/**
 * @file
 * Presentational MEL support layer: renders pre-approved melSupport data only.
 */
(function (Drupal, once, drupalSettings) {
  'use strict';

  /**
   * @param {string} raw
   * @returns {string}
   */
  function safeStyleClass(raw) {
    const s = String(raw || 'link').toLowerCase().replace(/[^a-z0-9_-]/g, '');
    return s || 'link';
  }

  /**
   * @param {HTMLElement} host
   * @param {Array} actions
   * @param {object} strings
   */
  function renderActions(host, actions, strings) {
    if (!host) {
      return;
    }
    host.innerHTML = '';
    const list = Array.isArray(actions) ? actions : [];
    if (list.length === 0) {
      host.setAttribute('hidden', 'hidden');
      return;
    }
    host.removeAttribute('hidden');
    const title = document.createElement('h3');
    title.className = 'mel-mel-support-layer__actions-title';
    title.textContent = strings.actions_heading || '';
    host.appendChild(title);
    const row = document.createElement('div');
    row.className = 'mel-mel-support-layer__actions-row';
    list.forEach((action) => {
      if (!action || !action.url || !action.label) {
        return;
      }
      const a = document.createElement('a');
      a.className =
        'mel-mel-support-layer__action mel-mel-support-layer__action--' + safeStyleClass(action.style);
      a.href = action.url;
      a.textContent = action.label;
      row.appendChild(a);
    });
    if (row.childElementCount === 0) {
      host.setAttribute('hidden', 'hidden');
      return;
    }
    host.appendChild(row);
  }

  /**
   * @param {HTMLElement} host
   * @param {object} debug
   * @param {object} strings
   */
  function renderDebug(host, debug, strings) {
    if (!host || !debug) {
      return;
    }
    host.removeAttribute('hidden');
    const pre = document.createElement('pre');
    pre.className = 'mel-mel-support-layer__debug-pre';
    pre.setAttribute('tabindex', '0');
    const heading = strings.debug_heading || 'Debug';
    pre.textContent =
      heading +
      '\n' +
      JSON.stringify(
        {
          route_name: debug.route_name,
          current_surface: debug.current_surface,
          action_ids: debug.action_ids,
        },
        null,
        2,
      );
    host.appendChild(pre);
  }

  /**
   * @param {HTMLElement} root
   * @param {object} settings
   */
  function hydrateHubLayer(root, settings) {
    const actionsHost = root.querySelector('[data-mel-support-actions-host]');
    const debugHost = root.querySelector('[data-mel-support-debug-host]');
    const strings = settings.strings || {};
    renderActions(actionsHost, settings.actions, strings);
    if (settings.debug && debugHost) {
      renderDebug(debugHost, settings.debug, strings);
    } else if (debugHost) {
      debugHost.setAttribute('hidden', 'hidden');
      debugHost.innerHTML = '';
    }
  }

  /**
   * Builds a compact / admin floating panel from structured settings.
   *
   * @param {HTMLElement} mount
   * @param {object} settings
   */
  function hydrateShellMount(mount, settings) {
    const variant = settings.variant || 'compact';
    const strings = settings.strings || {};
    const esca = settings.escalation || {};
    const collapsedDefault = Boolean(settings.panel_collapsed_default);
    mount.innerHTML = '';
    mount.classList.add('mel-mel-support-mount', 'mel-mel-support-mount--' + variant);

    const panel = document.createElement('section');
    panel.className = 'mel-mel-support-floating';
    panel.setAttribute('data-mel-support-floating', '');

    const header = document.createElement('div');
    header.className = 'mel-mel-support-floating__header';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'mel-mel-support-floating__toggle';
    toggle.setAttribute('aria-expanded', collapsedDefault ? 'false' : 'true');
    toggle.textContent = collapsedDefault
      ? strings.panel_toggle_show || 'Show'
      : strings.panel_toggle_hide || 'Hide';
    const title = document.createElement('span');
    title.className = 'mel-mel-support-floating__title';
    title.textContent =
      variant === 'admin'
        ? strings.help_centre || 'Help'
        : strings.intro_compact || 'Support';
    header.appendChild(title);
    header.appendChild(toggle);
    panel.appendChild(header);

    const body = document.createElement('div');
    body.className = 'mel-mel-support-floating__body';
    if (collapsedDefault) {
      body.setAttribute('hidden', 'hidden');
    }

    const actionsHost = document.createElement('div');
    actionsHost.setAttribute('data-mel-support-actions-host', '');
    body.appendChild(actionsHost);

    const foot = document.createElement('div');
    foot.className = 'mel-mel-support-floating__foot';
    if (esca.help_home_url) {
      foot.appendChild(
        linkEl(esca.help_home_url, strings.help_centre || 'Help Centre'),
      );
    }
    if (esca.help_search_url) {
      foot.appendChild(
        linkEl(esca.help_search_url, strings.search_help || 'Search help'),
      );
    }
    if (esca.assistant_url) {
      foot.appendChild(
        linkEl(esca.assistant_url, strings.open_assistant || 'Assistant'),
      );
    }
    if (esca.contact_url) {
      foot.appendChild(
        linkEl(esca.contact_url, strings.contact_support || 'Contact'),
      );
    }
    body.appendChild(foot);
    panel.appendChild(body);
    mount.appendChild(panel);

    renderActions(actionsHost, settings.actions, strings);

    toggle.addEventListener('click', () => {
      const open = body.hasAttribute('hidden');
      if (open) {
        body.removeAttribute('hidden');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.textContent = strings.panel_toggle_hide || 'Hide';
      } else {
        body.setAttribute('hidden', 'hidden');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.textContent = strings.panel_toggle_show || 'Show';
      }
    });
  }

  /**
   * @param {string} href
   * @param {string} label
   * @returns {HTMLAnchorElement}
   */
  function linkEl(href, label) {
    const a = document.createElement('a');
    a.href = href;
    a.className = 'mel-mel-support-floating__link';
    a.textContent = label;
    return a;
  }

  /**
   * @param {object} settings
   */
  /**
   * @param {Document|HTMLElement} context
   * @param {object} settings
   */
  function run(context, settings) {
    if (!settings || !settings.context) {
      return;
    }
    once('mel-support-layer', '[data-mel-support-layer]', context).forEach((root) => {
      hydrateHubLayer(root, settings);
    });
    once('mel-support-root', '[data-mel-support-root]', context).forEach((mount) => {
      hydrateShellMount(mount, settings);
    });
  }

  Drupal.behaviors.melSupportComponents = {
    attach(context) {
      const settings = drupalSettings.melSupport;
      if (!settings) {
        return;
      }
      run(context, settings);
    },
  };
})(Drupal, once, drupalSettings);
