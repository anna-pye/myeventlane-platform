/**
 * @file
 * Applies MEL typography inside Commerce Stripe's provider-hosted card fields.
 */

(function (Drupal, once) {
  'use strict';

  const STRIPE_CARD_STYLE = {
    base: {
      color: '#24303a',
      fontFamily: 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
      fontSize: '18px',
      fontSmoothing: 'antialiased',
      fontWeight: '500',
      lineHeight: '24px',
      '::placeholder': {
        color: '#667085',
      },
    },
    invalid: {
      color: '#b42318',
      iconColor: '#b42318',
    },
  };

  Drupal.behaviors.melStripeCardPresentation = {
    attach(context) {
      const forms = once('mel-stripe-card-presentation', '.stripe-form', context);

      forms.forEach(() => {
        let attempts = 0;
        const applyStyle = () => {
          attempts += 1;
          const commerceStripe = Drupal.behaviors.commerceStripeForm;
          const cardElements = [
            commerceStripe?.cardNumber,
            commerceStripe?.cardExpiry,
            commerceStripe?.cardCvc,
          ];
          let styledElements = 0;

          cardElements.forEach((cardElement) => {
            if (cardElement && typeof cardElement.update === 'function') {
              cardElement.update({ style: STRIPE_CARD_STYLE });
              styledElements += 1;
            }
          });

          // Commerce Stripe mounts Elements in another Drupal behavior. Retry
          // briefly when MEL ran first, including after an AJAX form rebuild.
          if (styledElements < cardElements.length && attempts < 10) {
            window.setTimeout(applyStyle, 50);
          }
        };

        window.requestAnimationFrame(applyStyle);
      });
    },
  };
})(Drupal, once);
