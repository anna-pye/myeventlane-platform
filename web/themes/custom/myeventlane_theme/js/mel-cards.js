/**
 * @file
 * Event card image brightness: adds light/dark text contrast classes.
 */
(function (Drupal, once) {
  'use strict';

  // Perceived luminance (0–255). Single source of truth for all event cards.
  // Matches the former featured-carousel inline threshold (140).
  var MEL_CARD_BRIGHTNESS_THRESHOLD = 140;

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
      var isLight = avg > MEL_CARD_BRIGHTNESS_THRESHOLD;

      card.classList.remove('mel-event-card--light-bg', 'mel-event-card--dark-bg');
      card.classList.add(isLight ? 'mel-event-card--light-bg' : 'mel-event-card--dark-bg');
    }
    catch (e) {
      card.classList.remove('mel-event-card--light-bg');
      card.classList.add('mel-event-card--dark-bg');
    }
  }

  Drupal.behaviors.melCardBrightness = {
    attach: function (context) {
      once('melCardBrightness', '.mel-event-card', context).forEach(function (card) {
        // Homepage hero + Community Spotlight — dark full-bleed overlay treatment.
        if (
          card.closest('.mel-home-hero__feature-rotator')
          || card.closest('.mel-section--community-spotlight')
        ) {
          card.classList.remove('mel-event-card--light-bg');
          card.classList.add('mel-event-card--dark-bg');
          return;
        }

        // Overlay cards only — compact/list use a separate text panel (see _event-card.scss).
        if (
          !card.classList.contains('mel-event-card--standard')
          && !card.classList.contains('mel-event-card--featured')
          && !card.classList.contains('mel-event-card--editorial')
          && !card.classList.contains('mel-event-card--hero')
        ) {
          return;
        }

        var img = card.querySelector(
          '.mel-event-card__image > img, .mel-event-card__image > .mel-event-card__image-element, .mel-event-card__media > img'
        );
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
