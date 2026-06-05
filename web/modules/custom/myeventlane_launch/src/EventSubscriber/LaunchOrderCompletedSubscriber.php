<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifier;
use Drupal\myeventlane_launch\Service\CheckoutIdempotencyGuard;
use Drupal\myeventlane_launch\Service\LaunchPlatformEventBridge;
use Drupal\myeventlane_launch\Service\MELMonitoringService;
use Psr\Log\LoggerInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Launch hardening subscriber for order completion.
 *
 * Does not send customer email; checkout analytics and ticket issuance checks
 * only. Order confirmation email is queued by myeventlane_messaging.
 *
 * Ticket issuance monitoring runs on ORDER_PAID after TicketIssuer, not on
 * order placement (attendee roster rows are created later on place).
 */
final class LaunchOrderCompletedSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly CheckoutIdempotencyGuard $checkoutGuard,
    private readonly LaunchPlatformEventBridge $eventBridge,
    private readonly MELMonitoringService $monitoringService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
    private readonly TicketBackedOrderItemClassifier $ticketBackedOrderItemClassifier,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onOrderPlace', -50],
      OrderEvents::ORDER_PAID => ['onOrderPaid', -50],
    ];
  }

  /**
   * Handles order placement in an additive, non-blocking way.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event): void {
    $entity = $event->getEntity();
    if (!$entity instanceof OrderInterface) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    $session = $request?->hasSession() ? $request->getSession() : NULL;
    $sessionId = $this->checkoutGuard->buildSessionId($entity, $session);

    if ($this->checkoutGuard->isCompleted($sessionId)) {
      $this->monitoringService->monitorCheckoutFailure([
        'order_id' => (int) $entity->id(),
        'session_id' => $sessionId,
        'reason' => 'duplicate_completion_event',
      ]);
      return;
    }

    $this->checkoutGuard->markCompleted($sessionId, [
      'order_id' => (int) $entity->id(),
    ]);

    try {
      $this->eventBridge->onOrderCompleted($entity);
    }
    catch (\Throwable $e) {
      $this->monitoringService->monitorCheckoutFailure([
        'order_id' => (int) $entity->id(),
        'session_id' => $sessionId,
        'reason' => 'event_bridge_failure',
      ], $e);
    }
  }

  /**
   * Validates ticket issuance after payment (TicketIssuer on ORDER_PAID).
   */
  public function onOrderPaid(OrderEvent $event): void {
    $order = $event->getOrder();
    if (!$order instanceof OrderInterface) {
      return;
    }

    $this->checkTicketIssuance($order);
  }

  /**
   * Detects likely ticket issuance failures after ORDER_PAID.
   */
  private function checkTicketIssuance(OrderInterface $order): void {
    $orderItemIds = [];
    foreach ($order->getItems() as $orderItem) {
      if (!$this->ticketBackedOrderItemClassifier->isTicketBackedOrderItem($orderItem)) {
        continue;
      }
      $orderItemIds[] = (int) $orderItem->id();
    }

    if ($orderItemIds === []) {
      return;
    }

    $orderId = (int) $order->id();
    if ($orderId < 1 || !$this->entityTypeManager->hasDefinition('myeventlane_ticket')) {
      return;
    }

    $ticketCount = (int) $this->entityTypeManager
      ->getStorage('myeventlane_ticket')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderId)
      ->count()
      ->execute();

    if ($ticketCount === 0) {
      $this->monitoringService->monitorTicketIssuanceFailure([
        'order_id' => $orderId,
        'order_item_ids' => $orderItemIds,
      ]);
      $this->logger->error('Ticket issuance check failed for order {order_id}: no myeventlane_ticket records found.', [
        'order_id' => $orderId,
      ]);
    }
  }

}
