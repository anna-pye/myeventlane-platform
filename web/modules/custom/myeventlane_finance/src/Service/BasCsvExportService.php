<?php

declare(strict_types=1);

namespace Drupal\myeventlane_finance\Service;

use Drupal\myeventlane_finance\ValueObject\DateRange;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV Export Service for BAS summaries.
 *
 * Generates accountant-friendly CSV exports with plain numbers,
 * no formulas, and no assumptions.
 */
final class BasCsvExportService {

  /**
   * Constructs BasCsvExportService.
   */
  public function __construct(
    private readonly BasReportService $basReportService,
  ) {}

  /**
   * Exports admin BAS summary as CSV.
   *
   * @param \Drupal\myeventlane_finance\ValueObject\DateRange $dateRange
   *   Date range for the report.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   Streamed CSV response.
   */
  public function exportAdminBas(DateRange $dateRange): StreamedResponse {
    $summary = $this->basReportService->getAdminBasSummary($dateRange);

    $response = new StreamedResponse();
    $response->setCallback(function () use ($summary) {
      $handle = fopen('php://output', 'w');

      // CSV headers for admin export.
      fputcsv($handle, [
        'Period',
        'G1_Total_Sales',
        'GST_on_Sales_1A',
        'GST_on_Purchases_1B',
        'GST_Refunds',
        'Net_GST',
        'Notes',
      ]);

      // CSV row with plain numbers (no formulas).
      fputcsv($handle, [
        $summary['period'],
        number_format($summary['g1_total_sales'], 2, '.', ''),
        number_format($summary['gst_on_sales_1a'], 2, '.', ''),
        number_format($summary['gst_on_purchases_1b'], 2, '.', ''),
        number_format($summary['gst_refunds'], 2, '.', ''),
        number_format($summary['net_gst'], 2, '.', ''),
        'Generated from MyEventLane transactional data',
      ]);

      fclose($handle);
    });

    $filename = 'bas-admin-' . date('Y-m-d', $dateRange->getStartTimestamp()) . '-to-' . date('Y-m-d', $dateRange->getEndTimestamp()) . '.csv';
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

  /**
   * Exports vendor BAS summary as CSV.
   *
   * @param int $vendorId
   *   The vendor entity ID.
   * @param \Drupal\myeventlane_finance\ValueObject\DateRange $dateRange
   *   Date range for the report.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   Streamed CSV response.
   */
  public function exportVendorBas(int $vendorId, DateRange $dateRange): StreamedResponse {
    $summary = $this->basReportService->getVendorBasSummary($vendorId, $dateRange);

    $response = new StreamedResponse();
    $response->setCallback(function () use ($summary, $vendorId) {
      $handle = fopen('php://output', 'w');

      // CSV headers for vendor export.
      fputcsv($handle, [
        'Period',
        'Vendor_ID',
        'G1_Total_Sales',
        'GST_on_Sales_1A',
        'GST_Refunds',
        'Net_GST',
        'Notes',
      ]);

      // CSV row with plain numbers (no formulas).
      fputcsv($handle, [
        $summary['period'],
        (string) $vendorId,
        number_format($summary['g1_total_sales'], 2, '.', ''),
        number_format($summary['gst_on_sales_1a'], 2, '.', ''),
        number_format($summary['gst_refunds'], 2, '.', ''),
        number_format($summary['net_gst'], 2, '.', ''),
        'Generated from MyEventLane transactional data',
      ]);

      fclose($handle);
    });

    $filename = 'bas-vendor-' . $vendorId . '-' . date('Y-m-d', $dateRange->getStartTimestamp()) . '-to-' . date('Y-m-d', $dateRange->getEndTimestamp()) . '.csv';
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

}













