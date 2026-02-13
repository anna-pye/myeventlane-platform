<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_policy\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_escalations_analytics\Service\EscalationAnalyticsQuery;
use Drupal\myeventlane_escalations_analytics\Service\EscalationMetricsCalculator;
use Drupal\myeventlane_escalations_analytics\Service\VendorHealthEvaluator;
use Psr\Log\LoggerInterface;

/**
 * Evaluates all vendors against the risk policy for the current week.
 *
 * For each vendor with escalations:
 *   1. Compute health bucket for the last evaluation_window_days.
 *   2. Update streak in keyvalue store.
 *   3. Determine if a policy trigger should fire.
 */
final class VendorPolicyEvaluator {

  public function __construct(
    private readonly EscalationAnalyticsQuery $analyticsQuery,
    private readonly EscalationMetricsCalculator $metricsCalculator,
    private readonly VendorHealthEvaluator $healthEvaluator,
    private readonly VendorRiskStreakStore $streakStore,
    private readonly PolicyWeek $policyWeek,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Evaluates all vendors and returns trigger decisions.
   *
   * @return array<int, array>
   *   Array of decisions, keyed by vendor_id. Each decision:
   *   - vendor_id: int
   *   - trigger: bool (should action fire?)
   *   - bucket: string (current health bucket)
   *   - risk_streak: int (updated streak)
   *   - metrics: array (computed metrics for this evaluation window)
   */
  public function evaluateAll(): array {
    $config = $this->configFactory->get('myeventlane_escalations_policy.settings');
    $threshold_weeks = (int) ($config->get('threshold_weeks') ?? 3);
    $cooldown_weeks = (int) ($config->get('cooldown_weeks') ?? 4);
    $window_days = (int) ($config->get('evaluation_window_days') ?? 7);

    $now = $this->time->getRequestTime();
    $current_week = $this->policyWeek->current();
    $from = $now - ($window_days * 86400);

    $vendor_ids = $this->analyticsQuery->getVendorIdsWithEscalations();
    $decisions = [];

    foreach ($vendor_ids as $vid) {
      $record = $this->streakStore->get($vid);

      // Skip if already evaluated this week.
      if ($record['last_run_week'] === $current_week) {
        $this->logger->info('Policy: vendor {vid} already evaluated for week {week}, skipping.', [
          'vid' => $vid,
          'week' => $current_week,
        ]);
        continue;
      }

      // Compute health for the evaluation window.
      $escalations = $this->analyticsQuery->query(vendor_id: $vid, from: $from);
      $metrics = $this->metricsCalculator->calculate($escalations);
      $health = $this->healthEvaluator->evaluate($metrics);
      $bucket = $health['bucket'];

      // Update streak.
      if ($bucket === 'risk') {
        $record['risk_streak']++;
      }
      else {
        $record['risk_streak'] = 0;
      }

      $record['last_bucket'] = $bucket;
      $record['last_run_week'] = $current_week;

      // Determine trigger.
      $trigger = FALSE;

      if ($record['risk_streak'] >= $threshold_weeks) {
        // Check cooldown.
        $cooldown_expired = TRUE;

        if ($record['last_triggered_timestamp'] > 0) {
          $weeks_since_trigger = (int) floor(($now - $record['last_triggered_timestamp']) / (7 * 86400));
          $cooldown_expired = $weeks_since_trigger >= $cooldown_weeks;
        }

        if ($cooldown_expired) {
          $trigger = TRUE;
          $record['last_triggered_week'] = $current_week;
          $record['last_triggered_timestamp'] = $now;

          $this->logger->notice('Policy trigger: vendor {vid} at-risk for {streak} consecutive weeks.', [
            'vid' => $vid,
            'streak' => $record['risk_streak'],
          ]);
        }
        else {
          $this->logger->info('Policy: vendor {vid} at-risk ({streak} weeks) but still in cooldown.', [
            'vid' => $vid,
            'streak' => $record['risk_streak'],
          ]);
        }
      }

      // Persist updated streak.
      $this->streakStore->set($vid, $record);

      $decisions[$vid] = [
        'vendor_id' => $vid,
        'trigger' => $trigger,
        'bucket' => $bucket,
        'risk_streak' => $record['risk_streak'],
        'metrics' => $metrics,
      ];
    }

    return $decisions;
  }

}
