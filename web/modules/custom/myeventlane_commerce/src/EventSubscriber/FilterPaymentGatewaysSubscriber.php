<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce_payment\Event\FilterPaymentGatewaysEvent;
use Drupal\commerce_payment\Event\PaymentEvents;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Restricts Commerce payment gateways to the launch Option A matrix.
 *
 * Target:
 * - Customers: tickets / boost / donations → stripe
 * - MEL Pro / recurring → stripe_pe_recurring
 * - mel_stripe_cc → administrators only (preserved for local/admin testing)
 */
final class FilterPaymentGatewaysSubscriber implements EventSubscriberInterface {

  private const MANUAL_GATEWAY_ID = 'mel_stripe_cc';

  private const RECURRING_GATEWAY_ID = 'stripe_pe_recurring';

  private const CARD_GATEWAY_ID = 'stripe';

  public function __construct(
    private readonly OrderItemClassifier $orderItemClassifier,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
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

    $requiresRecurring = $this->orderItemClassifier->requiresRecurringPaymentGateway($order);
    $isAdministrator = $this->currentUser->hasRole('administrator');
    $removed = [];

    foreach ($gateways as $id => $gateway) {
      $gatewayId = $gateway->id();

      if ($gatewayId === self::MANUAL_GATEWAY_ID && !$isAdministrator) {
        unset($gateways[$id]);
        $removed[] = $gatewayId;
        continue;
      }

      if ($gatewayId === self::RECURRING_GATEWAY_ID && !$requiresRecurring) {
        unset($gateways[$id]);
        $removed[] = $gatewayId;
        continue;
      }

      if ($gatewayId === self::CARD_GATEWAY_ID && $requiresRecurring) {
        unset($gateways[$id]);
        $removed[] = $gatewayId;
      }
    }

    if ($removed !== []) {
      $this->logger->info('Filtered payment gateways for order @oid: removed @removed (recurring=@recurring, admin=@admin).', [
        '@oid' => (string) ($order->id() ?? 'new'),
        '@removed' => implode(',', array_unique($removed)),
        '@recurring' => $requiresRecurring ? '1' : '0',
        '@admin' => $isAdministrator ? '1' : '0',
      ]);
    }

    $event->setPaymentGateways($gateways);
  }

}
