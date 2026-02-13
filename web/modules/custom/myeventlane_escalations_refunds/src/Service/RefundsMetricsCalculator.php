<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_refunds\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Computes refund metrics from log and request rows.
 *
 * READ-ONLY. No PII. All monetary values in cents.
 *
 * Correlation flags detect refund activity occurring within a configurable
 * window after escalation creation (default: 7 days).
 */
final class RefundsMetricsCalculator {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Calculates refund metrics for a single escalation's context.
   *
   * @param array $logs
   *   Refund log rows from the repository.
   * @param array $requests
   *   Refund request rows from the repository.
   * @param int $escalation_created
   *   Escalation entity created timestamp.
   *
   * @return array
   *   Computed metrics including counts, totals, and correlation flags.
   */
  public function calculate(array $logs, array $requests, int $escalation_created): array {
    $window_days = (int) ($this->configFactory
      ->get('myeventlane_escalations_refunds.settings')
      ->get('correlation_window_days') ?? 7);
    $window_end = $escalation_created + ($window_days * 86400);

    // Request counts by status.
    $requests_by_status = $this->countByKey($requests, 'status');
    $total_requested_cents = $this->sumCents($requests);

    // Log counts by status.
    $logs_by_status = $this->countByKey($logs, 'status');
    $total_completed_cents = $this->sumCentsWhere($logs, 'status', 'completed');
    $total_failed_cents = $this->sumCentsWhere($logs, 'status', 'failed');
    $total_pending_cents = $this->sumCentsWhere($logs, 'status', 'pending');

    // Correlation flags.
    $refund_after_escalation = FALSE;
    foreach ($logs as $log) {
      $completed_at = (int) ($log['completed'] ?? 0);
      if ($completed_at > 0 && $completed_at >= $escalation_created && $completed_at <= $window_end) {
        $refund_after_escalation = TRUE;
        break;
      }
    }

    $request_after_escalation = FALSE;
    foreach ($requests as $req) {
      $req_created = (int) ($req['created'] ?? 0);
      if ($req_created >= $escalation_created && $req_created <= $window_end) {
        $request_after_escalation = TRUE;
        break;
      }
    }

    return [
      'request_count' => count($requests),
      'requests_by_status' => $requests_by_status,
      'total_requested_cents' => $total_requested_cents,
      'log_count' => count($logs),
      'logs_by_status' => $logs_by_status,
      'total_completed_cents' => $total_completed_cents,
      'total_failed_cents' => $total_failed_cents,
      'total_pending_cents' => $total_pending_cents,
      'refund_after_escalation' => $refund_after_escalation,
      'request_after_escalation' => $request_after_escalation,
      'correlation_window_days' => $window_days,
    ];
  }

  /**
   * Calculates vendor-level refund metrics.
   *
   * @param array $logs
   *   Vendor's refund log rows.
   * @param array $requests
   *   Vendor's refund request rows.
   *
   * @return array
   *   Aggregated vendor metrics (no PII).
   */
  public function calculateForVendor(array $logs, array $requests): array {
    return [
      'request_count' => count($requests),
      'requests_by_status' => $this->countByKey($requests, 'status'),
      'total_requested_cents' => $this->sumCents($requests),
      'log_count' => count($logs),
      'logs_by_status' => $this->countByKey($logs, 'status'),
      'total_completed_cents' => $this->sumCentsWhere($logs, 'status', 'completed'),
      'total_failed_cents' => $this->sumCentsWhere($logs, 'status', 'failed'),
      'total_pending_cents' => $this->sumCentsWhere($logs, 'status', 'pending'),
      'logs_by_type' => $this->countByKey($logs, 'refund_type'),
    ];
  }

  /**
   * Counts rows grouped by a specific key.
   */
  private function countByKey(array $rows, string $key): array {
    $counts = [];
    foreach ($rows as $row) {
      $value = (string) ($row[$key] ?? 'unknown');
      $counts[$value] = ($counts[$value] ?? 0) + 1;
    }
    return $counts;
  }

  /**
   * Sums amount_cents across all rows.
   */
  private function sumCents(array $rows): int {
    $total = 0;
    foreach ($rows as $row) {
      $total += (int) ($row['amount_cents'] ?? 0);
    }
    return $total;
  }

  /**
   * Sums amount_cents where a column matches a specific value.
   */
  private function sumCentsWhere(array $rows, string $key, string $match): int {
    $total = 0;
    foreach ($rows as $row) {
      if (($row[$key] ?? '') === $match) {
        $total += (int) ($row['amount_cents'] ?? 0);
      }
    }
    return $total;
  }

}
