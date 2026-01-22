<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElement;
use Drupal\myeventlane_commerce\Service\StripeConnectPaymentService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Stripe Connect payment gateway.
 *
 * Extends the Stripe PaymentElement gateway to add Connect destination charges
 * for vendor ticket sales.
 *
 * @CommercePaymentGateway(
 *   id = "stripe_connect",
 *   label = @Translation("Stripe Connect (Vendor Payments)"),
 *   display_label = @Translation("Credit card"),
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_stripe\PluginForm\PaymentMethodAddForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "mastercard", "visa"
 *   },
 *   requires_billing_information = TRUE,
 * )
 */
class StripeConnect extends StripePaymentElement implements SupportsStoredPaymentMethodsInterface {

  /**
   * The Stripe Connect payment service.
   *
   * @var \Drupal\myeventlane_commerce\Service\StripeConnectPaymentService
   */
  protected StripeConnectPaymentService $stripeConnectPayment;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->stripeConnectPayment = $container->get('myeventlane_commerce.stripe_connect_payment');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentIntent(OrderInterface $order, $intent_attributes = [], ?PaymentInterface $payment = NULL) {
    /** @var array $intent_attributes */
    // Get Connect parameters for this order.
    $connectParams = $this->stripeConnectPayment->getConnectPaymentIntentParams($order);

    // Merge Connect parameters into intent attributes.
    if (!empty($connectParams)) {
      $intent_attributes = array_merge($intent_attributes, $connectParams);
    }

    // Call parent to create the PaymentIntent with Connect parameters.
    return parent::createPaymentIntent($order, $intent_attributes, $payment);
  }

}
