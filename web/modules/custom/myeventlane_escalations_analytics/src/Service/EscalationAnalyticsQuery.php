<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_analytics\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides filtered entity queries for escalation analytics.
 *
 * Access control is NOT enforced here; callers must enforce access
 * at the controller layer before invoking these methods.
 */
final class EscalationAnalyticsQuery {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Queries escalation entities matching the given filters.
   *
   * @param int|null $vendor_id
   *   Filter by vendor entity ID. NULL = all vendors.
   * @param int|null $from
   *   Filter by created >= this UNIX timestamp.
   * @param int|null $to
   *   Filter by created <= this UNIX timestamp.
   * @param array|null $statuses
   *   Filter by status values. NULL = all statuses.
   *
   * @return \Drupal\myeventlane_escalations\Entity\Escalation[]
   *   Loaded escalation entities.
   */
  public function query(?int $vendor_id = NULL, ?int $from = NULL, ?int $to = NULL, ?array $statuses = NULL): array {
    $ids = $this->queryIds($vendor_id, $from, $to, $statuses);
    if (empty($ids)) {
      return [];
    }

    return $this->entityTypeManager->getStorage('escalation')->loadMultiple($ids);
  }

  /**
   * Queries escalation entity IDs matching the given filters.
   *
   * @param int|null $vendor_id
   *   Filter by vendor entity ID. NULL = all vendors.
   * @param int|null $from
   *   Filter by created >= this UNIX timestamp.
   * @param int|null $to
   *   Filter by created <= this UNIX timestamp.
   * @param array|null $statuses
   *   Filter by status values. NULL = all statuses.
   *
   * @return int[]
   *   Escalation entity IDs.
   */
  public function queryIds(?int $vendor_id = NULL, ?int $from = NULL, ?int $to = NULL, ?array $statuses = NULL): array {
    $storage = $this->entityTypeManager->getStorage('escalation');
    $query = $storage->getQuery()->accessCheck(FALSE);

    if ($vendor_id !== NULL) {
      $query->condition('vendor_id', $vendor_id);
    }

    if ($from !== NULL) {
      $query->condition('created', $from, '>=');
    }

    if ($to !== NULL) {
      $query->condition('created', $to, '<=');
    }

    if ($statuses !== NULL && $statuses !== []) {
      $query->condition('status', $statuses, 'IN');
    }

    $query->sort('created', 'DESC');

    return array_map('intval', $query->execute());
  }

  /**
   * Returns distinct vendor IDs that have at least one escalation.
   *
   * @return int[]
   *   Vendor entity IDs.
   */
  public function getVendorIdsWithEscalations(): array {
    $storage = $this->entityTypeManager->getStorage('escalation');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vendor_id', NULL, 'IS NOT NULL')
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $escalations = $storage->loadMultiple($ids);
    $vendor_ids = [];
    foreach ($escalations as $escalation) {
      if (!$escalation->get('vendor_id')->isEmpty()) {
        $vid = (int) $escalation->get('vendor_id')->target_id;
        $vendor_ids[$vid] = $vid;
      }
    }

    return array_values($vendor_ids);
  }

}
