<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Event-level Boost performance CSV export.
 *
 * Route: /vendor/events/{event}/boost/export
 * Uses MetricsAggregator::getEventOverview() and BoostMetricsService data only.
 */
final class BoostEventExportController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    private readonly MetricsAggregator $metricsAggregator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger);
  }

  /**
   * Exports Boost performance for a single event as CSV.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   Streamed CSV download.
   */
  public function export(NodeInterface $event): StreamedResponse {
    $this->assertEventOwnership($event);

    $overview = $this->metricsAggregator->getEventOverview($event);
    $boostMetrics = $overview['boost_metrics'] ?? [];
    $placements = $boostMetrics['placements'] ?? [];
    $salesDuring = $boostMetrics['sales_during_period'] ?? NULL;
    $ordersFollowing = $boostMetrics['orders_following_click'] ?? NULL;

    $eventTitle = $event->label();
    $eventId = (int) $event->id();
    $tz = $this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC';

    $headers = [
      'Event title',
      'Boost placement',
      'Status',
      'Start date',
      'End date',
      'Spend',
      'Impressions',
      'Clicks',
      'CTR',
      'Sales during boost period',
      'Orders following click (24h)',
    ];

    $salesValue = $this->parseCurrencyToNumeric(
      is_array($salesDuring) && isset($salesDuring['revenue']) ? $salesDuring['revenue'] : NULL
    );
    $ordersCount = is_array($ordersFollowing) && isset($ordersFollowing['count'])
      ? (int) $ordersFollowing['count'] : '';

    $filename = 'boost-performance-event-' . $eventId . '.csv';

    $response = new StreamedResponse(function () use (
      $headers,
      $placements,
      $eventTitle,
      $salesValue,
      $ordersCount,
      $tz,
    ) {
      $handle = fopen('php://output', 'w');
      if (!$handle) {
        return;
      }

      fputcsv($handle, $headers);

      if (empty($placements)) {
        fclose($handle);
        return;
      }

      foreach ($placements as $p) {
        $startDate = isset($p['start_ts']) ? $this->formatDateInTimezone((int) $p['start_ts'], $tz) : '';
        $endDate = isset($p['end_ts']) ? $this->formatDateInTimezone((int) $p['end_ts'], $tz) : '';
        $spend = $this->parseCurrencyToNumeric($p['spend_to_date'] ?? $p['budget'] ?? NULL);
        $ctr = isset($p['ctr']) ? (float) $p['ctr'] : '';

        fputcsv($handle, [
          $eventTitle,
          $p['placement'] ?? '',
          $p['status'] ?? '',
          $startDate,
          $endDate,
          $spend,
          $p['impressions'] ?? 0,
          $p['clicks'] ?? 0,
          $ctr,
          $salesValue,
          $ordersCount,
        ]);
      }

      fclose($handle);
    }, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ]);

    return $response;
  }

  /**
   * Formats a Unix timestamp in the given timezone.
   */
  private function formatDateInTimezone(int $ts, string $tz): string {
    try {
      $dt = new \DateTimeImmutable('@' . $ts, new \DateTimeZone('UTC'));
      $dt = $dt->setTimezone(new \DateTimeZone($tz));
      return $dt->format('Y-m-d H:i:s');
    }
    catch (\Exception $e) {
      return (string) $ts;
    }
  }

  /**
   * Strips currency formatting to numeric only.
   *
   * @param string|null $value
   *   Formatted value, e.g. "$1,234.56".
   *
   * @return string|float
   *   Numeric value or empty string.
   */
  private function parseCurrencyToNumeric(?string $value): string|float {
    if ($value === NULL || $value === '') {
      return '';
    }
    $cleaned = preg_replace('/[^0-9.-]/', '', $value);
    if ($cleaned === '' || $cleaned === '-') {
      return '';
    }
    $num = (float) $cleaned;
    return $num;
  }

}
