<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Evaluates automation rules for events.
 *
 * Deterministic and explainable. No AI dependencies.
 * Returns structured candidates for dashboard surfacing, future email, or logs.
 */
final class AutomationRuleService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Evaluates automation rules for an event.
   *
   * @param array<string, mixed> $event_data
   *   Event context. Expected keys: id, is_published, is_ended, capacity,
   *   tickets_sold, rsvps, percent_sold, start_timestamp, end_timestamp,
   *   created_timestamp, changed_timestamp, is_boosted, boost_eligible,
   *   cta_route, cta_params, vendor_id, attendee_count, has_meaningful_setup.
   *
   * @return array<int, array<string, mixed>>
   *   Automation candidates. Each: type, key, severity, title, message,
   *   cta_route, cta_params, pro_feature, sendable, recipient_type.
   */
  public function evaluateEventAutomations(array $event_data): array {
    $config = $this->configFactory->get('myeventlane_automation.settings');
    if (!$config->get('enabled')) {
      return [];
    }

    $rules = $config->get('rules') ?? [];
    $thresholds = $config->get('thresholds') ?? [];
    $lowSalesPercent = (int) ($thresholds['low_sales_percent'] ?? 20);
    $nearlySoldOutPercent = (int) ($thresholds['nearly_sold_out_percent'] ?? 80);
    $reminderHours = (int) ($thresholds['reminder_hours_before_event'] ?? 24);
    $postEventHours = (int) ($thresholds['post_event_followup_hours'] ?? 24);
    $draftReminderDays = (int) ($thresholds['draft_reminder_days'] ?? 2);
    $noSalesMinHours = (int) ($thresholds['no_sales_min_hours_live'] ?? 48);
    $lowSalesMinHours = (int) ($thresholds['low_sales_min_hours_live'] ?? 72);

    $eventId = (int) ($event_data['id'] ?? 0);
    $isPublished = (bool) ($event_data['is_published'] ?? FALSE);
    $isEnded = (bool) ($event_data['is_ended'] ?? FALSE);
    $capacity = isset($event_data['capacity']) ? (int) $event_data['capacity'] : NULL;
    $ticketsSold = (int) ($event_data['tickets_sold'] ?? 0);
    $rsvps = (int) ($event_data['rsvps'] ?? 0);
    $percentSold = (float) ($event_data['percent_sold'] ?? 0);
    $startTs = (int) ($event_data['start_timestamp'] ?? 0);
    $endTs = (int) ($event_data['end_timestamp'] ?? 0);
    $createdTs = (int) ($event_data['created_timestamp'] ?? 0);
    $isBoosted = (bool) ($event_data['is_boosted'] ?? FALSE);
    $boostEligible = (bool) ($event_data['boost_eligible'] ?? FALSE);
    $attendeeCount = (int) ($event_data['attendee_count'] ?? $ticketsSold + $rsvps);
    $hasMeaningfulSetup = (bool) ($event_data['has_meaningful_setup'] ?? TRUE);
    $ctaParams = $event_data['cta_params'] ?? ['node' => $eventId];
    $now = time();
    $hoursSincePublish = $createdTs > 0 ? ($now - $createdTs) / 3600 : 0;
    $hoursSinceEnd = $endTs > 0 && $endTs < $now ? ($now - $endTs) / 3600 : 0;

    $candidates = [];

    // Rule A: Low sales boost nudge.
    if (!empty($rules['low_sales_boost']) && $isPublished && !$isEnded) {
      $capValid = $capacity === NULL || $capacity > 0;
      $pctLow = $percentSold < $lowSalesPercent;
      $hasSalesOrTime = $ticketsSold > 0 || $hoursSincePublish >= $lowSalesMinHours;
      if ($capValid && $pctLow && $hasSalesOrTime && (!$isBoosted || !$boostEligible)) {
        $candidates[] = [
          'type' => 'organiser_nudge',
          'key' => 'low_sales_boost',
          'severity' => 'warning',
          'title' => t('Ticket sales are slower than expected'),
          'message' => t('Consider boosting your event to reach more people.'),
          'cta_route' => 'myeventlane_boost.boost_page',
          'cta_params' => $ctaParams,
          'pro_feature' => 'boost_priority',
          'sendable' => FALSE,
          'recipient_type' => 'organiser',
        ];
      }
    }

    // Rule B: No sales yet.
    if (!empty($rules['no_sales_yet']) && $isPublished && !$isEnded && $ticketsSold === 0) {
      $upcomingWindow = $startTs > 0 && $startTs > $now;
      $minThreshold = $hoursSincePublish >= $noSalesMinHours;
      if ($upcomingWindow || $minThreshold) {
        $candidates[] = [
          'type' => 'organiser_nudge',
          'key' => 'no_sales_yet',
          'severity' => 'info',
          'title' => t('No tickets sold yet'),
          'message' => t('Share your event now to build momentum.'),
          'cta_route' => 'entity.node.canonical',
          'cta_params' => ['node' => $eventId],
          'pro_feature' => NULL,
          'sendable' => FALSE,
          'recipient_type' => 'organiser',
        ];
      }
    }

    // Rule C: Nearly sold out.
    if (!empty($rules['nearly_sold_out']) && $isPublished && !$isEnded && $percentSold >= $nearlySoldOutPercent) {
      $candidates[] = [
        'type' => 'organiser_nudge',
        'key' => 'nearly_sold_out',
        'severity' => 'success',
        'title' => t('Your event is nearly sold out'),
        'message' => t('You could add more tickets or promote the final spots.'),
        'cta_route' => 'myeventlane_event.wizard.edit',
        'cta_params' => ['node' => $eventId],
        'pro_feature' => NULL,
        'sendable' => FALSE,
        'recipient_type' => 'organiser',
      ];
    }

    // Rule D: Event ended rerun prompt.
    if (!empty($rules['event_ended_rerun']) && $isEnded && ($attendeeCount > 0 || $ticketsSold > 0)) {
      $candidates[] = [
        'type' => 'organiser_nudge',
        'key' => 'event_ended_rerun',
        'severity' => 'info',
        'title' => t('Your event has ended'),
        'message' => t('Run it again in a few clicks by duplicating the event.'),
        'cta_route' => 'myeventlane_event.duplicate',
        'cta_params' => ['node' => $eventId],
        'pro_feature' => NULL,
        'sendable' => FALSE,
        'recipient_type' => 'organiser',
      ];
    }

    // Rule E: Draft reminder.
    if (!empty($rules['draft_reminder']) && !$isPublished && $hasMeaningfulSetup && $createdTs > 0) {
      $daysSinceChange = ($now - $createdTs) / 86400;
      if ($daysSinceChange >= $draftReminderDays) {
        $candidates[] = [
          'type' => 'organiser_nudge',
          'key' => 'draft_reminder',
          'severity' => 'info',
          'title' => t('Your event draft is nearly ready'),
          'message' => t('Finish the details and publish when you\'re ready.'),
          'cta_route' => 'myeventlane_event.wizard.edit',
          'cta_params' => ['node' => $eventId],
          'pro_feature' => NULL,
          'sendable' => TRUE,
          'recipient_type' => 'organiser',
        ];
      }
    }

    // Rule F: Attendee reminder candidate.
    if (!empty($rules['attendee_reminder']) && $isPublished && !$isEnded && $attendeeCount > 0 && $startTs > 0) {
      $reminderWindowStart = $startTs - ($reminderHours * 3600);
      $reminderWindowEnd = $startTs - (($reminderHours - 2) * 3600);
      if ($now >= $reminderWindowStart && $now <= $reminderWindowEnd) {
        $candidates[] = [
          'type' => 'lifecycle_candidate',
          'key' => 'attendee_reminder',
          'severity' => 'info',
          'title' => t('Reminder: @title is coming up soon', ['@title' => $event_data['title'] ?? t('your event')]),
          'message' => t('Send a reminder to attendees before the event.'),
          'cta_route' => NULL,
          'cta_params' => [],
          'pro_feature' => 'audience_messaging',
          'sendable' => TRUE,
          'recipient_type' => 'attendee',
        ];
      }
    }

    // Rule G: Post-event follow-up candidate.
    if (!empty($rules['post_event_followup']) && $isEnded && $attendeeCount > 0) {
      if ($hoursSinceEnd >= 0 && $hoursSinceEnd <= ($postEventHours * 2)) {
        $candidates[] = [
          'type' => 'lifecycle_candidate',
          'key' => 'post_event_followup',
          'severity' => 'info',
          'title' => t('Thanks for hosting with MyEventLane'),
          'message' => t('View attendees, duplicate the event, or review performance.'),
          'cta_route' => 'myeventlane_vendor.console.event_attendees',
          'cta_params' => ['event' => $eventId],
          'pro_feature' => 'audience_messaging',
          'sendable' => TRUE,
          'recipient_type' => 'organiser',
        ];
      }
    }

    // Auto boost candidate (Pro only).
    if (!empty($rules['auto_boost_candidate']) && $isPublished && !$isEnded && $boostEligible && !$isBoosted) {
      $pctLow = $percentSold < $lowSalesPercent;
      $hasSalesOrTime = $ticketsSold > 0 || $hoursSincePublish >= $lowSalesMinHours;
      if ($pctLow && $hasSalesOrTime) {
        $candidates[] = [
          'type' => 'auto_boost_trigger',
          'key' => 'auto_boost_candidate',
          'severity' => 'warning',
          'title' => t('Boost suggestion ready'),
          'message' => t('Your event could benefit from a boost. Review and approve when ready.'),
          'cta_route' => 'myeventlane_boost.boost_page',
          'cta_params' => $ctaParams,
          'pro_feature' => 'boost_priority',
          'sendable' => FALSE,
          'recipient_type' => 'organiser',
          'eligibility' => TRUE,
        ];
      }
    }

    $this->logger->debug('Automation rules evaluated for event @id: @count candidates', [
      '@id' => $eventId,
      '@count' => count($candidates),
    ]);

    return $candidates;
  }

}
