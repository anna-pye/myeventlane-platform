<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Vendor dashboard Boost performance CSV export (all events).
 *
 * Route: /vendor/dashboard/boost/export
 * Scope: All boosts for vendor's events, last 30 days.
 * Uses existing metrics only.
 */
final class BoostVendorExportController extends VendorConsoleBaseController {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    private readonly MetricsAggregator $metricsAggregator,
    EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Exports Boost performance for all vendor events as CSV.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   Streamed CSV download.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If vendor owns no boosts.
   */
  public function export(): StreamedResponse {
    $this->assertVendorAccess();

    $userId = (int) $this->currentUser->id();
    $vendor = $this->getCurrentVendorOrNull();
    if (!$vendor) {
      throw new AccessDeniedHttpException();
    }

    $eventIds = $this->getPublishedUserEvents($userId);
    if (empty($eventIds)) {
      throw new AccessDeniedHttpException();
    }

    if (!$this->vendorHasAnyBoost($eventIds)) {
      throw new AccessDeniedHttpException();
    }

    $rangeStart = $this->time->getRequestTime() - (30 * 86400);
    $rangeEnd = $this->time->getRequestTime();
    $tz = $this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC';

    $rows = $this->buildExportRows($eventIds, $rangeStart, $rangeEnd, $tz);

    $headers = [
      'Event title',
      'Event ID',
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

    $vendorId = (int) $vendor->id();
    $filename = 'boost-performance-vendor-' . $vendorId . '.csv';

    $response = new StreamedResponse(function () use ($headers, $rows) {
      $handle = fopen('php://output', 'w');
      if (!$handle) {
        return;
      }

      fputcsv($handle, $headers);

      foreach ($rows as $row) {
        fputcsv($handle, $row);
      }

      fclose($handle);
    }, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ]);

    return $response;
  }

  /**
   * Gets published event IDs for the current user (vendor).
   */
  private function getPublishedUserEvents(int $userId): array {
    if ($userId <= 0) {
      return [];
    }

    try {
      $nids = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->condition('status', 1)
        ->execute();
      return array_map('intval', $nids);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Checks whether any of the given events have at least one Boost order item.
   */
  private function vendorHasAnyBoost(array $eventIds): bool {
    if (empty($eventIds)) {
      return FALSE;
    }

    try {
      $count = $this->entityTypeManager
        ->getStorage('commerce_order_item')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'boost')
        ->condition('field_target_event', $eventIds, 'IN')
        ->count()
        ->execute();
      return (int) $count > 0;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Builds CSV rows from events and placements (last 30 days filter).
   *
   * @param int[] $eventIds
   *   Published event node IDs.
   * @param int $rangeStart
   *   Unix timestamp (range start).
   * @param int $rangeEnd
   *   Unix timestamp (range end).
   * @param string $tz
   *   Site timezone.
   *
   * @return array
   *   List of row arrays for fputcsv.
   */
  private function buildExportRows(array $eventIds, int $rangeStart, int $rangeEnd, string $tz): array {
    $rows = [];
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    foreach ($eventIds as $nid) {
      $event = $nodeStorage->load($nid);
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        continue;
      }

      $overview = $this->metricsAggregator->getEventOverview($event);
      $boostMetrics = $overview['boost_metrics'] ?? [];
      $placements = $boostMetrics['placements'] ?? [];
      $salesDuring = $boostMetrics['sales_during_period'] ?? NULL;
      $ordersFollowing = $boostMetrics['orders_following_click'] ?? NULL;

      $salesValue = $this->parseCurrencyToNumeric(
        is_array($salesDuring) && isset($salesDuring['revenue']) ? $salesDuring['revenue'] : NULL
      );
      $ordersCount = is_array($ordersFollowing) && isset($ordersFollowing['count'])
        ? (int) $ordersFollowing['count'] : '';

      $eventTitle = $event->label();
      $eventId = (int) $event->id();

      foreach ($placements as $p) {
        $startTs = isset($p['start_ts']) ? (int) $p['start_ts'] : NULL;
        $endTs = isset($p['end_ts']) ? (int) $p['end_ts'] : NULL;

        if ($startTs === NULL || $endTs === NULL) {
          continue;
        }

        $overlaps = $endTs >= $rangeStart && $startTs <= $rangeEnd;
        if (!$overlaps) {
          continue;
        }

        $startDate = $this->formatDateInTimezone($startTs, $tz);
        $endDate = $this->formatDateInTimezone($endTs, $tz);
        $spend = $this->parseCurrencyToNumeric($p['spend_to_date'] ?? $p['budget'] ?? NULL);
        $ctr = isset($p['ctr']) ? (float) $p['ctr'] : '';

        $rows[] = [
          $eventTitle,
          $eventId,
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
        ];
      }
    }

    return $rows;
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
    return (float) $cleaned;
  }

}
