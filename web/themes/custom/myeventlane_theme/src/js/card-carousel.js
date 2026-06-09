/**
 * @file
 * MEL Card Carousel (Swiper) — Drupal behavior + once guard.
 *
 * Initialises when .mel-card-carousel is present (including BigPipe/AJAX inserts).
 */

import Swiper from 'swiper';
import { Navigation, Keyboard } from 'swiper/modules';
import 'swiper/css';
import 'swiper/css/navigation';

Swiper.use([Navigation, Keyboard]);

/**
 * @param {HTMLElement} carousel
 * @return {{ nextEl: Element|null, prevEl: Element|null }}
 */
function getNavigationElements(carousel) {
  const shell = carousel.closest('.mel-card-carousel-shell');
  const root = shell || carousel;
  return {
    nextEl: root.querySelector('.swiper-button-next'),
    prevEl: root.querySelector('.swiper-button-prev'),
  };
}

/**
 * @param {HTMLElement} carousel
 * @param {import('swiper').SwiperOptions} options
 */
function createCarousel(carousel, options) {
  if (carousel.swiper) {
    carousel.swiper.update();
    return;
  }
  if (carousel.dataset.melSwiperInit === '1') {
    return;
  }
  carousel.dataset.melSwiperInit = '1';
  // eslint-disable-next-line no-new
  new Swiper(carousel, options);
}

/**
 * @param {Document|Element} context
 */
function initMelCardCarousels(context) {
  const root = context && context.querySelectorAll ? context : document;

  root.querySelectorAll('.mel-card-carousel--spotlight').forEach((carousel) => {
    const nav = getNavigationElements(carousel);
    createCarousel(carousel, {
      slidesPerView: 1.15,
      spaceBetween: 12,
      // Keep swipe enabled whenever there is more than one slide; never fit all
      // slides in view at desktop (slidesPerView: 4 + 4 slides => isLocked: true).
      watchOverflow: false,
      grabCursor: true,
      simulateTouch: true,
      touchEventsTarget: 'container',
      shortSwipes: true,
      longSwipes: true,
      resistanceRatio: 0.85,
      observer: true,
      observeParents: true,
      resizeObserver: true,
      keyboard: {
        enabled: true,
      },
      navigation: {
        nextEl: nav.nextEl,
        prevEl: nav.prevEl,
      },
      breakpoints: {
        768: {
          slidesPerView: 2.15,
          spaceBetween: 16,
        },
        1024: {
          slidesPerView: 3.15,
          spaceBetween: 20,
        },
        1280: {
          slidesPerView: 3.5,
          spaceBetween: 24,
        },
      },
    });
  });

  root.querySelectorAll('.mel-card-carousel:not(.mel-card-carousel--spotlight)').forEach((carousel) => {
    const nav = getNavigationElements(carousel);
    createCarousel(carousel, {
      slidesPerView: 1.15,
      spaceBetween: 12,
      watchOverflow: true,
      grabCursor: true,
      simulateTouch: true,
      touchEventsTarget: 'container',
      keyboard: {
        enabled: true,
      },
      navigation: {
        nextEl: nav.nextEl,
        prevEl: nav.prevEl,
      },
      breakpoints: {
        768: {
          slidesPerView: 2,
          spaceBetween: 24,
        },
      },
    });
  });
}

if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
  Drupal.behaviors.melCardCarousel = {
    attach(context) {
      initMelCardCarousels(context || document);
    },
  };
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => initMelCardCarousels(document));
}
else {
  initMelCardCarousels(document);
}
