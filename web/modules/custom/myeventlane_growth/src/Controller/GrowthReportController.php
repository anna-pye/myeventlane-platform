<?php

declare(strict_types=1);

namespace Drupal\myeventlane_growth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_growth\Service\GrowthTrackingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Internal report for growth funnel aggregates.
 */
final class GrowthReportController extends ControllerBase {

  public function __construct(
    private readonly GrowthTrackingService $tracking,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_growth.tracking'),
    );
  }

  public function report(): array {
    $agg = $this->tracking->getAggregates();
    $impressions = $agg['impressions'] ?? [];
    $clicks = $agg['clicks'] ?? [];
    $dismissals = $agg['dismissals'] ?? [];
    $conversions = $agg['conversions'] ?? [];

    $keys = array_unique(array_merge(
      array_keys($impressions),
      array_keys($clicks),
      array_keys($dismissals),
      array_keys($conversions),
    ));
    sort($keys);

    $rows = [];
    foreach ($keys as $key) {
      $imp = (int) ($impressions[$key] ?? 0);
      $clk = (int) ($clicks[$key] ?? 0);
      $dis = (int) ($dismissals[$key] ?? 0);
      $conv = (int) ($conversions[$key] ?? 0);
      $rate = $imp > 0 ? round(($conv / $imp) * 100, 2) : 0.0;
      $rows[] = [
        'key' => $key,
        'impressions' => $imp,
        'clicks' => $clk,
        'dismissals' => $dis,
        'conversions' => $conv,
        'conversion_rate' => $rate,
      ];
    }

    $graceImpressions = (int) ($impressions['pro_grace_access_risk'] ?? 0);
    $billingRecoveryConversions = 0;
    foreach ($conversions as $k => $v) {
      if (str_starts_with((string) $k, 'pro_grace') || str_starts_with((string) $k, 'pro_billing')) {
        $billingRecoveryConversions += (int) $v;
      }
    }

    return [
      '#theme' => 'mel_growth_report',
      '#rows' => $rows,
      '#grace_impressions' => $graceImpressions,
      '#billing_recovery_conversions' => $billingRecoveryConversions,
      '#attached' => [
        'library' => ['myeventlane_growth/admin_report'],
      ],
    ];
  }

}
