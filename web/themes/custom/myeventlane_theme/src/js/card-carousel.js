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
  const rotator = carousel.closest('[data-mel-spotlight-rotator]');
  const root = shell || rotator || carousel;
  return {
    nextEl: root.querySelector('[data-mel-carousel-next]'),
    prevEl: root.querySelector('[data-mel-carousel-prev]'),
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
 * Lightweight Community Spotlight rotator — one editorial group visible at a time.
 *
 * @param {Document|Element} context
 */
function initSpotlightRotators(context) {
  const root = context && context.querySelectorAll ? context : document;

  root.querySelectorAll('[data-mel-spotlight-rotator]').forEach((rotator) => {
    if (rotator.dataset.melSpotlightInit === '1') {
      return;
    }
    rotator.dataset.melSpotlightInit = '1';

    const slides = Array.from(rotator.querySelectorAll('.mel-spotlight-rotator__slide'));
    const prevBtn = rotator.querySelector('[data-mel-carousel-prev]');
    const nextBtn = rotator.querySelector('[data-mel-carousel-next]');
    if (slides.length <= 1) {
      prevBtn?.setAttribute('hidden', '');
      nextBtn?.setAttribute('hidden', '');
      return;
    }

    let activeIndex = slides.findIndex((slide) => slide.classList.contains('is-active'));
    if (activeIndex < 0) {
      activeIndex = 0;
    }

    const setActive = (index) => {
      activeIndex = (index + slides.length) % slides.length;
      slides.forEach((slide, i) => {
        const isActive = i === activeIndex;
        slide.classList.toggle('is-active', isActive);
        slide.setAttribute('aria-hidden', isActive ? 'false' : 'true');
      });
    };

    prevBtn?.addEventListener('click', () => setActive(activeIndex - 1));
    nextBtn?.addEventListener('click', () => setActive(activeIndex + 1));

    rotator.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        setActive(activeIndex - 1);
      }
      else if (event.key === 'ArrowRight') {
        event.preventDefault();
        setActive(activeIndex + 1);
      }
    });

    let touchStartX = 0;
    rotator.addEventListener('touchstart', (event) => {
      touchStartX = event.changedTouches[0]?.screenX ?? 0;
    }, { passive: true });
    rotator.addEventListener('touchend', (event) => {
      const touchEndX = event.changedTouches[0]?.screenX ?? 0;
      const delta = touchEndX - touchStartX;
      if (Math.abs(delta) >= 40) {
        setActive(activeIndex + (delta < 0 ? 1 : -1));
      }
    }, { passive: true });
  });
}

/**
 * @param {Document|Element} context
 */
function initMelCardCarousels(context) {
  const root = context && context.querySelectorAll ? context : document;

  initSpotlightRotators(root);

  root.querySelectorAll('.mel-card-carousel--spotlight').forEach((carousel) => {
    const nav = getNavigationElements(carousel);
    createCarousel(carousel, {
      slidesPerView: 1.15,
      spaceBetween: 12,
      centeredInsufficientSlides: true,
      watchOverflow: true,
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
  document.addEventListener('DOMContentLoaded', () => {
    initSpotlightRotators(document);
    initMelCardCarousels(document);
  });
}
else {
  initSpotlightRotators(document);
  initMelCardCarousels(document);
}
