<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_checkout_flow\MelAttendeeAttendanceState;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\myeventlane_event_attendees\Service\VendorAttendeePresentationServiceInterface;
use Drupal\node\NodeInterface;

/**
 * Canonical operational view-model builder for vendor attendee surfaces.
 *
 * Single source of truth for attendee row presentation outside of CSV exports.
 * Composes:
 * - {@see VendorAttendeePresentationService::buildVendorRowFromEventAttendee()}
 *   for the canonical row payload (already used by vendor list and order detail).
 * - {@see AttendanceManagerInterface::getAttendeesForEvent()} as the loader.
 * - {@see MelAttendeeAttendanceState} for operational state mapping.
 *
 * Twig consumes the returned payloads directly and never re-derives state,
 * badges, or grouping. Empty states are surfaced through governed slots
 * supplied by the caller (typically MelReadinessHelper).
 */
final class MelAttendeeOperationsPresenter {

  use StringTranslationTrait;

  public function __construct(
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly VendorAttendeePresentationServiceInterface $vendorPresentation,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly LoggerChannelInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the operational view-model for a single event.
   *
   * Shape:
   * - event: { id, title, url }
   * - rows: list<row>
   * - groups: { tickets: list<group>, rsvp: row[], manual: row[] }
   * - filters: { available_states: list<{value, label}>, available_sources: list<{value, label}> }
   * - readiness: { total, active, checked_in, refunded, cancelled, pending, invalid }
   * - cache: { contexts: list<string>, tags: list<string> }
   *
   * Each row is shaped by {@see ::buildRow()}.
   *
   * @param array{
   *   state?: string|null,
   *   source?: string|null,
   *   search?: string|null
   * } $filters
   *
   * @return array<string, mixed>
   */
  public function buildEventViewModel(NodeInterface $event, array $filters = []): array {
    $eventId = (int) $event->id();
    $entities = $this->attendanceManager->getAttendeesForEvent($eventId);

    $rows = [];
    $stateTotals = $this->emptyStateTotals();
    $sources = [];

    foreach ($entities as $entity) {
      if (!$entity instanceof EventAttendee) {
        continue;
      }
      $row = $this->buildRow($entity);
      $rows[] = $row;
      $stateTotals[$row['state']['value']] = ($stateTotals[$row['state']['value']] ?? 0) + 1;
      $sources[$row['source']['value']] = $row['source']['label'];
    }

    $filteredRows = $this->applyFilters($rows, $filters);
    $groups = $this->groupRows($filteredRows);

    $stateOptions = [];
    foreach (MelAttendeeAttendanceState::all() as $state) {
      $stateOptions[] = [
        'value' => $state->value,
        'label' => (string) $this->t('@label', ['@label' => $state->label()]),
        'count' => $stateTotals[$state->value] ?? 0,
      ];
    }

    $sourceOptions = [];
    foreach ($sources as $value => $label) {
      $sourceOptions[] = ['value' => $value, 'label' => $label];
    }

    $totals = [
      'total' => count($rows),
      'after_filters' => count($filteredRows),
      'active' => count(array_filter($rows, static fn (array $r): bool => $r['state']['counts_as_active'])),
      'checked_in' => $stateTotals[MelAttendeeAttendanceState::CheckedIn->value] ?? 0,
      'cancelled' => $stateTotals[MelAttendeeAttendanceState::Cancelled->value] ?? 0,
      'refunded' => $stateTotals[MelAttendeeAttendanceState::Refunded->value] ?? 0,
      'pending' => $stateTotals[MelAttendeeAttendanceState::Pending->value] ?? 0,
      'invalid' => $stateTotals[MelAttendeeAttendanceState::Invalid->value] ?? 0,
    ];

    return [
      'event' => [
        'id' => $eventId,
        'title' => (string) $event->label(),
        'url' => $event->toUrl()->toString(),
      ],
      'rows' => $filteredRows,
      'groups' => $groups,
      'filters' => [
        'available_states' => $stateOptions,
        'available_sources' => $sourceOptions,
        'applied' => [
          'state' => isset($filters['state']) ? (string) $filters['state'] : '',
          'source' => isset($filters['source']) ? (string) $filters['source'] : '',
          'search' => isset($filters['search']) ? trim((string) $filters['search']) : '',
        ],
      ],
      'readiness' => $totals,
      'cache' => [
        'contexts' => ['user', 'url.query_args:state', 'url.query_args:source', 'url.query_args:search'],
        'tags' => ['event_attendee_list:' . $eventId, 'node:' . $eventId],
      ],
    ];
  }

  /**
   * Builds the canonical row payload for a single attendee.
   *
   * Shape:
   * - id, name, email, phone
   * - source: { value, label }
   * - ticket: { type, code, variation_id }
   * - state: { value, label, helper, modifier, counts_as_active, is_settled, can_check_in }
   * - custom_answers: list<{key, label, value}>
   * - timestamps: { registered_at, checked_in_at, registered_at_iso }
   * - operational_badges: list<{ id, label, modifier }>
   *
   * @return array<string, mixed>
   */
  public function buildRow(EventAttendee $attendee): array {
    $vendorRow = $this->vendorPresentation->buildVendorRowFromEventAttendee($attendee);

    $orderItem = NULL;
    $variationId = '';
    if ($attendee->hasField('order_item') && !$attendee->get('order_item')->isEmpty()) {
      $candidate = $attendee->get('order_item')->entity;
      if ($candidate instanceof OrderItemInterface) {
        $orderItem = $candidate;
        try {
          $purchased = $orderItem->getPurchasedEntity();
          if ($purchased) {
            $variationId = (string) $purchased->id();
          }
        }
        catch (\Throwable $e) {
          $this->logger->warning('Operations presenter: cannot resolve purchased entity for attendee @id: @msg.', [
            '@id' => (string) $attendee->id(),
            '@msg' => $e->getMessage(),
          ]);
        }
      }
    }

    $state = MelAttendeeAttendanceState::fromEventAttendee($attendee, $orderItem);

    $registeredAt = '';
    $registeredAtIso = '';
    try {
      $createdTs = $attendee->getCreatedTime();
      if ($createdTs > 0) {
        $registeredAt = $this->dateFormatter->format($createdTs, 'short');
        $registeredAtIso = (new \DateTime('@' . $createdTs))->format('c');
      }
    }
    catch (\Throwable) {
      // Already logged once per row in buildRow callers if needed; keep silent here.
    }

    $checkedInAt = '';
    if ($attendee->hasField('checked_in_at') && !$attendee->get('checked_in_at')->isEmpty()) {
      $ts = (int) $attendee->get('checked_in_at')->value;
      if ($ts > 0) {
        $checkedInAt = $this->dateFormatter->format($ts, 'short');
      }
    }

    $badges = [];
    if ($state === MelAttendeeAttendanceState::CheckedIn) {
      $badges[] = [
        'id' => 'checked_in',
        'label' => (string) $this->t('Checked in'),
        'modifier' => 'success',
      ];
    }
    if ($state === MelAttendeeAttendanceState::Refunded) {
      $badges[] = [
        'id' => 'refunded',
        'label' => (string) $this->t('Refunded'),
        'modifier' => 'warning',
      ];
    }
    if ($state === MelAttendeeAttendanceState::Pending) {
      $badges[] = [
        'id' => 'pending',
        'label' => (string) $this->t('Pending'),
        'modifier' => 'info',
      ];
    }
    if ($state === MelAttendeeAttendanceState::Invalid) {
      $badges[] = [
        'id' => 'invalid',
        'label' => (string) $this->t('Needs attention'),
        'modifier' => 'danger',
      ];
    }

    return [
      'id' => (int) $attendee->id(),
      'name' => $vendorRow['full_name'],
      'email' => $vendorRow['email'],
      'phone' => $vendorRow['phone'],
      'source' => [
        'value' => $vendorRow['source'],
        'label' => $this->labelForSource($vendorRow['source']),
      ],
      'ticket' => [
        'type' => $vendorRow['ticket_type'],
        'code' => $vendorRow['ticket_code'],
        'variation_id' => $variationId,
      ],
      'state' => [
        'value' => $state->value,
        'label' => (string) $this->t('@label', ['@label' => $state->label()]),
        'helper' => (string) $this->t('@helper', ['@helper' => $state->helperText()]),
        'modifier' => $state->modifier(),
        'counts_as_active' => $state->countsAsActive(),
        'is_settled' => $state->isSettled(),
        'can_check_in' => $state->isCheckInEligible(),
      ],
      'custom_answers' => $vendorRow['custom_answers'],
      'timestamps' => [
        'registered_at' => $registeredAt,
        'registered_at_iso' => $registeredAtIso,
        'checked_in_at' => $checkedInAt,
      ],
      'operational_badges' => $badges,
    ];
  }

  /**
   * Groups rows by source (RSVP/ticket/manual) and ticket variation.
   *
   * @param list<array<string, mixed>> $rows
   *
   * @return array{
   *   tickets: list<array{title: string, variation_id: string, count: int, attendees: list<array<string, mixed>>}>,
   *   rsvp: list<array<string, mixed>>,
   *   manual: list<array<string, mixed>>
   * }
   */
  private function groupRows(array $rows): array {
    $tickets = [];
    $rsvp = [];
    $manual = [];
    foreach ($rows as $row) {
      $source = $row['source']['value'] ?? '';
      if ($source === EventAttendee::SOURCE_RSVP) {
        $rsvp[] = $row;
        continue;
      }
      if ($source === EventAttendee::SOURCE_MANUAL) {
        $manual[] = $row;
        continue;
      }
      if ($source === EventAttendee::SOURCE_TICKET) {
        $variationId = (string) ($row['ticket']['variation_id'] ?? '');
        $key = $variationId !== '' ? $variationId : 'ticket';
        if (!isset($tickets[$key])) {
          $tickets[$key] = [
            'title' => (string) ($row['ticket']['type'] ?? '') ?: (string) $this->t('Ticket'),
            'variation_id' => $variationId,
            'count' => 0,
            'attendees' => [],
          ];
        }
        $tickets[$key]['attendees'][] = $row;
        $tickets[$key]['count']++;
      }
    }
    return [
      'tickets' => array_values($tickets),
      'rsvp' => $rsvp,
      'manual' => $manual,
    ];
  }

  /**
   * Filters operational rows by state, source, and free-text search.
   *
   * @param list<array<string, mixed>> $rows
   * @param array{state?: string|null, source?: string|null, search?: string|null} $filters
   *
   * @return list<array<string, mixed>>
   */
  private function applyFilters(array $rows, array $filters): array {
    $state = isset($filters['state']) ? (string) $filters['state'] : '';
    $source = isset($filters['source']) ? (string) $filters['source'] : '';
    $search = isset($filters['search']) ? mb_strtolower(trim((string) $filters['search'])) : '';

    if ($state === '' && $source === '' && $search === '') {
      return array_values($rows);
    }

    $filtered = [];
    foreach ($rows as $row) {
      if ($state !== '' && (string) ($row['state']['value'] ?? '') !== $state) {
        continue;
      }
      if ($source !== '' && (string) ($row['source']['value'] ?? '') !== $source) {
        continue;
      }
      if ($search !== '') {
        $haystack = mb_strtolower(($row['name'] ?? '') . ' ' . ($row['email'] ?? '') . ' ' . ($row['ticket']['code'] ?? ''));
        if (!str_contains($haystack, $search)) {
          continue;
        }
      }
      $filtered[] = $row;
    }
    return array_values($filtered);
  }

  private function labelForSource(string $source): string {
    return match ($source) {
      EventAttendee::SOURCE_RSVP => (string) $this->t('RSVP'),
      EventAttendee::SOURCE_TICKET => (string) $this->t('Ticket'),
      EventAttendee::SOURCE_MANUAL => (string) $this->t('Manual'),
      default => $source,
    };
  }

  /**
   * @return array<string, int>
   */
  private function emptyStateTotals(): array {
    $totals = [];
    foreach (MelAttendeeAttendanceState::all() as $state) {
      $totals[$state->value] = 0;
    }
    return $totals;
  }

}
