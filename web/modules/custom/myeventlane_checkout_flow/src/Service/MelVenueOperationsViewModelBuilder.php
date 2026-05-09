<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\node\NodeInterface;

/**
 * View-model for the vendor venue operations surface (single operational route).
 *
 * Composes readiness from {@see MelAttendeeCheckinManager::readinessForEvent()}
 * and row metadata from {@see MelAttendeeOperationsPresenter::buildEventViewModel()}
 * without introducing parallel counters or direct SQL.
 */
final class MelVenueOperationsViewModelBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly MelAttendeeCheckinManager $checkinManager,
    private readonly MelAttendeeOperationsPresenter $operationsPresenter,
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly DateFormatterInterface $dateFormatter,
    TranslationInterface $string_translation,
    private readonly LoggerChannelInterface $logger,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return array<string, mixed>
   */
  public function build(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $readiness = $this->checkinManager->readinessForEvent($event);
    $availability = $this->attendanceManager->getAvailability($event);
    $vm = $this->operationsPresenter->buildEventViewModel($event, []);

    $capacity = $availability['capacity'] ?? 0;
    $remaining = $availability['remaining'];
    $totalAttendees = (int) ($readiness['total'] ?? 0);
    $checkedIn = (int) ($readiness['checked_in'] ?? 0);
    $notCheckedIn = max(0, $totalAttendees - $checkedIn);

    $recent = $this->buildRecentActivity($eventId);

    $hero = [
      'title' => $event->label(),
      'start' => $this->formatEventStart($event),
      'venue' => $this->formatVenueLine($event),
      'attendance_state' => (string) $this->t('@checked of @total checked in', [
        '@checked' => (string) $checkedIn,
        '@total' => (string) $totalAttendees,
      ]),
      'readiness' => (string) $this->t('@ready ready to check in, @blocked not eligible', [
        '@ready' => (string) ($readiness['ready'] ?? 0),
        '@blocked' => (string) ($readiness['blocked'] ?? 0),
      ]),
      'readiness_labels' => $this->readinessHelper->vendorReadinessPresentationLabels(),
    ];

    $metrics = [
      'checked_in' => $checkedIn,
      'not_checked_in' => $notCheckedIn,
      'total_attendees' => $totalAttendees,
      'remaining_capacity' => $remaining,
      'capacity' => $capacity > 0 ? $capacity : NULL,
    ];

    $links = [
      'attendees_list' => Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $eventId])->toString(),
      'export' => Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $eventId])->toString(),
      'door_checkin' => $this->safeRouteUrl('myeventlane_event.checkin_door', ['event' => $eventId]),
      'legacy_checkin' => $this->safeRouteUrl('myeventlane_checkin.page', ['node' => $eventId]),
    ];

    $search = [
      'placeholder' => (string) $this->t('Search by name or email'),
      'form_action' => Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $eventId])->toString(),
    ];

    return [
      'event' => $event,
      'hero' => $hero,
      'metrics' => $metrics,
      'attendee_rows' => $vm['rows'] ?? [],
      'readiness_breakdown' => $readiness,
      'recent_activity' => $recent,
      'links' => $links,
      'search' => $search,
      'operational_actions' => $this->buildOperationalActions($eventId),
      '#cache' => [
        'tags' => array_values(array_unique(array_merge(
          ['node:' . $eventId, 'event_attendee_list:' . $eventId],
          $vm['cache']['tags'] ?? []
        ))),
        'contexts' => array_values(array_unique(array_merge(['user'], $vm['cache']['contexts'] ?? []))),
      ],
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildRecentActivity(int $eventId): array {
    $candidates = [];
    foreach ($this->attendanceManager->getAttendeesForEvent($eventId) as $entity) {
      if (!$entity instanceof EventAttendee || !$entity->isCheckedIn()) {
        continue;
      }
      if (!$entity->hasField('checked_in_at') || $entity->get('checked_in_at')->isEmpty()) {
        continue;
      }
      $ts = (int) $entity->get('checked_in_at')->value;
      if ($ts <= 0) {
        continue;
      }
      $candidates[] = [
        'name' => $entity->getName(),
        'checked_in_at' => $ts,
      ];
    }
    usort($candidates, static fn(array $a, array $b): int => (int) ($b['checked_in_at'] ?? 0) <=> (int) ($a['checked_in_at'] ?? 0));
    $out = [];
    foreach (array_slice($candidates, 0, 12) as $item) {
      $item['checked_in_at_formatted'] = $this->dateFormatter->format((int) $item['checked_in_at'], 'short');
      $out[] = $item;
    }
    return $out;
  }

  /**
   * @return list<array<string, string>>
   */
  private function buildOperationalActions(int $eventId): array {
    $actions = [];
    $door = $this->safeRouteUrl('myeventlane_event.checkin_door', ['event' => $eventId]);
    if ($door !== '') {
      $actions[] = [
        'label' => (string) $this->t('Door check-in'),
        'url' => $door,
        'style' => 'primary',
      ];
    }
    $legacy = $this->safeRouteUrl('myeventlane_checkin.page', ['node' => $eventId]);
    if ($legacy !== '') {
      $actions[] = [
        'label' => (string) $this->t('Classic check-in list'),
        'url' => $legacy,
        'style' => 'secondary',
      ];
    }
    $actions[] = [
      'label' => (string) $this->t('Attendee spreadsheet'),
      'url' => Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $eventId])->toString(),
      'style' => 'secondary',
    ];
    return $actions;
  }

  private function formatEventStart(NodeInterface $event): string {
    if (!$event->hasField('field_event_start') || $event->get('field_event_start')->isEmpty()) {
      return '';
    }
    $date = $event->get('field_event_start')->date;
    if (!$date) {
      return '';
    }
    return $this->dateFormatter->format($date->getTimestamp(), 'medium');
  }

  private function formatVenueLine(NodeInterface $event): string {
    if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      return (string) $event->get('field_venue_name')->value;
    }
    return '';
  }

  /**
   * @param array<string, mixed> $params
   */
  private function safeRouteUrl(string $route, array $params): string {
    try {
      return Url::fromRoute($route, $params)->toString();
    }
    catch (\Throwable $e) {
      $this->logger->notice('Venue operations: route @route unavailable (@msg).', [
        '@route' => $route,
        '@msg' => $e->getMessage(),
      ]);
      return '';
    }
  }

}
