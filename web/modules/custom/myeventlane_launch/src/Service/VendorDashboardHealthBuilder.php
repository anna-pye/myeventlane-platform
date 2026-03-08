<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_analytics\Service\ConversionAnalyticsService;
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Adds event health metrics to vendor dashboard event rows.
 */
final class VendorDashboardHealthBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConversionAnalyticsService $conversionService,
    private readonly MetricsAggregator $metricsAggregator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Enriches dashboard event rows with event health metrics.
   *
   * @param array<int, array<string, mixed>> $eventRows
   *   Event rows from vendor dashboard.
   *
   * @return array<int, array<string, mixed>>
   *   Enriched event rows.
   */
  public function enrichRows(array $eventRows): array {
    if ($eventRows === []) {
      return [];
    }

    foreach ($eventRows as &$row) {
      $eventId = (int) ($row['id'] ?? 0);
      if ($eventId <= 0) {
        $row['event_health'] = $this->emptyHealth();
        continue;
      }

      $row['event_health'] = $this->buildHealthForEvent($eventId, $row);
    }
    unset($row);

    return $eventRows;
  }

  /**
   * Builds health metrics for one event row.
   *
   * @param int $eventId
   *   Event node ID.
   * @param array<string, mixed> $row
   *   Existing dashboard row.
   *
   * @return array<string, mixed>
   *   Health metrics.
   */
  private function buildHealthForEvent(int $eventId, array $row): array {
    $health = $this->emptyHealth();
    $health['tickets_progress_pct'] = $this->resolveTicketProgress($row);
    $health['rsvp_count'] = (int) ($row['rsvps'] ?? 0);
    $health['revenue_formatted'] = (string) ($row['revenue_formatted'] ?? '$0');

    try {
      $funnel = $this->conversionService->getConversionFunnel($eventId);
      $health['page_views'] = (int) ($funnel['views'] ?? 0);
      if (!empty($funnel['conversion_rates']['overall'])) {
        $health['conversion_rate_pct'] = (float) $funnel['conversion_rates']['overall'];
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Failed conversion metrics for event @event_id: @message', [
        '@event_id' => $eventId,
        '@message' => $e->getMessage(),
      ]);
    }

    // Keep overview orchestration anchored to existing vendor metrics stack.
    try {
      $node = $this->entityTypeManager->getStorage('node')->load($eventId);
      if ($node instanceof NodeInterface) {
        $overview = $this->metricsAggregator->getEventOverview($node);
        if (!empty($overview['rsvps']['count'])) {
          $health['rsvp_count'] = (int) $overview['rsvps']['count'];
        }
        if (!empty($overview['sales']['gross'])) {
          $health['revenue_formatted'] = (string) $overview['sales']['gross'];
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Failed overview metrics for event @event_id: @message', [
        '@event_id' => $eventId,
        '@message' => $e->getMessage(),
      ]);
    }

    return $health;
  }

  /**
   * Resolves sold-progress percentage from existing dashboard row fields.
   */
  private function resolveTicketProgress(array $row): float {
    if (isset($row['pct_filled']) && is_numeric($row['pct_filled'])) {
      return max(0.0, min(100.0, (float) $row['pct_filled']));
    }

    if (isset($row['bar_width']) && is_numeric($row['bar_width'])) {
      return max(0.0, min(100.0, (float) $row['bar_width']));
    }

    return 0.0;
  }

  /**
   * Default health structure.
   *
   * @return array<string, mixed>
   *   Empty metrics.
   */
  private function emptyHealth(): array {
    return [
      'tickets_progress_pct' => 0.0,
      'rsvp_count' => 0,
      'revenue_formatted' => '$0',
      'page_views' => 0,
      'conversion_rate_pct' => 0.0,
    ];
  }

}
