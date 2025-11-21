import QrScanner from 'https://cdn.jsdelivr.net/npm/qr-scanner@1.4.2/qr-scanner.min.js';

(function ($, Drupal) {
  Drupal.behaviors.melQrScan = {
    attach(context) {
      const video = document.getElementById('mel-qr-video');
      const resultBox = document.getElementById('mel-qr-result');

      if (!video || video.dataset.bound) return;
      video.dataset.bound = true;

      const scanner = new QrScanner(video, result => handle(result));
      scanner.start();

      function handle(result) {
        fetch('/vendor/qr/validate', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({code: result}),
        })
        .then(r => r.json())
        .then(res => {
          resultBox.textContent = res.message;
          resultBox.className = 'mel-qr-status ' + res.status;
        });
      }
    }
  };
})(jQuery, Drupal);