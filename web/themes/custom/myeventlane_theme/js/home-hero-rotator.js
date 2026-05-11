/**
 * @file
 * Lightweight homepage hero featured-event rotator.
 */
(function (Drupal, once) {
  'use strict';

  var ROTATION_DELAY = 6200;
  var ITEM_SELECTOR = [
    '.mel-featured-carousel__slide',
    '.mel-featured-carousel__item',
    '.mel-featured-home-grid__item',
  ].join(',');
  var FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]',
  ].join(',');

  function setFocusableState(item, isActive) {
    item.querySelectorAll(FOCUSABLE_SELECTOR).forEach(function (element) {
      if (isActive) {
        if (element.hasAttribute('data-mel-original-tabindex')) {
          var original = element.getAttribute('data-mel-original-tabindex');
          element.removeAttribute('data-mel-original-tabindex');
          if (original === '') {
            element.removeAttribute('tabindex');
          }
          else {
            element.setAttribute('tabindex', original);
          }
        }
        return;
      }

      if (!element.hasAttribute('data-mel-original-tabindex')) {
        element.setAttribute('data-mel-original-tabindex', element.getAttribute('tabindex') || '');
      }
      element.setAttribute('tabindex', '-1');
    });
  }

  function activate(items, index) {
    items.forEach(function (item, itemIndex) {
      var isActive = itemIndex === index;
      item.classList.toggle('is-active', isActive);
      item.setAttribute('aria-hidden', isActive ? 'false' : 'true');
      setFocusableState(item, isActive);
    });
  }

  function clampIndex(index, length) {
    return (index + length) % length;
  }

  function enhanceFeaturedCarousel(carousel) {
    if (carousel.closest('[data-mel-home-hero-rotator]')) {
      return;
    }

    var track = carousel.querySelector('.mel-featured-carousel__track');
    var items = Array.prototype.slice.call(carousel.querySelectorAll('[data-mel-featured-slide]'));
    if (!track || items.length === 0) {
      return;
    }

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var dots = Array.prototype.slice.call(carousel.querySelectorAll('[data-mel-featured-dot]'));
    var prev = carousel.querySelector('[data-mel-featured-prev]');
    var next = carousel.querySelector('[data-mel-featured-next]');
    var activeIndex = 0;
    var timer = null;

    function update(index, shouldScroll) {
      activeIndex = clampIndex(index, items.length);
      items.forEach(function (item, itemIndex) {
        var isActive = itemIndex === activeIndex;
        item.classList.toggle('is-active', isActive);
        item.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        setFocusableState(item, isActive);
      });
      dots.forEach(function (dot, dotIndex) {
        var isActive = dotIndex === activeIndex;
        dot.classList.toggle('is-active', isActive);
        dot.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });

      if (shouldScroll !== false) {
        track.scrollTo({
          left: items[activeIndex].offsetLeft,
          behavior: reducedMotion ? 'auto' : 'smooth',
        });
      }
    }

    function stop() {
      if (timer) {
        window.clearInterval(timer);
        timer = null;
      }
    }

    function start() {
      stop();
      if (reducedMotion || items.length < 2) {
        return;
      }
      timer = window.setInterval(function () {
        update(activeIndex + 1);
      }, ROTATION_DELAY);
    }

    carousel.classList.add('is-enhanced');
    update(0, false);

    if (prev) {
      prev.addEventListener('click', function () {
        stop();
        update(activeIndex - 1);
        start();
      });
    }

    if (next) {
      next.addEventListener('click', function () {
        stop();
        update(activeIndex + 1);
        start();
      });
    }

    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        var index = parseInt(dot.getAttribute('data-mel-featured-dot'), 10);
        if (Number.isNaN(index)) {
          return;
        }
        stop();
        update(index);
        start();
      });
    });

    track.addEventListener('scroll', function () {
      window.clearTimeout(track.melFeaturedScrollTimer);
      track.melFeaturedScrollTimer = window.setTimeout(function () {
        var nearestIndex = activeIndex;
        var nearestDistance = Infinity;
        items.forEach(function (item, index) {
          var distance = Math.abs(item.offsetLeft - track.scrollLeft);
          if (distance < nearestDistance) {
            nearestDistance = distance;
            nearestIndex = index;
          }
        });
        update(nearestIndex, false);
      }, 120);
    }, { passive: true });

    carousel.addEventListener('mouseenter', stop);
    carousel.addEventListener('mouseleave', start);
    carousel.addEventListener('focusin', stop);
    carousel.addEventListener('focusout', start);

    start();
  }

  Drupal.behaviors.melHomeHeroRotator = {
    attach: function (context) {
      once('melFeaturedCarousel', '[data-mel-featured-carousel]', context).forEach(enhanceFeaturedCarousel);

      once('melHomeHeroRotator', '[data-mel-home-hero-rotator]', context).forEach(function (rotator) {
        var items = Array.prototype.slice.call(rotator.querySelectorAll(ITEM_SELECTOR));
        if (items.length === 0) {
          return;
        }

        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var activeIndex = 0;
        var timer = null;

        rotator.classList.add('is-enhanced');
        activate(items, activeIndex);

        if (reducedMotion || items.length < 2) {
          return;
        }

        function stop() {
          if (timer) {
            window.clearInterval(timer);
            timer = null;
          }
        }

        function start() {
          stop();
          timer = window.setInterval(function () {
            activeIndex = (activeIndex + 1) % items.length;
            activate(items, activeIndex);
          }, ROTATION_DELAY);
        }

        rotator.addEventListener('mouseenter', stop);
        rotator.addEventListener('mouseleave', start);
        rotator.addEventListener('focusin', stop);
        rotator.addEventListener('focusout', start);

        start();
      });
    },
  };
})(Drupal, once);
