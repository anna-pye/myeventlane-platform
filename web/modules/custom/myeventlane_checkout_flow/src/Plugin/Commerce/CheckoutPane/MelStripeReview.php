<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsZeroBalanceOrderInterface;
use Drupal\commerce_stripe\Plugin\Commerce\CheckoutPane\StripeReview;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElementInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Displays Stripe Payment Element for charges and zero-value card setup.
 *
 * Commerce Stripe's standard review pane intentionally hides all zero-balance
 * orders. MEL Pro trials are different: their scoped gateway uses a SetupIntent
 * to save a card without charging it today.
 *
 * @CommerceCheckoutPane(
 *   id = "mel_stripe_review",
 *   label = @Translation("MEL Stripe review"),
 *   default_step = "review",
 *   wrapper_element = "container",
 * )
 */
final class MelStripeReview extends StripeReview {

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $paneForm = parent::buildPaneForm($pane_form, $form_state, $complete_form);

    // Commerce Stripe 2.2.1's JS supports SetupIntent confirmation but its
    // review pane does not provide the intentType setting. Supply it only for
    // the zero-value trial SetupIntent created by the MEL Pro gateway.
    $intentId = $this->order->getData('stripe_intent');
    if (is_string($intentId) && str_starts_with($intentId, 'seti_')) {
      $paneForm['#attached']['drupalSettings']['commerceStripePaymentElement']['intentType'] = 'setup';
    }

    return $paneForm;
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible(): bool {
    if (parent::isVisible()) {
      return TRUE;
    }

    $gateway = $this->order->get('payment_gateway');
    $gatewayEntity = $gateway->entity;
    if ($gateway->isEmpty() || !$gatewayEntity instanceof PaymentGatewayInterface || $this->order->isPaid()) {
      return FALSE;
    }

    $balance = $this->order->getBalance();
    $plugin = $gatewayEntity->getPlugin();

    return $balance !== NULL
      && $balance->isZero()
      && $plugin instanceof StripePaymentElementInterface
      && $plugin instanceof SupportsZeroBalanceOrderInterface;
  }

}
