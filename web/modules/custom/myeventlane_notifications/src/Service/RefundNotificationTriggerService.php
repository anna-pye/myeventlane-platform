<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\NotificationContext;
use Drupal\myeventlane_notifications\NotificationDomain;
use Drupal\node\NodeInterface;

/**
 * In-app notifications for refund lifecycle events (vendor and buyer).
 */
final class RefundNotificationTriggerService {

  public function __construct(
    private readonly NotificationManager $notificationManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
    private readonly TranslationInterface $translation,
  ) {}

  /**
   * @param array<string, mixed> $requestRow
   *   Refund request row from RefundRequestStorage.
   */
  public function onRefundRequested(array $requestRow, OrderInterface $order, NodeInterface $event): void {
    $vendorUid = (int) ($requestRow['vendor_uid'] ?? $event->getOwnerId());
    if ($vendorUid < 1) {
      return;
    }
    $requestId = (int) ($requestRow['id'] ?? 0);
    $this->queueBusinessRefund(
      [$vendorUid],
      (string) $this->translation->translate('Refund requested'),
      (string) $this->translation->translate('A buyer requested a refund for @event.', ['@event' => $event->label()]),
      'refund_requested',
      'refund_request:' . $requestId,
      (string) $this->translation->translate('Review request'),
      'myeventlane_refunds.vendor_refund_requests',
      ['node' => (int) $event->id()],
    );

    $buyerUid = (int) ($requestRow['buyer_uid'] ?? $order->getCustomerId());
    if ($buyerUid > 0) {
      $this->queuePersonalRefund(
        [$buyerUid],
        (string) $this->translation->translate('Refund requested'),
        (string) $this->translation->translate('Your refund request for @event is pending review.', ['@event' => $event->label()]),
        'refund_requested_buyer',
        'refund_request_buyer:' . $requestId,
        (string) $this->translation->translate('View order'),
        'myeventlane_checkout_flow.order_detail',
        ['commerce_order' => (int) $order->id()],
      );
    }
  }

  /**
   * @param array<string, mixed> $requestRow
   */
  public function onRefundApproved(array $requestRow, OrderInterface $order, NodeInterface $event): void {
    $vendorUid = (int) ($requestRow['vendor_uid'] ?? $event->getOwnerId());
    if ($vendorUid < 1) {
      return;
    }
    $requestId = (int) ($requestRow['id'] ?? 0);
    $this->queueBusinessRefund(
      [$vendorUid],
      (string) $this->translation->translate('Refund approved'),
      (string) $this->translation->translate('You approved a refund for order @order.', [
        '@order' => $order->getOrderNumber() ?: (string) $order->id(),
      ]),
      'refund_approved',
      'refund_approved:' . $requestId,
      (string) $this->translation->translate('View order'),
      'myeventlane_vendor.console.event_order_view',
      ['event' => (int) $event->id(), 'order' => (int) $order->id()],
    );

    $buyerUid = (int) ($requestRow['buyer_uid'] ?? $order->getCustomerId());
    if ($buyerUid > 0) {
      $this->queuePersonalRefund(
        [$buyerUid],
        (string) $this->translation->translate('Refund approved'),
        (string) $this->translation->translate('Your refund for @event was approved.', ['@event' => $event->label()]),
        'refund_approved_buyer',
        'refund_approved_buyer:' . $requestId,
        (string) $this->translation->translate('View order'),
        'myeventlane_checkout_flow.order_detail',
        ['commerce_order' => (int) $order->id()],
      );
    }
  }

  /**
   * @param array<string, mixed> $requestRow
   */
  public function onRefundRejected(array $requestRow, OrderInterface $order, NodeInterface $event): void {
    $vendorUid = (int) ($requestRow['vendor_uid'] ?? $event->getOwnerId());
    if ($vendorUid < 1) {
      return;
    }
    $requestId = (int) ($requestRow['id'] ?? 0);
    $this->queueBusinessRefund(
      [$vendorUid],
      (string) $this->translation->translate('Refund rejected'),
      (string) $this->translation->translate('You declined a refund request for @event.', ['@event' => $event->label()]),
      'refund_rejected',
      'refund_rejected:' . $requestId,
      (string) $this->translation->translate('View requests'),
      'myeventlane_refunds.vendor_refund_requests',
      ['node' => (int) $event->id()],
    );

    $buyerUid = (int) ($requestRow['buyer_uid'] ?? $order->getCustomerId());
    if ($buyerUid > 0) {
      $this->queuePersonalRefund(
        [$buyerUid],
        (string) $this->translation->translate('Refund rejected'),
        (string) $this->translation->translate('Your refund request for @event was declined.', ['@event' => $event->label()]),
        'refund_rejected_buyer',
        'refund_rejected_buyer:' . $requestId,
        (string) $this->translation->translate('View order'),
        'myeventlane_checkout_flow.order_detail',
        ['commerce_order' => (int) $order->id()],
      );
    }
  }

  public function onRefundCompleted(OrderInterface $order, NodeInterface $event, int $vendorUid): void {
    if ($vendorUid < 1) {
      return;
    }
    $this->queueBusinessRefund(
      [$vendorUid],
      (string) $this->translation->translate('Refund completed'),
      (string) $this->translation->translate('A refund for order @order has been processed.', [
        '@order' => $order->getOrderNumber() ?: (string) $order->id(),
      ]),
      'refund_completed',
      'refund_completed:' . $order->id() . ':' . $event->id(),
      (string) $this->translation->translate('View order'),
      'myeventlane_vendor.console.event_order_view',
      ['event' => (int) $event->id(), 'order' => (int) $order->id()],
    );

    $buyerUid = (int) $order->getCustomerId();
    if ($buyerUid > 0) {
      $this->queuePersonalRefund(
        [$buyerUid],
        (string) $this->translation->translate('Refund completed'),
        (string) $this->translation->translate('Your refund for @event has been processed.', ['@event' => $event->label()]),
        'refund_completed_buyer',
        'refund_completed_buyer:' . $order->id() . ':' . $event->id(),
        (string) $this->translation->translate('View order'),
        'myeventlane_checkout_flow.order_detail',
        ['commerce_order' => (int) $order->id()],
      );
    }
  }

  /**
   * @param list<int> $userIds
   * @param array<string, scalar> $routeParameters
   */
  private function queueBusinessRefund(
    array $userIds,
    string $title,
    string $message,
    string $actionContext,
    string $suppressionKey,
    string $actionLabel,
    string $routeName,
    array $routeParameters,
  ): void {
    $this->queueRefund(
      $userIds,
      NotificationContext::BUSINESS,
      NotificationDomain::REFUNDS,
      $title,
      $message,
      $actionContext,
      $suppressionKey,
      $actionLabel,
      $routeName,
      $routeParameters,
    );
  }

  /**
   * @param list<int> $userIds
   * @param array<string, scalar> $routeParameters
   */
  private function queuePersonalRefund(
    array $userIds,
    string $title,
    string $message,
    string $actionContext,
    string $suppressionKey,
    string $actionLabel,
    string $routeName,
    array $routeParameters,
  ): void {
    $this->queueRefund(
      $userIds,
      NotificationContext::PERSONAL,
      NotificationDomain::ORDERS,
      $title,
      $message,
      $actionContext,
      $suppressionKey,
      $actionLabel,
      $routeName,
      $routeParameters,
    );
  }

  /**
   * @param list<int> $userIds
   * @param array<string, scalar> $routeParameters
   */
  private function queueRefund(
    array $userIds,
    string $context,
    string $domain,
    string $title,
    string $message,
    string $actionContext,
    string $suppressionKey,
    string $actionLabel,
    string $routeName,
    array $routeParameters,
  ): void {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if ($userIds === []) {
      return;
    }
    try {
      $notification = $this->notificationManager->createNotification([
        'type' => MelNotification::TYPE_SYSTEM,
        'title' => $title,
        'message' => $message,
        'audience_type' => MelNotification::AUDIENCE_USER_IDS,
        'audience_data' => ['user_ids' => $userIds],
        'channels' => ['toast'],
        'status' => MelNotification::STATUS_DRAFT,
        'priority' => MelNotification::PRIORITY_HIGH,
        'priority_score' => 70,
        'suppression_key' => $suppressionKey,
        'context' => $context,
        'domain' => $domain,
        'action_label' => $actionLabel,
        'route_name' => $routeName,
        'route_parameters' => $routeParameters,
        'action_context' => $actionContext,
      ]);
      $this->notificationManager->queueNotification($notification);
    }
    catch (\RuntimeException) {
      // Suppressed duplicate.
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to queue refund notification (@ctx): @message', [
        '@ctx' => $actionContext,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
