<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Url;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Schedules event reminder emails via the canonical messaging queue.
 *
 * Builds full template context and calls MessagingManager::queue() so that
 * only message_id is enqueued; idempotency is enforced by the manager.
 */
final class EventReminderScheduler {

  /**
   * Constructs EventReminderScheduler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory (unused; kept for backward compat).
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\myeventlane_messaging\Service\MessagingManager $messagingManager
   *   The messaging manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\myeventlane_rsvp\Service\IcsGenerator|null $icsGenerator
   *   Optional ICS generator for calendar attachments.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly MessagingManager $messagingManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ?object $icsGenerator = NULL,
  ) {}

  /**
   * Scans for events needing reminders and queues messages via MessagingManager.
   */
  public function scan(): void {
    $now = $this->time->getRequestTime();

    $this->scanReminders($now, 7 * 24 * 3600, 'event_reminder_7d', '7 days');
    $this->scanReminders($now, 24 * 3600, 'event_reminder_24h', '24 hours');
  }

  /**
   * Scans for events in the reminder window and queues via manager.
   *
   * @param int $now
   *   Current timestamp.
   * @param int $reminderOffset
   *   Seconds before event start.
   * @param string $template
   *   Template key (event_reminder_7d or event_reminder_24h).
   * @param string $timeframe
   *   Human-readable timeframe for template.
   */
  private function scanReminders(int $now, int $reminderOffset, string $template, string $timeframe): void {
    $windowStart = $now + $reminderOffset - 3600;
    $windowEnd = $now + $reminderOffset + 3600;

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

      if ($event->hasField('field_event_state') && !$event->get('field_event_state')->isEmpty()) {
        $state = $event->get('field_event_state')->value;
        if (in_array($state, ['cancelled', 'ended'], TRUE)) {
          continue;
        }
      }

      $eventId = (int) $event->id();

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

        try {
          $order = $orderItem->getOrder();
          if (!$order instanceof OrderInterface) {
            continue;
          }
        }
        catch (\Exception $e) {
          continue;
        }

        $orderId = $order->id();
        if (isset($processedOrders[$orderId])) {
          continue;
        }

        $orderState = $order->getState()->getId();
        if (!in_array($orderState, ['completed', 'placed', 'fulfilled'], TRUE)) {
          continue;
        }
        if (in_array($orderState, ['canceled', 'refunded'], TRUE)) {
          continue;
        }

        $orderEmail = $order->getEmail();
        if (empty($orderEmail)) {
          continue;
        }

        $context = $this->buildContext($order, $event, $timeframe);
        $attachments = [];

        if ($this->icsGenerator && method_exists($this->icsGenerator, 'generate')) {
          try {
            $icsContent = $this->icsGenerator->generate($event);
            if ($icsContent) {
              $filename = 'event-' . $eventId . '-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($event->label())) . '.ics';
              $attachments[] = [
                'filename' => $filename,
                'content' => $icsContent,
                'mime' => 'text/calendar',
              ];
            }
          }
          catch (\Throwable $e) {
            $this->logger->warning('ICS generation failed for event @eid: @msg', [
              '@eid' => $eventId,
              '@msg' => $e->getMessage(),
            ]);
          }
        }

        $this->messagingManager->queue($template, $orderEmail, $context, [
          'langcode' => $order->language()->getId(),
          'attachments' => $attachments,
        ]);

        $processedOrders[$orderId] = TRUE;
        $this->logger->info('Scheduled @template reminder for order @order_id, event @event_id', [
          '@template' => $template,
          '@order_id' => $orderId,
          '@event_id' => $eventId,
        ]);
      }
    }
  }

  /**
   * Builds serializable template context (no entities).
   */
  private function buildContext(OrderInterface $order, NodeInterface $event, string $timeframe): array {
    $context = [
      'order_id' => (int) $order->id(),
      'event_id' => (int) $event->id(),
      'order_number' => $order->getOrderNumber(),
      'event_title' => $event->label(),
      'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'my_tickets_url' => Url::fromRoute('myeventlane_checkout_flow.order_detail', [
        'commerce_order' => $order->id(),
      ], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'timeframe' => $timeframe,
    ];

    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $startDate = $event->get('field_event_start')->date;
      if ($startDate) {
        $ts = $startDate->getTimestamp();
        $context['event_start'] = $this->dateFormatter->format($ts, 'custom', 'F j, Y g:ia T');
        $context['event_start_date'] = $this->dateFormatter->format($ts, 'custom', 'F j, Y');
        $context['event_start_time'] = $this->dateFormatter->format($ts, 'custom', 'g:ia T');
      }
    }

    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $context['event_location'] = $event->get('field_location')->value;
    }
    elseif ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $context['event_location'] = $event->get('field_venue_name')->value;
    }

    $attendeeNames = [];
    foreach ($order->getItems() as $item) {
      if ($item->hasField('field_ticket_holder') && !$item->get('field_ticket_holder')->isEmpty()) {
        foreach ($item->get('field_ticket_holder')->referencedEntities() as $p) {
          $first = $p->hasField('field_first_name') && !$p->get('field_first_name')->isEmpty()
            ? $p->get('field_first_name')->value : '';
          $last = $p->hasField('field_last_name') && !$p->get('field_last_name')->isEmpty()
            ? $p->get('field_last_name')->value : '';
          $name = trim($first . ' ' . $last);
          if ($name !== '') {
            $attendeeNames[] = $name;
          }
        }
      }
    }
    $context['attendee_names'] = $attendeeNames;
    $context['attendee_count'] = count($attendeeNames);

    return $context;
  }

  /**
   * Generates a unique key for a reminder (kept for drush/legacy).
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
   * Checks if a reminder has already been sent (state API; legacy).
   *
   * @param string $reminderKey
   *   The reminder key.
   *
   * @return bool
   *   TRUE if reminder already sent.
   */
  public function isReminderSent(string $reminderKey): bool {
    return (bool) \Drupal::state()->get("myeventlane_messaging.{$reminderKey}", FALSE);
  }

  /**
   * Marks a reminder as sent (state API; legacy).
   *
   * @param string $reminderKey
   *   The reminder key.
   */
  public function markReminderSent(string $reminderKey): void {
    \Drupal::state()->set("myeventlane_messaging.{$reminderKey}", TRUE);
  }

}
