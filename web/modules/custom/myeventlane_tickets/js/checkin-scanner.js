(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.myeventlaneTicketScanner = {
    attach: function attach(context) {
      once('myeventlane-ticket-scanner', '.mel-checkin-scanner-page', context).forEach(function init() {
        var settings = drupalSettings.myeventlaneTicketsCheckin || {};
        if (!settings.submitUrl || !settings.eventId) {
          return;
        }

        var eventId = String(settings.eventId);
        var queueStorageKey = 'mel_checkin_queue_event_' + eventId;
        var dedupeStorageKey = 'mel_checkin_dedupe_event_' + eventId;
        var deviceStorageKey = 'mel_checkin_device_id';
        var deviceId = getOrCreateDeviceId(deviceStorageKey);
        var lastScanValue = '';
        var lastScanAt = 0;

        var offlineIndicator = document.getElementById('mel-checkin-offline-indicator');
        var queuedBadge = document.getElementById('mel-checkin-queued-badge');
        var statusOverlay = document.getElementById('mel-checkin-status-overlay');
        var statusIcon = document.getElementById('mel-checkin-status-icon');
        var statusText = document.getElementById('mel-checkin-status-text');
        var statusSubtext = document.getElementById('mel-checkin-status-subtext');
        var manualForm = document.getElementById('mel-checkin-manual-form');
        var manualInput = document.getElementById('mel-checkin-manual-input');
        var scanner = null;

        updateOnlineState();
        updateQueuedBadge();
        registerServiceWorker();

        if (typeof window.Html5Qrcode === 'function') {
          scanner = new window.Html5Qrcode('mel-checkin-camera');
          scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 280, height: 280 } },
            function onScanSuccess(decodedText) {
              handleInput(decodedText);
            },
            function onScanError() {}
          ).catch(function () {
            showState('invalid', 'Camera unavailable', 'Use manual entry.');
          });
        } else {
          showState('invalid', 'Scanner unavailable', 'Use manual entry.');
        }

        if (manualForm) {
          manualForm.addEventListener('submit', function onManualSubmit(e) {
            e.preventDefault();
            var value = (manualInput && manualInput.value ? manualInput.value : '').trim();
            if (!value) {
              showState('invalid', 'Code required', '');
              return;
            }
            handleInput(value);
            manualInput.value = '';
          });
        }

        window.addEventListener('online', function () {
          updateOnlineState();
          syncQueue();
        });
        window.addEventListener('offline', updateOnlineState);
        window.setInterval(syncQueue, 8000);

        function handleInput(input) {
          var value = String(input || '').trim();
          if (!value) {
            return;
          }
          var now = Date.now();
          if (value === lastScanValue && (now - lastScanAt) < 2000) {
            return;
          }
          lastScanValue = value;
          lastScanAt = now;

          if (navigator.onLine) {
            submitOnline(value, 'online').catch(function () {
              enqueueOffline(value);
            });
          } else {
            enqueueOffline(value);
          }
        }

        async function submitOnline(input, mode) {
          var response = await fetch(settings.submitUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': settings.csrfToken || ''
            },
            credentials: 'same-origin',
            body: JSON.stringify({
              input: input,
              device_id: deviceId,
              mode: mode
            })
          });
          var data = await response.json();
          if (!response.ok) {
            showState('invalid', data.message || 'Request failed', '');
            beep(false);
            return;
          }
          renderResult(data);
        }

        async function enqueueOffline(input) {
          var queue = getQueue();
          var dedupe = getDedupe();
          var payloadHash = await sha256(input);
          if (dedupe[payloadHash]) {
            showState('already_checked_in', 'Already queued on this device', 'Will sync when online.');
            beep(false);
            return;
          }
          queue.push({
            input: input,
            scanned_at_local: Math.floor(Date.now() / 1000),
            payload_hash: payloadHash
          });
          dedupe[payloadHash] = Math.floor(Date.now() / 1000);
          saveQueue(queue);
          saveDedupe(dedupe);
          updateQueuedBadge();
          showState('offline', 'Queued for sync', 'No connection. Will submit when online.');
          beep(true);
        }

        async function syncQueue() {
          if (!navigator.onLine) {
            return;
          }
          var queue = getQueue();
          if (!queue.length) {
            return;
          }
          try {
            var response = await fetch(settings.batchUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': settings.csrfToken || ''
              },
              credentials: 'same-origin',
              body: JSON.stringify({
                device_id: deviceId,
                items: queue.map(function (item) {
                  return {
                    input: item.input,
                    scanned_at_local: item.scanned_at_local
                  };
                })
              })
            });
            var data = await response.json();
            if (!response.ok || !data || !Array.isArray(data.results)) {
              showState('invalid', 'Queue sync failed', '');
              return;
            }
            var successCount = data.results.filter(function (r) { return r && r.ok; }).length;
            localStorage.removeItem(queueStorageKey);
            localStorage.removeItem(dedupeStorageKey);
            updateQueuedBadge();
            showState('admitted', 'Synced ' + String(successCount) + ' scans', 'Queue cleared.');
            beep(true);
          } catch (err) {
            showState('invalid', 'Queue sync failed', '');
          }
        }

        function renderResult(data) {
          var result = String(data.result || 'invalid');
          var message = String(data.message || '');
          var ticket = String(data.ticket_label || '');
          showState(result, message, ticket);
          if (result === 'admitted') {
            beep(true);
          } else {
            beep(false);
          }
        }

        function showState(state, text, subtext) {
          if (!statusOverlay || !statusIcon || !statusText || !statusSubtext) {
            return;
          }
          statusOverlay.className = 'mel-checkin-status-overlay state-' + state;
          if (state === 'admitted') {
            statusIcon.textContent = '✅';
          } else if (state === 'already_checked_in') {
            statusIcon.textContent = '⚠';
          } else if (state === 'offline') {
            statusIcon.textContent = '📶';
          } else {
            statusIcon.textContent = '❌';
          }
          statusText.textContent = text || 'Ready';
          statusSubtext.textContent = subtext || '';
          if (navigator.vibrate) {
            if (state === 'admitted') {
              navigator.vibrate([90, 40, 90]);
            } else {
              navigator.vibrate([180]);
            }
          }
        }

        function beep(success) {
          try {
            var AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) {
              return;
            }
            var audioCtx = new AudioContext();
            var oscillator = audioCtx.createOscillator();
            var gainNode = audioCtx.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            oscillator.type = 'sine';
            oscillator.frequency.value = success ? 880 : 220;
            gainNode.gain.value = 0.1;
            oscillator.start();
            oscillator.stop(audioCtx.currentTime + (success ? 0.08 : 0.14));
          } catch (e) {}
        }

        function updateOnlineState() {
          if (!offlineIndicator) {
            return;
          }
          if (navigator.onLine) {
            offlineIndicator.textContent = 'Online';
            offlineIndicator.className = 'mel-checkin-offline-indicator is-online';
          } else {
            offlineIndicator.textContent = 'Offline';
            offlineIndicator.className = 'mel-checkin-offline-indicator is-offline';
          }
        }

        function updateQueuedBadge() {
          if (!queuedBadge) {
            return;
          }
          var queue = getQueue();
          queuedBadge.textContent = 'Queued: ' + String(queue.length);
        }

        function getQueue() {
          try {
            var raw = localStorage.getItem(queueStorageKey);
            if (!raw) {
              return [];
            }
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
          } catch (e) {
            return [];
          }
        }

        function saveQueue(queue) {
          localStorage.setItem(queueStorageKey, JSON.stringify(queue));
        }

        function getDedupe() {
          try {
            var raw = localStorage.getItem(dedupeStorageKey);
            if (!raw) {
              return {};
            }
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
          } catch (e) {
            return {};
          }
        }

        function saveDedupe(dedupe) {
          localStorage.setItem(dedupeStorageKey, JSON.stringify(dedupe));
        }

        function getOrCreateDeviceId(storageKey) {
          var existing = localStorage.getItem(storageKey);
          if (existing) {
            return existing;
          }
          var id = uuidV4();
          localStorage.setItem(storageKey, id);
          return id;
        }

        function uuidV4() {
          if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
          }
          var arr = new Uint8Array(16);
          window.crypto.getRandomValues(arr);
          arr[6] = (arr[6] & 0x0f) | 0x40;
          arr[8] = (arr[8] & 0x3f) | 0x80;
          var hex = Array.from(arr).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
          return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
        }

        async function sha256(value) {
          if (window.crypto && window.crypto.subtle) {
            var bytes = new TextEncoder().encode(String(value));
            var hash = await window.crypto.subtle.digest('SHA-256', bytes);
            return Array.from(new Uint8Array(hash)).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
          }
          return String(value);
        }

        function registerServiceWorker() {
          if (!('serviceWorker' in navigator) || !settings.swUrl) {
            return;
          }
          navigator.serviceWorker.register(settings.swUrl, {
            scope: settings.swScope || undefined
          }).catch(function () {});
        }
      });
    }
  };
})(Drupal, drupalSettings, once);

