<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_analytics\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Calculates derived escalation metrics from loaded entities.
 *
 * All metrics are computed in-memory from entity data. Nothing is stored.
 * avg_first_response_hours uses the first comment on the escalation thread.
 */
final class EscalationMetricsCalculator {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Calculates metrics from a set of loaded escalation entities.
   *
   * @param \Drupal\myeventlane_escalations\Entity\Escalation[] $escalations
   *   Loaded escalation entities.
   *
   * @return array
   *   Associative array of derived metrics.
   */
  public function calculate(array $escalations): array {
    $total = count($escalations);

    if ($total === 0) {
      return $this->emptyMetrics();
    }

    $resolved_count = 0;
    $breach_count = 0;
    $escalated_count = 0;
    $resolution_times = [];
    $by_status = [];
    $by_priority = [];
    $escalation_ids_for_response = [];

    foreach ($escalations as $escalation) {
      $status = (string) $escalation->get('status')->value;
      $priority = (string) $escalation->get('priority')->value;

      // Count by status.
      $by_status[$status] = ($by_status[$status] ?? 0) + 1;

      // Count by priority.
      $by_priority[$priority] = ($by_priority[$priority] ?? 0) + 1;

      // Resolved/closed count.
      if (in_array($status, ['resolved', 'closed'], TRUE)) {
        $resolved_count++;

        // Collect resolution time (resolved_at - created).
        $resolved_at = $escalation->hasField('resolved_at') && !$escalation->get('resolved_at')->isEmpty()
          ? (int) $escalation->get('resolved_at')->value
          : 0;
        $created = (int) $escalation->get('created')->value;

        if ($resolved_at > 0 && $created > 0 && $resolved_at > $created) {
          $resolution_times[] = ($resolved_at - $created) / 3600;
        }
      }

      // Breach count: first OR resolution breached.
      $first_breached = $escalation->hasField('field_sla_first_breached')
        ? (bool) ($escalation->get('field_sla_first_breached')->value ?? FALSE)
        : FALSE;
      $resolution_breached = $escalation->hasField('field_sla_resolution_breached')
        ? (bool) ($escalation->get('field_sla_resolution_breached')->value ?? FALSE)
        : FALSE;

      if ($first_breached || $resolution_breached) {
        $breach_count++;
      }

      // Escalated count: level > 0.
      $level = $escalation->hasField('field_escalation_level')
        ? (int) ($escalation->get('field_escalation_level')->value ?? 0)
        : 0;

      if ($level > 0) {
        $escalated_count++;
      }

      // Collect IDs for first-response-time calculation.
      $escalation_ids_for_response[(int) $escalation->id()] = (int) $escalation->get('created')->value;
    }

    // Calculate avg first response time from comment thread.
    $response_times = $this->computeFirstResponseTimes($escalation_ids_for_response);

    return [
      'total_escalations' => $total,
      'resolved_count' => $resolved_count,
      'resolution_rate' => $total > 0 ? round(($resolved_count / $total) * 100, 1) : 0.0,
      'breach_count' => $breach_count,
      'breach_rate' => $total > 0 ? round(($breach_count / $total) * 100, 1) : 0.0,
      'escalated_count' => $escalated_count,
      'escalation_rate' => $total > 0 ? round(($escalated_count / $total) * 100, 1) : 0.0,
      'avg_first_response_hours' => $response_times !== [] ? round(array_sum($response_times) / count($response_times), 1) : 0.0,
      'avg_resolution_hours' => $resolution_times !== [] ? round(array_sum($resolution_times) / count($resolution_times), 1) : 0.0,
      'by_status' => $by_status,
      'by_priority' => $by_priority,
    ];
  }

  /**
   * Returns an empty metrics array for zero-escalation sets.
   */
  private function emptyMetrics(): array {
    return [
      'total_escalations' => 0,
      'resolved_count' => 0,
      'resolution_rate' => 0.0,
      'breach_count' => 0,
      'breach_rate' => 0.0,
      'escalated_count' => 0,
      'escalation_rate' => 0.0,
      'avg_first_response_hours' => 0.0,
      'avg_resolution_hours' => 0.0,
      'by_status' => [],
      'by_priority' => [],
    ];
  }

  /**
   * Computes first response time for each escalation from the comment thread.
   *
   * First response = time from escalation creation to the first comment
   * on field_escalation_thread. Escalations with no comments are excluded.
   *
   * @param array<int, int> $escalation_created
   *   Map of escalation_id => created timestamp.
   *
   * @return float[]
   *   Array of response times in hours.
   */
  private function computeFirstResponseTimes(array $escalation_created): array {
    if (empty($escalation_created)) {
      return [];
    }

    try {
      $comment_storage = $this->entityTypeManager->getStorage('comment');
    }
    catch (\Throwable) {
      return [];
    }

    $response_hours = [];

    // Query first comment per escalation. Process in chunks to avoid
    // excessive memory use on large datasets.
    foreach (array_chunk(array_keys($escalation_created), 50, TRUE) as $chunk_ids) {
      $cids = $comment_storage->getQuery()
        ->condition('entity_type', 'escalation')
        ->condition('entity_id', $chunk_ids, 'IN')
        ->condition('field_name', 'field_escalation_thread')
        ->sort('created', 'ASC')
        ->accessCheck(FALSE)
        ->execute();

      if (empty($cids)) {
        continue;
      }

      $comments = $comment_storage->loadMultiple($cids);

      // Find the earliest comment per escalation.
      $first_by_escalation = [];
      foreach ($comments as $comment) {
        $eid = (int) $comment->get('entity_id')->target_id;
        $comment_created = (int) $comment->getCreatedTime();

        if (!isset($first_by_escalation[$eid]) || $comment_created < $first_by_escalation[$eid]) {
          $first_by_escalation[$eid] = $comment_created;
        }
      }

      // Compute hours for each.
      foreach ($first_by_escalation as $eid => $first_comment_time) {
        $created = $escalation_created[$eid] ?? 0;
        if ($created > 0 && $first_comment_time > $created) {
          $response_hours[] = ($first_comment_time - $created) / 3600;
        }
      }
    }

    return $response_hours;
  }

}
