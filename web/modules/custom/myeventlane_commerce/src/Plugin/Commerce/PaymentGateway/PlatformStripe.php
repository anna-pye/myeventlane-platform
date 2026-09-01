<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\Stripe;
use Stripe\PaymentIntent;
use Stripe\Stripe as StripeLibrary;

/**
 * Isolates MEL platform payments from Stripe Connect account state.
 *
 * Commerce Stripe and the Stripe SDK use global static credentials. Re-enter
 * the configured platform context before every remote operation so a platform
 * PaymentMethod can never be looked up with a connected-account header or a
 * credential left behind by another MEL gateway.
 */
final class PlatformStripe extends Stripe {

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE): void {
    $this->enterPlatformContext();
    parent::createPayment($payment, $capture);
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->enterPlatformContext();
    parent::capturePayment($payment, $amount);
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment): void {
    $this->enterPlatformContext();
    parent::voidPayment($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->enterPlatformContext();
    parent::refundPayment($payment, $amount);
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details): void {
    $this->enterPlatformContext();
    parent::createPaymentMethod($payment_method, $payment_details);
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method): void {
    $this->enterPlatformContext();
    parent::deletePaymentMethod($payment_method);
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentIntent(OrderInterface $order, array $intent_attributes = [], ?PaymentInterface $payment = NULL): ?PaymentIntent {
    $this->enterPlatformContext();
    return parent::createPaymentIntent($order, $intent_attributes, $payment);
  }

  /**
   * Restores this plugin's configured key and the platform account scope.
   */
  private function enterPlatformContext(): void {
    $this->init();
    StripeLibrary::setAccountId(NULL);
  }

}
