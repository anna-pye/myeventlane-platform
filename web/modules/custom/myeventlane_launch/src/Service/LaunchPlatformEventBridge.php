<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Bridges platform events to launch hardening actions.
 */
final class LaunchPlatformEventBridge {

  public function __construct(
    private readonly MELMonitoringService $monitoringService,
    private readonly ReliableEmailSender $emailSender,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Handles order-completed bridge actions.
   */
  public function onOrderCompleted(OrderInterface $order): void {
    $orderId = (int) $order->id();
    $recipient = (string) ($order->getEmail() ?? '');
    if ($recipient === '') {
      $this->monitoringService->monitorCheckoutFailure([
        'order_id' => $orderId,
        'reason' => 'missing_order_email',
      ]);
      return;
    }

    // Disabled: handled by myeventlane_messaging unified flow (OrderPlacedSubscriber).
    $this->logger->info('Launch event bridge skipped duplicate ticket confirmation email for order {order_id} (unified messaging).', [
      'order_id' => $orderId,
    ]);
    return;
  }

  /**
   * Handles RSVP-created bridge actions.
   *
   * @deprecated Replaced by myeventlane_messaging (RsvpMailer via MessagingManager::queue).
   *   Retained for API compatibility; no longer sends email.
   */
  public function onRsvpCreated(string $email, int $eventId): void {
    // Disabled: handled by myeventlane_messaging unified RSVP flow.
    $this->logger->notice('Launch event bridge onRsvpCreated skipped (unified messaging); event {event_id}.', [
      'event_id' => $eventId,
    ]);
  }

  /**
   * Handles refund-completed bridge actions.
   */
  public function onRefundCompleted(string $email, int $orderId): void {
    $this->emailSender->queueEmail(
      'refund_confirmation',
      $email,
      'Your refund has been processed',
      'Your refund has been processed for order #' . $orderId . '.',
      ['order_id' => $orderId]
    );
  }

}
