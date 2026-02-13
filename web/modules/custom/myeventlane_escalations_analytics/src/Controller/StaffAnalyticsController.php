<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_analytics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_escalations_analytics\Service\EscalationAnalyticsQuery;
use Drupal\myeventlane_escalations_analytics\Service\EscalationMetricsCalculator;
use Drupal\myeventlane_escalations_analytics\Service\VendorHealthEvaluator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff-facing analytics dashboard for escalations.
 *
 * Shows platform-wide KPIs, per-vendor metrics with health buckets,
 * and provides CSV export of vendor data.
 */
final class StaffAnalyticsController extends ControllerBase {

  public function __construct(
    private readonly EscalationAnalyticsQuery $analyticsQuery,
    private readonly EscalationMetricsCalculator $metricsCalculator,
    private readonly VendorHealthEvaluator $healthEvaluator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_escalations_analytics.query'),
      $container->get('myeventlane_escalations_analytics.metrics'),
      $container->get('myeventlane_escalations_analytics.health'),
    );
  }

  /**
   * Renders the staff analytics dashboard.
   */
  public function dashboard(): array {
    // Platform-wide metrics (all escalations).
    $all_escalations = $this->analyticsQuery->query();
    $platform_metrics = $this->metricsCalculator->calculate($all_escalations);

    // Per-vendor breakdown.
    $vendor_ids = $this->analyticsQuery->getVendorIdsWithEscalations();
    $vendor_storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');
    $vendor_rows = [];

    foreach ($vendor_ids as $vid) {
      $vendor = $vendor_storage->load($vid);
      if (!$vendor) {
        continue;
      }

      $vendor_escalations = $this->analyticsQuery->query(vendor_id: $vid);
      $vendor_metrics = $this->metricsCalculator->calculate($vendor_escalations);
      $vendor_health = $this->healthEvaluator->evaluate($vendor_metrics);

      $vendor_rows[] = [
        'vendor_id' => $vid,
        'vendor_name' => $vendor->label(),
        'metrics' => $vendor_metrics,
        'health' => $vendor_health,
      ];
    }

    return [
      '#theme' => 'escalation_analytics_staff',
      '#platform_metrics' => $platform_metrics,
      '#vendor_rows' => $vendor_rows,
      '#export_url' => Url::fromRoute('myeventlane_escalations_analytics.staff_export')->toString(),
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Exports per-vendor analytics as a CSV download.
   */
  public function export(): Response {
    $vendor_ids = $this->analyticsQuery->getVendorIdsWithEscalations();
    $vendor_storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');

    $lines = [];
    $lines[] = implode(',', [
      'Vendor',
      'Total',
      'Resolved',
      'Breach Rate (%)',
      'Avg Response (h)',
      'Avg Resolution (h)',
      'Health',
    ]);

    foreach ($vendor_ids as $vid) {
      $vendor = $vendor_storage->load($vid);
      if (!$vendor) {
        continue;
      }

      $vendor_escalations = $this->analyticsQuery->query(vendor_id: $vid);
      $vendor_metrics = $this->metricsCalculator->calculate($vendor_escalations);
      $vendor_health = $this->healthEvaluator->evaluate($vendor_metrics);

      $lines[] = implode(',', [
        $this->csvEscape($vendor->label()),
        $vendor_metrics['total_escalations'],
        $vendor_metrics['resolved_count'],
        $vendor_metrics['breach_rate'],
        $vendor_metrics['avg_first_response_hours'],
        $vendor_metrics['avg_resolution_hours'],
        $vendor_health['bucket'],
      ]);
    }

    $csv = implode("\r\n", $lines) . "\r\n";

    return new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=UTF-8',
      'Content-Disposition' => 'attachment; filename="escalation-analytics-' . date('Y-m-d') . '.csv"',
    ]);
  }

  /**
   * Escapes a value for safe CSV output.
   */
  private function csvEscape(string $value): string {
    if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
      return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
  }

}
