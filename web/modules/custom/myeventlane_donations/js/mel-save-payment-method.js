/**
 * @file
 * SetupIntent + Stripe Payment Element for saving payment method.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  function initSavePaymentMethod(container) {
    const config = drupalSettings.melSavePaymentMethod;
    if (!config || !config.clientSecret || !config.publishableKey || !config.returnUrl) {
      return;
    }

    const form = container.querySelector('#mel-save-payment-form');
    const submitBtn = container.querySelector('#mel-save-payment-submit');
    const errorsEl = container.querySelector('#mel-payment-element-errors');
    const elementContainer = container.querySelector('#mel-payment-element-container');

    if (!form || !submitBtn || !elementContainer) {
      return;
    }

    const stripe = Stripe(config.publishableKey);
    const elements = stripe.elements({ clientSecret: config.clientSecret });

    const paymentElement = elements.create('payment');
    paymentElement.mount(elementContainer);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (submitBtn.disabled) {
        return;
      }

      submitBtn.disabled = true;
      errorsEl.textContent = '';

      stripe.confirmSetup({
        elements,
        confirmParams: {
          return_url: config.returnUrl,
        },
      }).then(function (result) {
        if (result.error) {
          errorsEl.textContent = result.error.message || 'An error occurred.';
          submitBtn.disabled = false;
        } else if (result.setupIntent && result.setupIntent.status === 'succeeded') {
          window.location.href = config.returnUrl;
        }
        // If redirect needed (e.g. 3DS), Stripe handles it via return_url.
      });
    });
  }

  Drupal.behaviors.melSavePaymentMethod = {
    attach: function (context, settings) {
      once('mel-save-payment-method', '.mel-mel-save-payment', context).forEach(initSavePaymentMethod);
    },
  };
})(Drupal, drupalSettings, once);
