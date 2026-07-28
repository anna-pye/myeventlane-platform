<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;

/**
 * Normalizes event_attendee rows for vendor list, order detail, and CSV export.
 *
 * Paid ticket holder paragraphs remain the checkout snapshot; vendor surfaces
 * use {@see \Drupal\myeventlane_event_attendees\Entity\EventAttendee} as canonical.
 */
final class VendorAttendeePresentationService implements VendorAttendeePresentationServiceInterface {

  public function __construct(
    private readonly TicketLabelResolver $ticketLabelResolver,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Maps extra_data (machine keys) to a stable list for Twig/CSV.
   *
   * @return list<array{key: string, label: string, value: string}>
   */
  public function normalizeCustomAnswers(EventAttendee $attendee): array {
    $map = $attendee->getExtraDataMap();
    if ($map === []) {
      return [];
    }
    $out = [];
    foreach ($map as $key => $item) {
      $keyStr = (string) $key;
      if (is_array($item) && isset($item['label'], $item['value'])) {
        $value = $this->normalizeCustomAnswerValue($item['value']);
        if ($value === NULL) {
          continue;
        }
        $label = $this->normalizeCustomAnswerValue($item['label']);
        $out[] = [
          'key' => $keyStr,
          'label' => $label !== NULL && $label !== '' ? $label : $keyStr,
          'value' => $value,
        ];
      }
      else {
        $value = $this->normalizeCustomAnswerValue($item);
        // Nested maps are operational metadata, not attendee-facing answers.
        if ($value === NULL) {
          continue;
        }
        $out[] = [
          'key' => $keyStr,
          'label' => $keyStr,
          'value' => $value,
        ];
      }
    }
    return $out;
  }

  /**
   * Converts one answer value without coercing nested metadata to a string.
   */
  private function normalizeCustomAnswerValue(mixed $value): ?string {
    if ($value === NULL) {
      return '';
    }
    if (is_scalar($value)) {
      return trim((string) $value);
    }
    if ($value instanceof \Stringable) {
      return trim((string) $value);
    }
    if (!is_array($value)) {
      return NULL;
    }
    if ($value === []) {
      return '';
    }

    $parts = [];
    foreach ($value as $part) {
      if (!is_scalar($part) && !$part instanceof \Stringable) {
        return NULL;
      }
      $text = trim((string) $part);
      if ($text !== '') {
        $parts[] = $text;
      }
    }
    return implode(', ', $parts);
  }

  /**
   * Single-line custom answers for CSV (semicolon-separated Label: value).
   */
  public function customAnswersListToCsvString(array $normalized): string {
    $parts = [];
    foreach ($normalized as $row) {
      $label = $row['label'] ?? '';
      $value = $row['value'] ?? '';
      if ($value === '') {
        continue;
      }
      $parts[] = ($label !== '' ? $label : ($row['key'] ?? '')) . ': ' . $value;
    }
    return implode('; ', array_filter($parts, static fn ($p) => $p !== ''));
  }

  /**
   * Ticket / variation label for ticket-source attendees; em dash otherwise.
   */
  public function resolveTicketTypeLabel(EventAttendee $attendee): string {
    if ($attendee->getSource() !== EventAttendee::SOURCE_TICKET) {
      return '—';
    }
    if (!$attendee->hasField('order_item') || $attendee->get('order_item')->isEmpty()) {
      return '—';
    }
    $orderItem = $attendee->get('order_item')->entity;
    if (!$orderItem instanceof OrderItemInterface) {
      return '—';
    }
    if ($orderItem->bundle() === 'boost') {
      return '—';
    }
    return $this->ticketLabelResolver->getTicketLabel($orderItem);
  }

  /**
   * Vendor row shape shared by attendees list, order detail, and CSV.
   *
   * @return array{
   *   full_name: string,
   *   email: string,
   *   phone: string,
   *   ticket_type: string,
   *   ticket_code: string,
   *   source: string,
   *   custom_answers: list<array{key: string, label: string, value: string}>,
   *   custom_answers_display: string
   * }
   */
  public function buildVendorRowFromEventAttendee(EventAttendee $attendee): array {
    $custom = $this->normalizeCustomAnswers($attendee);
    $display = $this->customAnswersListToCsvString($custom);

    $phone = '';
    if ($attendee->hasField('phone') && !$attendee->get('phone')->isEmpty()) {
      $phone = (string) $attendee->get('phone')->value;
    }

    return [
      'full_name' => $attendee->getName(),
      'email' => $attendee->getEmail(),
      'phone' => $phone,
      'ticket_type' => $this->resolveTicketTypeLabel($attendee),
      'ticket_code' => (string) ($attendee->getTicketCode() ?? ''),
      'source' => $attendee->getSource(),
      'custom_answers' => $custom,
      'custom_answers_display' => $display,
    ];
  }

  /**
   * Values for CSV export (aligned with vendor console attendees export).
   *
   * @return array<string, mixed>
   */
  public function buildCsvExportRow(EventAttendee $attendee): array {
    $vm = $this->buildVendorRowFromEventAttendee($attendee);
    $checkedInAt = '';
    if ($attendee->hasField('checked_in_at') && !$attendee->get('checked_in_at')->isEmpty()) {
      $ts = $attendee->get('checked_in_at')->value;
      if ($ts !== NULL && $ts !== '') {
        $checkedInAt = \DateTime::createFromFormat('U', (string) $ts)?->format('c') ?? '';
      }
    }

    return [
      'name' => $vm['full_name'],
      'email' => $vm['email'],
      'phone' => $vm['phone'],
      'source' => $vm['source'],
      'ticket_type' => $vm['ticket_type'],
      'ticket_code' => $vm['ticket_code'],
      'custom_answers' => $vm['custom_answers_display'],
      'checked_in' => $attendee->isCheckedIn(),
      'checked_in_at' => $checkedInAt,
    ];
  }

  /**
   * Batch log for vendor parity (list / order / CSV).
   *
   * @param string $normalizationSource
   *   Primary normalization source label, e.g. event_attendee or mixed snapshot.
   * @param int $canonicalRowCount
   *   Rows built from {@see EventAttendee} (vendor canonical).
   * @param int $paragraphSnapshotRowCount
   *   Rows built from checkout holder paragraphs (pre-sync fallback).
   */
  public function logVendorParityBatch(
    string $surface,
    int $eventNid,
    int $rowCount,
    int $customAnswerPairsTotal,
    string $normalizationSource = 'event_attendee',
    int $canonicalRowCount = 0,
    int $paragraphSnapshotRowCount = 0,
  ): void {
    if ($canonicalRowCount === 0 && $paragraphSnapshotRowCount === 0 && $rowCount > 0) {
      $canonicalRowCount = $rowCount;
    }
    $this->loggerFactory->get('myeventlane_event_attendees')->notice(
      'TEMP_DEBUG vendor_parity: surface=@surface event_id=@eid rows=@r custom_answer_pairs_total=@a normalization_source=@norm canonical_rows=@cr snapshot_rows=@sr',
      [
        '@surface' => $surface,
        '@eid' => (string) $eventNid,
        '@r' => (string) $rowCount,
        '@a' => (string) $customAnswerPairsTotal,
        '@norm' => $normalizationSource,
        '@cr' => (string) $canonicalRowCount,
        '@sr' => (string) $paragraphSnapshotRowCount,
        'surface' => $surface,
        'event_id' => $eventNid,
        'row_count' => $rowCount,
        'custom_answer_pairs_total' => $customAnswerPairsTotal,
        'normalization_source' => $normalizationSource,
        'canonical_row_count' => $canonicalRowCount,
        'paragraph_snapshot_row_count' => $paragraphSnapshotRowCount,
      ]
    );
  }

  /**
   * Logs how many event-level question templates exist (RSVP audit hook).
   */
  public function logRsvpEventQuestionTemplateCount(NodeInterface $event): void {
    if ($event->bundle() !== 'event') {
      return;
    }
    $count = 0;
    if ($event->hasField('field_attendee_questions') && !$event->get('field_attendee_questions')->isEmpty()) {
      $count = count($event->get('field_attendee_questions')->referencedEntities());
    }
    $this->loggerFactory->get('myeventlane_event_attendees')->notice(
      'TEMP_DEBUG rsvp_questions: event=@nid template_count=@c rendered_on=RsvpPublicForm_when_nonzero',
      [
        '@nid' => (string) $event->id(),
        '@c' => (string) $count,
        'event_id' => (int) $event->id(),
        'template_count' => $count,
      ]
    );
  }

  /**
   * Logs RSVP custom answers persisted onto event_attendee (tiered questions path).
   */
  public function logRsvpQuestionsSaved(int $eventNid, int $templateCount, int $answerPairsSaved): void {
    $this->loggerFactory->get('myeventlane_event_attendees')->notice(
      'TEMP_DEBUG rsvp_questions: event_id=@eid template_count=@t answer_pairs_saved=@a target=event_attendee.extra_data',
      [
        '@eid' => (string) $eventNid,
        '@t' => (string) $templateCount,
        '@a' => (string) $answerPairsSaved,
        'event_id' => $eventNid,
        'template_count' => $templateCount,
        'answer_pairs_saved' => $answerPairsSaved,
      ]
    );
  }

}
