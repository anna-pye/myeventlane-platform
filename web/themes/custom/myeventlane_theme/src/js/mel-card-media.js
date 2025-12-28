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
      const firstSlide = track.querySelector('.mel-featured-carousel__slide');
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
      initCarouselControls(context);
    }
  };

})(Drupal);
