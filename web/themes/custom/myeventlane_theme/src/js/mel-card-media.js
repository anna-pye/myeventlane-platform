/**
 * @file
 * Event card image loading and carousel controls.
 * Uses Drupal behaviors with once() for proper initialization.
 */

(function (Drupal) {
  'use strict';

  /**
   * Initialize image loading skeleton.
   * Marks images as loaded when they complete loading.
   */
  function initImageSkeleton(context) {
    const imageWrappers = once('mel-card-image', '.mel-event-card__image', context);

    imageWrappers.forEach(function (wrapper) {
      const img = wrapper.querySelector('img.mel-event-card__image-element, img');

      if (!img) {
        // No image, mark as loaded to remove skeleton
        wrapper.classList.add('is-loaded');
        return;
      }

      // Check if image is already loaded
      if (img.complete && img.naturalHeight !== 0) {
        wrapper.classList.add('is-loaded');
        img.classList.add('is-loaded');
        return;
      }

      // Wait for image to load
      img.addEventListener('load', function () {
        wrapper.classList.add('is-loaded');
        img.classList.add('is-loaded');
      }, { once: true });

      // Handle error case - show placeholder
      img.addEventListener('error', function () {
        wrapper.classList.add('is-loaded');
        img.style.display = 'none';
        const fallbackPlaceholder = wrapper.querySelector('.mel-event-card__placeholder--fallback');
        if (fallbackPlaceholder) {
          fallbackPlaceholder.style.display = 'flex';
        }
      }, { once: true });
    });
  }

  /**
   * Auto-advance full-width featured hero slider (homepage).
   * Pauses on hover, focus-within, hidden document, or off-screen; respects
   * prefers-reduced-motion (no autoplay).
   */
  function initFeaturedHeroAutoplay(context) {
    const heroes = once('mel-featured-hero-autoplay', '.mel-featured-hero', context);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    heroes.forEach(function (hero) {
      const track = hero.querySelector('.mel-featured-hero__track');
      if (!track) {
        return;
      }

      const slides = track.querySelectorAll('.mel-featured-hero__slide');
      if (slides.length < 2) {
        return;
      }

      const intervalMs = Math.max(
        4000,
        parseInt(hero.getAttribute('data-mel-hero-interval'), 10) || 5500,
      );

      let intervalId = null;
      let userPaused = false;
      let viewportPaused = false;
      const ac = new AbortController();
      const signal = ac.signal;

      function shouldRun() {
        return (
          !reducedMotion.matches &&
          !userPaused &&
          !viewportPaused &&
          !document.hidden
        );
      }

      function advance() {
        if (!shouldRun()) {
          return;
        }
        const w = track.clientWidth;
        if (w < 8) {
          return;
        }
        const maxScroll = track.scrollWidth - w;
        const atEnd = track.scrollLeft + w >= maxScroll - 4;
        const nextLeft = atEnd ? 0 : track.scrollLeft + w;

        track.scrollTo({
          left: nextLeft,
          behavior: reducedMotion.matches ? 'auto' : 'smooth',
        });
      }

      function startTimer() {
        if (intervalId !== null || !shouldRun()) {
          return;
        }
        intervalId = window.setInterval(advance, intervalMs);
      }

      function stopTimer() {
        if (intervalId === null) {
          return;
        }
        window.clearInterval(intervalId);
        intervalId = null;
      }

      function syncTimer() {
        if (shouldRun()) {
          startTimer();
        }
        else {
          stopTimer();
        }
      }

      hero.addEventListener(
        'mouseenter',
        function () {
          userPaused = true;
          stopTimer();
        },
        { signal: signal },
      );

      hero.addEventListener(
        'mouseleave',
        function () {
          userPaused = false;
          syncTimer();
        },
        { signal: signal },
      );

      hero.addEventListener(
        'focusin',
        function () {
          userPaused = true;
          stopTimer();
        },
        { signal: signal },
      );

      hero.addEventListener(
        'focusout',
        function (e) {
          if (!hero.contains(e.relatedTarget)) {
            userPaused = false;
            syncTimer();
          }
        },
        { signal: signal },
      );

      document.addEventListener(
        'visibilitychange',
        function () {
          syncTimer();
        },
        { signal: signal },
      );

      reducedMotion.addEventListener(
        'change',
        function () {
          syncTimer();
        },
        { signal: signal },
      );

      if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver(
          function (entries) {
            entries.forEach(function (entry) {
              viewportPaused = !entry.isIntersecting;
              syncTimer();
            });
          },
          { threshold: 0.15, rootMargin: '0px' },
        );
        io.observe(hero);
        hero._melHeroIO = io;
      }

      hero._melHeroAutoplay = {
        destroy: function () {
          stopTimer();
          ac.abort();
          if (hero._melHeroIO) {
            hero._melHeroIO.disconnect();
            delete hero._melHeroIO;
          }
          delete hero._melHeroAutoplay;
        },
      };

      syncTimer();
    });
  }

  /**
   * Initialize carousel controls.
   * Adds prev/next button functionality for featured carousel.
   */
  function initCarouselControls(context) {
    const carousels = once('mel-carousel', '.mel-featured-carousel', context);

    carousels.forEach(function (carousel) {
      const track = carousel.querySelector('.mel-featured-carousel__track');
      const prevButton = carousel.querySelector('.mel-featured-carousel__button--prev');
      const nextButton = carousel.querySelector('.mel-featured-carousel__button--next');

      if (!track || !prevButton || !nextButton) {
        return;
      }

      // Get slide width for scroll distance
      const firstSlide = track.querySelector('.mel-featured-carousel__slide, .mel-featured-carousel__item');
      if (!firstSlide) {
        return;
      }

      const slideWidth = firstSlide.offsetWidth + parseInt(getComputedStyle(track).gap, 10) || 16;

      // Respect prefers-reduced-motion
      const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      const scrollBehavior = prefersReducedMotion ? 'auto' : 'smooth';

      // Previous button
      prevButton.addEventListener('click', function () {
        track.scrollBy({
          left: -slideWidth,
          behavior: scrollBehavior
        });
      });

      // Next button
      nextButton.addEventListener('click', function () {
        track.scrollBy({
          left: slideWidth,
          behavior: scrollBehavior
        });
      });
    });
  }

  /**
   * Drupal behavior for event card enhancements.
   */
  Drupal.behaviors.melCardMedia = {
    attach: function (context, settings) {
      initImageSkeleton(context);
      initFeaturedHeroAutoplay(context);
      initCarouselControls(context);
    },

    detach: function (context, settings, trigger) {
      once
        .remove('mel-featured-hero-autoplay', '.mel-featured-hero', context)
        .forEach(function (hero) {
          if (hero._melHeroAutoplay && typeof hero._melHeroAutoplay.destroy === 'function') {
            hero._melHeroAutoplay.destroy();
          }
        });
    },
  };

})(Drupal);
