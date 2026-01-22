<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Schedules event reminder emails for orders.
 */
final class EventReminderScheduler {

  /**
   * Constructs EventReminderScheduler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Scans for events needing reminders and enqueues reminder jobs.
   */
  public function scan(): void {
    $now = $this->time->getRequestTime();

    // Scan for 7-day reminders.
    $this->scanReminders($now, 7 * 24 * 3600, 'reminder_7d');

    // Scan for 24-hour reminders.
    $this->scanReminders($now, 24 * 3600, 'reminder_24h');
  }

  /**
   * Scans for events needing reminders at a specific time before start.
   *
   * @param int $now
   *   Current timestamp.
   * @param int $reminderOffset
   *   Seconds before event start to send reminder.
   * @param string $reminderType
   *   Reminder type identifier ('reminder_7d' or 'reminder_24h').
   */
  private function scanReminders(int $now, int $reminderOffset, string $reminderType): void {
    // Calculate time window: events starting between (offset - 1 hour) and (offset + 1 hour).
    $windowStart = $now + $reminderOffset - 3600;
    $windowEnd = $now + $reminderOffset + 3600;

    // Find events starting in this window.
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $query = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->exists('field_event_start')
      ->condition('field_event_start', date('Y-m-d\TH:i:s', $windowStart), '>=')
      ->condition('field_event_start', date('Y-m-d\TH:i:s', $windowEnd), '<=');

    $eventIds = $query->execute();

    if (empty($eventIds)) {
      return;
    }

    $events = $nodeStorage->loadMultiple($eventIds);
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');

    foreach ($events as $event) {
      if (!$event instanceof NodeInterface) {
        continue;
      }

      // Skip if event is cancelled or ended.
      if ($event->hasField('field_event_state') && !$event->get('field_event_state')->isEmpty()) {
        $state = $event->get('field_event_state')->value;
        if (in_array($state, ['cancelled', 'ended'], TRUE)) {
          continue;
        }
      }

      $eventId = (int) $event->id();

      // Find order items for this event.
      $orderItemIds = $orderItemStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_target_event', $eventId)
        ->execute();

      if (empty($orderItemIds)) {
        continue;
      }

      $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
      $processedOrders = [];

      foreach ($orderItems as $orderItem) {
        if (!$orderItem instanceof OrderItemInterface) {
          continue;
        }

        // Get order.
        try {
          $order = $orderItem->getOrder();
          if (!$order instanceof OrderInterface) {
            continue;
          }
        }
        catch (\Exception $e) {
          continue;
        }

        // Skip if order already processed (multiple items per order).
        $orderId = $order->id();
        if (isset($processedOrders[$orderId])) {
          continue;
        }

        // Only process completed/placed orders.
        $orderState = $order->getState()->getId();
        if (!in_array($orderState, ['completed', 'placed', 'fulfilled'], TRUE)) {
          continue;
        }

        // Skip cancelled or refunded orders.
        if (in_array($orderState, ['canceled', 'refunded'], TRUE)) {
          continue;
        }

        // Get order email.
        $orderEmail = $order->getEmail();
        if (empty($orderEmail)) {
          continue;
        }

        // Check if reminder already sent (idempotency check).
        $reminderKey = $this->getReminderKey($orderId, $eventId, $reminderType);
        if ($this->isReminderSent($reminderKey)) {
          continue;
        }

        // Enqueue reminder.
        $queueName = $reminderType === 'reminder_7d' ? 'event_reminder_7d' : 'event_reminder_24h';
        $queue = $this->queueFactory->get($queueName);
        $queue->createItem([
          'order_id' => $orderId,
          'event_id' => $eventId,
          'reminder_type' => $reminderType,
        ]);

        // Mark as processed.
        $processedOrders[$orderId] = TRUE;

        $this->logger->info('Scheduled @type reminder for order @order_id, event @event_id', [
          '@type' => $reminderType,
          '@order_id' => $orderId,
          '@event_id' => $eventId,
        ]);
      }
    }
  }

  /**
   * Generates a unique key for a reminder.
   *
   * @param int $orderId
   *   Order ID.
   * @param int $eventId
   *   Event ID.
   * @param string $reminderType
   *   Reminder type.
   *
   * @return string
   *   Unique reminder key.
   */
  public function getReminderKey(int $orderId, int $eventId, string $reminderType): string {
    return "reminder:{$reminderType}:order:{$orderId}:event:{$eventId}";
  }

  /**
   * Checks if a reminder has already been sent.
   *
   * Uses state API to track sent reminders.
   *
   * @param string $reminderKey
   *   The reminder key.
   *
   * @return bool
   *   TRUE if reminder already sent, FALSE otherwise.
   */
  public function isReminderSent(string $reminderKey): bool {
    $state = \Drupal::state();
    return (bool) $state->get("myeventlane_messaging.{$reminderKey}", FALSE);
  }

  /**
   * Marks a reminder as sent.
   *
   * @param string $reminderKey
   *   The reminder key.
   */
  public function markReminderSent(string $reminderKey): void {
    $state = \Drupal::state();
    $state->set("myeventlane_messaging.{$reminderKey}", TRUE);
  }

}
