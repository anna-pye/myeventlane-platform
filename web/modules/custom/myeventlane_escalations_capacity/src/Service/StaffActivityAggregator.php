<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_capacity\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggregates staff activity metrics from escalation comment threads.
 *
 * A "staff comment" is any comment whose author holds at least one role
 * listed in the configured staff_roles setting.
 *
 * IMPORTANT: This service NEVER returns or exposes individual user identities.
 * All output is aggregate.
 */
final class StaffActivityAggregator {

  /**
   * Chunk size for loading escalation entities.
   */
  private const CHUNK_SIZE = 50;

  /**
   * Local per-request cache of uid → is_staff boolean.
   *
   * @var array<int, bool>
   */
  private array $staffCache = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Computes aggregate staff activity metrics for the given escalation IDs.
   *
   * @param int[] $escalation_ids
   *   Escalation entity IDs to analyse.
   * @param int|null $window_start
   *   Optional Unix timestamp; only consider comments created on or after this.
   *   If NULL, uses the configured activity_window_days from request time.
   *
   * @return array
   *   Associative array with aggregate-only keys:
   *   - total_staff_replies: int
   *   - avg_replies_per_escalation: float
   *   - escalations_without_staff_reply: int
   *   - avg_first_staff_response_hours: float
   */
  public function aggregate(array $escalation_ids, ?int $window_start = NULL): array {
    $config = $this->configFactory->get('myeventlane_escalations_capacity.settings');
    $staff_roles = (array) $config->get('staff_roles') ?: [];

    if (empty($escalation_ids) || empty($staff_roles)) {
      return $this->emptyResult();
    }

    if ($window_start === NULL) {
      $activity_window_days = (int) $config->get('activity_window_days') ?: 7;
      $window_start = $this->time->getRequestTime() - ($activity_window_days * 86400);
    }

    // Reset per-request cache.
    $this->staffCache = [];

    $total_staff_replies = 0;
    $escalations_with_staff_reply = 0;
    $first_response_deltas = [];
    $escalation_count = count($escalation_ids);

    $escalation_storage = $this->entityTypeManager->getStorage('escalation');
    $comment_storage = $this->entityTypeManager->getStorage('comment');

    foreach (array_chunk($escalation_ids, self::CHUNK_SIZE) as $chunk) {
      $escalations = $escalation_storage->loadMultiple($chunk);

      foreach ($escalations as $escalation) {
        $escalation_id = (int) $escalation->id();
        $escalation_created = (int) $escalation->get('created')->value;

        // Query comments for this escalation's thread.
        $comment_ids = $comment_storage->getQuery()
          ->accessCheck(TRUE)
          ->condition('entity_id', $escalation_id)
          ->condition('entity_type', 'escalation')
          ->condition('field_name', 'field_escalation_thread')
          ->condition('created', $window_start, '>=')
          ->sort('created', 'ASC')
          ->execute();

        if (empty($comment_ids)) {
          continue;
        }

        $comments = $comment_storage->loadMultiple($comment_ids);
        $staff_reply_count = 0;
        $first_staff_timestamp = NULL;

        foreach ($comments as $comment) {
          $uid = (int) $comment->getOwnerId();
          if ($this->isStaffUser($uid, $staff_roles)) {
            $staff_reply_count++;
            if ($first_staff_timestamp === NULL) {
              $first_staff_timestamp = (int) $comment->getCreatedTime();
            }
          }
        }

        $total_staff_replies += $staff_reply_count;

        if ($staff_reply_count > 0) {
          $escalations_with_staff_reply++;
        }

        if ($first_staff_timestamp !== NULL) {
          $delta_seconds = $first_staff_timestamp - $escalation_created;
          // Guard against negative deltas (e.g. clock skew).
          if ($delta_seconds > 0) {
            $first_response_deltas[] = $delta_seconds;
          }
        }
      }
    }

    $avg_replies = $escalation_count > 0
      ? round($total_staff_replies / $escalation_count, 1)
      : 0.0;

    $avg_first_response_hours = 0.0;
    if (!empty($first_response_deltas)) {
      $avg_first_response_hours = round(
        array_sum($first_response_deltas) / count($first_response_deltas) / 3600,
        1,
      );
    }

    return [
      'total_staff_replies' => $total_staff_replies,
      'avg_replies_per_escalation' => $avg_replies,
      'escalations_without_staff_reply' => $escalation_count - $escalations_with_staff_reply,
      'avg_first_staff_response_hours' => $avg_first_response_hours,
    ];
  }

  /**
   * Checks whether a user has any of the configured staff roles.
   *
   * Results are cached per-request in $this->staffCache.
   *
   * @param int $uid
   *   The user ID to check.
   * @param string[] $staff_roles
   *   Machine names of roles considered "staff".
   *
   * @return bool
   *   TRUE if the user holds at least one staff role.
   */
  private function isStaffUser(int $uid, array $staff_roles): bool {
    if (isset($this->staffCache[$uid])) {
      return $this->staffCache[$uid];
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user) {
      $this->logger->warning('Could not load user @uid when aggregating staff activity.', [
        '@uid' => $uid,
      ]);
      $this->staffCache[$uid] = FALSE;
      return FALSE;
    }

    $user_roles = $user->getRoles(TRUE);
    $is_staff = !empty(array_intersect($user_roles, $staff_roles));
    $this->staffCache[$uid] = $is_staff;

    return $is_staff;
  }

  /**
   * Returns an empty result set.
   */
  private function emptyResult(): array {
    return [
      'total_staff_replies' => 0,
      'avg_replies_per_escalation' => 0.0,
      'escalations_without_staff_reply' => 0,
      'avg_first_staff_response_hours' => 0.0,
    ];
  }

}
