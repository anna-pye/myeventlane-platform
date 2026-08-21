<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce_payment\Event\FilterPaymentGatewaysEvent;
use Drupal\commerce_payment\Event\PaymentEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\myeventlane_commerce\Service\StripeConnectPaymentService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Restricts Commerce payment gateways to the launch Option A matrix.
 *
 * Target:
 * - Customers: tickets / boost / donations → stripe
 * - MEL Pro / recurring (Pro-only carts) → stripe_pe_recurring
 * - Mixed ticket + Pro carts → stripe (Card Element); PE removed
 * - mel_stripe_cc → administrators only (preserved for local/admin testing)
 *
 * Fail-safe: never remove Card Element for an exclusive recurring cart unless
 * the PE recurring gateway is still present after Commerce conditions.
 * Otherwise checkout can end with zero gateways (missing config/currency).
 */
final class FilterPaymentGatewaysSubscriber implements EventSubscriberInterface {

  private const MANUAL_GATEWAY_ID = 'mel_stripe_cc';

  private const RECURRING_GATEWAY_ID = 'stripe_pe_recurring';

  private const CARD_GATEWAY_ID = 'stripe';

  private const DIRECT_CHARGE_GATEWAY_ID = 'stripe_connect';

  public function __construct(
    private readonly OrderItemClassifier $orderItemClassifier,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly ?ConfigFactoryInterface $configFactory = NULL,
    private readonly ?StripeConnectPaymentService $stripeConnectPayment = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PaymentEvents::FILTER_PAYMENT_GATEWAYS => ['onFilterPaymentGateways', -100],
    ];
  }

  /**
   * Filters gateways after Commerce condition evaluation.
   */
  public function onFilterPaymentGateways(FilterPaymentGatewaysEvent $event): void {
    $order = $event->getOrder();
    $gateways = $event->getPaymentGateways();
    if ($gateways === []) {
      return;
    }

    $directChargeEnabled = $this->configFactory !== NULL
      && (bool) $this->configFactory->get('myeventlane_core.settings')->get('direct_charge_enabled');
    $requiresDirectCharge = $this->stripeConnectPayment?->requiresDirectCharge($order) ?? FALSE;

    if ($directChargeEnabled && $requiresDirectCharge) {
      $validation = $this->stripeConnectPayment->validateDirectChargeOrder($order);
      foreach ($gateways as $id => $gateway) {
        if (!$validation['valid'] || $gateway->id() !== self::DIRECT_CHARGE_GATEWAY_ID) {
          unset($gateways[$id]);
        }
      }

      if ($gateways === []) {
        $this->logger->error('Direct-charge checkout blocked for order @oid: @reason', [
          '@oid' => (string) ($order->id() ?? 'new'),
          '@reason' => $validation['message'] ?? 'The direct-charge gateway is unavailable.',
        ]);
      }
      $event->setPaymentGateways($gateways);
      return;
    }

    $requiresRecurring = $this->orderItemClassifier->requiresRecurringPaymentGateway($order);
    $exclusiveRecurring = $this->orderItemClassifier->requiresExclusiveRecurringPaymentGateway($order);
    $isAdministrator = $this->currentUser->hasRole('administrator');
    $hasRecurringGateway = $this->gatewayListContains($gateways, self::RECURRING_GATEWAY_ID);
    $removed = [];

    if ($requiresRecurring && !$exclusiveRecurring) {
      $this->logger->warning('Mixed Pro cart order @oid keeps @card and drops @pe so non-Pro lines are not charged off_session.', [
        '@oid' => (string) ($order->id() ?? 'new'),
        '@card' => self::CARD_GATEWAY_ID,
        '@pe' => self::RECURRING_GATEWAY_ID,
      ]);
    }

    if ($exclusiveRecurring && !$hasRecurringGateway) {
      $this->logger->warning('Recurring cart order @oid has no @pe gateway after Commerce conditions; retaining @card if present so checkout is not empty.', [
        '@oid' => (string) ($order->id() ?? 'new'),
        '@pe' => self::RECURRING_GATEWAY_ID,
        '@card' => self::CARD_GATEWAY_ID,
      ]);
    }

    foreach ($gateways as $id => $gateway) {
      $gatewayId = $gateway->id();

      // The direct-charge gateway is never available outside the explicit
      // organiser-revenue branch above. This keeps the enabled config entity
      // dormant while the migration switch is off and excludes it from all
      // platform-account checkouts.
      if ($gatewayId === self::DIRECT_CHARGE_GATEWAY_ID) {
        unset($gateways[$id]);
        $removed[] = $gatewayId;
        continue;
      }

      if ($gatewayId === self::MANUAL_GATEWAY_ID && !$isAdministrator) {
        unset($gateways[$id]);
        $removed[] = $gatewayId;
        continue;
      }

      // PE only for Pro-only / recurring renewals — not mixed ticket + Pro carts.
      if ($gatewayId === self::RECURRING_GATEWAY_ID && !$exclusiveRecurring) {
        unset($gateways[$id]);
        $removed[] = $gatewayId;
        continue;
      }

      // Only strip Card Element when the cart is exclusive recurring and PE is available.
      if ($gatewayId === self::CARD_GATEWAY_ID && $exclusiveRecurring && $hasRecurringGateway) {
        unset($gateways[$id]);
        $removed[] = $gatewayId;
      }
    }

    if ($removed !== []) {
      $this->logger->info('Filtered payment gateways for order @oid: removed @removed (recurring=@recurring, exclusive=@exclusive, admin=@admin, pe_present=@pe).', [
        '@oid' => (string) ($order->id() ?? 'new'),
        '@removed' => implode(',', array_unique($removed)),
        '@recurring' => $requiresRecurring ? '1' : '0',
        '@exclusive' => $exclusiveRecurring ? '1' : '0',
        '@admin' => $isAdministrator ? '1' : '0',
        '@pe' => $hasRecurringGateway ? '1' : '0',
      ]);
    }

    if ($gateways === []) {
      $this->logger->error('Payment gateway filter left order @oid with zero gateways (recurring=@recurring, exclusive=@exclusive, admin=@admin).', [
        '@oid' => (string) ($order->id() ?? 'new'),
        '@recurring' => $requiresRecurring ? '1' : '0',
        '@exclusive' => $exclusiveRecurring ? '1' : '0',
        '@admin' => $isAdministrator ? '1' : '0',
      ]);
    }

    $event->setPaymentGateways($gateways);
  }

  /**
   * Whether a gateway entity id is present in the filtered list.
   *
   * @param array<string|\Drupal\commerce_payment\Entity\PaymentGatewayInterface> $gateways
   *   Gateway entities keyed by entity id or numeric index.
   */
  private function gatewayListContains(array $gateways, string $gatewayId): bool {
    foreach ($gateways as $gateway) {
      if ($gateway->id() === $gatewayId) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
