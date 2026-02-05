/**
 * @file
 * Stripe.js Fallback Loader
 *
 * Safety net when commerce_stripe/stripe library fails to load Stripe.js.
 * Runs once on DOM ready. If Stripe already loaded, exits immediately.
 * Only loads manually and re-runs behaviors when library clearly failed.
 */

(function () {
  'use strict';

  const FALLBACK_TIMEOUT_MS = 8000;
  const POLL_INTERVAL_MS = 200;
  let fallbackRan = false;

  /**
   * Re-runs Commerce Stripe behaviors. Only when Stripe was not loaded before.
   */
  function reRunStripeBehaviors() {
    if (typeof Drupal === 'undefined' || !Drupal.behaviors) {
      return;
    }
    if (Drupal.behaviors.commerceStripeForm) {
      Drupal.behaviors.commerceStripeForm.attach(document);
    }
    if (Drupal.behaviors.commerceStripePaymentElement) {
      Drupal.behaviors.commerceStripePaymentElement.attach(document);
    }
  }

  /**
   * Load Stripe.js manually from CDN. Only when library failed.
   */
  function loadStripeManually() {
    const script = document.createElement('script');
    script.src = 'https://js.stripe.com/v3/';
    script.async = false;
    script.defer = false;

    script.onload = function () {
      reRunStripeBehaviors();
    };

    script.onerror = function () {
      console.error('[Stripe Fallback] Failed to load Stripe.js from CDN');
    };

    const firstScript = document.querySelector('script');
    if (firstScript && firstScript.parentNode) {
      firstScript.parentNode.insertBefore(script, firstScript);
    } else {
      document.head.appendChild(script);
    }
  }

  /**
   * Fallback: run only when Stripe is not loaded. Single run per page.
   */
  function loadStripeFallback() {
    if (fallbackRan) {
      return;
    }

    if (typeof window.Stripe !== 'undefined') {
      fallbackRan = true;
      return;
    }

    const existingScript = document.querySelector('script[src*="js.stripe.com"]');
    if (existingScript) {
      let elapsed = 0;
      const poll = setInterval(function () {
        elapsed += POLL_INTERVAL_MS;
        if (typeof window.Stripe !== 'undefined') {
          clearInterval(poll);
          fallbackRan = true;
          reRunStripeBehaviors();
          return;
        }
        if (elapsed >= FALLBACK_TIMEOUT_MS) {
          clearInterval(poll);
          fallbackRan = true;
          loadStripeManually();
        }
      }, POLL_INTERVAL_MS);
      return;
    }

    fallbackRan = true;
    loadStripeManually();
  }

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    loadStripeFallback();
  } else {
    document.addEventListener('DOMContentLoaded', loadStripeFallback);
  }
})();
