<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsZeroBalanceOrderInterface;
use Drupal\commerce_stripe\ErrorHelper;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElement;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\SetupIntent;

/**
 * Provides a Stripe Payment Element that can save a card for a free Pro trial.
 *
 * The contributed Stripe Payment Element already creates SetupIntents for
 * zero-value orders, but does not declare Commerce's zero-balance capability.
 * Without that marker Commerce removes the gateway before checkout is built.
 * This scoped plugin changes only the Pro recurring gateway.
 *
 * @CommercePaymentGateway(
 *   id = "mel_pro_stripe_payment_element",
 *   label = @Translation("MEL Pro Stripe Payment Element"),
 *   display_label = @Translation("Stripe Payment Element"),
 *   payment_method_types = {"stripe_card"},
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_stripe\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard",
 *     "visa", "unionpay"
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
final class ProStripePaymentElement extends StripePaymentElement implements SupportsZeroBalanceOrderInterface {

  /**
   * {@inheritdoc}
   *
   * A trial has no amount to charge today. Use a SetupIntent so Stripe's
   * Payment Element can authenticate and save a reusable payment method for
   * the first off-session renewal. Non-zero Pro orders retain the contributed
   * gateway's normal PaymentIntent flow.
   */
  public function createIntent(OrderInterface $order): PaymentIntent|SetupIntent {
    $balance = $order->getBalance();
    if ($balance === NULL || !$balance->isZero()) {
      return parent::createIntent($order);
    }

    $intentAttributes = [
      'usage' => 'off_session',
      'automatic_payment_methods' => [
        'enabled' => TRUE,
      ],
      'metadata' => [
        'order_id' => $order->id(),
        'store_id' => $order->getStoreId(),
        'purpose' => 'mel_pro_trial',
      ],
    ];

    $customerRemoteId = $this->getRemoteCustomerId($order->getCustomer());
    if ($customerRemoteId !== NULL && $customerRemoteId !== '') {
      $intentAttributes['customer'] = $customerRemoteId;
    }

    try {
      $intent = SetupIntent::create($intentAttributes);
      $order->setData('stripe_intent', $intent->id)->save();
    }
    catch (ApiErrorException $exception) {
      ErrorHelper::handleException($exception);
      throw $exception;
    }

    return $intent;
  }

}
