<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores lightweight public interaction analytics internally.
 */
final class AnalyticsService {

  public const EVENT_VENDOR_PAGE_VIEW = 'vendor_page_view';
  public const EVENT_FOLLOW_CLICK = 'follow_click';
  public const EVENT_EVENT_CLICK = 'event_click';
  public const EVENT_EVENT_VIEW = 'event_view';

  private const TABLE = 'myeventlane_public_analytics_event';

  /**
   * Constructs an AnalyticsService object.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly DiscoveryAttributionSources $discoveryAttributionSources,
  ) {}

  /**
   * Counts stored analytics events for an entity.
   *
   * @param int|null $start_ts
   *   Optional inclusive start timestamp filter.
   * @param int|null $end_ts
   *   Optional inclusive end timestamp filter.
   */
  public function countEvents(string $entity_type, int $entity_id, string $event_type, ?int $start_ts = NULL, ?int $end_ts = NULL): int {
    if ($entity_type === '' || $entity_id <= 0 || $event_type === '') {
      return 0;
    }

    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return 0;
    }

    try {
      $query = $this->database->select(self::TABLE, 'a');
      $query->addExpression('COUNT(*)', 'cnt');
      $query->condition('entity_type', $entity_type);
      $query->condition('entity_id', $entity_id);
      $query->condition('event_type', $event_type);
      if ($start_ts !== NULL) {
        $query->condition('timestamp', $start_ts, '>=');
      }
      if ($end_ts !== NULL) {
        $query->condition('timestamp', $end_ts, '<=');
      }
      return (int) $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to count analytics events for @type:@id (@event): @message', [
        '@type' => $entity_type,
        '@id' => (string) $entity_id,
        '@event' => $event_type,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Counts analytics events for many entities in one query.
   *
   * @param list<int> $entity_ids
   *   Entity IDs to count (invalid IDs are ignored).
   *
   * @return array<int, int>
   *   Map of entity_id => count (missing IDs are omitted).
   */
  public function countEventsByEntityIds(
    string $entity_type,
    array $entity_ids,
    string $event_type,
    ?int $start_ts = NULL,
    ?int $end_ts = NULL,
  ): array {
    if ($entity_type === '' || $event_type === '') {
      return [];
    }

    $entity_ids = array_values(array_unique(array_filter(
      array_map(static fn(mixed $id): int => (int) $id, $entity_ids),
      static fn(int $id): bool => $id > 0,
    )));
    if ($entity_ids === []) {
      return [];
    }

    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return [];
    }

    try {
      $query = $this->database->select(self::TABLE, 'a');
      $query->addField('a', 'entity_id', 'entity_id');
      $query->addExpression('COUNT(*)', 'cnt');
      $query->condition('entity_type', $entity_type);
      $query->condition('entity_id', $entity_ids, 'IN');
      $query->condition('event_type', $event_type);
      if ($start_ts !== NULL) {
        $query->condition('timestamp', $start_ts, '>=');
      }
      if ($end_ts !== NULL) {
        $query->condition('timestamp', $end_ts, '<=');
      }
      $query->groupBy('entity_id');

      $counts = [];
      foreach ($query->execute() as $row) {
        $counts[(int) $row->entity_id] = (int) $row->cnt;
      }
      return $counts;
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to batch-count analytics events (@event): @message', [
        '@event' => $event_type,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Counts event_click rows grouped by discovery attribution source.
   *
   * @return array<string, int>
   *   Map of source => count (empty source rows are omitted).
   */
  public function countEventClicksGroupedBySource(
    string $entity_type,
    int $entity_id,
    ?int $start_ts = NULL,
    ?int $end_ts = NULL,
  ): array {
    if ($entity_type === '' || $entity_id <= 0) {
      return [];
    }

    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return [];
    }

    $schema = $this->database->schema();
    if (!$schema->fieldExists(self::TABLE, 'source')) {
      return [];
    }

    try {
      $query = $this->database->select(self::TABLE, 'a');
      $query->addField('a', 'source', 'source');
      $query->addExpression('COUNT(*)', 'cnt');
      $query->condition('entity_type', $entity_type);
      $query->condition('entity_id', $entity_id);
      $query->condition('event_type', self::EVENT_EVENT_CLICK);
      $query->isNotNull('source');
      $query->condition('source', '', '<>');
      if ($start_ts !== NULL) {
        $query->condition('timestamp', $start_ts, '>=');
      }
      if ($end_ts !== NULL) {
        $query->condition('timestamp', $end_ts, '<=');
      }
      $query->groupBy('source');

      $counts = [];
      foreach ($query->execute() as $row) {
        $source = (string) ($row->source ?? '');
        if ($source === '' || !$this->discoveryAttributionSources->isAllowed($source)) {
          continue;
        }
        $counts[$source] = (int) ($row->cnt ?? 0);
      }
      return $counts;
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to group analytics clicks by source for @type:@id: @message', [
        '@type' => $entity_type,
        '@id' => (string) $entity_id,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Tracks a public analytics event.
   *
   * @param string|null $source
   *   Optional discovery attribution source (allowlisted values only).
   */
  public function track(
    string $entity_type,
    int $entity_id,
    string $event_type,
    ?int $user_id = NULL,
    ?string $source = NULL,
  ): bool {
    if ($entity_type === '' || $entity_id <= 0 || $event_type === '') {
      $this->logger->error('Rejected invalid analytics event: entity_type=@type entity_id=@id event_type=@event', [
        '@type' => $entity_type,
        '@id' => $entity_id,
        '@event' => $event_type,
      ]);
      return FALSE;
    }

    if ($source !== NULL && $source !== '' && !$this->discoveryAttributionSources->isAllowed($source)) {
      $this->logger->error('Rejected analytics event with invalid source=@source for @type:@id (@event)', [
        '@source' => $source,
        '@type' => $entity_type,
        '@id' => $entity_id,
        '@event' => $event_type,
      ]);
      return FALSE;
    }

    if (!$this->database->schema()->tableExists(self::TABLE)) {
      $this->logger->error('Analytics table @table is missing. Run database updates before public analytics can be recorded.', [
        '@table' => self::TABLE,
      ]);
      return FALSE;
    }

    try {
      $uid = $user_id ?? ((int) $this->currentUser->id() ?: NULL);
      $fields = [
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
        'event_type' => $event_type,
        'user_id' => $uid,
        'timestamp' => $this->time->getRequestTime(),
      ];

      $schema = $this->database->schema();
      if ($source !== NULL && $source !== '' && $schema->fieldExists(self::TABLE, 'source')) {
        $fields['source'] = $source;
      }

      $this->database->insert(self::TABLE)
        ->fields($fields)
        ->execute();
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to store analytics event @event for @type:@id: @message', [
        '@event' => $event_type,
        '@type' => $entity_type,
        '@id' => $entity_id,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
