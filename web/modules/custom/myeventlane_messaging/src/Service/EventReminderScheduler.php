<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Url;
use Drupal\myeventlane_attendee\Attendee\AttendeeInterface;
use Drupal\myeventlane_attendee\Attendee\RsvpAttendee;
use Drupal\myeventlane_attendee\Attendee\TicketAttendee;
use Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\myeventlane_notifications\Service\ReminderNotificationTriggerService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Schedules event reminder emails via the canonical messaging queue.
 *
 * Builds full template context and calls MessagingManager::queue() so that
 * only message_id is enqueued; idempotency is enforced by the manager.
 *
 * Commerce order purchasers are reminded via field_target_event order items.
 * RSVP and other attendees (via AttendeeRepositoryResolver) are reminded when
 * their email was not already covered by an order in the same scan window.
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
   * @param \Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver $attendeeRepositoryResolver
   *   Resolves RSVP and ticket attendees for non-order reminders.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager (default langcode for non-order recipients).
   * @param \Drupal\myeventlane_rsvp\Service\IcsGenerator|null $icsGenerator
   *   Optional ICS generator for calendar attachments.
   * @param \Drupal\myeventlane_core\Service\DomainDetector|null $domainDetector
   *   The domain detector (for public-domain customer links).
   * @param \Drupal\myeventlane_notifications\Service\ReminderNotificationTriggerService|null $reminderNotificationTrigger
   *   Optional in-app reminder trigger.
   * @param \Drupal\myeventlane_core\Service\EventDateTimeResolver|null $eventDateTime
   *   Resolves event wall-clock values in each event's timezone.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly MessagingManager $messagingManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AttendeeRepositoryResolver $attendeeRepositoryResolver,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ?object $icsGenerator = NULL,
    private readonly ?DomainDetector $domainDetector = NULL,
    private readonly ?ReminderNotificationTriggerService $reminderNotificationTrigger = NULL,
    private readonly ?EventDateTimeResolver $eventDateTime = NULL,
  ) {}

  /**
   * Scans for events needing reminders; queues via MessagingManager.
   */
  public function scan(): void {
    $now = $this->time->getRequestTime();

    $this->scanReminders($now, 7 * 24 * 3600, 'event_reminder_7d', '7 days', NULL);
    $this->scanReminders($now, 24 * 3600, 'event_reminder_24h', '24 hours', '24h');
    $this->scanReminders($now, 3600, NULL, '1 hour', '1h');
  }

  /**
   * Scans for events in the reminder window and queues via manager.
   *
   * @param int $now
   *   Current timestamp.
   * @param int $reminderOffset
   *   Seconds before event start.
   * @param string|null $template
   *   Email template key, or NULL to skip email (in-app only).
   * @param string $timeframe
   *   Human-readable timeframe for template context.
   * @param string|null $inAppWindow
   *   When set (24h or 1h), queues matching in-app reminder notifications.
   */
  private function scanReminders(int $now, int $reminderOffset, ?string $template, string $timeframe, ?string $inAppWindow): void {
    $windowStart = $now + $reminderOffset - 3600;
    $windowEnd = $now + $reminderOffset + 3600;
    [$storageStart, $storageEnd] = $this->eventDateTime
      ?->getBroadStorageBounds($windowStart, $windowEnd)
      ?? [gmdate('Y-m-d\TH:i:s', $windowStart), gmdate('Y-m-d\TH:i:s', $windowEnd)];

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $query = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->exists('field_event_start')
      ->condition('field_event_start', $storageStart, '>=')
      ->condition('field_event_start', $storageEnd, '<=');

    $eventIds = $query->execute();
    if (empty($eventIds)) {
      return;
    }

    $events = $nodeStorage->loadMultiple($eventIds);
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $queueEmail = $template !== NULL && $template !== '';

    foreach ($events as $event) {
      if (!$event instanceof NodeInterface) {
        continue;
      }

      if ($this->eventDateTime !== NULL
        && !$this->eventDateTime->fieldIsWithin($event, 'field_event_start', $windowStart, $windowEnd)) {
        continue;
      }

      if ($event->hasField('field_event_state') && !$event->get('field_event_state')->isEmpty()) {
        $state = $event->get('field_event_state')->value;
        if (in_array($state, ['cancelled', 'ended'], TRUE)) {
          continue;
        }
      }

      if (!$this->remindersEnabledForEvent($event)) {
        continue;
      }

      $eventId = (int) $event->id();
      $processedEmails = [];

      $orderItemIds = $orderItemStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_target_event', $eventId)
        ->execute();

      if (!empty($orderItemIds)) {
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

          $orderEmail = trim((string) $order->getEmail());
          if ($queueEmail && $orderEmail === '') {
            continue;
          }

          if ($queueEmail) {
            $context = $this->buildOrderContext($order, $event, $timeframe);
            $this->messagingManager->queue($template, $orderEmail, $context, [
              'langcode' => $order->language()->getId(),
              'attachments' => $this->buildIcsAttachments($event, $eventId),
            ]);
            $processedEmails[strtolower($orderEmail)] = TRUE;
          }

          if ($inAppWindow !== NULL && $this->reminderNotificationTrigger !== NULL) {
            $this->reminderNotificationTrigger->onOrderEventReminder($order, $event, $inAppWindow);
          }

          $processedOrders[$orderId] = TRUE;
          if ($queueEmail) {
            $this->logger->info('Scheduled @template reminder for order @order_id, event @event_id', [
              '@template' => $template,
              '@order_id' => $orderId,
              '@event_id' => $eventId,
            ]);
          }
        }
      }

      if ($queueEmail) {
        $this->queueAttendeeReminders($event, $eventId, $template, $timeframe, $processedEmails);
      }
    }
  }

  /**
   * Queues reminders for RSVP/ticket attendees not already emailed via orders.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param int $eventId
   *   The event node ID.
   * @param string $template
   *   Email template key.
   * @param string $timeframe
   *   Human-readable timeframe for template context.
   * @param array<string, bool> $processedEmails
   *   Lowercase email keys already reminded in this scan pass.
   */
  private function queueAttendeeReminders(
    NodeInterface $event,
    int $eventId,
    string $template,
    string $timeframe,
    array $processedEmails,
  ): void {
    $repository = $this->attendeeRepositoryResolver->getRepository($event);
    $attendees = $repository->loadByEvent($event);
    if ($attendees === []) {
      return;
    }

    $defaultLangcode = $this->languageManager->getDefaultLanguage()->getId();
    $attachments = $this->buildIcsAttachments($event, $eventId);

    foreach ($attendees as $attendee) {
      if (!$attendee instanceof AttendeeInterface) {
        continue;
      }

      $email = trim($attendee->getEmail());
      if ($email === '') {
        continue;
      }

      $emailKey = strtolower($email);
      if (isset($processedEmails[$emailKey])) {
        continue;
      }

      $context = $this->buildAttendeeContext($event, $attendee, $timeframe);
      $this->messagingManager->queue($template, $email, $context, [
        'langcode' => $defaultLangcode,
        'attachments' => $attachments,
      ]);
      $processedEmails[$emailKey] = TRUE;

      $this->logger->info('Scheduled @template reminder for attendee @email, event @event_id', [
        '@template' => $template,
        '@email' => $email,
        '@event_id' => $eventId,
      ]);
    }
  }

  /**
   * Whether event-level reminder emails are enabled.
   */
  private function remindersEnabledForEvent(NodeInterface $event): bool {
    if ($event->hasField('field_enable_reminders') && !$event->get('field_enable_reminders')->isEmpty()) {
      return (bool) $event->get('field_enable_reminders')->value;
    }
    return TRUE;
  }

  /**
   * Builds ICS calendar attachments for an event reminder email.
   *
   * @return array<int, array<string, string>>
   *   Attachment payloads for MessagingManager::queue().
   */
  private function buildIcsAttachments(NodeInterface $event, int $eventId): array {
    if (!$this->icsGenerator || !method_exists($this->icsGenerator, 'generate')) {
      return [];
    }

    try {
      $icsContent = $this->icsGenerator->generate($event);
      if (!$icsContent) {
        return [];
      }

      $filename = 'event-' . $eventId . '-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($event->label())) . '.ics';
      return [[
        'filename' => $filename,
        'content' => $icsContent,
        'mime' => 'text/calendar',
      ],
      ];
    }
    catch (\Throwable $e) {
      $this->logger->warning('ICS generation failed for event @eid: @msg', [
        '@eid' => $eventId,
        '@msg' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds serializable template context for a commerce order recipient.
   */
  private function buildOrderContext(OrderInterface $order, NodeInterface $event, string $timeframe): array {
    $event_url = $this->buildPublicUrl($event->toUrl('canonical', ['absolute' => FALSE])->toString());
    $my_tickets_url = $this->buildOrderDetailUrl((int) $order->id());
    if (!$event_url) {
      $event_url = $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
    }

    $context = [
      'order_id' => (int) $order->id(),
      'event_id' => (int) $event->id(),
      'order_number' => $order->getOrderNumber(),
      'event_title' => $event->label(),
      'event_url' => $event_url,
      'my_tickets_url' => $my_tickets_url,
      'timeframe' => $timeframe,
    ];

    $this->appendEventScheduleContext($context, $event);

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
   * Builds serializable template context for an RSVP or ticket attendee.
   */
  private function buildAttendeeContext(NodeInterface $event, AttendeeInterface $attendee, string $timeframe): array {
    $event_url = $this->buildPublicUrl($event->toUrl('canonical', ['absolute' => FALSE])->toString());
    if (!$event_url) {
      $event_url = $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
    }

    $displayName = trim($attendee->getDisplayName());
    $attendeeNames = $displayName !== '' ? [$displayName] : [];

    $context = [
      'event_id' => (int) $event->id(),
      'event_title' => $event->label(),
      'event_url' => $event_url,
      'timeframe' => $timeframe,
      'attendee_names' => $attendeeNames,
      'attendee_count' => $attendeeNames !== [] ? count($attendeeNames) : 1,
    ];

    $this->appendAttendeeReferenceContext($context, $attendee);
    $this->appendEventScheduleContext($context, $event);

    return $context;
  }

  /**
   * Adds booking reference and CTA URLs expected by reminder templates.
   *
   * @param array<string, mixed> $context
   *   Context array to mutate.
   * @param \Drupal\myeventlane_attendee\Attendee\AttendeeInterface $attendee
   *   RSVP or ticket attendee.
   */
  private function appendAttendeeReferenceContext(array &$context, AttendeeInterface $attendee): void {
    if ($attendee instanceof RsvpAttendee) {
      $submissionId = (int) substr($attendee->getAttendeeId(), 5);
      $context['submission_id'] = $submissionId;
      $context['order_number'] = 'RSVP-' . $submissionId;
      $context['my_tickets_url'] = $this->buildAccountHubUrl();
      return;
    }

    if ($attendee instanceof TicketAttendee) {
      $context['attendee_id'] = $attendee->getAttendeeId();
      $orderReference = $this->resolveOrderReferenceFromTicketAttendee($attendee);
      if ($orderReference !== NULL) {
        $context += $orderReference;
        return;
      }

      $ticketCode = trim($attendee->getEventAttendee()->getTicketCode() ?? '');
      $context['order_number'] = $ticketCode !== '' ? $ticketCode : $attendee->getAttendeeId();
      $context['my_tickets_url'] = $this->buildMyTicketsHubUrl();
      return;
    }

    $attendeeId = $attendee->getAttendeeId();
    if (str_starts_with($attendeeId, 'rsvp:')) {
      $submissionId = (int) substr($attendeeId, 5);
      $context['submission_id'] = $submissionId;
      $context['order_number'] = 'RSVP-' . $submissionId;
      $context['my_tickets_url'] = $this->buildAccountHubUrl();
    }
    elseif (str_starts_with($attendeeId, 'ticket:')) {
      $context['attendee_id'] = $attendeeId;
      $context['order_number'] = $attendeeId;
      $context['my_tickets_url'] = $this->buildMyTicketsHubUrl();
    }
  }

  /**
   * Resolves commerce order reference fields for a ticket attendee.
   *
   * @return array{order_id: int, order_number: string|null, my_tickets_url: string}|null
   *   Order context when an order item link exists, otherwise NULL.
   */
  private function resolveOrderReferenceFromTicketAttendee(TicketAttendee $attendee): ?array {
    $eventAttendee = $attendee->getEventAttendee();
    if (!$eventAttendee->hasField('order_item') || $eventAttendee->get('order_item')->isEmpty()) {
      return NULL;
    }

    $orderItem = $eventAttendee->get('order_item')->entity;
    if (!$orderItem instanceof OrderItemInterface) {
      return NULL;
    }

    try {
      $order = $orderItem->getOrder();
      if (!$order instanceof OrderInterface) {
        return NULL;
      }
    }
    catch (\Exception $e) {
      return NULL;
    }

    $orderId = (int) $order->id();
    return [
      'order_id' => $orderId,
      'order_number' => $order->getOrderNumber(),
      'my_tickets_url' => $this->buildOrderDetailUrl($orderId),
    ];
  }

  /**
   * Builds the public order detail URL used by reminder templates.
   */
  private function buildOrderDetailUrl(int $orderId): string {
    $url = $this->buildPublicUrl('/my-tickets/order/' . $orderId);
    if ($url !== NULL) {
      return $url;
    }

    return Url::fromRoute('myeventlane_checkout_flow.order_detail', [
      'commerce_order' => $orderId,
    ], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
  }

  /**
   * Builds the customer tickets hub URL for non-order ticket holders.
   */
  private function buildMyTicketsHubUrl(): string {
    $url = $this->buildPublicUrl('/my-tickets');
    if ($url !== NULL) {
      return $url;
    }

    return Url::fromRoute('myeventlane_checkout_flow.my_tickets', [], [
      'absolute' => TRUE,
    ])->toString(TRUE)->getGeneratedUrl();
  }

  /**
   * Builds the customer account hub URL for RSVP holders.
   */
  private function buildAccountHubUrl(): string {
    $url = $this->buildPublicUrl('/my-account');
    if ($url !== NULL) {
      return $url;
    }

    return Url::fromRoute('myeventlane_account.dashboard', [], [
      'absolute' => TRUE,
    ])->toString(TRUE)->getGeneratedUrl();
  }

  /**
   * Appends shared event schedule/location fields to reminder context.
   *
   * @param array<string, mixed> $context
   *   Context array to mutate.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   */
  private function appendEventScheduleContext(array &$context, NodeInterface $event): void {
    $this->appendEventDateTimeContext($context, $event);

    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $context['event_location'] = $event->get('field_location')->value;
    }
    elseif ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $context['event_location'] = $event->get('field_venue_name')->value;
    }
  }

  /**
   * Appends timezone-correct event date and time fields to message context.
   *
   * @param array<string, mixed> $context
   *   Context array to mutate.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   */
  public function appendEventDateTimeContext(array &$context, NodeInterface $event): void {
    $timestamp = $this->eventDateTime?->getFieldTimestamp($event, 'field_event_start');
    if ($timestamp === NULL) {
      return;
    }

    $timezone = $this->eventDateTime->getTimezoneId($event);
    $context['event_start'] = $this->dateFormatter->format($timestamp, 'custom', 'F j, Y g:ia T', $timezone);
    $context['event_start_date'] = $this->dateFormatter->format($timestamp, 'custom', 'F j, Y', $timezone);
    $context['event_start_time'] = $this->dateFormatter->format($timestamp, 'custom', 'g:ia T', $timezone);
  }

  /**
   * Builds an absolute URL on the public domain for customer-facing links.
   *
   * @param string $path
   *   Internal path (e.g. '/my-tickets/order/123' or '/node/1').
   *
   * @return string|null
   *   Full URL or NULL if domain config is unavailable.
   */
  private function buildPublicUrl(string $path): ?string {
    if (!$this->domainDetector) {
      return NULL;
    }
    try {
      return $this->domainDetector->buildDomainUrl($path, 'public');
    }
    catch (\Exception $e) {
      return NULL;
    }
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
