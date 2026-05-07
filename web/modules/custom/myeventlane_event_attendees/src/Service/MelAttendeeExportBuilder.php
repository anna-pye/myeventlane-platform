<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;

/**
 * Canonical CSV row builder for attendee operational exports.
 *
 * Wraps {@see VendorAttendeePresentationService} and the
 * {@see AttendanceManagerInterface} so every attendee CSV controller (vendor
 * attendee export, RSVP export, paragraph export, views export) shares the
 * same headers, ordering, escaping, and formula-injection neutralisation.
 * Existing controllers retain their URLs and access checks and delegate row
 * generation here.
 *
 * Lives in myeventlane_event_attendees so that checkout_flow,
 * checkout_paragraph, rsvp, and views can all depend on it without
 * introducing circular dependencies.
 *
 * State derivation mirrors
 * {@see \Drupal\myeventlane_checkout_flow\MelAttendeeAttendanceState}; the
 * vocabulary (registered/checked_in/cancelled/refunded/pending/invalid) is
 * defined once on each side and asserted equivalent in the unit tests.
 */
final class MelAttendeeExportBuilder {

  use StringTranslationTrait;

  /**
   * UTF-8 byte order mark, prepended to streams for Excel compatibility.
   */
  public const BOM = "\xEF\xBB\xBF";

  /**
   * Operational state vocabulary (mirrors MelAttendeeAttendanceState).
   */
  public const STATE_REGISTERED = 'registered';
  public const STATE_CHECKED_IN = 'checked_in';
  public const STATE_CANCELLED = 'cancelled';
  public const STATE_REFUNDED = 'refunded';
  public const STATE_PENDING = 'pending';
  public const STATE_INVALID = 'invalid';

  /**
   * Characters unsafe at the start of a CSV cell (formula triggers).
   */
  private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

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
   * Canonical header row.
   *
   * @return list<string>
   */
  public function getHeaders(): array {
    return [
      (string) $this->t('Name'),
      (string) $this->t('Email'),
      (string) $this->t('Phone'),
      (string) $this->t('Source'),
      (string) $this->t('Ticket type'),
      (string) $this->t('Ticket code'),
      (string) $this->t('Custom answers'),
      (string) $this->t('Operational state'),
      (string) $this->t('Checked in'),
      (string) $this->t('Checked in at'),
      (string) $this->t('Registered'),
    ];
  }

  /**
   * Builds a per-row export array for a single EventAttendee.
   *
   * The row is already escaped for spreadsheet formula injection; controllers
   * may pass it directly to fputcsv().
   *
   * @return list<string>
   */
  public function buildRow(EventAttendee $attendee, bool $obfuscateEmail = FALSE): array {
    $base = $this->vendorPresentation->buildCsvExportRow($attendee);

    $email = (string) ($base['email'] ?? '');
    if ($obfuscateEmail && $email !== '') {
      $email = $this->obfuscateEmail($email);
    }

    $orderItem = NULL;
    if ($attendee->hasField('order_item') && !$attendee->get('order_item')->isEmpty()) {
      $candidate = $attendee->get('order_item')->entity;
      $orderItem = $candidate instanceof OrderItemInterface ? $candidate : NULL;
    }
    $stateCode = $this->resolveOperationalState($attendee, $orderItem);
    $stateLabel = $this->labelForState($stateCode);

    $registeredAt = '';
    try {
      $createdTs = $attendee->getCreatedTime();
      if ($createdTs > 0) {
        $registeredAt = $this->dateFormatter->format($createdTs, 'custom', 'Y-m-d H:i:s');
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Attendee export: cannot format created timestamp for attendee @id: @msg.', [
        '@id' => (string) $attendee->id(),
        '@msg' => $e->getMessage(),
      ]);
    }

    $checkedInAtRaw = (string) ($base['checked_in_at'] ?? '');
    $checkedInAtFormatted = '';
    if ($checkedInAtRaw !== '') {
      try {
        $dt = new \DateTime($checkedInAtRaw);
        $checkedInAtFormatted = $dt->format('Y-m-d H:i:s');
      }
      catch (\Throwable) {
        $checkedInAtFormatted = '';
      }
    }

    $row = [
      (string) ($base['name'] ?? ''),
      $email,
      (string) ($base['phone'] ?? ''),
      $this->labelForSource((string) ($base['source'] ?? '')),
      (string) ($base['ticket_type'] ?? ''),
      (string) ($base['ticket_code'] ?? ''),
      (string) ($base['custom_answers'] ?? ''),
      $stateLabel,
      ($base['checked_in'] ?? FALSE) ? (string) $this->t('Yes') : (string) $this->t('No'),
      $checkedInAtFormatted,
      $registeredAt,
    ];

    return $this->sanitizeRow($row);
  }

  /**
   * Builds rows for every attendee on an event in canonical order.
   *
   * @return list<list<string>>
   */
  public function buildRowsForEvent(NodeInterface $event, bool $obfuscateEmail = FALSE, ?string $sourceFilter = NULL): array {
    $rows = [];
    $entities = $this->attendanceManager->getAttendeesForEvent((int) $event->id(), NULL, $sourceFilter);
    foreach ($entities as $entity) {
      if (!$entity instanceof EventAttendee) {
        continue;
      }
      $rows[] = $this->buildRow($entity, $obfuscateEmail);
    }
    return $rows;
  }

  /**
   * Builds the full CSV body (BOM + header + rows) as a single string.
   *
   * @param list<list<string>> $rows
   *   Pre-built rows from {@see ::buildRowsForEvent()} or {@see ::buildRow()}.
   */
  public function renderCsvString(array $rows): string {
    $handle = fopen('php://temp', 'r+');
    if (!$handle) {
      $this->logger->error('Attendee export: unable to open temp stream for CSV rendering.');
      return self::BOM . $this->headersAsLine();
    }
    fwrite($handle, self::BOM);
    fputcsv($handle, $this->getHeaders(), ',', '"', '\\');
    foreach ($rows as $row) {
      fputcsv($handle, $row, ',', '"', '\\');
    }
    rewind($handle);
    $contents = stream_get_contents($handle) ?: '';
    fclose($handle);
    return $contents;
  }

  /**
   * Streams BOM + header + rows directly to the open file handle.
   *
   * Convenience for controllers that already use StreamedResponse + fputcsv.
   *
   * @param resource $handle
   * @param list<list<string>> $rows
   */
  public function streamCsv($handle, array $rows): void {
    if (!is_resource($handle)) {
      $this->logger->error('Attendee export: streamCsv called with non-resource handle.');
      return;
    }
    fwrite($handle, self::BOM);
    fputcsv($handle, $this->getHeaders(), ',', '"', '\\');
    foreach ($rows as $row) {
      fputcsv($handle, $row, ',', '"', '\\');
    }
  }

  /**
   * Suggests a safe filename for downloaded exports.
   */
  public function buildFilename(NodeInterface $event, string $variant = 'attendees'): string {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $event->label())) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
      $slug = 'event-' . $event->id();
    }
    $variantSlug = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($variant)) ?? 'attendees';
    return sprintf('%s-%s-%s.csv', $variantSlug, $slug, date('Y-m-d'));
  }

  /**
   * Neutralises spreadsheet formulas at the start of any cell.
   *
   * @param list<string> $row
   *
   * @return list<string>
   */
  public function sanitizeRow(array $row): array {
    $out = [];
    foreach ($row as $cell) {
      $out[] = $this->sanitizeCell((string) $cell);
    }
    return $out;
  }

  /**
   * Cell-level sanitisation. Public for re-use by adjacent exporters.
   */
  public function sanitizeCell(string $cell): string {
    if ($cell === '') {
      return '';
    }
    if (str_starts_with($cell, self::BOM)) {
      $cell = substr($cell, strlen(self::BOM));
      if ($cell === '') {
        return '';
      }
    }
    $first = $cell[0];
    foreach (self::FORMULA_PREFIXES as $prefix) {
      if ($first === $prefix) {
        return "'" . $cell;
      }
    }
    return $cell;
  }

  /**
   * Translatable label for an EventAttendee source enum value.
   */
  public function labelForSource(string $source): string {
    return match ($source) {
      EventAttendee::SOURCE_RSVP => (string) $this->t('RSVP'),
      EventAttendee::SOURCE_TICKET => (string) $this->t('Ticket purchase'),
      EventAttendee::SOURCE_MANUAL => (string) $this->t('Manual entry'),
      default => $source,
    };
  }

  /**
   * Translatable label for an operational state code.
   *
   * Mirror of {@see MelAttendeeAttendanceState::label()} for cross-module use.
   */
  public function labelForState(string $stateCode): string {
    return match ($stateCode) {
      self::STATE_REGISTERED => (string) $this->t('Registered'),
      self::STATE_CHECKED_IN => (string) $this->t('Checked in'),
      self::STATE_CANCELLED => (string) $this->t('Cancelled'),
      self::STATE_REFUNDED => (string) $this->t('Refunded'),
      self::STATE_PENDING => (string) $this->t('Pending'),
      self::STATE_INVALID => (string) $this->t('Needs attention'),
      default => $stateCode,
    };
  }

  /**
   * Resolves operational state for an attendee + optional order context.
   *
   * Behaviour matches MelAttendeeAttendanceState::fromEventAttendee().
   * Kept private so the export builder remains self-contained, then asserted
   * equivalent in the unit tests.
   */
  public function resolveOperationalState(EventAttendee $attendee, ?OrderItemInterface $orderItem = NULL): string {
    $status = $attendee->getStatus();

    if ($status === EventAttendee::STATUS_CHECKED_IN) {
      return self::STATE_CHECKED_IN;
    }
    if ($status === EventAttendee::STATUS_CANCELLED) {
      return self::STATE_CANCELLED;
    }

    if ($orderItem instanceof OrderItemInterface) {
      $orderState = $this->resolveOrderStateId($orderItem);
      if (in_array($orderState, ['refunded', 'partially_refunded'], TRUE)) {
        return self::STATE_REFUNDED;
      }
      if ($orderState === 'canceled') {
        return self::STATE_CANCELLED;
      }
      if ($orderState === '') {
        return self::STATE_INVALID;
      }
      if (in_array($orderState, ['draft', 'validation', 'pending'], TRUE)) {
        return self::STATE_PENDING;
      }
    }

    if ($status === EventAttendee::STATUS_WAITLIST) {
      return self::STATE_PENDING;
    }
    if ($status === EventAttendee::STATUS_NO_SHOW) {
      return self::STATE_CANCELLED;
    }
    if ($status === EventAttendee::STATUS_CONFIRMED) {
      return self::STATE_REGISTERED;
    }

    return self::STATE_INVALID;
  }

  private function obfuscateEmail(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
      return $email;
    }
    $prefix = substr($parts[0], 0, 2);
    return $prefix . '***@' . $parts[1];
  }

  private function headersAsLine(): string {
    $headers = $this->getHeaders();
    return implode(
      ',',
      array_map(static fn (string $h): string => '"' . str_replace('"', '""', $h) . '"', $headers)
    ) . "\n";
  }

  private function resolveOrderStateId(OrderItemInterface $orderItem): string {
    try {
      $order = $orderItem->getOrder();
    }
    catch (\Throwable) {
      return '';
    }
    if ($order === NULL) {
      return '';
    }
    try {
      return (string) $order->getState()->getId();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
