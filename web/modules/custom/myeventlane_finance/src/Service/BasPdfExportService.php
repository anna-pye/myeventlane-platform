<?php

declare(strict_types=1);

namespace Drupal\myeventlane_finance\Service;

use Dompdf\Dompdf;
use Drupal\myeventlane_finance\ValueObject\DateRange;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF Export Service for BAS summaries.
 *
 * Generates accountant-friendly PDF exports with proper formatting.
 */
final class BasPdfExportService {

  /**
   * Constructs BasPdfExportService.
   */
  public function __construct(
    private readonly BasReportService $basReportService,
  ) {}

  /**
   * Exports admin BAS summary as PDF.
   *
   * @param \Drupal\myeventlane_finance\ValueObject\DateRange $dateRange
   *   Date range for the report.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   PDF response.
   */
  public function exportAdminBas(DateRange $dateRange): Response {
    $summary = $this->basReportService->getAdminBasSummary($dateRange);

    $html = $this->buildPdfHtml('admin', $summary, $dateRange);

    $dompdf = new Dompdf(['isHtml5ParserEnabled' => TRUE]);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'bas-admin-' . date('Y-m-d', $dateRange->getStartTimestamp()) . '-to-' . date('Y-m-d', $dateRange->getEndTimestamp()) . '.pdf';

    return new Response(
      $dompdf->output(),
      200,
      [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      ]
    );
  }

  /**
   * Exports vendor BAS summary as PDF.
   *
   * @param int $vendorId
   *   The vendor entity ID.
   * @param \Drupal\myeventlane_finance\ValueObject\DateRange $dateRange
   *   Date range for the report.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   PDF response.
   */
  public function exportVendorBas(int $vendorId, DateRange $dateRange): Response {
    $summary = $this->basReportService->getVendorBasSummary($vendorId, $dateRange);

    $html = $this->buildPdfHtml('vendor', $summary, $dateRange, $vendorId);

    $dompdf = new Dompdf(['isHtml5ParserEnabled' => TRUE]);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'bas-vendor-' . $vendorId . '-' . date('Y-m-d', $dateRange->getStartTimestamp()) . '-to-' . date('Y-m-d', $dateRange->getEndTimestamp()) . '.pdf';

    return new Response(
      $dompdf->output(),
      200,
      [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      ]
    );
  }

  /**
   * Builds HTML content for PDF.
   *
   * @param string $type
   *   Export type: 'admin' or 'vendor'.
   * @param array $summary
   *   BAS summary array.
   * @param \Drupal\myeventlane_finance\ValueObject\DateRange $dateRange
   *   Date range.
   * @param int|null $vendorId
   *   Optional vendor ID for vendor exports.
   *
   * @return string
   *   HTML content for PDF.
   */
  private function buildPdfHtml(string $type, array $summary, DateRange $dateRange, ?int $vendorId = NULL): string {
    $title = 'Business Activity Statement (BAS) Summary';
    $period = $summary['period'];
    $generatedDate = date('Y-m-d H:i:s');

    $html = '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>' . htmlspecialchars($title) . '</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      font-size: 12px;
      margin: 40px;
      color: #333;
    }
    h1 {
      font-size: 20px;
      margin-bottom: 10px;
      color: #000;
    }
    .header-info {
      margin-bottom: 30px;
    }
    .header-info p {
      margin: 5px 0;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 30px;
    }
    table th,
    table td {
      border: 1px solid #ddd;
      padding: 10px;
      text-align: left;
    }
    table th {
      background-color: #f5f5f5;
      font-weight: bold;
    }
    table td {
      text-align: right;
    }
    table td:first-child {
      text-align: left;
    }
    .notes {
      margin-top: 30px;
      padding: 15px;
      background-color: #f9f9f9;
      border-left: 4px solid #666;
    }
    .notes h3 {
      margin-top: 0;
      font-size: 14px;
    }
    .notes ul {
      margin: 10px 0;
      padding-left: 20px;
    }
    .notes li {
      margin: 5px 0;
    }
    .footer {
      margin-top: 30px;
      font-size: 10px;
      color: #666;
      text-align: right;
    }
  </style>
</head>
<body>
  <h1>' . htmlspecialchars($title) . '</h1>
  
  <div class="header-info">
    <p><strong>Period:</strong> ' . htmlspecialchars($period) . '</p>';

    if ($type === 'vendor' && $vendorId !== NULL) {
      $html .= '<p><strong>Vendor ID:</strong> ' . htmlspecialchars((string) $vendorId) . '</p>';
    }

    $html .= '<p><strong>Generated:</strong> ' . htmlspecialchars($generatedDate) . '</p>
  </div>

  <table>
    <thead>
      <tr>
        <th>Item</th>
        <th>Amount (AUD)</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>G1 Total Sales</td>
        <td>$' . number_format($summary['g1_total_sales'], 2, '.', ',') . '</td>
      </tr>
      <tr>
        <td>1A GST on Sales</td>
        <td>$' . number_format($summary['gst_on_sales_1a'], 2, '.', ',') . '</td>
      </tr>';

    if ($type === 'admin') {
      $html .= '<tr>
        <td>1B GST on Purchases</td>
        <td>$' . number_format($summary['gst_on_purchases_1b'], 2, '.', ',') . '</td>
      </tr>';
    }

    $html .= '<tr>
        <td>GST Refunds</td>
        <td>$' . number_format($summary['gst_refunds'], 2, '.', ',') . '</td>
      </tr>
      <tr>
        <td><strong>Net GST</strong></td>
        <td><strong>$' . number_format($summary['net_gst'], 2, '.', ',') . '</strong></td>
      </tr>
    </tbody>
  </table>

  <div class="notes">
    <h3>Statement Notes</h3>
    <p>This BAS summary is generated from MyEventLane transactional data.</p>
    <p><strong>Included:</strong></p>
    <ul>
      <li>Ticket sales</li>
      <li>Donations where recorded</li>
      <li>Boost revenue</li>
      <li>Refunds</li>
    </ul>
    <p><strong>Excluded:</strong></p>
    <ul>
      <li>PAYG withholding</li>
      <li>Payroll taxes</li>
      <li>External expenses</li>
    </ul>
    <p><strong>For BAS preparation only.</strong></p>
  </div>

  <div class="footer">
    Generated on ' . htmlspecialchars($generatedDate) . '
  </div>
</body>
</html>';

    return $html;
  }

}
