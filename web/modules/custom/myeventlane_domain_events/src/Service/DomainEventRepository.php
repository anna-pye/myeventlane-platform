<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Persists and retrieves domain events and projection state.
 */
final class DomainEventRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Inserts a domain event and returns the event row ID.
   *
   * @param array<string, mixed> $context
   *   Optional context fields:
   *   - vendor_id
   *   - event_node_id
   *   - order_id
   *   - user_id
   *   - source_module
   *   - source_idempotency_key
   *   - occurred_at
   */
  public function insertEvent(
    string $eventUuid,
    string $eventType,
    string $aggregateType,
    string $aggregateId,
    array $payload,
    array $context = [],
  ): int {
    $sourceIdempotencyKey = isset($context['source_idempotency_key']) ? (string) $context['source_idempotency_key'] : NULL;
    $sourceModule = (string) ($context['source_module'] ?? 'myeventlane_domain_events');

    if ($sourceIdempotencyKey !== NULL && $sourceIdempotencyKey !== '') {
      $existingId = $this->database->select('myeventlane_domain_event', 'e')
        ->fields('e', ['id'])
        ->condition('source_module', $sourceModule)
        ->condition('source_idempotency_key', $sourceIdempotencyKey)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($existingId !== FALSE) {
        return (int) $existingId;
      }
    }

    try {
      $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->logger->error('Failed to encode domain event payload for {event_type}: {message}', [
        'event_type' => $eventType,
        'message' => $e->getMessage(),
      ]);
      throw $e;
    }

    $now = $this->time->getCurrentTime();
    $occurredAt = isset($context['occurred_at']) ? (int) $context['occurred_at'] : $now;

    return (int) $this->database->insert('myeventlane_domain_event')
      ->fields([
        'event_uuid' => $eventUuid,
        'event_type' => $eventType,
        'aggregate_type' => $aggregateType,
        'aggregate_id' => $aggregateId,
        'vendor_id' => isset($context['vendor_id']) ? (int) $context['vendor_id'] : NULL,
        'event_node_id' => isset($context['event_node_id']) ? (int) $context['event_node_id'] : NULL,
        'order_id' => isset($context['order_id']) ? (int) $context['order_id'] : NULL,
        'user_id' => isset($context['user_id']) ? (int) $context['user_id'] : NULL,
        'payload_json' => $payloadJson,
        'source_module' => $sourceModule,
        'source_idempotency_key' => $sourceIdempotencyKey,
        'occurred_at' => $occurredAt,
        'created_at' => $now,
      ])
      ->execute();
  }

  /**
   * Loads one domain event row by ID.
   *
   * @return array<string, mixed>|null
   *   Normalized event row or NULL.
   */
  public function fetchEvent(int $eventId): ?array {
    $row = $this->database->select('myeventlane_domain_event', 'e')
      ->fields('e')
      ->condition('id', $eventId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $row ? $this->normalizeEventRow($row) : NULL;
  }

  /**
   * Loads events after a specific ID in ascending order.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized event rows.
   */
  public function fetchEventsAfterId(int $afterEventId, int $limit = 500): array {
    $safeLimit = max(1, min(5000, $limit));
    $query = $this->database->select('myeventlane_domain_event', 'e');
    $query->fields('e');
    $query->condition('e.id', $afterEventId, '>');
    $query->orderBy('e.id', 'ASC');
    $query->range(0, $safeLimit);
    $rows = $query->execute()->fetchAllAssoc('id');

    $events = [];
    foreach ($rows as $row) {
      $events[] = $this->normalizeEventRow((array) $row);
    }
    return $events;
  }

  /**
   * Loads events that are unprocessed for the given projector.
   *
   * @return array<int, array<string, mixed>>
   *   Event rows in ascending ID order.
   */
  public function fetchUnprocessedEvents(string $projectorId, int $limit = 100): array {
    $query = $this->database->select('myeventlane_domain_event', 'e');
    $query->leftJoin('myeventlane_domain_projection_state', 's', 's.event_id = e.id AND s.projector_id = :projector_id', [
      ':projector_id' => $projectorId,
    ]);
    $query->fields('e');
    $query->isNull('s.id');
    $query->orderBy('e.id', 'ASC');
    $query->range(0, $limit);
    $rows = $query->execute()->fetchAllAssoc('id');

    $events = [];
    foreach ($rows as $row) {
      $events[] = $this->normalizeEventRow((array) $row);
    }
    return $events;
  }

  /**
   * Returns existing projection state row for an event/projector pair.
   *
   * @return array<string, mixed>|null
   *   Projection state row or NULL.
   */
  public function fetchProjectionState(string $projectorId, int $eventId): ?array {
    $row = $this->database->select('myeventlane_domain_projection_state', 's')
      ->fields('s')
      ->condition('projector_id', $projectorId)
      ->condition('event_id', $eventId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Upserts projection state for an event/projector.
   */
  public function saveProjectionState(
    string $projectorId,
    int $eventId,
    string $status,
    ?int $processedAt = NULL,
    int $retryCount = 0,
    ?string $lastError = NULL,
  ): void {
    $existing = $this->fetchProjectionState($projectorId, $eventId);
    $fields = [
      'status' => $status,
      'processed_at' => $processedAt,
      'retry_count' => $retryCount,
      'last_error' => $lastError,
    ];

    if ($existing === NULL) {
      $this->database->insert('myeventlane_domain_projection_state')
        ->fields($fields + [
          'projector_id' => $projectorId,
          'event_id' => $eventId,
        ])
        ->execute();
      return;
    }

    $this->database->update('myeventlane_domain_projection_state')
      ->fields($fields)
      ->condition('projector_id', $projectorId)
      ->condition('event_id', $eventId)
      ->execute();
  }

  /**
   * Loads projection checkpoint for one projector.
   *
   * @return array<string, mixed>|null
   *   Checkpoint row or NULL.
   */
  public function getProjectionCheckpoint(string $projectorId): ?array {
    $row = $this->database->select('myeventlane_domain_projection_checkpoint', 'c')
      ->fields('c')
      ->condition('projector_id', $projectorId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if (!is_array($row)) {
      return NULL;
    }

    return [
      'projector_id' => (string) $row['projector_id'],
      'last_event_id' => (int) $row['last_event_id'],
      'last_event_occurred_at' => isset($row['last_event_occurred_at']) ? (int) $row['last_event_occurred_at'] : NULL,
      'updated_at' => (int) $row['updated_at'],
      'replay_lock' => $row['replay_lock'] !== NULL ? (string) $row['replay_lock'] : NULL,
    ];
  }

  /**
   * Upserts projection checkpoint for one projector.
   */
  public function saveProjectionCheckpoint(string $projectorId, int $lastEventId, ?int $lastOccurredAt): void {
    $existing = $this->getProjectionCheckpoint($projectorId);
    $fields = [
      'last_event_id' => max(0, $lastEventId),
      'last_event_occurred_at' => $lastOccurredAt,
      'updated_at' => $this->time->getCurrentTime(),
    ];

    if ($existing === NULL) {
      $this->database->insert('myeventlane_domain_projection_checkpoint')
        ->fields($fields + [
          'projector_id' => $projectorId,
          'replay_lock' => NULL,
        ])
        ->execute();
      return;
    }

    $this->database->update('myeventlane_domain_projection_checkpoint')
      ->fields($fields)
      ->condition('projector_id', $projectorId)
      ->execute();
  }

  /**
   * Normalizes DB row into typed event payload.
   *
   * @param array<string, mixed> $row
   *   Raw row.
   *
   * @return array<string, mixed>
   *   Normalized event.
   */
  private function normalizeEventRow(array $row): array {
    $payload = [];
    if (isset($row['payload_json']) && is_string($row['payload_json']) && $row['payload_json'] !== '') {
      try {
        $decoded = json_decode($row['payload_json'], TRUE, 512, JSON_THROW_ON_ERROR);
        $payload = is_array($decoded) ? $decoded : [];
      }
      catch (\JsonException $e) {
        $this->logger->error('Invalid payload_json for event {event_id}: {message}', [
          'event_id' => (int) ($row['id'] ?? 0),
          'message' => $e->getMessage(),
        ]);
      }
    }

    $row['id'] = (int) $row['id'];
    $row['occurred_at'] = (int) $row['occurred_at'];
    $row['created_at'] = (int) $row['created_at'];
    $row['vendor_id'] = isset($row['vendor_id']) ? (int) $row['vendor_id'] : NULL;
    $row['event_node_id'] = isset($row['event_node_id']) ? (int) $row['event_node_id'] : NULL;
    $row['order_id'] = isset($row['order_id']) ? (int) $row['order_id'] : NULL;
    $row['user_id'] = isset($row['user_id']) ? (int) $row['user_id'] : NULL;
    $row['payload'] = $payload;

    return $row;
  }

}

