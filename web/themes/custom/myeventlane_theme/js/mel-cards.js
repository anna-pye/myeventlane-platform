/**
 * @file
 * Event card image brightness: adds light/dark text contrast classes.
 */
(function (Drupal, once) {
  'use strict';

  function applyBrightness(img, card) {
    var canvas = document.createElement('canvas');
    var ctx = canvas.getContext('2d');
    if (!ctx) {
      return;
    }

    var sW = 100;
    var sH = 60;
    canvas.width = sW;
    canvas.height = sH;

    try {
      var srcY = img.naturalHeight * 0.6;
      var srcH = img.naturalHeight * 0.4;

      ctx.drawImage(img, 0, srcY, img.naturalWidth, srcH, 0, 0, sW, sH);

      var data = ctx.getImageData(0, 0, sW, sH).data;
      var total = 0;

      for (var i = 0; i < data.length; i += 4) {
        total += (data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114);
      }

      var avg = total / (data.length / 4);

      card.classList.add(avg > 140 ? 'mel-event-card--light-bg' : 'mel-event-card--dark-bg');
    }
    catch (e) {
      card.classList.add('mel-event-card--dark-bg');
    }
  }

  Drupal.behaviors.melCardBrightness = {
    attach: function (context) {
      once('melCardBrightness', '.mel-event-card', context).forEach(function (card) {
        // Image-led listing cards use fixed overlay + CTA tokens (not adaptive chips).
        if (
          card.classList.contains('mel-event-card--standard')
          || card.classList.contains('mel-event-card--hero')
          || card.classList.contains('mel-event-card--featured')
        ) {
          return;
        }

        var img = card.querySelector('.mel-event-card__image-element');
        if (!img) {
          return;
        }

        if (img.complete && img.naturalWidth > 0) {
          applyBrightness(img, card);
        }
        else {
          img.addEventListener('load', function onLoad() {
            img.removeEventListener('load', onLoad);
            applyBrightness(img, card);
          });
        }
      });
    },
  };
})(Drupal, once);
