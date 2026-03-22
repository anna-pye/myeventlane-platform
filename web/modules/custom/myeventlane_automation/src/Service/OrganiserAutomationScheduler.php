<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Service\BoostStatusService;
use Drupal\myeventlane_vendor\Service\RsvpStatsService;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Scans events and enqueues organiser automation jobs.
 *
 * Idempotent: avoids duplicate scheduling via AutomationJobService.
 */
final class OrganiserAutomationScheduler {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AutomationRuleService $ruleService,
    private readonly AutomationJobService $jobService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
    private readonly ?TicketSalesService $ticketSales = NULL,
    private readonly ?RsvpStatsService $rsvpStats = NULL,
    private readonly ?BoostStatusService $boostStatus = NULL,
    private readonly ?EventCapacityServiceInterface $capacityService = NULL,
  ) {}

  /**
   * Scans eligible events and enqueues automation jobs.
   */
  public function scan(): void {
    $config = $this->configFactory->get('myeventlane_automation.settings');
    if (!$config->get('enabled')) {
      return;
    }

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->sort('changed', 'DESC')
      ->range(0, 200);

    $eventIds = $query->execute();
    $now = time();
    $dayStart = strtotime('today', $now);

    foreach ($eventIds as $nid) {
      $event = $this->entityTypeManager->getStorage('node')->load((int) $nid);
      if (!$event instanceof NodeInterface) {
        continue;
      }

      $eventData = $this->buildEventData($event);
      $candidates = $this->ruleService->evaluateEventAutomations($eventData);

      foreach ($candidates as $candidate) {
        if (!($candidate['sendable'] ?? FALSE)) {
          continue;
        }

        $key = $candidate['key'] ?? '';
        $recipientType = $candidate['recipient_type'] ?? 'organiser';

        if ($recipientType === 'organiser') {
          $ownerId = (int) $event->getOwnerId();
          if ($ownerId <= 0) {
            continue;
          }
          $user = $this->entityTypeManager->getStorage('user')->load($ownerId);
          $email = $user && method_exists($user, 'getEmail') ? $user->getEmail() : '';
          if (empty($email)) {
            continue;
          }
          $recipientHash = $this->jobService->hashRecipient($email);

          if ($this->jobService->isAlreadyScheduled((int) $event->id(), $key, $recipientHash, $dayStart)) {
            $this->logger->debug('Automation job skipped (duplicate): @key event @id', [
              '@key' => $key,
              '@id' => $event->id(),
            ]);
            continue;
          }

          $payload = $this->buildPayload($event, $key, $eventData);

          $jobId = $this->jobService->createJob(
            (int) $event->id(),
            $key,
            $recipientType,
            $recipientHash,
            $payload,
          );

          $this->queueFactory->get('automation_dispatch')->createItem([
            'automation_key' => $key,
            'event_id' => (int) $event->id(),
            'recipient_type' => $recipientType,
            'recipient_ids' => [$ownerId],
            'payload' => array_merge($payload, [
              'recipient_emails' => [$email],
              'event_title' => $event->label(),
            ]),
            'job_id' => $jobId,
            'created' => $now,
          ]);

          $this->logger->info('Queued automation @key for event @id', [
            '@key' => $key,
            '@id' => $event->id(),
          ]);
        }
        elseif ($recipientType === 'attendee' && $key === 'attendee_reminder') {
          $attendees = $this->loadAttendeeEmails($event);
          if (empty($attendees)) {
            continue;
          }
          foreach ($attendees as $email) {
            $recipientHash = $this->jobService->hashRecipient($email);
            if ($this->jobService->isAlreadyScheduled((int) $event->id(), $key, $recipientHash, $dayStart)) {
              continue;
            }
            $payload = $this->buildPayload($event, $key, $eventData);
            $jobId = $this->jobService->createJob(
              (int) $event->id(),
              $key,
              $recipientType,
              $recipientHash,
              array_merge($payload, ['recipient_emails' => [$email]]),
            );
            $this->queueFactory->get('automation_dispatch')->createItem([
              'automation_key' => $key,
              'event_id' => (int) $event->id(),
              'recipient_type' => $recipientType,
              'payload' => array_merge($payload, [
                'recipient_emails' => [$email],
                'event_title' => $event->label(),
              ]),
              'job_id' => $jobId,
              'created' => $now,
            ]);
          }
        }
      }
    }
  }

  private function buildEventData(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $ticketsSold = 0;
    $rsvps = 0;
    if ($this->ticketSales) {
      try {
        $summary = $this->ticketSales->getSalesSummary($event);
        $ticketsSold = (int) ($summary['tickets_sold'] ?? 0);
      }
      catch (\Throwable) {
        // Ignore.
      }
    }
    if ($this->rsvpStats) {
      try {
        $rsvps = $this->rsvpStats->getEventRsvpCount($eventId);
      }
      catch (\Throwable) {
        // Ignore.
      }
    }

    $capacity = NULL;
    if ($this->capacityService) {
      try {
        $capacity = $this->capacityService->getCapacityTotal($event);
      }
      catch (\Throwable) {
        // Ignore.
      }
    }

    $filled = $ticketsSold + $rsvps;
    $percentSold = ($capacity !== NULL && $capacity > 0)
      ? round($filled / $capacity * 100, 1)
      : 0.0;

    $startTs = 0;
    $endTs = 0;
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $item = $event->get('field_event_start');
      $startTs = $item->date ? $item->date->getTimestamp() : strtotime($item->value ?? '');
    }
    if ($event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()) {
      $item = $event->get('field_event_end');
      $endTs = $item->date ? $item->date->getTimestamp() : strtotime($item->value ?? '');
    }

    $now = time();
    $isEnded = $endTs > 0 && $endTs < $now;

    $boostData = ['eligible' => FALSE, 'active' => FALSE];
    if ($this->boostStatus) {
      try {
        $boostData = $this->boostStatus->getBoostStatuses($eventId);
      }
      catch (\Throwable) {
        // Ignore.
      }
    }

    $hasMeaningfulSetup = !$event->get('title')->isEmpty()
      || ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty());

    return [
      'id' => $eventId,
      'title' => $event->label(),
      'is_published' => $event->isPublished(),
      'is_ended' => $isEnded,
      'capacity' => $capacity,
      'tickets_sold' => $ticketsSold,
      'rsvps' => $rsvps,
      'percent_sold' => $percentSold,
      'start_timestamp' => $startTs,
      'end_timestamp' => $endTs,
      'created_timestamp' => (int) $event->getCreatedTime(),
      'changed_timestamp' => (int) $event->getChangedTime(),
      'is_boosted' => !empty($boostData['active']),
      'boost_eligible' => !empty($boostData['eligible']),
      'cta_params' => ['node' => $eventId],
      'attendee_count' => $filled,
      'has_meaningful_setup' => $hasMeaningfulSetup,
    ];
  }

  private function buildPayload(NodeInterface $event, string $key, array $eventData): array {
    $eventId = (int) $event->id();
    $editUrl = Url::fromRoute('myeventlane_event.wizard.edit', ['node' => $eventId], ['absolute' => TRUE])->toString();
    $attendeesUrl = NULL;
    $duplicateUrl = NULL;
    try {
      $attendeesUrl = Url::fromRoute('myeventlane_vendor.console.event_attendees', ['event' => $eventId], ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      // Route may not exist.
    }
    try {
      $duplicateUrl = Url::fromRoute('myeventlane_event.duplicate', ['node' => $eventId], ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      // Route may not exist.
    }

    $payload = [
      'event_id' => $eventId,
      'event_title' => $event->label(),
      'attendee_count' => $eventData['attendee_count'] ?? 0,
      'edit_url' => $editUrl,
      'attendees_url' => $attendeesUrl,
      'duplicate_url' => $duplicateUrl,
      'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];

    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $item = $event->get('field_event_start');
      if ($item->date) {
        $payload['event_date'] = $item->date->format('l, j F Y');
        $payload['event_time'] = $item->date->format('g:ia');
      }
    }
    if ($event->hasField('field_event_venue') && !$event->get('field_event_venue')->isEmpty()) {
      $payload['event_location'] = $event->get('field_event_venue')->value;
    }
    elseif ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $payload['event_location'] = $event->get('field_location')->value;
    }

    return $payload;
  }

  private function loadAttendeeEmails(NodeInterface $event): array {
    $emails = [];
    if (!\Drupal::hasService('myeventlane_attendee.repository_resolver')) {
      return $emails;
    }
    try {
      $resolver = \Drupal::service('myeventlane_attendee.repository_resolver');
      $repo = $resolver->getRepository($event);
      $attendees = $repo->loadByEvent($event);
      foreach ($attendees as $a) {
        $e = $a->getEmail();
        if (!empty($e) && filter_var($e, FILTER_VALIDATE_EMAIL)) {
          $emails[] = $e;
        }
      }
    }
    catch (\Throwable) {
      // Ignore.
    }
    return $emails;
  }

}
