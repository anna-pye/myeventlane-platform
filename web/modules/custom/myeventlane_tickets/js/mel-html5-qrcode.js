/**
 * @file
 * Html5Qrcode-compatible minimal scanner for MEL door check-in.
 *
 * Prefers native BarcodeDetector when available; falls back to jsQR on canvas
 * (required for iOS Safari, which does not expose a usable BarcodeDetector for web).
 */
(function (window) {
  'use strict';

  function melScanDebugEnabled() {
    try {
      if (window.MEL_CHECKIN_SCAN_DEBUG === true) {
        return true;
      }
      if (typeof localStorage !== 'undefined' && localStorage.getItem('mel_checkin_scan_debug') === '1') {
        return true;
      }
      if (/[?&]mel_scan_debug=1(?:&|$)/.test(String(window.location.search || ''))) {
        return true;
      }
    }
    catch (e) {}
    return false;
  }

  function buildVideoConstraints(cameraConfig) {
    if (cameraConfig && cameraConfig.video && typeof cameraConfig.video === 'object') {
      return cameraConfig.video;
    }
    if (cameraConfig && typeof cameraConfig.facingMode !== 'undefined') {
      var fm = cameraConfig.facingMode;
      if (typeof fm === 'string') {
        return { facingMode: { ideal: fm } };
      }
      return { facingMode: fm };
    }
    return { facingMode: { ideal: 'environment' } };
  }

  function Html5Qrcode(elementId) {
    this.elementId = elementId;
    this.stream = null;
    this.video = null;
    this.detector = null;
    this.loopHandle = null;
    this.lastHitAt = 0;
    this.canvas = null;
    this.ctx = null;
    this.mode = 'none';
    this.stats = {
      frames: 0,
      successes: 0,
      detectorErrors: 0,
      jsqrNulls: 0
    };
    this.lastDiagLog = 0;
    this.tickBusy = false;
    this.visibilityHandler = null;
  }

  Html5Qrcode.prototype._log = function (message, detail) {
    if (!melScanDebugEnabled()) {
      return;
    }
    var now = Date.now();
    if (message === 'frame' && now - this.lastDiagLog < 2200) {
      return;
    }
    this.lastDiagLog = now;
    if (detail !== undefined) {
      console.info('[MEL_CHECKIN_SCAN]', message, detail);
    } else {
      console.info('[MEL_CHECKIN_SCAN]', message);
    }
  };

  Html5Qrcode.prototype._previewPayload = function (text) {
    var raw = String(text || '');
    return {
      length: raw.length,
      prefix: raw.slice(0, 24)
    };
  };

  Html5Qrcode.prototype._activeTracksDiag = function () {
    if (!this.stream || !this.stream.getTracks) {
      return [];
    }
    return this.stream.getTracks().map(function (t) {
      return {
        kind: t.kind,
        readyState: t.readyState,
        label: typeof t.label === 'string' ? t.label : '',
        settings: typeof t.getSettings === 'function' ? t.getSettings() : {}
      };
    });
  };

  Html5Qrcode.prototype.start = async function (cameraConfig, scanConfig, successCb, errorCb) {
    var self = this;
    var container = document.getElementById(this.elementId);
    if (!container) {
      throw new Error('Scanner container not found.');
    }
    container.innerHTML = '';

    var video = document.createElement('video');
    video.setAttribute('playsinline', '');
    video.setAttribute('webkit-playsinline', '');
    video.playsInline = true;
    video.muted = true;
    video.autoplay = true;
    video.setAttribute('muted', '');
    video.className = 'mel-checkin-video';
    container.appendChild(video);
    this.video = video;

    var constraints = {
      audio: false,
      video: buildVideoConstraints(cameraConfig || {})
    };

    this._log('getUserMedia video constraints', constraints);

    try {
      this.stream = await navigator.mediaDevices.getUserMedia(constraints);
      video.srcObject = this.stream;
    }
    catch (err) {
      if (errorCb) {
        errorCb(String(err));
      }
      throw err;
    }

    await new Promise(function (resolve) {
      if (video.readyState >= 1) {
        resolve();
        return;
      }
      video.addEventListener('loadedmetadata', function () {
        resolve();
      }, { once: true });
    });

    try {
      await video.play();
    }
    catch (err) {
      this._log('video.play error', String(err));
      if (errorCb) {
        errorCb(String(err));
      }
    }

    this._log('camera started', {
      tracks: this._activeTracksDiag(),
      videoWidth: video.videoWidth,
      videoHeight: video.videoHeight,
      readyState: video.readyState
    });

    this.detector = null;
    if ('BarcodeDetector' in window) {
      try {
        this.detector = new window.BarcodeDetector({ formats: ['qr_code'] });
        this.mode = 'barcode_detector';
        this._log('decoder mode', 'barcode_detector');
      }
      catch (e) {
        this.detector = null;
        this._log('BarcodeDetector init failed', String(e));
        if (errorCb) {
          errorCb(String(e));
        }
      }
    }

    if (!this.detector && typeof window.jsQR === 'function') {
      this.mode = 'jsqr';
      this.canvas = document.createElement('canvas');
      this.ctx = this.canvas.getContext('2d', { willReadFrequently: true });
      this._log('decoder mode', 'jsqr');
    }
    else if (!this.detector) {
      this.mode = 'none';
      this._log('decoder mode', 'none (missing jsQR)');
    }

    if (this.mode === 'none') {
      if (errorCb) {
        errorCb('mel_scanner_no_decoder');
      }
      await this.stop();
      throw new Error('mel_scanner_no_decoder');
    }

    var fps = scanConfig && scanConfig.fps ? Math.max(1, Math.min(24, Number(scanConfig.fps))) : 10;
    var intervalMs = Math.max(67, Math.floor(1000 / fps));
    var maxDecodeEdge = 960;

    this.visibilityHandler = function () {
      if (!document.hidden && self.video) {
        self.video.play().catch(function () {});
      }
    };
    document.addEventListener('visibilitychange', this.visibilityHandler);

    this.loopHandle = window.setInterval(function () {
      if (!self.video || self.tickBusy) {
        return;
      }
      self.tickBusy = true;
      Promise.resolve(self._scanOnce(successCb, maxDecodeEdge)).catch(function (e) {
        self.stats.detectorErrors++;
        if (errorCb) {
          errorCb(String(e));
        }
      }).finally(function () {
        self.tickBusy = false;
      });
    }, intervalMs);

    return null;
  };

  Html5Qrcode.prototype._scanOnce = async function (successCb, maxDecodeEdge) {
    var video = this.video;
    if (!video) {
      return;
    }

    if (this.mode === 'barcode_detector' && this.detector) {
      this.stats.frames++;
      var results = await this.detector.detect(video);
      if (results && results.length > 0 && results[0].rawValue) {
        this._emitDecode(results[0].rawValue, successCb);
      }
      if (melScanDebugEnabled() && this.stats.frames % 100 === 0) {
        this._log('frame', {
          mode: this.mode,
          frames: this.stats.frames,
          successes: this.stats.successes,
          vw: video.videoWidth,
          vh: video.videoHeight,
          readyState: video.readyState
        });
      }
      return;
    }

    if (this.mode === 'jsqr' && this.canvas && this.ctx && window.jsQR) {
      if (video.readyState < 2) {
        return;
      }
      var vw = video.videoWidth;
      var vh = video.videoHeight;
      if (vw < 2 || vh < 2) {
        return;
      }
      this.stats.frames++;

      var scale = Math.min(maxDecodeEdge / vw, maxDecodeEdge / vh, 1);
      var cw = Math.max(2, Math.floor(vw * scale));
      var ch = Math.max(2, Math.floor(vh * scale));

      this.canvas.width = cw;
      this.canvas.height = ch;
      this.ctx.drawImage(video, 0, 0, vw, vh, 0, 0, cw, ch);
      var imageData = this.ctx.getImageData(0, 0, cw, ch);
      var code = window.jsQR(imageData.data, cw, ch, { inversionAttempts: 'attemptBoth' });
      if (code && code.data) {
        this._emitDecode(code.data, successCb);
      }
      else {
        this.stats.jsqrNulls++;
      }

      if (melScanDebugEnabled() && this.stats.frames % 100 === 0) {
        this._log('frame', {
          mode: this.mode,
          frames: this.stats.frames,
          successes: this.stats.successes,
          jsqrMisses: this.stats.jsqrNulls,
          decodeSize: cw + 'x' + ch,
          vw: vw,
          vh: vh
        });
      }
    }
  };

  Html5Qrcode.prototype._emitDecode = function (rawValue, successCb) {
    var now = Date.now();
    if (now - this.lastHitAt < 700) {
      return;
    }
    this.lastHitAt = now;
    this.stats.successes++;
    this._log('decode', Object.assign({ mode: this.mode }, this._previewPayload(rawValue)));
    successCb(rawValue, { format: { formatName: 'QR_CODE' } });
  };

  Html5Qrcode.prototype.stop = async function () {
    if (this.loopHandle) {
      window.clearInterval(this.loopHandle);
      this.loopHandle = null;
    }
    if (this.visibilityHandler) {
      document.removeEventListener('visibilitychange', this.visibilityHandler);
      this.visibilityHandler = null;
    }
    if (this.stream) {
      this.stream.getTracks().forEach(function (t) {
        t.stop();
      });
      this.stream = null;
    }
    if (this.video) {
      this.video.srcObject = null;
      this.video = null;
    }
    this.detector = null;
    this.canvas = null;
    this.ctx = null;
  };

  Html5Qrcode.prototype.clear = async function () {
    var el = document.getElementById(this.elementId);
    if (el) {
      el.innerHTML = '';
    }
  };

  window.Html5Qrcode = Html5Qrcode;
})(window);
