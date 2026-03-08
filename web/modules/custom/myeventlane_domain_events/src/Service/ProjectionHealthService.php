<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;

/**
 * Computes projection pipeline health based on lag and queue backlog.
 */
final class ProjectionHealthService {

  private const PROJECTOR_QUEUE_NAME = 'myeventlane_domain_event_projector';

  public function __construct(
    private readonly Connection $database,
    private readonly QueueFactory $queueFactory,
    private readonly ProjectorRegistry $registry,
  ) {}

  /**
   * Returns projection pipeline health and observability metrics.
   *
   * @return array<string, int|string|null>
   *   Status payload with totals and timestamps.
   */
  public function getReport(): array {
    $totalEvents = $this->countEvents();
    $newestEvent = $this->loadNewestEvent();
    $lastProjectionTimestamp = $this->loadLastProjectionTimestamp();
    $queueBacklog = $this->queueFactory->get(self::PROJECTOR_QUEUE_NAME)->numberOfItems();
    $projectorTelemetry = $this->buildProjectorTelemetry();
    $checkpointSummary = $this->buildCheckpointSummary($projectorTelemetry, $newestEvent['id']);
    $lagEvents = max(0, (int) $newestEvent['id'] - (int) $checkpointSummary['min_checkpoint_event_id']);
    $lagSeconds = NULL;
    if ($newestEvent['occurred_at'] !== NULL && $checkpointSummary['min_checkpoint_occurred_at'] !== NULL) {
      $lagSeconds = max(0, $newestEvent['occurred_at'] - $checkpointSummary['min_checkpoint_occurred_at']);
    }
    elseif ((int) $newestEvent['id'] > 0 && (int) $checkpointSummary['min_checkpoint_event_id'] === 0) {
      $lagSeconds = NULL;
    }

    return [
      'status' => $this->determineStatus($totalEvents, $lagEvents, $queueBacklog),
      'total_events' => $totalEvents,
      'newest_event_id' => $newestEvent['id'],
      'last_event_timestamp' => $newestEvent['occurred_at'],
      'last_projection_timestamp' => $lastProjectionTimestamp,
      'queue_backlog' => $queueBacklog,
      'max_checkpoint_event_id' => $checkpointSummary['max_checkpoint_event_id'],
      'lag_events' => $lagEvents,
      'lag_seconds' => $lagSeconds,
      'projectors' => $projectorTelemetry,
      'replay_status_summary' => $this->buildReplayStatusSummary($totalEvents, $lagEvents, $queueBacklog),
    ];
  }

  /**
   * Alias for compatibility with external tooling.
   *
   * @return array<string, mixed>
   *   Health payload.
   */
  public function getHealth(): array {
    return $this->getReport();
  }

  private function countEvents(): int {
    $query = $this->database->select('myeventlane_domain_event', 'e');
    $query->addExpression('COUNT(e.id)', 'event_count');
    return (int) $query->execute()->fetchField();
  }

  /**
   * @return array{id:int, occurred_at:?int}
   *   Newest event metadata.
   */
  private function loadNewestEvent(): array {
    $query = $this->database->select('myeventlane_domain_event', 'e');
    $query->fields('e', ['id', 'occurred_at']);
    $query->orderBy('e.id', 'DESC');
    $query->range(0, 1);
    $row = $query->execute()->fetchAssoc();
    if (!is_array($row)) {
      return ['id' => 0, 'occurred_at' => NULL];
    }
    return [
      'id' => (int) $row['id'],
      'occurred_at' => isset($row['occurred_at']) ? (int) $row['occurred_at'] : NULL,
    ];
  }

  private function loadLastProjectionTimestamp(): ?int {
    $candidates = [
      $this->maxUpdatedAt('myeventlane_projection_event_metrics'),
      $this->maxUpdatedAt('myeventlane_projection_vendor_metrics'),
      $this->maxUpdatedAt('myeventlane_projection_finance'),
    ];

    $max = NULL;
    foreach ($candidates as $candidate) {
      if ($candidate === NULL) {
        continue;
      }
      $max = $max === NULL ? $candidate : max($max, $candidate);
    }

    return $max;
  }

  private function maxUpdatedAt(string $table): ?int {
    $query = $this->database->select($table, 'p');
    $query->addExpression('MAX(p.updated_at)', 'last_projection_timestamp');
    $value = $query->execute()->fetchField();
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * @return array<int, array<string, int|string|null>>
   *   Per-projector telemetry rows.
   */
  private function buildProjectorTelemetry(): array {
    $rows = [];
    foreach ($this->registry->getProjectors() as $projector) {
      $projectorId = $projector->id();
      $checkpoint = $this->database->select('myeventlane_domain_projection_checkpoint', 'c')
        ->fields('c', ['last_event_id', 'last_event_occurred_at'])
        ->condition('projector_id', $projectorId)
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      $lastEventId = is_array($checkpoint) ? (int) ($checkpoint['last_event_id'] ?? 0) : 0;
      $lastOccurredAt = is_array($checkpoint) && isset($checkpoint['last_event_occurred_at']) ? (int) $checkpoint['last_event_occurred_at'] : NULL;

      $query = $this->database->select('myeventlane_domain_event', 'e');
      $query->leftJoin('myeventlane_domain_projection_state', 's', 's.event_id = e.id AND s.projector_id = :projector_id', [
        ':projector_id' => $projectorId,
      ]);
      $or = $query->orConditionGroup()
        ->isNull('s.id')
        ->condition('s.status', 'processed', '<>');
      $query->condition($or);
      $query->condition('e.id', $lastEventId, '>');
      $query->addField('e', 'id', 'event_id');
      $query->orderBy('e.id', 'ASC');
      $query->range(0, 1);
      $oldestUnprocessed = $query->execute()->fetchField();

      $rows[] = [
        'projector_id' => $projectorId,
        'checkpoint_event_id' => $lastEventId,
        'checkpoint_occurred_at' => $lastOccurredAt,
        'oldest_unprocessed_event_id' => $oldestUnprocessed !== FALSE ? (int) $oldestUnprocessed : NULL,
      ];
    }

    return $rows;
  }

  /**
   * @param array<int, array<string, int|string|null>> $projectorTelemetry
   *   Per-projector telemetry rows.
   * @param int $newestEventId
   *   Newest event ID.
   *
   * @return array{min_checkpoint_event_id:int,max_checkpoint_event_id:int,min_checkpoint_occurred_at:?int}
   *   Checkpoint summary.
   */
  private function buildCheckpointSummary(array $projectorTelemetry, int $newestEventId): array {
    if ($projectorTelemetry === []) {
      return [
        'min_checkpoint_event_id' => 0,
        'max_checkpoint_event_id' => 0,
        'min_checkpoint_occurred_at' => NULL,
      ];
    }

    $minCheckpoint = $newestEventId > 0 ? $newestEventId : 0;
    $maxCheckpoint = 0;
    $minOccurredAt = NULL;
    foreach ($projectorTelemetry as $row) {
      $checkpointEventId = (int) ($row['checkpoint_event_id'] ?? 0);
      $checkpointOccurredAt = isset($row['checkpoint_occurred_at']) ? (int) $row['checkpoint_occurred_at'] : NULL;
      $minCheckpoint = min($minCheckpoint, $checkpointEventId);
      $maxCheckpoint = max($maxCheckpoint, $checkpointEventId);
      if ($checkpointOccurredAt !== NULL) {
        $minOccurredAt = $minOccurredAt === NULL ? $checkpointOccurredAt : min($minOccurredAt, $checkpointOccurredAt);
      }
    }

    return [
      'min_checkpoint_event_id' => $minCheckpoint,
      'max_checkpoint_event_id' => $maxCheckpoint,
      'min_checkpoint_occurred_at' => $minOccurredAt,
    ];
  }

  private function determineStatus(int $totalEvents, int $lagEvents, int $queueBacklog): string {
    if ($totalEvents === 0) {
      return 'healthy';
    }

    if ($lagEvents >= 100 && $queueBacklog === 0) {
      return 'stalled';
    }

    if ($lagEvents >= 100 || $queueBacklog > 0) {
      return 'delayed';
    }

    return 'healthy';
  }

  private function buildReplayStatusSummary(int $totalEvents, int $lagEvents, int $queueBacklog): string {
    if ($totalEvents === 0) {
      return 'No events in store.';
    }
    if ($lagEvents === 0 && $queueBacklog === 0) {
      return 'Replay caught up.';
    }
    if ($queueBacklog > 0) {
      return sprintf('Projector queue draining (%d pending).', $queueBacklog);
    }
    return sprintf('Replay lag detected (%d events behind).', $lagEvents);
  }

}
