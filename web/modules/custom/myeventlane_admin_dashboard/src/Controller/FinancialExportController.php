<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_admin_dashboard\Service\PlatformMetricsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams financial export as CSV.
 */
final class FinancialExportController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected PlatformMetricsService $metricsService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('myeventlane_admin_dashboard.metrics'),
    );
  }

  /**
   * Exports financial data as CSV.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request (days, store_id query params).
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   CSV streamed response.
   */
  public function export(Request $request): Response {
    $days = (int) $request->query->get('days', 30);
    $storeId = $request->query->get('store_id');
    if ($storeId !== NULL) {
      $storeId = (int) $storeId;
    }

    $vendorRanking = $this->metricsService->getVendorRanking($days, 100);
    $payoutSummary = $this->metricsService->getPayoutLiabilitySummary(90);

    if ($storeId !== NULL) {
      $vendorRanking = array_filter($vendorRanking, fn($r) => $r['store_id'] === $storeId);
    }

    $filename = 'myeventlane-financial-export-' . date('Ymd') . '.csv';

    $response = new StreamedResponse(function () use ($vendorRanking, $payoutSummary) {
      $handle = fopen('php://output', 'w');
      if (!$handle) {
        return;
      }

      fputcsv($handle, [
        'Store ID',
        'Store',
        'Orders',
        'Gross',
        'Commission',
        'Net',
        'Unpaid Liability',
      ]);

      if (empty($vendorRanking)) {
        fputcsv($handle, ['', '', 0, '0.00', '0.00', '0.00', number_format($payoutSummary['unpaid_total'] ?? 0, 2)]);
      }
      else {
        foreach ($vendorRanking as $row) {
          fputcsv($handle, [
            $row['store_id'],
            $row['store_label'],
            $row['orders'],
            number_format($row['gross'], 2),
            number_format($row['commission'], 2),
            number_format($row['net'], 2),
            number_format($row['unpaid_liability'], 2),
          ]);
        }
      }

      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

}
