<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver;
use Psr\Log\LoggerInterface;

/**
 * Scheduler service that finds events needing automation actions.
 */
final class AutomationScheduler {

  /**
   * Constructs the scheduler.
   */
  public function __construct(
    private readonly AutomationDispatchService $dispatchService,
    private readonly EventStateResolverInterface $stateResolver,
    private readonly AttendeeRepositoryResolver $attendeeRepositoryResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Scans for events needing automation actions and enqueues jobs.
   */
  public function scan(): void {
    $now = $this->time->getRequestTime();

    $this->scanSalesOpening($now);
    $this->scanReminders($now);
    $this->scanWaitlistInvites($now);
    $this->scanWeeklyDigests($now);
  }

  /**
   * Scans for events with sales opening soon.
   */
  private function scanSalesOpening(int $now): void {
    // Find events transitioning from scheduled to live.
    // Check events with sales_start in the past 1 hour (to catch missed scans).
    $oneHourAgo = $now - 3600;
    $oneHourFromNow = $now + 3600;

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->exists('field_sales_start')
      ->condition('field_sales_start', date('Y-m-d\TH:i:s', $oneHourAgo), '>=')
      ->condition('field_sales_start', date('Y-m-d\TH:i:s', $oneHourFromNow), '<=');

    $eventIds = $query->execute();

    foreach ($eventIds as $eventId) {
      $event = $this->entityTypeManager->getStorage('node')->load($eventId);
      if (!$event) {
        continue;
      }

      $state = $this->stateResolver->resolveState($event);
      if ($state !== 'live') {
        continue;
      }

      $salesStart = $this->stateResolver->getSalesStart($event);
      if ($salesStart === NULL || $salesStart > $now) {
        continue;
      }

      // Check if already dispatched.
      $vendorHash = $this->dispatchService->hashRecipient((string) $event->getOwnerId());
      if ($this->dispatchService->isAlreadySent($eventId, AutomationDispatchService::TYPE_SALES_OPEN, $vendorHash)) {
        continue;
      }

      // Create dispatch record and enqueue.
      $dispatchId = $this->dispatchService->createDispatch(
        $eventId,
        AutomationDispatchService::TYPE_SALES_OPEN,
        $vendorHash,
        $now
      );

      $queue = $this->queueFactory->get('automation_sales_open');
      $queue->createItem([
        'dispatch_id' => $dispatchId,
        'event_id' => $eventId,
      ]);

      $this->logger->info('Scheduled sales_open notification for event @id', ['@id' => $eventId]);
    }
  }

  /**
   * Scans for events needing reminders (24h and 2h before start).
   */
  private function scanReminders(int $now): void {

    // Check 24h reminders: events starting between 23h and 25h from now.
    $reminder24hStart = $now + (23 * 3600);
    $reminder24hEnd = $now + (25 * 3600);

    $query24h = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->exists('field_event_start')
      ->condition('field_event_start', date('Y-m-d\TH:i:s', $reminder24hStart), '>=')
      ->condition('field_event_start', date('Y-m-d\TH:i:s', $reminder24hEnd), '<=');

    $eventIds24h = $query24h->execute();

    foreach ($eventIds24h as $eventId) {
      $event = $this->entityTypeManager->getStorage('node')->load($eventId);
      if (!$event) {
        continue;
      }

      // Skip if cancelled or ended.
      $state = $this->stateResolver->resolveState($event);
      if (in_array($state, ['cancelled', 'ended'], TRUE)) {
        continue;
      }

      // Check if reminders are disabled for this event.
      if ($event->hasField('field_enable_reminders') && !$event->get('field_enable_reminders')->isEmpty()) {
        if (!$event->get('field_enable_reminders')->value) {
          continue;
        }
      }

      // Get attendees and schedule reminders.
      $repository = $this->attendeeRepositoryResolver->getRepository($event);
      $attendees = $repository->loadByEvent($event);

      foreach ($attendees as $attendee) {
        $email = $attendee->getEmail();
        if (empty($email)) {
          continue;
        }

        $recipientHash = $this->dispatchService->hashRecipient($email);
        if ($this->dispatchService->isAlreadySent($eventId, AutomationDispatchService::TYPE_REMINDER_24H, $recipientHash)) {
          continue;
        }

        $dispatchId = $this->dispatchService->createDispatch(
          $eventId,
          AutomationDispatchService::TYPE_REMINDER_24H,
          $recipientHash,
          $now
        );

        $queue = $this->queueFactory->get('automation_reminder_24h');
        $queue->createItem([
          'dispatch_id' => $dispatchId,
          'event_id' => $eventId,
          'recipient_email' => $email,
        ]);
      }
    }

    // Check 2h reminders: events starting between 1h and 3h from now.
    $reminder2hStart = $now + 3600;
    $reminder2hEnd = $now + (3 * 3600);

    $query2h = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->exists('field_event_start')
      ->condition('field_event_start', date('Y-m-d\TH:i:s', $reminder2hStart), '>=')
      ->condition('field_event_start', date('Y-m-d\TH:i:s', $reminder2hEnd), '<=');

    $eventIds2h = $query2h->execute();

    foreach ($eventIds2h as $eventId) {
      $event = $this->entityTypeManager->getStorage('node')->load($eventId);
      if (!$event) {
        continue;
      }

      $state = $this->stateResolver->resolveState($event);
      if (in_array($state, ['cancelled', 'ended'], TRUE)) {
        continue;
      }

      if ($event->hasField('field_enable_reminders') && !$event->get('field_enable_reminders')->isEmpty()) {
        if (!$event->get('field_enable_reminders')->value) {
          continue;
        }
      }

      $repository = $this->attendeeRepositoryResolver->getRepository($event);
      $attendees = $repository->loadByEvent($event);

      foreach ($attendees as $attendee) {
        $email = $attendee->getEmail();
        if (empty($email)) {
          continue;
        }

        $recipientHash = $this->dispatchService->hashRecipient($email);
        if ($this->dispatchService->isAlreadySent($eventId, AutomationDispatchService::TYPE_REMINDER_2H, $recipientHash)) {
          continue;
        }

        $dispatchId = $this->dispatchService->createDispatch(
          $eventId,
          AutomationDispatchService::TYPE_REMINDER_2H,
          $recipientHash,
          $now
        );

        $queue = $this->queueFactory->get('automation_reminder_2h');
        $queue->createItem([
          'dispatch_id' => $dispatchId,
          'event_id' => $eventId,
          'recipient_email' => $email,
        ]);
      }
    }

    $this->logger->info('Scanned reminders: @count_24h events for 24h, @count_2h events for 2h', [
      '@count_24h' => count($eventIds24h),
      '@count_2h' => count($eventIds2h),
    ]);
  }

  /**
   * Scans for events with waitlist capacity available.
   *
   * Note: This should be triggered when capacity changes, not just on cron.
   * For now, we check on cron but ideally this would be event-driven.
   */
  private function scanWaitlistInvites(int $now): void {
    // @todo This should ideally be triggered when capacity changes (refund, cancel, etc).
    // For now, we do a basic scan of live events with waitlist enabled.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_event_state', 'live');

    $eventIds = $query->execute();

    foreach ($eventIds as $eventId) {
      $event = $this->entityTypeManager->getStorage('node')->load($eventId);
      if (!$event) {
        continue;
      }

      // Skip if cancelled or ended.
      $state = $this->stateResolver->resolveState($event);
      if (in_array($state, ['cancelled', 'ended'], TRUE)) {
        continue;
      }

      // Check if waitlist auto-invite is enabled.
      if ($event->hasField('field_waitlist_auto_invite') && !$event->get('field_waitlist_auto_invite')->isEmpty()) {
        if (!$event->get('field_waitlist_auto_invite')->value) {
          continue;
        }
      }

      // Check capacity availability.
      // @todo Use capacity service to check if capacity is available.
      // For now, skip this automated scan and rely on event-driven triggers.
      // This will be handled when capacity changes occur.
    }

    // Note: Waitlist invites should be triggered when:
    // - Refund processed
    // - Ticket cancelled
    // - Capacity released
    // See integration points in cancel/refund handlers.
  }

  /**
   * Scans for weekly category digests (runs once per week).
   */
  private function scanWeeklyDigests(int $now): void {
    // Check if we should run weekly digests (once per week, on Monday).
    $dayOfWeek = (int) date('w', $now);
    if ($dayOfWeek !== 1) {
      // Not Monday, skip.
      return;
    }

    // Check if we already ran this week (use state API to track last run).
    $state = \Drupal::state();
    $lastRun = $state->get('myeventlane_automation.weekly_digest_last_run', 0);
    $oneWeekAgo = $now - (7 * 24 * 3600);

    if ($lastRun > $oneWeekAgo) {
      // Already ran this week.
      return;
    }

    // Check if CategoryDigestGenerator service exists.
    if (!\Drupal::hasService('myeventlane_core.category_digest')) {
      return;
    }

    $digestGenerator = \Drupal::service('myeventlane_core.category_digest');
    $userIds = $digestGenerator->getUsersWithFollowedCategories();

    foreach ($userIds as $userId) {
      $user = $this->entityTypeManager->getStorage('user')->load($userId);
      if (!$user || !$user->isActive()) {
        continue;
      }

      $recipientHash = $this->dispatchService->hashRecipient($user->getEmail());
      if ($this->dispatchService->isAlreadySent(NULL, AutomationDispatchService::TYPE_WEEKLY_CATEGORY_DIGEST, $recipientHash)) {
        // Already sent this week.
        continue;
      }

      $dispatchId = $this->dispatchService->createDispatch(
        NULL,
        AutomationDispatchService::TYPE_WEEKLY_CATEGORY_DIGEST,
        $recipientHash,
        $now
      );

      $queue = $this->queueFactory->get('automation_weekly_digest');
      $queue->createItem([
        'dispatch_id' => $dispatchId,
        'user_id' => $userId,
      ]);
    }

    // Mark as run.
    $state->set('myeventlane_automation.weekly_digest_last_run', $now);

    $this->logger->info('Scheduled weekly category digests for @count users', ['@count' => count($userIds)]);
  }

}
