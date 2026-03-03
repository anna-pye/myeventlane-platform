<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\EventSubscriber;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\myeventlane_boost\Service\BoostEntitlementManager;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber to revoke boosts when payments are refunded or voided.
 */
final class BoostRefundSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a BoostRefundSubscriber.
   *
   * @param \Drupal\myeventlane_boost\Service\BoostEntitlementManager $entitlementManager
   *   The entitlement manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly BoostEntitlementManager $entitlementManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_payment.refund.post_transition' => 'onRefundOrVoid',
      'commerce_payment.void.post_transition' => 'onRefundOrVoid',
    ];
  }

  /**
   * Handle refund or void transitions.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onRefundOrVoid(WorkflowTransitionEvent $event): void {
    $payment = $event->getEntity();
    if (!$payment instanceof PaymentInterface) {
      return;
    }

    $order = $payment->getOrder();
    if ($order === NULL) {
      return;
    }

    $this->logger->notice('Processing refund/void for payment @pid on order @oid', [
      '@pid' => $payment->id(),
      '@oid' => $order->id(),
    ]);

    $revoked = $this->entitlementManager->revokeEntitlementsForOrder($order, 'payment_refund_or_void');
    $this->logger->notice('Revoked @count boost entitlements for refunded/voided order @oid.', [
      '@count' => $revoked,
      '@oid' => $order->id(),
    ]);
  }

}
