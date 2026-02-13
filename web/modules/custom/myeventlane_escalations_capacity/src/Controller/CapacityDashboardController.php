<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_capacity\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_escalations_capacity\Service\CapacitySignalEvaluator;
use Drupal\myeventlane_escalations_capacity\Service\EscalationWorkloadQuery;
use Drupal\myeventlane_escalations_capacity\Service\StaffActivityAggregator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dashboard controller for the escalation capacity analytics page.
 *
 * Builds a render array containing aggregate workload, staff activity,
 * and capacity signal data. No individual staff identities are exposed.
 */
final class CapacityDashboardController extends ControllerBase {

  public function __construct(
    private readonly EscalationWorkloadQuery $workloadQuery,
    private readonly StaffActivityAggregator $activityAggregator,
    private readonly CapacitySignalEvaluator $signalEvaluator,
    private readonly ConfigFactoryInterface $config,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_escalations_capacity.workload_query'),
      $container->get('myeventlane_escalations_capacity.staff_activity_aggregator'),
      $container->get('myeventlane_escalations_capacity.signal_evaluator'),
      $container->get('config.factory'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Renders the capacity dashboard.
   */
  public function dashboard(): array {
    $settings = $this->config->get('myeventlane_escalations_capacity.settings');
    $activity_window_days = (int) $settings->get('activity_window_days') ?: 7;
    $trend_window_days = (int) $settings->get('trend_window_days') ?: 7;
    $now = $this->time->getRequestTime();

    // ── Current-window metrics ──
    $workload = $this->workloadQuery->getWorkloadMetrics();
    $open_ids = $this->workloadQuery->getOpenEscalationIds();
    $activity = $this->activityAggregator->aggregate($open_ids);

    // ── Signal evaluation ──
    $signal = $this->signalEvaluator->evaluate($workload, $activity);

    // ── Trend: previous window for "this week vs last week" deltas ──
    $prev_workload = $this->workloadQuery->getWorkloadMetricsForWindow(
      offset_days: $trend_window_days,
      window_days: $trend_window_days,
    );

    $prev_window_start = $now - (($activity_window_days + $trend_window_days) * 86400);

    // For previous-window activity, use the same open IDs but shift the
    // comment window to the previous period for comparison.
    $prev_activity = $this->activityAggregator->aggregate(
      $open_ids,
      $prev_window_start,
    );

    $trend = $this->buildTrend($workload, $activity, $prev_workload, $prev_activity);

    return [
      '#theme' => 'escalation_capacity_dashboard',
      '#workload' => $workload,
      '#activity' => $activity,
      '#signal' => $signal,
      '#trend' => $trend,
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Builds trend delta data comparing current vs previous window.
   *
   * @param array $workload
   *   Current workload metrics.
   * @param array $activity
   *   Current activity metrics.
   * @param array $prev_workload
   *   Previous window workload metrics.
   * @param array $prev_activity
   *   Previous window activity metrics.
   *
   * @return array
   *   Array with delta values and direction strings.
   */
  private function buildTrend(array $workload, array $activity, array $prev_workload, array $prev_activity): array {
    $trend = [];

    // New escalations delta.
    $current_new = (int) ($workload['new_in_window_count'] ?? 0);
    $prev_new = (int) ($prev_workload['new_in_window_count'] ?? 0);
    $trend['new_in_window'] = $this->delta($current_new, $prev_new);

    // Staff replies delta.
    $current_replies = (int) ($activity['total_staff_replies'] ?? 0);
    $prev_replies = (int) ($prev_activity['total_staff_replies'] ?? 0);
    $trend['total_staff_replies'] = $this->delta($current_replies, $prev_replies);

    // Avg first response delta (lower is better, so direction is inverted).
    $current_response = (float) ($activity['avg_first_staff_response_hours'] ?? 0);
    $prev_response = (float) ($prev_activity['avg_first_staff_response_hours'] ?? 0);
    $trend['avg_first_staff_response_hours'] = $this->delta($current_response, $prev_response, TRUE);

    return $trend;
  }

  /**
   * Computes a delta entry for trend display.
   *
   * @param float|int $current
   *   Current value.
   * @param float|int $previous
   *   Previous value.
   * @param bool $lower_is_better
   *   If TRUE, a decrease is shown as positive (green).
   *
   * @return array
   *   Array with 'value', 'direction', and 'label'.
   */
  private function delta(float|int $current, float|int $previous, bool $lower_is_better = FALSE): array {
    $diff = $current - $previous;
    $abs_diff = abs($diff);

    if ($diff == 0) {
      $direction = 'neutral';
      $label = 'No change';
    }
    elseif ($diff > 0) {
      $direction = $lower_is_better ? 'negative' : 'positive';
      $label = '+' . ($abs_diff == (int) $abs_diff ? (int) $abs_diff : round($abs_diff, 1));
    }
    else {
      $direction = $lower_is_better ? 'positive' : 'negative';
      $label = '-' . ($abs_diff == (int) $abs_diff ? (int) $abs_diff : round($abs_diff, 1));
    }

    return [
      'value' => $diff,
      'direction' => $direction,
      'label' => $label,
    ];
  }

}
