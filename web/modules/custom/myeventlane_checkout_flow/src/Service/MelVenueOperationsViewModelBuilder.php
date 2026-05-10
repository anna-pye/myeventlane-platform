<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\myeventlane_event_attendees\Service\VendorAttendeePresentationServiceInterface;
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

  /**
   * Per-purpose CSRF token id for the vendor manual check-in endpoint.
   *
   * Mirrors the {@see VendorAttendeeController::checkIn()} validator so the
   * AJAX header (`X-CSRF-Token`) and the no-JS form `_token` field share a
   * single id. Distinct from `mel_door_checkin` to keep the two surfaces
   * independently revocable.
   */
  public const CHECKIN_CSRF_ID = 'mel_vendor_attendee_checkin';

  public function __construct(
    private readonly MelAttendeeCheckinManager $checkinManager,
    private readonly MelAttendeeOperationsPresenter $operationsPresenter,
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly VendorAttendeePresentationServiceInterface $vendorPresentation,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly DateFormatterInterface $dateFormatter,
    TranslationInterface $string_translation,
    private readonly LoggerChannelInterface $logger,
    private readonly TimeInterface $time,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Lightweight door header stats (scanner shell) — read-only readiness slice.
   *
   * @return array<string, mixed>
   */
  public function buildDoorHeaderContext(NodeInterface $event): array {
    $readiness = $this->checkinManager->readinessForEvent($event);
    $syncTs = $this->time->getRequestTime();
    $total = (int) ($readiness['total'] ?? 0);
    $checkedIn = (int) ($readiness['checked_in'] ?? 0);
    $rate = $total > 0 ? round(100.0 * $checkedIn / $total, 1) : 0.0;

    return [
      'checked_in' => $checkedIn,
      'total' => $total,
      'remaining' => max(0, $total - $checkedIn),
      'rate_percent' => $rate,
      'live_indicator' => (string) $this->t('Live'),
      'count_line' => (string) $this->t('@checked in · @total expected', [
        '@checked' => (string) $checkedIn,
        '@total' => (string) $total,
      ]),
      'last_sync_formatted' => $this->dateFormatter->format($syncTs, 'short'),
      'last_sync_iso' => (new \DateTimeImmutable('@' . $syncTs))->format('c'),
    ];
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
    $ratePercent = $totalAttendees > 0 ? round(100.0 * $checkedIn / $totalAttendees, 1) : 0.0;
    $syncTs = $this->time->getRequestTime();

    $rows = $vm['rows'] ?? [];
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
      'live_indicator' => (string) $this->t('Live operations'),
      'last_sync_formatted' => $this->dateFormatter->format($syncTs, 'short'),
      'last_sync_iso' => (new \DateTimeImmutable('@' . $syncTs))->format('c'),
      'check_in_rate_percent' => $ratePercent,
      'status_chips' => $this->buildHeroStatusChips($event, $rows, $capacity, $remaining),
    ];

    $metrics = [
      'checked_in' => $checkedIn,
      'not_checked_in' => $notCheckedIn,
      'total_attendees' => $totalAttendees,
      'remaining_capacity' => $remaining,
      'capacity' => $capacity > 0 ? $capacity : NULL,
      'check_in_rate_percent' => $ratePercent,
      'ready_to_check_in' => (int) ($readiness['ready'] ?? 0),
      'not_eligible' => (int) ($readiness['blocked'] ?? 0),
    ];

    $links = [
      'attendees_list' => Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $eventId])->toString(),
      'export' => Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $eventId])->toString(),
      'operations' => $this->safeRouteUrl('myeventlane_event_attendees.vendor_operations', ['node' => $eventId]),
      'door_checkin' => $this->safeRouteUrl('myeventlane_event_attendees.vendor_operations_door', ['node' => $eventId]),
      'legacy_checkin' => $this->safeRouteUrl('myeventlane_checkin.page', ['node' => $eventId]),
    ];

    $search = [
      'placeholder' => (string) $this->t('Search attendee, email, or ticket code'),
      'form_action' => Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $eventId])->toString(),
    ];

    return [
      'event' => $event,
      'hero' => $hero,
      'metrics' => $metrics,
      'attendee_rows' => $rows,
      'readiness_breakdown' => $readiness,
      'recent_activity' => $recent,
      'links' => $links,
      'search' => $search,
      'empty_guidance' => [
        'no_rows' => $this->readinessHelper->vendorAttendeeOperationsNoAttendeesYetSlots()['why_empty'],
        'checkin' => $this->readinessHelper->vendorAttendeeCheckinGuidanceLine(),
      ],
      'operational_actions' => $this->buildOperationalActions($eventId),
      // Per-purpose CSRF token consumed by the manual check-in form (hidden
      // `_token` field) and the AJAX behaviour (`X-CSRF-Token` header).
      // Validated server-side by VendorAttendeeController::checkIn() against
      // self::CHECKIN_CSRF_ID. Cache contexts include `session` so the token
      // is not shared across logged-in vendors and is regenerated when the
      // session rotates.
      'csrf' => [
        'checkin_token' => (string) $this->csrfToken->get(self::CHECKIN_CSRF_ID),
        'checkin_token_id' => self::CHECKIN_CSRF_ID,
      ],
      '#cache' => [
        'tags' => array_values(array_unique(array_merge(
          ['node:' . $eventId, 'event_attendee_list:' . $eventId],
          $vm['cache']['tags'] ?? []
        ))),
        'contexts' => array_values(array_unique(array_merge(
          ['user', 'session'],
          $vm['cache']['contexts'] ?? []
        ))),
      ],
    ];
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return list<array<string, string>>
   */
  private function buildHeroStatusChips(NodeInterface $event, array $rows, int $capacityNumeric, mixed $remaining): array {
    $chips = [];
    $tally = $this->tallySourceKinds($rows);

    $chips[] = ['label' => (string) $this->t('Live'), 'modifier' => 'live'];

    if ($event->isPublished()) {
      $chips[] = ['label' => (string) $this->t('Published'), 'modifier' => 'calm'];
    }
    else {
      $chips[] = ['label' => (string) $this->t('Unpublished'), 'modifier' => 'warn'];
    }

    $mode = $this->labelAttendeeMode($tally);
    if ($mode !== '') {
      $chips[] = ['label' => $mode, 'modifier' => 'lavender'];
    }

    $capChip = $this->capacityChipLabel($capacityNumeric, $remaining);
    if ($capChip !== '') {
      $chips[] = ['label' => $capChip, 'modifier' => 'capacity'];
    }

    return $chips;
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return array{ticket:int, rsvp:int, manual:int}
   */
  private function tallySourceKinds(array $rows): array {
    $out = ['ticket' => 0, 'rsvp' => 0, 'manual' => 0];
    foreach ($rows as $row) {
      $sv = (string) ($row['source']['value'] ?? '');
      match ($sv) {
        EventAttendee::SOURCE_TICKET => $out['ticket']++,
        EventAttendee::SOURCE_RSVP => $out['rsvp']++,
        EventAttendee::SOURCE_MANUAL => $out['manual']++,
        default => NULL,
      };
    }
    return $out;
  }

  /**
   * @param array{ticket:int, rsvp:int, manual:int} $tally
   */
  private function labelAttendeeMode(array $tally): string {
    $ticket = $tally['ticket'] > 0;
    $rsvp = $tally['rsvp'] > 0;
    if ($ticket && !$rsvp) {
      return (string) $this->t('Ticketed');
    }
    if (!$ticket && $rsvp) {
      return (string) $this->t('RSVP');
    }
    if ($ticket && $rsvp) {
      return (string) $this->t('Ticketed & RSVP');
    }
    if ($tally['manual'] > 0) {
      return (string) $this->t('Manual roster');
    }
    return '';
  }

  private function capacityChipLabel(int $capacityNumeric, mixed $remaining): string {
    if ($capacityNumeric <= 0 && $remaining === NULL) {
      return (string) $this->t('Capacity not set');
    }
    if ($remaining === NULL) {
      return (string) $this->t('Capacity open');
    }
    $rem = max(0, (int) $remaining);
    if ($capacityNumeric > 0 && $rem === 0) {
      return (string) $this->t('At capacity');
    }
    if ($capacityNumeric > 0) {
      $ratio = $rem / max(1, $capacityNumeric);
      if ($ratio <= 0.1) {
        return (string) $this->t('Nearly full');
      }
    }
    return (string) $this->t('@spots spots left', ['@spots' => (string) $rem]);
  }

  /**
   * @return list<array<string, string>>
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
      $presentation = [];
      try {
        $presentation = $this->vendorPresentation->buildVendorRowFromEventAttendee($entity);
      }
      catch (\Throwable $e) {
        $this->logger->notice('Venue ops recent activity: presentation failed (@msg).', [
          '@msg' => $e->getMessage(),
        ]);
      }

      $candidates[] = [
        'name' => $entity->getName(),
        'ticket_type' => (string) ($presentation['ticket_type'] ?? ''),
        'checked_in_at' => $ts,
        'accessibility_labels' => $this->extractAccessibilityLabels($entity),
      ];
    }
    usort($candidates, static fn(array $a, array $b): int => (int) ($b['checked_in_at'] ?? 0) <=> (int) ($a['checked_in_at'] ?? 0));
    $out = [];
    foreach (array_slice($candidates, 0, 12) as $item) {
      $ts = (int) $item['checked_in_at'];
      $item['checked_in_at_formatted'] = $this->dateFormatter->format($ts, 'short');
      $item['checked_in_at_iso'] = (new \DateTimeImmutable('@' . $ts))->format('c');
      $item['initials'] = $this->initialsFromName((string) ($item['name'] ?? ''));
      $out[] = $item;
    }
    return $out;
  }

  /**
   * @return list<string>
   */
  private function extractAccessibilityLabels(EventAttendee $attendee): array {
    if (!$attendee->hasField('extra_data') || $attendee->get('extra_data')->isEmpty()) {
      return [];
    }
    $raw = $attendee->get('extra_data')->value;
    if (!is_string($raw) || $raw === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    $labels = [];
    foreach (['accessibility', 'accessibility_needs', 'mobility', 'dietary'] as $key) {
      if (!empty($decoded[$key]) && is_string($decoded[$key])) {
        $labels[] = (string) $decoded[$key];
      }
    }
    return $labels;
  }

  private function initialsFromName(string $name): string {
    $name = trim($name);
    if ($name === '') {
      return '?';
    }
    $parts = preg_split('/\s+/u', $name) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
      $part = (string) $part;
      if ($part === '') {
        continue;
      }
      $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials !== '' ? $initials : strtoupper(substr($name, 0, 1));
  }

  /**
   * @return list<array<string, string>>
   */
  private function buildOperationalActions(int $eventId): array {
    $actions = [];
    $door = $this->safeRouteUrl('myeventlane_event_attendees.vendor_operations_door', ['node' => $eventId]);
    if ($door !== '') {
      $actions[] = [
        'label' => (string) $this->t('Start scanning'),
        'url' => $door,
        'variant' => 'primary',
        'icon' => 'scan',
      ];
    }
    $actions[] = [
      'label' => (string) $this->t('View attendee list'),
      'url' => Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $eventId])->toString(),
      'variant' => 'lavender',
      'icon' => 'list',
    ];
    $actions[] = [
      'label' => (string) $this->t('Export CSV'),
      'url' => Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $eventId])->toString(),
      'variant' => 'lavender',
      'icon' => 'export',
    ];
    try {
      $manualUrl = Url::fromRoute(
        'myeventlane_event_attendees.vendor_operations',
        ['node' => $eventId],
        ['fragment' => 'mel-ops-attendee-search'],
      )->toString();
    }
    catch (\Throwable $e) {
      $manualUrl = $this->safeRouteUrl('myeventlane_event_attendees.vendor_operations', ['node' => $eventId]);
    }
    if ($manualUrl !== '') {
      $actions[] = [
        'label' => (string) $this->t('Manual lookup'),
        'url' => $manualUrl,
        'variant' => 'lavender',
        'icon' => 'search',
      ];
    }

    $settings = $this->safeRouteUrl('myeventlane_vendor.console.event_settings', ['event' => $eventId]);
    if ($settings !== '') {
      $actions[] = [
        'label' => (string) $this->t('Door settings'),
        'url' => $settings,
        'variant' => 'muted',
        'icon' => 'settings',
      ];
    }

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
