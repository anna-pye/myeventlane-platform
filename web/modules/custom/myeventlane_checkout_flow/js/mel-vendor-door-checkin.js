/**
 * @file
 * Vendor Door Mode: Html5Qrcode scanner + shared result rendering with manual flow.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.melVendorDoorScanner = {
    attach(context) {
      once('mel-vendor-door-scanner', '[data-mel-vendor-door]', context).forEach((root) => {
        const cfg = drupalSettings.melDoorCheckin;
        const startBtn = root.querySelector('[data-mel-door-start-scan]');
        const cameraEl = root.querySelector('#mel-vendor-door-camera');
        const cameraShell = root.querySelector('[data-mel-door-camera-shell]');
        const torchBtn = root.querySelector('[data-mel-door-torch]');
        const stickyScan = root.querySelector('[data-mel-door-sticky-scan]');
        const resultEl = root.querySelector('#checkin-result');

        if (!startBtn || !cameraEl || !cfg || !cfg.validateUrl || !cfg.csrfToken) {
          return;
        }

        const melRes = Drupal.melDoorResult;

        const setScanRunning = (on) => {
          const v = on ? '1' : '0';
          if (cameraShell && cameraShell.getAttribute('data-mel-door-scan-running') !== v) {
            cameraShell.setAttribute('data-mel-door-scan-running', v);
          }
        };

        function postJson(body) {
          return fetch(cfg.validateUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': cfg.csrfToken,
              Accept: 'application/json',
            },
            body: JSON.stringify(body),
          }).then((r) => r.json());
        }

        function handleScanResponse(res) {
          if (melRes && resultEl) {
            melRes.fromValidate(resultEl, res);
            return;
          }
          if (!resultEl) {
            return;
          }
          if (res.status === 'success') {
            const name = res.attendee && res.attendee.name ? res.attendee.name : '';
            const ticket = res.attendee && res.attendee.ticket_type ? res.attendee.ticket_type : '';
            const line = [name, ticket].filter(Boolean).join(' — ');
            resultEl.textContent = line || res.message || Drupal.t('Checked in');
            resultEl.classList.remove('mel-checkin__result--error');
          }
          else if (res.status === 'duplicate') {
            resultEl.textContent = res.message || Drupal.t('Already checked in');
            resultEl.classList.remove('mel-checkin__result--error');
          }
          else {
            resultEl.textContent = res.message || Drupal.t('Could not check in');
            resultEl.classList.add('mel-checkin__result--error');
          }
        }

        let scanner = null;
        let torchOn = false;
        let videoTrack = null;
        let lastScan = '';
        let lastScanAt = 0;

        function stopScanner() {
          if (scanner && typeof scanner.stop === 'function') {
            scanner.stop().catch(() => {});
          }
          scanner = null;
          videoTrack = null;
          setScanRunning(false);
          if (cameraEl) {
            cameraEl.classList.remove('is-active');
          }
          if (torchBtn) {
            torchBtn.hidden = true;
            torchOn = false;
            torchBtn.setAttribute('aria-pressed', 'false');
            torchBtn.textContent = Drupal.t('Light on');
          }
        }

        function applyTorch() {
          if (!videoTrack || typeof videoTrack.applyConstraints !== 'function') {
            return;
          }
          const caps = videoTrack.getCapabilities ? videoTrack.getCapabilities() : {};
          if (!caps.torch) {
            return;
          }
          videoTrack
            .applyConstraints({
              advanced: [{ torch: torchOn }],
            })
            .catch(() => {});
        }

        const runStart = () => {
          if (scanner) {
            return;
          }

          if (typeof window.Html5Qrcode !== 'function') {
            if (melRes && resultEl) {
              melRes.show(resultEl, 'error', {
                title: Drupal.t('Scanner is not ready'),
                meta: Drupal.t('Refresh this page — or use manual check-in below.'),
                detail: '',
              });
            }
            return;
          }
          if (melRes && resultEl) {
            melRes.show(resultEl, 'loading', {
              title: Drupal.t('Starting camera'),
              meta: Drupal.t('Allow camera access when your browser asks.'),
              detail: '',
            });
          }

          cameraEl.hidden = false;
          setScanRunning(true);
          startBtn.setAttribute('disabled', 'disabled');
          scanner = new window.Html5Qrcode('mel-vendor-door-camera');
          scanner
            .start(
              { facingMode: { ideal: 'environment' } },
              { fps: 8, qrbox: { width: Math.min(280, Math.round(window.innerWidth * 0.72)), height: Math.min(280, Math.round(window.innerWidth * 0.72)) } },
              (decodedText) => {
                const code = String(decodedText || '').trim();
                if (!code) {
                  return;
                }
                const now = Date.now();
                if (code === lastScan && now - lastScanAt < 2000) {
                  return;
                }
                lastScan = code;
                lastScanAt = now;

                if (melRes && resultEl) {
                  melRes.show(resultEl, 'loading', {
                    title: Drupal.t('Confirming ticket'),
                    meta: '',
                    detail: '',
                  });
                }

                postJson({ code })
                  .then((res) => {
                    handleScanResponse(res);
                    stopScanner();
                    startBtn.removeAttribute('disabled');
                    cameraEl.hidden = true;
                  })
                  .catch(() => {
                    if (melRes && resultEl) {
                      melRes.show(resultEl, 'error', {
                        title: Drupal.t('Connection issue'),
                        meta: Drupal.t('Check your signal and tap Scan again.'),
                        detail: '',
                      });
                    }
                    stopScanner();
                    cameraEl.hidden = true;
                    startBtn.removeAttribute('disabled');
                  });
              },
              () => {}
            )
            .then(() => {
              cameraEl.classList.add('is-active');
              try {
                const video = cameraEl.querySelector('video');
                const stream = video && video.srcObject;
                if (stream && stream.getVideoTracks && stream.getVideoTracks()[0]) {
                  videoTrack = stream.getVideoTracks()[0];
                  const caps = videoTrack.getCapabilities ? videoTrack.getCapabilities() : {};
                  if (torchBtn && caps.torch) {
                    torchBtn.hidden = false;
                    torchBtn.textContent = Drupal.t('Light on');
                  }
                }
              } catch (e) {
                // Torch is optional.
              }
              if (melRes && resultEl && typeof melRes.hide === 'function') {
                melRes.hide(resultEl);
              }
            })
            .catch(() => {
              stopScanner();
              cameraEl.hidden = true;
              startBtn.removeAttribute('disabled');
              if (melRes && resultEl) {
                melRes.show(resultEl, 'error', {
                  title: Drupal.t('Camera unavailable'),
                  meta: Drupal.t('Allow camera access or use manual check-in below.'),
                  detail: '',
                });
              }
            });
        };

        startBtn.addEventListener('click', runStart);
        if (stickyScan) {
          stickyScan.addEventListener('click', runStart);
        }

        if (torchBtn) {
          torchBtn.addEventListener('click', () => {
            torchOn = !torchOn;
            torchBtn.setAttribute('aria-pressed', torchOn ? 'true' : 'false');
            torchBtn.textContent = torchOn ? Drupal.t('Light off') : Drupal.t('Light on');
            applyTorch();
          });
        }
      });
    },
  };

})(Drupal, drupalSettings, once);
