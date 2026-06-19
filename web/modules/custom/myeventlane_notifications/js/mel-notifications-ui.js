/**
 * @file
 * MEL notifications: toast polling (backward compatible), bell, badge, preview.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var STACK_ID = 'mel-notification-stack';
  var DISMISSED = {};
  var SESSION_KEY = 'melNotifSeenDeliveryIds';

  function getSessionSeenIds() {
    try {
      var raw = window.sessionStorage.getItem(SESSION_KEY);
      if (!raw) {
        return [];
      }
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function rememberSessionId(deliveryId) {
    var id = Number(deliveryId);
    if (!id) {
      return;
    }
    var list = getSessionSeenIds();
    if (list.indexOf(id) !== -1) {
      return;
    }
    list.push(id);
    try {
      window.sessionStorage.setItem(SESSION_KEY, JSON.stringify(list));
    } catch (e) {}
  }

  function isSessionSeen(deliveryId) {
    var id = Number(deliveryId);
    return id > 0 && getSessionSeenIds().indexOf(id) !== -1;
  }

  function accentForDomain(domain, type) {
    if (domain === 'tickets') {
      return '#f26d5b';
    }
    if (domain === 'orders') {
      return '#e8a54b';
    }
    if (domain === 'sales') {
      return '#6b5ce6';
    }
    if (domain === 'refunds') {
      return '#c45c8a';
    }
    if (domain === 'rsvps') {
      return '#3d9a82';
    }
    if (domain === 'followers') {
      return '#5b8def';
    }
    if (domain === 'event_updates') {
      return '#6b5ce6';
    }
    if (domain === 'boosts') {
      return '#f0ad4e';
    }
    if (domain === 'reminders') {
      return '#3d9a82';
    }
    if (type === 'ticket') {
      return '#f26d5b';
    }
    return '#293241';
  }

  function ensureStack() {
    var existing = document.getElementById(STACK_ID);
    if (existing) {
      return existing;
    }
    var stack = document.createElement('div');
    stack.id = STACK_ID;
    stack.setAttribute('aria-live', 'polite');
    stack.style.cssText =
      'position:fixed;top:1rem;right:1rem;z-index:10050;display:flex;flex-direction:column;gap:0.75rem;max-width:min(420px, calc(100vw - 2rem));pointer-events:none;';
    document.body.appendChild(stack);
    return stack;
  }

  function fetchCsrfToken() {
    return fetch('/session/token', { credentials: 'same-origin' }).then(function (r) {
      return r.text();
    });
  }

  function markRead(urlTemplate, id) {
    var url = urlTemplate.replace('__DELIVERY__', String(id));
    return fetchCsrfToken().then(function (token) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-CSRF-Token': token,
          Accept: 'application/json',
        },
      });
    });
  }

  function markAllRead(readAllUrl) {
    return fetchCsrfToken().then(function (token) {
      return fetch(readAllUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-CSRF-Token': token,
          Accept: 'application/json',
        },
      });
    });
  }

  function renderToast(stack, item, settings) {
    if (!item || !item.id) {
      return;
    }
    if (item.toast_eligible === false) {
      return;
    }
    if (DISMISSED[item.id] || isSessionSeen(item.id)) {
      return;
    }
    rememberSessionId(item.id);

    var el = document.createElement('div');
    el.className = 'mel-notification-toast';
    el.dataset.deliveryId = String(item.id);
    el.style.borderLeft = '4px solid ' + accentForDomain(item.domain || '', item.type);

    var title = document.createElement('div');
    title.style.fontWeight = '600';
    title.style.marginBottom = '4px';
    title.textContent = item.title || '';

    var body = document.createElement('div');
    body.style.opacity = '0.85';
    body.textContent = item.message_preview || item.message || '';

    el.appendChild(title);
    el.appendChild(body);

    var remove = function () {
      el.classList.add('is-leaving');
      window.setTimeout(function () {
        if (el.parentNode) {
          el.parentNode.removeChild(el);
        }
      }, 180);
    };

    window.setTimeout(remove, 5000);

    el.addEventListener('click', function () {
      DISMISSED[item.id] = true;
      markRead(settings.readUrlTemplate, item.id)
        .catch(function () {})
        .finally(function () {
          remove();
          refreshAllBells();
        });
      if (item.action && item.action.url) {
        window.location.href = item.action.url;
      }
    });

    stack.appendChild(el);
  }

  function pollToasts(settings) {
    if (!settings || !settings.pollUrl || !settings.readUrlTemplate) {
      return;
    }
    fetch(settings.pollUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (items) {
        if (!Array.isArray(items) || !items.length) {
          return;
        }
        var stack = ensureStack();
        items.forEach(function (item) {
          renderToast(stack, item, settings);
        });
      })
      .catch(function () {});
  }

  function updateBadge(badge, count, data) {
    if (!badge) {
      return;
    }
    var n = Math.max(0, parseInt(count, 10) || 0);
    var prev = badge.getAttribute('data-mel-count');
    if (prev !== null && String(n) === prev) {
      return;
    }
    badge.setAttribute('data-mel-count', String(n));
    badge.textContent = n > 99 ? '99+' : String(n);
    if (n < 1) {
      badge.classList.add('mel-notif-bell__badge--empty');
      badge.setAttribute('aria-hidden', 'true');
    } else {
      badge.classList.remove('mel-notif-bell__badge--empty');
      badge.removeAttribute('aria-hidden');
    }
    if (data && typeof Drupal !== 'undefined' && Drupal.t) {
      var label;
      if (data.bell_context === 'business') {
        label = Drupal.t('@count unread (@business business, @platform platform)', {
          '@count': n,
          '@business': data.business || 0,
          '@platform': data.platform || 0,
        });
      }
      else {
        label = Drupal.t('@count unread (@personal personal, @platform platform)', {
          '@count': n,
          '@personal': data.personal || 0,
          '@platform': data.platform || 0,
        });
      }
      badge.setAttribute('aria-label', label);
    }
  }

  function renderPreviewList(listEl, items, settings) {
    if (!listEl) {
      return;
    }
    listEl.textContent = '';
    listEl.setAttribute('role', 'menu');
    if (!items || !items.length) {
      listEl.removeAttribute('role');
      var empty = document.createElement('p');
      empty.className = 'mel-notif-bell__empty';
      empty.textContent = Drupal.t('No new notifications.');
      listEl.appendChild(empty);
      return;
    }
    items.forEach(function (row) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mel-notif-bell__row' + (row.is_unread ? ' is-unread' : '');
      btn.setAttribute('role', 'menuitem');
      if (row.domain) {
        btn.style.borderLeft = '3px solid ' + accentForDomain(row.domain, row.type);
      }
      if (row.badge) {
        var badgeEl = document.createElement('span');
        badgeEl.className = 'mel-notif-bell__row-badge mel-notif-bell__row-badge--' + row.badge;
        badgeEl.textContent = (row.domain || '').replace(/_/g, ' ');
        btn.appendChild(badgeEl);
      }
      var t = document.createElement('div');
      t.className = 'mel-notif-bell__row-title';
      t.textContent = row.title || '';
      var p = document.createElement('div');
      p.className = 'mel-notif-bell__row-preview';
      p.textContent = row.message_preview || '';
      btn.appendChild(t);
      btn.appendChild(p);
      btn.addEventListener('click', function () {
        var id = row.delivery_id;
        markRead(settings.readUrlTemplate, id)
          .catch(function () {})
          .finally(function () {
            refreshAllBells();
          });
        if (row.action && row.action.url) {
          window.location.href = row.action.url;
        }
      });
      listEl.appendChild(btn);
    });
  }

  function isMobileViewport() {
    return window.matchMedia('(max-width: 767px)').matches;
  }

  function initBell(root, settings) {
    var trigger = root.querySelector('[data-mel-notif-bell-trigger]');
    var panel = root.querySelector('[data-mel-notif-bell-panel]');
    var listEl = root.querySelector('[data-mel-notif-bell-list]');
    var badge = root.querySelector('[data-mel-notif-bell-badge]');
    var markAllBtn = root.querySelector('[data-mel-notif-mark-all]');
    if (!trigger || !panel || !listEl) {
      return;
    }

    var open = false;

    function setOpen(v) {
      open = v;
      trigger.setAttribute('aria-expanded', v ? 'true' : 'false');
      if (v) {
        panel.hidden = false;
        panel.classList.add('is-open');
        try {
          panel.focus({ preventScroll: true });
        } catch (e) {}
        if (settings.previewUrl) {
          fetch(settings.previewUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) {
              return r.json();
            })
            .then(function (data) {
              var items = data.items || [];
              renderPreviewList(listEl, items, settings);
              if (markAllBtn) {
                markAllBtn.hidden = items.length === 0;
              }
            })
            .catch(function () {
              listEl.textContent = '';
              var err = document.createElement('p');
              err.className = 'mel-notif-bell__empty';
              err.textContent = Drupal.t('Could not load notifications.');
              listEl.appendChild(err);
            });
        }
      } else {
        panel.classList.remove('is-open');
        panel.hidden = true;
      }
    }

    function toggle() {
      setOpen(!open);
    }

    root.melNotifBellSetOpen = setOpen;

    trigger.addEventListener('click', function (e) {
      if (isMobileViewport()) {
        return;
      }
      e.stopPropagation();
      toggle();
    });

    if (markAllBtn && settings.readAllUrl) {
      markAllBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        markAllRead(settings.readAllUrl)
          .then(function () {
            setOpen(false);
            refreshCounts();
          })
          .catch(function () {});
      });
    }

    document.addEventListener('click', function (ev) {
      if (isMobileViewport()) {
        return;
      }
      if (open && !root.contains(ev.target)) {
        setOpen(false);
      }
    });

    document.addEventListener('keydown', function (ev) {
      if (isMobileViewport()) {
        return;
      }
      if (ev.key === 'Escape' && open) {
        setOpen(false);
        trigger.focus();
      }
    });

    function refreshCounts() {
      if (!settings.countUrl) {
        return;
      }
      fetch(settings.countUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          updateBadge(badge, data.unread, data);
        })
        .catch(function () {});
    }

    root.refreshMelNotificationBadge = refreshCounts;
    refreshCounts();
  }

  function refreshAllBells() {
    document.querySelectorAll('[data-mel-notif-bell]').forEach(function (el) {
      if (typeof el.refreshMelNotificationBadge === 'function') {
        el.refreshMelNotificationBadge();
      }
    });
  }

  Drupal.behaviors.melNotifications = {
    attach: function (context) {
      var settings = drupalSettings.myeventlaneNotifications || {};
      once('mel-notifications-toast', 'body', context).forEach(function () {
        var seconds = Math.min(Math.max(parseInt(settings.pollSeconds, 10) || 25, 15), 45);
        pollToasts(settings);
        window.setInterval(function () {
          pollToasts(settings);
          refreshAllBells();
          if (settings.previewUrl) {
            var openPanel = document.querySelector('[data-mel-notif-bell-panel].is-open');
            if (openPanel) {
              var root = openPanel.closest('[data-mel-notif-bell]');
              var listEl = openPanel.querySelector('[data-mel-notif-bell-list]');
              if (root && listEl) {
                fetch(settings.previewUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                  .then(function (r) {
                    return r.json();
                  })
                  .then(function (data) {
                    renderPreviewList(listEl, data.items || [], settings);
                  })
                  .catch(function () {});
              }
            }
          }
        }, seconds * 1000);
      });

      once('mel-notifications-bell', '[data-mel-notif-bell]', context).forEach(function (el) {
        initBell(el, settings);
      });
    },
  };
})(Drupal, drupalSettings, once);
