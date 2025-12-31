<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_messaging\Service\EventReminderScheduler;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_rsvp\Service\IcsGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for 7-day event reminder notifications.
 *
 * @QueueWorker(
 *   id = "event_reminder_7d",
 *   title = @Translation("7-day event reminder"),
 *   cron = {"time" = 60}
 * )
 */
final class EventReminder7dWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs EventReminder7dWorker.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\myeventlane_messaging\Service\MessagingManager $messagingManager
   *   The messaging manager.
   * @param \Drupal\myeventlane_messaging\Service\EventReminderScheduler $scheduler
   *   The reminder scheduler.
   * @param \Drupal\myeventlane_rsvp\Service\IcsGenerator $icsGenerator
   *   The ICS generator.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessagingManager $messagingManager,
    private readonly EventReminderScheduler $scheduler,
    private readonly IcsGenerator $icsGenerator,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('myeventlane_messaging.manager'),
      $container->get('myeventlane_messaging.event_reminder_scheduler'),
      $container->get('myeventlane_rsvp.ics_generator'),
      $container->get('logger.factory')->get('myeventlane_messaging')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $orderId = $data['order_id'] ?? NULL;
    $eventId = $data['event_id'] ?? NULL;
    $reminderType = $data['reminder_type'] ?? 'reminder_7d';

    if (!$orderId || !$eventId) {
      $this->logger->error('EventReminder7dWorker: Missing required data');
      return;
    }

    // Load order.
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $order = $orderStorage->load($orderId);
    if (!$order) {
      $this->logger->error('EventReminder7dWorker: Order @id not found', ['@id' => $orderId]);
      return;
    }

    // Verify order state (should be completed/placed).
    $orderState = $order->getState()->getId();
    if (!in_array($orderState, ['completed', 'placed', 'fulfilled'], TRUE)) {
      $this->logger->info('EventReminder7dWorker: Skipping order @id with state @state', [
        '@id' => $orderId,
        '@state' => $orderState,
      ]);
      return;
    }

    // Load event.
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $event = $nodeStorage->load($eventId);
    if (!$event) {
      $this->logger->error('EventReminder7dWorker: Event @id not found', ['@id' => $eventId]);
      return;
    }

    // Check idempotency.
    $reminderKey = $this->scheduler->getReminderKey($orderId, $eventId, $reminderType);
    if ($this->scheduler->isReminderSent($reminderKey)) {
      $this->logger->info('EventReminder7dWorker: Reminder already sent for order @order_id, event @event_id', [
        '@order_id' => $orderId,
        '@event_id' => $eventId,
      ]);
      return;
    }

    // Get order email.
    $orderEmail = $order->getEmail();
    if (empty($orderEmail)) {
      $this->logger->warning('EventReminder7dWorker: Order @id has no email', ['@id' => $orderId]);
      return;
    }

    // Prepare email context.
    $context = $this->buildEmailContext($order, $event, '7 days');

    // Generate ICS attachment.
    $attachments = [];
    try {
      $icsContent = $this->icsGenerator->generate($event);
      if ($icsContent) {
        $filename = 'event-' . $eventId . '-' . \Drupal::transliteration()->transliterate($event->label(), 'en', '_', 255) . '.ics';
        $attachments[] = [
          'filename' => $filename,
          'content' => $icsContent,
          'mime' => 'text/calendar',
        ];
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('EventReminder7dWorker: Failed to generate ICS: @message', ['@message' => $e->getMessage()]);
    }

    // Send email.
    try {
      $this->messagingManager->queue('event_reminder_7d', $orderEmail, $context, [
        'langcode' => $order->language()->getId(),
        'attachments' => $attachments,
      ]);

      // Mark reminder as sent.
      $this->scheduler->markReminderSent($reminderKey);

      $this->logger->info('Sent 7-day reminder for order @order_id, event @event_id to @email', [
        '@order_id' => $orderId,
        '@event_id' => $eventId,
        '@email' => $orderEmail,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('EventReminder7dWorker: Failed to send reminder: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Builds email context for reminder.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event.
   * @param string $timeframe
   *   Timeframe string (e.g., "7 days", "24 hours").
   *
   * @return array
   *   Email context array.
   */
  private function buildEmailContext($order, $event, string $timeframe): array {
    $context = [
      'order' => $order,
      'order_number' => $order->getOrderNumber(),
      'event' => $event,
      'event_title' => $event->label(),
      'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'my_tickets_url' => \Drupal\Core\Url::fromRoute('myeventlane_checkout_flow.order_detail', ['commerce_order' => $order->id()], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
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

    // Get attendee names from order items.
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

