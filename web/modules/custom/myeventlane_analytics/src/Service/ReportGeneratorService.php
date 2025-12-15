<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Report generator service.
 *
 * Generates PDF and enhanced CSV reports.
 */
final class ReportGeneratorService {

  /**
   * Constructs ReportGeneratorService.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
    private readonly AnalyticsDataService $dataService,
  ) {}

  /**
   * Generates a comprehensive analytics report for an event.
   *
   * @param int $eventId
   *   The event node ID.
   * @param int|null $startDate
   *   Start date timestamp (optional).
   * @param int|null $endDate
   *   End date timestamp (optional).
   *
   * @return array
   *   Render array for the report.
   */
  public function generateEventReport(int $eventId, ?int $startDate = NULL, ?int $endDate = NULL): array {
    $eventNode = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$eventNode) {
      return [];
    }

    $timeSeries = $this->dataService->getSalesTimeSeries($eventId, 'day', $startDate, $endDate);
    $ticketBreakdown = $this->dataService->getTicketTypeBreakdown($eventId);
    $conversionFunnel = $this->dataService->getConversionFunnel($eventId);

    // Calculate totals.
    $totalRevenue = 0.0;
    $totalTickets = 0;
    foreach ($timeSeries as $point) {
      $totalRevenue += $point['revenue'];
      $totalTickets += $point['ticket_count'];
    }

    $build = [
      '#theme' => 'myeventlane_analytics_report',
      '#event' => $eventNode,
      '#time_series' => $timeSeries,
      '#ticket_breakdown' => $ticketBreakdown,
      '#conversion_funnel' => $conversionFunnel,
      '#total_revenue' => $totalRevenue,
      '#total_tickets' => $totalTickets,
      '#start_date' => $startDate ? date('Y-m-d', $startDate) : NULL,
      '#end_date' => $endDate ? date('Y-m-d', $endDate) : NULL,
    ];

    return $build;
  }

  /**
   * Generates a PDF report for an event.
   *
   * @param int $eventId
   *   The event node ID.
   * @param int|null $startDate
   *   Start date timestamp (optional).
   * @param int|null $endDate
   *   End date timestamp (optional).
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   PDF response.
   */
  public function generatePdfReport(int $eventId, ?int $startDate = NULL, ?int $endDate = NULL): Response {
    $eventNode = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$eventNode) {
      throw new \InvalidArgumentException('Event not found.');
    }

    // Get report data.
    $timeSeries = $this->dataService->getSalesTimeSeries($eventId, 'day', $startDate, $endDate);
    $ticketBreakdown = $this->dataService->getTicketTypeBreakdown($eventId);
    $conversionFunnel = $this->dataService->getConversionFunnel($eventId);

    // Calculate totals.
    $totalRevenue = 0.0;
    $totalTickets = 0;
    foreach ($timeSeries as $point) {
      $totalRevenue += $point['revenue'];
      $totalTickets += $point['ticket_count'];
    }

    // Build HTML for PDF.
    $html = $this->buildPdfHtml($eventNode, $timeSeries, $ticketBreakdown, $conversionFunnel, $totalRevenue, $totalTickets);

    // Generate PDF using dompdf.
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfContent = $dompdf->output();

    $response = new Response($pdfContent);
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      sprintf('analytics-report-%s-%s.pdf', $eventId, date('Y-m-d'))
    ));

    return $response;
  }

  /**
   * Generates an Excel report for an event.
   *
   * @param int $eventId
   *   The event node ID.
   * @param int|null $startDate
   *   Start date timestamp (optional).
   * @param int|null $endDate
   *   End date timestamp (optional).
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Excel response.
   */
  public function generateExcelReport(int $eventId, ?int $startDate = NULL, ?int $endDate = NULL): Response {
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
      throw new \RuntimeException('PhpSpreadsheet library not available. Install with: composer require phpoffice/phpspreadsheet');
    }

    $eventNode = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$eventNode) {
      throw new \InvalidArgumentException('Event not found.');
    }

    // Get report data.
    $timeSeries = $this->dataService->getSalesTimeSeries($eventId, 'day', $startDate, $endDate);
    $ticketBreakdown = $this->dataService->getTicketTypeBreakdown($eventId);
    $conversionFunnel = $this->dataService->getConversionFunnel($eventId);

    // Calculate totals.
    $totalRevenue = 0.0;
    $totalTickets = 0;
    foreach ($timeSeries as $point) {
      $totalRevenue += $point['revenue'];
      $totalTickets += $point['ticket_count'];
    }

    // Create spreadsheet.
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Analytics Report');

    // Header.
    $sheet->setCellValue('A1', 'Event Analytics Report');
    $sheet->setCellValue('A2', $eventNode->label());
    $sheet->setCellValue('A3', 'Generated: ' . date('Y-m-d H:i:s'));

    // Summary.
    $row = 5;
    $sheet->setCellValue('A' . $row, 'Total Revenue');
    $sheet->setCellValue('B' . $row, '$' . number_format($totalRevenue, 2));
    $row++;
    $sheet->setCellValue('A' . $row, 'Total Tickets');
    $sheet->setCellValue('B' . $row, $totalTickets);

    // Time series data.
    $row += 3;
    $sheet->setCellValue('A' . $row, 'Date');
    $sheet->setCellValue('B' . $row, 'Revenue');
    $sheet->setCellValue('C' . $row, 'Tickets Sold');
    $row++;
    foreach ($timeSeries as $point) {
      $sheet->setCellValue('A' . $row, $point['date']);
      $sheet->setCellValue('B' . $row, $point['revenue']);
      $sheet->setCellValue('C' . $row, $point['ticket_count']);
      $row++;
    }

    // Ticket breakdown.
    $row += 2;
    $sheet->setCellValue('A' . $row, 'Ticket Type');
    $sheet->setCellValue('B' . $row, 'Sold');
    $sheet->setCellValue('C' . $row, 'Revenue');
    $row++;
    foreach ($ticketBreakdown as $ticket) {
      $sheet->setCellValue('A' . $row, $ticket['ticket_type']);
      $sheet->setCellValue('B' . $row, $ticket['sold']);
      $sheet->setCellValue('C' . $row, $ticket['revenue']);
      $row++;
    }

    // Write to temporary file.
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $tempFile = tempnam(sys_get_temp_dir(), 'analytics_');
    $writer->save($tempFile);

    $content = file_get_contents($tempFile);
    unlink($tempFile);

    $response = new Response($content);
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      sprintf('analytics-report-%s-%s.xlsx', $eventId, date('Y-m-d'))
    ));

    return $response;
  }

  /**
   * Builds HTML content for PDF generation.
   *
   * @param object $eventNode
   *   The event node.
   * @param array $timeSeries
   *   Time series data.
   * @param array $ticketBreakdown
   *   Ticket breakdown data.
   * @param array $conversionFunnel
   *   Conversion funnel data.
   * @param float $totalRevenue
   *   Total revenue.
   * @param int $totalTickets
   *   Total tickets.
   *
   * @return string
   *   HTML content.
   */
  private function buildPdfHtml(
    object $eventNode,
    array $timeSeries,
    array $ticketBreakdown,
    array $conversionFunnel,
    float $totalRevenue,
    int $totalTickets
  ): string {
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
      body { font-family: Arial, sans-serif; padding: 20px; }
      h1 { color: #333; }
      table { width: 100%; border-collapse: collapse; margin: 20px 0; }
      th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
      th { background-color: #f5f5f5; font-weight: bold; }
    </style></head><body>';
    
    $html .= '<h1>Analytics Report: ' . htmlspecialchars($eventNode->label()) . '</h1>';
    $html .= '<p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>';
    
    $html .= '<h2>Summary</h2>';
    $html .= '<p><strong>Total Revenue:</strong> $' . number_format($totalRevenue, 2) . '</p>';
    $html .= '<p><strong>Total Tickets:</strong> ' . $totalTickets . '</p>';
    
    if (!empty($timeSeries)) {
      $html .= '<h2>Sales Over Time</h2>';
      $html .= '<table><thead><tr><th>Date</th><th>Revenue</th><th>Tickets Sold</th></tr></thead><tbody>';
      foreach ($timeSeries as $point) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($point['date']) . '</td>';
        $html .= '<td>$' . number_format($point['revenue'], 2) . '</td>';
        $html .= '<td>' . $point['ticket_count'] . '</td>';
        $html .= '</tr>';
      }
      $html .= '</tbody></table>';
    }
    
    if (!empty($ticketBreakdown)) {
      $html .= '<h2>Ticket Type Breakdown</h2>';
      $html .= '<table><thead><tr><th>Ticket Type</th><th>Sold</th><th>Revenue</th></tr></thead><tbody>';
      foreach ($ticketBreakdown as $ticket) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($ticket['ticket_type']) . '</td>';
        $html .= '<td>' . $ticket['sold'] . '</td>';
        $html .= '<td>$' . number_format($ticket['revenue'], 2) . '</td>';
        $html .= '</tr>';
      }
      $html .= '</tbody></table>';
    }
    
    if (!empty($conversionFunnel)) {
      $html .= '<h2>Conversion Funnel</h2>';
      $html .= '<table><thead><tr><th>Stage</th><th>Count</th></tr></thead><tbody>';
      $html .= '<tr><td>Event Views</td><td>' . ($conversionFunnel['views'] ?? 0) . '</td></tr>';
      $html .= '<tr><td>Added to Cart</td><td>' . ($conversionFunnel['cart_additions'] ?? 0) . '</td></tr>';
      $html .= '<tr><td>Checkout Started</td><td>' . ($conversionFunnel['checkout_started'] ?? 0) . '</td></tr>';
      $html .= '<tr><td>Completed</td><td>' . ($conversionFunnel['completed'] ?? 0) . '</td></tr>';
      $html .= '</tbody></table>';
    }
    
    $html .= '</body></html>';
    
    return $html;
  }

}






