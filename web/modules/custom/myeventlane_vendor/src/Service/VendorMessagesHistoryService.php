<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads organiser-facing message history from the event communications log.
 *
 * Never exposes mail/queue/plugin internals in labels.
 */
final class VendorMessagesHistoryService {

  use StringTranslationTrait;

  public function __construct(
    private readonly Connection $database,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Whether the communications log table is available.
   */
  public function isAvailable(): bool {
    return $this->moduleHandler->moduleExists('myeventlane_vendor_comms')
      && $this->database->schema()->tableExists('myeventlane_event_comms_log');
  }

  /**
   * Loads recent messages for a vendor across managed events.
   *
   * @return list<array<string, mixed>>
   *   Timeline rows.
   */
  public function loadForVendor(int $vendorUid, array $eventIds, int $limit = 12): array {
    if (!$this->isAvailable() || $vendorUid <= 0) {
      return [];
    }

    // Empty managed-event list must not mean "all vendor history".
    // Otherwise Overview can show zero events while History still lists past sends.
    if ($eventIds === []) {
      return [];
    }

    try {
      $query = $this->database->select('myeventlane_event_comms_log', 'log')
        ->fields('log', [
          'id',
          'event_id',
          'message_type',
          'subject',
          'recipient_count',
          'sent_count',
          'failed_count',
          'status',
          'sent_at',
        ])
        ->condition('vendor_uid', $vendorUid)
        ->condition('event_id', $eventIds, 'IN')
        ->orderBy('sent_at', 'DESC')
        ->range(0, $limit);

      $rows = $query->execute()->fetchAll();
    }
    catch (\Throwable $e) {
      $this->logger->error('Messages history load failed for vendor @uid: @m', [
        '@uid' => (string) $vendorUid,
        '@m' => $e->getMessage(),
      ]);
      return [];
    }

    return $this->mapRows($rows);
  }

  /**
   * Loads recent messages for one event.
   *
   * @return list<array<string, mixed>>
   *   Timeline rows.
   */
  public function loadForEvent(int $eventId, int $limit = 20): array {
    if (!$this->isAvailable() || $eventId <= 0) {
      return [];
    }

    try {
      $rows = $this->database->select('myeventlane_event_comms_log', 'log')
        ->fields('log', [
          'id',
          'event_id',
          'message_type',
          'subject',
          'recipient_count',
          'sent_count',
          'failed_count',
          'status',
          'sent_at',
        ])
        ->condition('event_id', $eventId)
        ->orderBy('sent_at', 'DESC')
        ->range(0, $limit)
        ->execute()
        ->fetchAll();
    }
    catch (\Throwable $e) {
      $this->logger->error('Messages history load failed for event @id: @m', [
        '@id' => (string) $eventId,
        '@m' => $e->getMessage(),
      ]);
      return [];
    }

    return $this->mapRows($rows);
  }

  /**
   * Summarises status counts for an event or vendor-scoped set.
   *
   * @return array{sending: int, sent: int, failed: int, total: int}
   *   Organiser-facing counts.
   */
  public function summariseStatuses(array $rows): array {
    $sending = 0;
    $sent = 0;
    $failed = 0;
    foreach ($rows as $row) {
      $key = (string) ($row['status_key'] ?? '');
      $failedCount = (int) ($row['failed_count'] ?? 0);
      $sentCount = (int) ($row['sent_count'] ?? 0);
      if ($key === 'sending' || $key === 'pending') {
        $sending++;
      }
      elseif ($key === 'failed' || $failedCount > 0 || ($key === 'completed' && $sentCount === 0)) {
        // Worker may store completed with failures, or completed with zero deliveries.
        $failed++;
      }
      elseif ($key === 'completed' && $sentCount > 0) {
        // Only completed jobs that actually delivered count as Sent.
        $sent++;
      }
    }
    return [
      'sending' => $sending,
      'sent' => $sent,
      'failed' => $failed,
      'total' => count($rows),
    ];
  }

  /**
   * Maps database rows into organiser-facing timeline items.
   *
   * @param list<object> $rows
   *   Database rows.
   *
   * @return list<array<string, mixed>>
   *   Mapped timeline items.
   */
  private function mapRows(array $rows): array {
    $eventIds = [];
    foreach ($rows as $row) {
      $eventIds[(int) $row->event_id] = (int) $row->event_id;
    }
    $titles = $this->loadEventTitles(array_values($eventIds));

    $out = [];
    foreach ($rows as $row) {
      $statusKey = (string) ($row->status ?? 'pending');
      $typeKey = (string) ($row->message_type ?? 'update');
      $sentAt = (int) ($row->sent_at ?? 0);
      $recipientCount = (int) ($row->recipient_count ?? 0);
      $sentCount = (int) ($row->sent_count ?? 0);
      $failedCount = (int) ($row->failed_count ?? 0);
      $eventId = (int) $row->event_id;

      // Normalise zero-delivery "completed" and total queue failure for timeline + KPIs.
      if ($statusKey === 'completed' && $sentCount === 0) {
        $statusKey = 'failed';
      }

      $out[] = [
        'id' => (int) $row->id,
        'event_id' => $eventId,
        'event_title' => $titles[$eventId] ?? (string) $this->t('Event'),
        'subject' => (string) $row->subject,
        'type_key' => $typeKey,
        'type_label' => $this->typeLabel($typeKey),
        'audience_label' => (string) $this->t('Event guests'),
        'status_key' => $statusKey,
        'status_label' => $this->statusLabel($statusKey, $failedCount),
        'failed_count' => $failedCount,
        'sent_count' => $sentCount,
        'sent_label' => $sentAt > 0
          ? $this->dateFormatter->format($sentAt, 'custom', 'j M Y · g:ia')
          : '—',
        'delivery_summary' => $this->deliverySummary($statusKey, $sentCount, $failedCount, $recipientCount),
      ];
    }
    return $out;
  }

  /**
   * Loads event titles for history rows.
   *
   * @param list<int> $eventIds
   *   Event node IDs.
   *
   * @return array<int, string>
   *   Titles keyed by nid.
   */
  private function loadEventTitles(array $eventIds): array {
    if ($eventIds === []) {
      return [];
    }
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($eventIds);
    $titles = [];
    foreach ($nodes as $nid => $node) {
      if ($node instanceof NodeInterface) {
        $titles[(int) $nid] = (string) $node->label();
      }
    }
    return $titles;
  }

  /**
   * Returns organiser-facing message type label.
   */
  private function typeLabel(string $type): string {
    return match ($type) {
      'announcement', 'update' => (string) $this->t('Announcement'),
      'reminder' => (string) $this->t('Reminder'),
      'important_update', 'important_change' => (string) $this->t('Important update'),
      'cancellation' => (string) $this->t('Cancellation'),
      'thank_you' => (string) $this->t('Thank you'),
      default => (string) $this->t('Update'),
    };
  }

  /**
   * Returns organiser-facing status label.
   */
  private function statusLabel(string $status, int $failedCount = 0): string {
    if ($status === 'completed' && $failedCount > 0) {
      return (string) $this->t('Needs attention');
    }
    return match ($status) {
      'pending', 'sending' => (string) $this->t('Sending'),
      'completed' => (string) $this->t('Sent'),
      'failed' => (string) $this->t('Failed'),
      'cancelled', 'canceled' => (string) $this->t('Cancelled'),
      'draft' => (string) $this->t('Draft'),
      default => (string) $this->t('Sent'),
    };
  }

  /**
   * Builds a short delivery summary without mail-system jargon.
   */
  private function deliverySummary(string $status, int $sent, int $failed, int $recipients): string {
    if ($status === 'pending' || $status === 'sending') {
      return (string) $this->t('Sending to @count guest(s)…', ['@count' => $recipients]);
    }
    if ($sent === 0 && $failed === 0) {
      return (string) $this->t('No guests received this message.');
    }
    if ($status === 'failed' && $sent === 0) {
      return (string) $this->t('Could not send. Try again from Compose.');
    }
    if ($failed > 0) {
      return (string) $this->t('@sent of @total delivered · @failed need attention', [
        '@sent' => $sent,
        '@total' => $recipients,
        '@failed' => $failed,
      ]);
    }
    return (string) $this->t('@sent of @total delivered', [
      '@sent' => $sent,
      '@total' => $recipients,
    ]);
  }

}
