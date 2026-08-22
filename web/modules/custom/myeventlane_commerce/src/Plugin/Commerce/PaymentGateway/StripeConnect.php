<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_stripe\ErrorHelper;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElement;
use Drupal\commerce_stripe\WebhookEventState;
use Drupal\myeventlane_commerce\Service\DirectChargeOperationalEventHandler;
use Drupal\myeventlane_commerce\Service\StripeConnectPaymentService;
use Drupal\user\UserInterface;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Refund;
use Stripe\Stripe as StripeLibrary;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the Stripe Connect payment gateway.
 *
 * Creates organiser direct charges in the connected Stripe account context.
 *
 * @CommercePaymentGateway(
 *   id = "stripe_connect",
 *   label = @Translation("Stripe Connect (Vendor Payments)"),
 *   display_label = @Translation("Credit card"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_stripe\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"stripe_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "mastercard", "visa"
 *   },
 *   requires_billing_information = TRUE,
 * )
 */
class StripeConnect extends StripePaymentElement {

  /**
   * The Stripe Connect payment service.
   *
   * @var \Drupal\myeventlane_commerce\Service\StripeConnectPaymentService
   */
  protected StripeConnectPaymentService $stripeConnectPayment;

  /**
   * Queues approved organiser alerts for critical Connect events.
   */
  protected DirectChargeOperationalEventHandler $operationalEventHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): StripePaymentElement {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->stripeConnectPayment = $container->get('myeventlane_commerce.stripe_connect_payment');
    $instance->operationalEventHandler = $container->get('myeventlane_commerce.direct_charge_operational_event_handler');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentIntent(OrderInterface $order, $intent_attributes = [], ?PaymentInterface $payment = NULL): PaymentIntent {
    $previousAccount = $this->enterConnectedAccountContext($order, TRUE);
    try {
      /** @var array $intent_attributes */
      $connectParams = $this->stripeConnectPayment->getConnectPaymentIntentParams($order);

      if (!empty($connectParams)) {
        // Deep-merge metadata so we never overwrite keys set by Commerce or
        // contrib modules (e.g. payment_intent_id added by commerce_stripe).
        if (isset($connectParams['metadata']) && isset($intent_attributes['metadata'])) {
          $connectParams['metadata'] = array_merge(
            (array) $intent_attributes['metadata'],
            $connectParams['metadata'],
          );
        }
        $intent_attributes = array_merge($intent_attributes, $connectParams);
      }

      return parent::createPaymentIntent($order, $intent_attributes, $payment);
    }
    finally {
      StripeLibrary::setAccountId($previousAccount);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE): void {
    $previousAccount = $this->enterConnectedAccountContext($payment->getOrder());
    try {
      parent::createPayment($payment, $capture);
    }
    finally {
      StripeLibrary::setAccountId($previousAccount);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request): void {
    $previousAccount = $this->enterConnectedAccountContext($order);
    try {
      parent::onReturn($order, $request);
    }
    finally {
      StripeLibrary::setAccountId($previousAccount);
    }
  }

  /**
   * Processes Connect webhook objects in their originating account context.
   *
   * Connect events include the connected account on the event envelope. The
   * parent handler may retrieve related Stripe objects, so the same account
   * context is required for both synchronous and queued webhook processing.
   */
  public function processWebHook(?int $webhook_event_id, Event $webhook_event): ?Response {
    $accountId = trim((string) ($webhook_event->account ?? ''));
    if (!str_starts_with($accountId, 'acct_')) {
      throw new \LogicException('Connected Stripe account is missing from direct-charge webhook.');
    }

    $previousAccount = StripeLibrary::getAccountId();
    StripeLibrary::setAccountId($accountId);
    try {
      if ($this->operationalEventHandler->supports((string) $webhook_event->type)) {
        try {
          $result = $this->operationalEventHandler->handle($webhook_event);
          $status = $result['handled']
            ? WebhookEventState::Succeeded->value
            : WebhookEventState::Skipped->value;
          $this->updateWebhookEventStatus($webhook_event_id, $status, $result['reason']);
          return NULL;
        }
        catch (\Throwable $throwable) {
          $this->updateWebhookEventStatus($webhook_event_id, WebhookEventState::Failed->value, $throwable->getMessage());
          throw $throwable;
        }
      }
      return parent::processWebHook($webhook_event_id, $webhook_event);
    }
    finally {
      StripeLibrary::setAccountId($previousAccount);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $previousAccount = $this->enterConnectedAccountContext($payment->getOrder());
    try {
      parent::capturePayment($payment, $amount);
    }
    finally {
      StripeLibrary::setAccountId($previousAccount);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment): void {
    $previousAccount = $this->enterConnectedAccountContext($payment->getOrder());
    try {
      parent::voidPayment($payment);
    }
    finally {
      StripeLibrary::setAccountId($previousAccount);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $previousAccount = $this->enterConnectedAccountContext($payment->getOrder());
    try {
      $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
      $amount = $amount ?: $payment->getAmount();
      $this->assertRefundAmount($payment, $amount);

      try {
        $refund = Refund::create([
          'amount' => $this->minorUnitsConverter->toMinorUnits($amount),
          'payment_intent' => $payment->getRemoteId(),
          // Stripe returns the full application fee when the charge becomes
          // fully refunded, and a proportional amount for partial refunds.
          'refund_application_fee' => TRUE,
          'metadata' => [
            'refund_source' => self::PAYMENT_SOURCE,
            'refund_uid' => $this->currentUser->id(),
          ],
        ], [
          'idempotency_key' => $this->uuidService->generate(),
        ]);
        ErrorHelper::handleErrors($refund, $payment);

        $refundedAmount = $payment->getRefundedAmount()->add($amount);
        $payment->setState($refundedAmount->lessThan($payment->getAmount())
          ? 'partially_refunded'
          : 'refunded');
        $payment->setRefundedAmount($refundedAmount);
        $payment->save();
      }
      catch (ApiErrorException $exception) {
        ErrorHelper::handleException($exception, $payment);
      }
    }
    finally {
      StripeLibrary::setAccountId($previousAccount);
    }
  }

  /**
   * Direct-charge payment methods cannot be reused across organiser accounts.
   */
  protected function isReusable(): bool {
    return FALSE;
  }

  /**
   * A gateway-level customer ID cannot identify customers in many accounts.
   */
  public function getRemoteCustomerId(UserInterface $account): ?string {
    return NULL;
  }

  /**
   * Keeps the local method single-use and avoids creating Stripe Customers.
   */
  public function attachCustomerToStripePaymentMethod(PaymentMethodInterface $payment_method, array $payment_details): PaymentMethod {
    $payment_method->setReusable(FALSE);
    /** @var \Stripe\PaymentMethod $stripePaymentMethod */
    $stripePaymentMethod = $payment_details['stripe_payment_method'];
    return $stripePaymentMethod;
  }

  /**
   * Enters the immutable connected-account context for an order.
   */
  private function enterConnectedAccountContext(OrderInterface $order, bool $persist = FALSE): ?string {
    $accountId = trim((string) ($order->getData('stripe_connected_account_id') ?? ''));
    if ($accountId === '') {
      $validation = $this->stripeConnectPayment->validateDirectChargeOrder($order);
      if (!$validation['valid'] || $validation['account_id'] === NULL) {
        throw new \LogicException($validation['message'] ?? 'Direct-charge account validation failed.');
      }
      $accountId = $validation['account_id'];
    }

    if (!str_starts_with($accountId, 'acct_')) {
      throw new \LogicException('Invalid connected Stripe account for direct charge.');
    }

    if ($persist && $order->getData('stripe_connected_account_id') !== $accountId) {
      $order->setData('stripe_connected_account_id', $accountId);
    }

    $previousAccount = StripeLibrary::getAccountId();
    StripeLibrary::setAccountId($accountId);
    return $previousAccount;
  }

}
