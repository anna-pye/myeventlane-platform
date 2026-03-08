<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Service;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds projection tables from stored domain events.
 */
final class ProjectionReplayService {

  private const PROJECTION_TABLES = [
    'myeventlane_projection_event_metrics',
    'myeventlane_projection_vendor_metrics',
    'myeventlane_projection_finance',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly DomainEventRepository $repository,
    private readonly ProjectorRegistry $registry,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Replays all domain events in chunks.
   *
   * @param bool|callable $dryRun
   *   TRUE to scan only. Also supports legacy callable-first usage.
   * @param int $chunkSize
   *   Number of events to process per chunk.
   * @param callable(int): void|null $progress
   *   Optional callback invoked with the number of scanned/processed events.
   *
   * @return int
   *   Number of events scanned/processed.
   */
  public function replayAll(bool|callable $dryRun = FALSE, int $chunkSize = 500, ?callable $progress = NULL): int {
    if (is_callable($dryRun) && $progress === NULL) {
      $progress = $dryRun;
      $dryRun = FALSE;
    }

    $safeChunkSize = max(1, min(5000, $chunkSize));
    if (!$dryRun) {
      $this->wipeProjectionTables();
      $this->resetProjectionStateAndCheckpoints();
    }

    $afterEventId = 0;
    $processed = 0;
    while (TRUE) {
      $events = $this->repository->fetchEventsAfterId($afterEventId, $safeChunkSize);
      if ($events === []) {
        break;
      }

      foreach ($events as $event) {
        $eventId = (int) $event['id'];
        if (!$dryRun) {
          $projectors = $this->registry->getProjectorsForEventType((string) $event['event_type']);
          foreach ($projectors as $projector) {
            try {
              $projector->project($event);
              $this->repository->saveProjectionState(
                $projector->id(),
                $eventId,
                'processed',
                time(),
                0,
                NULL,
              );
              $this->repository->saveProjectionCheckpoint(
                $projector->id(),
                $eventId,
                isset($event['occurred_at']) ? (int) $event['occurred_at'] : NULL,
              );
            }
            catch (\Throwable $e) {
              $this->repository->saveProjectionState(
                $projector->id(),
                $eventId,
                'failed',
                NULL,
                1,
                $e->getMessage(),
              );
              $this->logger->error('Projection replay failed for projector {projector} on event {event_id}: {message}', [
                'projector' => $projector->id(),
                'event_id' => $eventId,
                'message' => $e->getMessage(),
              ]);
              throw $e;
            }
          }
        }

        $processed++;
        $afterEventId = $eventId;
      }

      if ($progress !== NULL) {
        $progress($processed);
      }
    }

    return $processed;
  }

  /**
   * Truncates all projection tables before replay.
   */
  public function wipeProjectionTables(): void {
    foreach (self::PROJECTION_TABLES as $table) {
      $this->database->truncate($table)->execute();
    }
  }

  /**
   * Resets state and checkpoint tracking before replay.
   */
  private function resetProjectionStateAndCheckpoints(): void {
    $this->database->truncate('myeventlane_domain_projection_state')->execute();
    $this->database->truncate('myeventlane_domain_projection_checkpoint')->execute();
  }

}
