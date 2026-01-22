<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Commands;

use Drupal\Core\Url;
use Drush\Commands\DrushCommands;

/**
 * Provides Drush commands for MyEventLane Messaging.
 */
final class MessagingCommands extends DrushCommands {

  /**
   * Runs all schedulers to enqueue messages.
   *
   * @command mel:msg-scan
   * @aliases mel-msg-scan
   * @usage drush mel:msg-scan
   */
  public function scan(): void {
    \Drupal::service('myeventlane_messaging.scheduler.boost')->scan();
    \Drupal::service('myeventlane_messaging.scheduler.cart')->scan();
    \Drupal::service('myeventlane_messaging.scheduler.event')->scan();
    \Drupal::service('myeventlane_messaging.event_reminder_scheduler')->scan();

    $this->logger()->success('Schedulers complete (boost/cart/event/reminders).');
  }

  /**
   * Runs the messaging queue now.
   *
   * @command mel:msg-run
   * @aliases mel-msg-run
   * @usage drush mel:msg-run
   */
  public function run(): void {
    $queue = \Drupal::queue('myeventlane_messaging');
    while ($item = $queue->claimItem()) {
      try {
        \Drupal::service('myeventlane_messaging.manager')->sendNow($item->data);
        $queue->deleteItem($item);
      }
      catch (\Throwable $e) {
        $queue->releaseItem($item);
        throw $e;
      }
    }

    $this->logger()->success('Queue processed.');
  }

  /**
   * Queues a test message.
   *
   * @param string $type
   *   The message template key.
   * @param string $email
   *   The email address.
   * @param int|null $id
   *   Optional entity ID.
   *
   * @command mel:msg-test
   * @aliases mel-msg-test
   * @usage drush mel:msg-test boost_reminder you@example.com
   */
  public function test(string $type, string $email, ?int $id = NULL): void {
    \Drupal::service('myeventlane_messaging.manager')->queue($type, $email, ['entity_id' => $id]);
    $this->logger()->success("Queued test message $type to $email.");
  }

  /**
   * Scans for events needing reminders and enqueues reminder jobs.
   *
   * @command mel:reminder-scan
   * @aliases mel-reminder-scan
   * @usage drush mel:reminder-scan
   *   Scans for events needing 7-day and 24-hour reminders.
   */
  public function reminderScan(): void {
    $scheduler = \Drupal::service('myeventlane_messaging.event_reminder_scheduler');
    $scheduler->scan();

    // Check queue counts.
    $queue7d = \Drupal::queue('event_reminder_7d');
    $queue24h = \Drupal::queue('event_reminder_24h');
    $count7d = $queue7d->numberOfItems();
    $count24h = $queue24h->numberOfItems();

    $this->logger()->success("Reminder scan complete. Queued: {$count7d} 7-day reminders, {$count24h} 24-hour reminders.");
  }

  /**
   * Processes reminder queues and sends emails.
   *
   * @param string|null $type
   *   Reminder type: '7d', '24h', or NULL for both.
   *
   * @command mel:reminder-run
   * @aliases mel-reminder-run
   * @usage drush mel:reminder-run
   *   Process all reminder queues.
   * @usage drush mel:reminder-run 7d
   *   Process only 7-day reminders.
   * @usage drush mel:reminder-run 24h
   *   Process only 24-hour reminders.
   */
  public function reminderRun(?string $type = NULL): void {
    $processed = 0;

    if ($type === NULL || $type === '7d') {
      $queue = \Drupal::queue('event_reminder_7d');
      while ($item = $queue->claimItem()) {
        try {
          $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('event_reminder_7d');
          $worker->processItem($item->data);
          $queue->deleteItem($item);
          $processed++;
        }
        catch (\Throwable $e) {
          $queue->releaseItem($item);
          $this->logger()->error("Failed to process 7-day reminder: " . $e->getMessage());
        }
      }
    }

    if ($type === NULL || $type === '24h') {
      $queue = \Drupal::queue('event_reminder_24h');
      while ($item = $queue->claimItem()) {
        try {
          $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('event_reminder_24h');
          $worker->processItem($item->data);
          $queue->deleteItem($item);
          $processed++;
        }
        catch (\Throwable $e) {
          $queue->releaseItem($item);
          $this->logger()->error("Failed to process 24-hour reminder: " . $e->getMessage());
        }
      }
    }

    $this->logger()->success("Processed {$processed} reminder(s).");
  }

  /**
   * Lists orders with events that can be used for reminder testing.
   *
   * @command mel:reminder-list-orders
   * @aliases mel-reminder-list
   * @usage drush mel:reminder-list-orders
   *   List all orders with events that can be used for testing.
   */
  public function reminderListOrders(): void {
    $orderStorage = \Drupal::entityTypeManager()->getStorage('commerce_order');
    $orderItemStorage = \Drupal::entityTypeManager()->getStorage('commerce_order_item');
    $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');

    // Find all completed orders.
    $orderIds = $orderStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', ['completed', 'placed', 'fulfilled'], 'IN')
      ->sort('order_id', 'DESC')
      ->range(0, 50)
      ->execute();

    if (empty($orderIds)) {
      $this->logger()->warning('No completed orders found.');
      return;
    }

    $orders = $orderStorage->loadMultiple($orderIds);
    $found = 0;

    $this->output()->writeln('');
    $this->output()->writeln('Orders with events (suitable for reminder testing):');
    $this->output()->writeln('');

    foreach ($orders as $order) {
      // Find event from order items.
      $event = NULL;
      foreach ($order->getItems() as $orderItem) {
        if ($orderItem->hasField('field_target_event') && !$orderItem->get('field_target_event')->isEmpty()) {
          $eventId = $orderItem->get('field_target_event')->target_id;
          $event = $nodeStorage->load($eventId);
          if ($event) {
            break;
          }
        }
      }

      if ($event) {
        $found++;
        $email = $order->getEmail() ?: '(no email)';
        $eventStart = '';
        if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
          $startDate = $event->get('field_event_start')->date;
          if ($startDate) {
            $eventStart = \Drupal::service('date.formatter')->format($startDate->getTimestamp(), 'custom', 'Y-m-d H:i');
          }
        }

        $this->output()->writeln(sprintf(
          '  Order #%s | Event: %s | Email: %s | Event Start: %s',
          $order->id(),
          $event->label(),
          $email,
          $eventStart ?: '(no date)'
        ));
      }
    }

    if ($found === 0) {
      $this->logger()->warning('No orders with events found.');
    }
    else {
      $this->output()->writeln('');
      $this->logger()->success("Found {$found} order(s) with events.");
    }
  }

  /**
   * Tests reminder email by sending directly to an email address.
   *
   * @param string $type
   *   Reminder type: '7d' or '24h'.
   * @param string $email
   *   Email address to send test to.
   * @param int $order_id
   *   Order ID to use for context.
   *
   * @command mel:reminder-test
   * @aliases mel-reminder-test
   * @usage drush mel:reminder-test 7d test@example.com 123
   *   Send a test 7-day reminder email using order 123.
   */
  public function reminderTest(string $type, string $email, int $order_id): void {
    if (!in_array($type, ['7d', '24h'], TRUE)) {
      $this->logger()->error("Invalid reminder type. Use '7d' or '24h'.");
      return;
    }

    $orderStorage = \Drupal::entityTypeManager()->getStorage('commerce_order');
    $order = $orderStorage->load($order_id);
    if (!$order) {
      $this->logger()->error("Order {$order_id} not found.");
      return;
    }

    // Find event from order items.
    $event = NULL;
    foreach ($order->getItems() as $orderItem) {
      if ($orderItem->hasField('field_target_event') && !$orderItem->get('field_target_event')->isEmpty()) {
        $eventId = $orderItem->get('field_target_event')->target_id;
        $event = \Drupal::entityTypeManager()->getStorage('node')->load($eventId);
        if ($event) {
          break;
        }
      }
    }

    if (!$event) {
      $this->logger()->error("No event found for order {$order_id}.");
      return;
    }

    // Build context (reuse worker logic).
    $context = $this->buildReminderContext($order, $event, $type === '7d' ? '7 days' : '24 hours');

    // Generate ICS.
    $attachments = [];
    try {
      $icsGenerator = \Drupal::service('myeventlane_rsvp.ics_generator');
      $icsContent = $icsGenerator->generate($event);
      if ($icsContent) {
        $filename = 'event-' . $event->id() . '-' . \Drupal::transliteration()->transliterate($event->label(), 'en', '_', 255) . '.ics';
        $attachments[] = [
          'filename' => $filename,
          'content' => $icsContent,
          'mime' => 'text/calendar',
        ];
      }
    }
    catch (\Exception $e) {
      $this->logger()->warning("Failed to generate ICS: " . $e->getMessage());
    }

    // Send email.
    $templateKey = "event_reminder_{$type}";
    \Drupal::service('myeventlane_messaging.manager')->queue(
      $templateKey,
      $email,
      $context,
      [
        'langcode' => $order->language()->getId(),
        'attachments' => $attachments,
      ]
    );

    $this->logger()->success("Test {$type} reminder queued to {$email}. Run 'drush mel:msg-run' to send.");
  }

  /**
   * Manually queues a reminder for testing (bypasses date checks).
   *
   * @param string $type
   *   Reminder type: '7d' or '24h'.
   * @param int $order_id
   *   Order ID.
   *
   * @command mel:reminder-queue-manual
   * @aliases mel-reminder-queue
   * @usage drush mel:reminder-queue-manual 7d 123
   *   Manually queue a 7-day reminder for order 123 (for testing).
   */
  public function reminderQueueManual(string $type, int $order_id): void {
    if (!in_array($type, ['7d', '24h'], TRUE)) {
      $this->logger()->error("Invalid reminder type. Use '7d' or '24h'.");
      return;
    }

    $orderStorage = \Drupal::entityTypeManager()->getStorage('commerce_order');
    $order = $orderStorage->load($order_id);
    if (!$order) {
      $this->logger()->error("Order {$order_id} not found.");
      return;
    }

    // Verify order state.
    $orderState = $order->getState()->getId();
    if (!in_array($orderState, ['completed', 'placed', 'fulfilled'], TRUE)) {
      $this->logger()->error("Order {$order_id} is in state '{$orderState}'. Must be completed, placed, or fulfilled.");
      return;
    }

    // Find event from order items.
    $event = NULL;
    $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
    foreach ($order->getItems() as $orderItem) {
      if ($orderItem->hasField('field_target_event') && !$orderItem->get('field_target_event')->isEmpty()) {
        $eventId = $orderItem->get('field_target_event')->target_id;
        $event = $nodeStorage->load($eventId);
        if ($event) {
          break;
        }
      }
    }

    if (!$event) {
      $this->logger()->error("No event found for order {$order_id}.");
      return;
    }

    $eventId = (int) $event->id();
    $reminderType = "reminder_{$type}";

    // Check idempotency.
    $scheduler = \Drupal::service('myeventlane_messaging.event_reminder_scheduler');
    $reminderKey = $scheduler->getReminderKey($order_id, $eventId, $reminderType);
    if ($scheduler->isReminderSent($reminderKey)) {
      $this->logger()->warning("Reminder already sent for order {$order_id}, event {$eventId}. Use --force to override.");
      return;
    }

    // Enqueue reminder.
    $queueName = $type === '7d' ? 'event_reminder_7d' : 'event_reminder_24h';
    $queue = \Drupal::queue($queueName);
    $queue->createItem([
      'order_id' => $order_id,
      'event_id' => $eventId,
      'reminder_type' => $reminderType,
    ]);

    $this->logger()->success("Manually queued {$type} reminder for order {$order_id}, event {$eventId}. Run 'drush mel:reminder-run' to process.");
  }

  /**
   * Builds reminder email context.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event.
   * @param string $timeframe
   *   Timeframe string.
   *
   * @return array
   *   Email context array.
   */
  private function buildReminderContext($order, $event, string $timeframe): array {
    $context = [
      'order' => $order,
      'order_number' => $order->getOrderNumber(),
      'event' => $event,
      'event_title' => $event->label(),
      'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'my_tickets_url' => Url::fromRoute('myeventlane_checkout_flow.order_detail', ['commerce_order' => $order->id()], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'timeframe' => $timeframe,
    ];

    // Event date/time.
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $startDate = $event->get('field_event_start')->date;
      if ($startDate) {
        $dateFormatter = \Drupal::service('date.formatter');
        $context['event_start'] = $dateFormatter->format($startDate->getTimestamp(), 'custom', 'F j, Y g:ia T');
        $context['event_start_date'] = $dateFormatter->format($startDate->getTimestamp(), 'custom', 'F j, Y');
        $context['event_start_time'] = $dateFormatter->format($startDate->getTimestamp(), 'custom', 'g:ia T');
      }
    }

    // Event location.
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $context['event_location'] = $event->get('field_location')->value;
    }
    elseif ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $context['event_location'] = $event->get('field_venue_name')->value;
    }

    // Get attendee names.
    $attendeeNames = [];
    foreach ($order->getItems() as $orderItem) {
      if ($orderItem->hasField('field_ticket_holder') && !$orderItem->get('field_ticket_holder')->isEmpty()) {
        foreach ($orderItem->get('field_ticket_holder')->referencedEntities() as $paragraph) {
          $firstName = $paragraph->hasField('field_first_name') && !$paragraph->get('field_first_name')->isEmpty()
            ? $paragraph->get('field_first_name')->value : '';
          $lastName = $paragraph->hasField('field_last_name') && !$paragraph->get('field_last_name')->isEmpty()
            ? $paragraph->get('field_last_name')->value : '';
          $name = trim($firstName . ' ' . $lastName);
          if (!empty($name)) {
            $attendeeNames[] = $name;
          }
        }
      }
    }
    $context['attendee_names'] = $attendeeNames;
    $context['attendee_count'] = count($attendeeNames);

    return $context;
  }

}
