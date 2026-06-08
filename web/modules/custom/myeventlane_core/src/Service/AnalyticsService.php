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

  private const TABLE = 'myeventlane_public_analytics_event';

  /**
   * Constructs an AnalyticsService object.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
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
   * Tracks a public analytics event.
   */
  public function track(string $entity_type, int $entity_id, string $event_type, ?int $user_id = NULL): bool {
    if ($entity_type === '' || $entity_id <= 0 || $event_type === '') {
      $this->logger->error('Rejected invalid analytics event: entity_type=@type entity_id=@id event_type=@event', [
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
      $this->database->insert(self::TABLE)
        ->fields([
          'entity_type' => $entity_type,
          'entity_id' => $entity_id,
          'event_type' => $event_type,
          'user_id' => $uid,
          'timestamp' => $this->time->getRequestTime(),
        ])
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
