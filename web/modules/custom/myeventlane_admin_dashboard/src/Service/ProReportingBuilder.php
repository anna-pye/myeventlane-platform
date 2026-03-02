<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Service;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProRecoveryAnalyticsService;
use Drupal\myeventlane_pro\Service\ProSubscriptionHealthService;

/**
 * Lazy builder for the Pro subscription KPI section on the Platform Control Centre.
 */
final class ProReportingBuilder implements TrustedCallbackInterface {

  public function __construct(
    private readonly ProSubscriptionHealthService $subscriptionHealthService,
    private readonly ProRecoveryAnalyticsService $recoveryAnalyticsService,
    private readonly PlatformMetricsService $platformMetricsService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return [
      'renderProKpis',
    ];
  }

  /**
   * Lazy builder: Pro subscription KPIs for the Platform Control Centre.
   *
   * @return array
   *   Render array for platform_control_centre__pro_kpis.
   */
  public function renderProKpis(): array {
    $metrics = [
      'active_count' => $this->subscriptionHealthService->getActiveProCount(),
      'mrr' => $this->subscriptionHealthService->getMRR(),
      'churn_rate_30d' => $this->subscriptionHealthService->getChurnRate(30),
      'failed_renewals_30d' => $this->subscriptionHealthService->getFailedRenewals(30),
      'grace_users' => $this->subscriptionHealthService->getGraceUsers(),
    ];
    $recoveryStats = $this->recoveryAnalyticsService->getPlatformRecoveryStats(30);
    $topStores = $this->recoveryAnalyticsService->getTopRecoveringStores(30, 5);
    $platformKpis = $this->platformMetricsService->getKpis(30);
    $platformGrossCents = $this->decimalValueToCents($platformKpis['gross_total'] ?? 0);
    $recoveredRevenueCents = $this->decimalValueToCents($recoveryStats['recovered_revenue'] ?? 0);

    // PlatformMetricsService gross_total comes from completed order gross values.
    // Recovered revenue is therefore treated as a subset of gross_total, so the
    // uplift denominator is gross_total minus recovered_revenue.
    $upliftDenominatorCents = max(0, $platformGrossCents - $recoveredRevenueCents);
    $upliftPercent = 0.0;
    if ($recoveredRevenueCents > 0 && $upliftDenominatorCents > 0) {
      $upliftTenthsPercent = intdiv(
        ($recoveredRevenueCents * 1000) + intdiv($upliftDenominatorCents, 2),
        $upliftDenominatorCents
      );
      $upliftPercent = $upliftTenthsPercent / 10;
    }

    $reportUrl = NULL;
    try {
      $reportUrl = Url::fromRoute('myeventlane_pro.admin_report')->toString();
    }
    catch (\Exception) {
      // Route may not exist; omit link.
    }

    return [
      '#theme' => 'platform_control_centre__pro_kpis',
      '#metrics' => $metrics,
      '#recovery_stats' => $recoveryStats,
      '#top_recovering_stores' => $topStores,
      '#pro_uplift_percent' => $upliftPercent,
      '#report_url' => $reportUrl,
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['commerce_subscription_list', 'commerce_order_list', 'commerce_store_list'],
        'max-age' => 120,
      ],
    ];
  }

  /**
   * Converts decimal values to integer cents using string math.
   */
  private function decimalValueToCents(mixed $value): int {
    $normalized = trim((string) $value);
    if ($normalized === '' || !preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
      return 0;
    }

    [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
    $fractionDigits = preg_replace('/\D/', '', $fraction) ?? '';
    $fractionForRounding = str_pad($fractionDigits, 3, '0');
    $cents = ((int) $whole * 100) + (int) substr($fractionForRounding, 0, 2);
    if ((int) $fractionForRounding[2] >= 5) {
      $cents++;
    }

    return $cents;
  }

}
