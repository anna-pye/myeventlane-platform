/**
 * @file
 * IntersectionObserver on `.mel-event-skeleton` + grid MutationObserver (BigPipe/AJAX).
 * The grid is `display: none` while `data-loaded="false"`, so the grid is never observed.
 */
(function (Drupal, once) {
  'use strict';

  const FAILSAFE_MS = 2000;
  const MIN_DISPLAY = 120;
  const perSkeleton = new WeakMap();
  const imageBindOnce = new WeakSet();

  /**
   * @param {Element} root
   * @return {boolean}
   */
  function imagesReady(root) {
    const imgs = Array.from(root.querySelectorAll('img'));
    if (imgs.length === 0) {
      return true;
    }
    return imgs.every((img) => img.complete && img.naturalWidth > 0);
  }

  /**
   * @param {Element} grid
   * @return {boolean}
   */
  function hasRealCards(grid) {
    return Boolean(grid.querySelector(
      '.mel-event-card:not(.mel-event-card--skeleton)',
    ));
  }

  /**
   * @param {Element} grid
   * @param {Element} skeleton
   * @param {{ force?: boolean }} [options] force=true skips min dwell (failsafe)
   * @return {void}
   */
  function applyReveal(grid, skeleton, options) {
    if (grid.getAttribute('data-loaded') === 'true') {
      return;
    }
    const force = !!(options && options.force);
    if (!force) {
      const st = perSkeleton.get(skeleton);
      if (st && st.startTime != null) {
        const elapsed = performance.now() - st.startTime;
        if (elapsed < MIN_DISPLAY) {
          setTimeout(function () {
            applyReveal(grid, skeleton, options);
          }, MIN_DISPLAY - elapsed);
          return;
        }
      }
    }
    grid.setAttribute('data-loaded', 'true');
    if (skeleton && skeleton.hasAttribute('data-skeleton')) {
      skeleton.classList.add('skeleton-hidden');
      let removed = false;
      const remove = function () {
        if (removed) {
          return;
        }
        removed = true;
        skeleton.setAttribute('hidden', '');
        skeleton.style.display = 'none';
      };
      const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce) {
        remove();
      }
      else {
        // A transitionend event is not guaranteed when the class is applied
        // before first paint. Always remove the faded skeleton from layout so
        // it cannot leave a large blank gap above the real event cards.
        const transitionFallback = setTimeout(remove, 350);
        skeleton.addEventListener('transitionend', function () {
          clearTimeout(transitionFallback);
          remove();
        }, { once: true });
      }
    }
    tearDown(skeleton);
  }

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) {
        return;
      }
      const skeleton = entry.target;
      const grid = skeleton.nextElementSibling;
      if (!grid || !grid.classList || !grid.classList.contains('mel-event-grid')) {
        return;
      }
      if (grid.getAttribute('data-loaded') !== 'false') {
        return;
      }
      const ready = hasRealCards(grid) && imagesReady(grid);
      if (ready) {
        applyReveal(grid, skeleton);
      }
    });
  }, { root: null, rootMargin: '64px 0px', threshold: 0.01 });

  /**
   * Clear timers, MO, and IO; remove pending map entry.
   *
   * @param {Element} skeleton
   */
  function tearDown(skeleton) {
    const s = perSkeleton.get(skeleton);
    if (s) {
      if (s.tid) {
        clearTimeout(s.tid);
      }
      s.mo && s.mo.disconnect();
      perSkeleton.delete(skeleton);
    }
    try {
      observer.unobserve(skeleton);
    }
    catch (e) {
      // Not observed, or already torn down.
    }
  }

  /**
   * @param {Element} skeleton
   * @return {void}
   */
  function tryReveal(skeleton) {
    const grid = skeleton && skeleton.nextElementSibling;
    if (!grid || !grid.classList || !grid.classList.contains('mel-event-grid')) {
      return;
    }
    if (grid.getAttribute('data-loaded') !== 'false') {
      return;
    }
    if (!hasRealCards(grid) || !imagesReady(grid)) {
      return;
    }
    applyReveal(grid, skeleton);
  }

  /**
   * @param {Element} skeleton
   * @param {Element} grid
   */
  function wireImageCompletions(skeleton, grid) {
    function bindOne(img) {
      if (imageBindOnce.has(img) || (img.complete && img.naturalWidth > 0)) {
        return;
      }
      imageBindOnce.add(img);
      const bump = function () { tryReveal(skeleton); };
      if (!img.complete) {
        img.addEventListener('load', bump, { once: true });
        img.addEventListener('error', bump, { once: true });
        return;
      }
      if (img.naturalWidth > 0) {
        return;
      }
      if (img.decode) {
        img.decode().then(bump).catch(bump);
        return;
      }
      setTimeout(bump, 0);
    }
    grid.querySelectorAll('img').forEach((img) => { bindOne(img); });
  }

  Drupal.behaviors.melEventGridSkeleton = {
    attach: function (context) {
      const skeletonEls = once('mel-skeleton', '.mel-event-skeleton', context);
      skeletonEls.forEach((skeleton) => {
        const grid = skeleton.nextElementSibling;
        if (!grid || !grid.classList || !grid.classList.contains('mel-event-grid') || grid.getAttribute('data-loaded') !== 'false') {
          return;
        }
        if (perSkeleton.has(skeleton)) {
          return;
        }

        const st = { mo: null, tid: null, startTime: performance.now() };
        st.mo = new MutationObserver(function () {
          tryReveal(skeleton);
          if (grid.getAttribute('data-loaded') === 'false' && hasRealCards(grid) && !imagesReady(grid)) {
            wireImageCompletions(skeleton, grid);
          }
        });
        st.mo.observe(grid, { childList: true, subtree: true });
        st.tid = setTimeout(function () {
          if (grid.getAttribute('data-loaded') === 'false') {
            applyReveal(grid, skeleton, { force: true });
          }
          else {
            tearDown(skeleton);
          }
        }, FAILSAFE_MS);
        perSkeleton.set(skeleton, st);
        try {
          observer.observe(skeleton);
        }
        catch (e) {
          // ignore
        }
        wireImageCompletions(skeleton, grid);
      });
    },
  };
})(Drupal, once);
