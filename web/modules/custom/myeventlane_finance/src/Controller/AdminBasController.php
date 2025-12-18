<?php

declare(strict_types=1);

namespace Drupal\myeventlane_finance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_finance\Service\BasCsvExportService;
use Drupal\myeventlane_finance\Service\BasPdfExportService;
use Drupal\myeventlane_finance\Service\BasReportService;
use Drupal\myeventlane_finance\ValueObject\DateRange;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for admin BAS reporting page.
 */
final class AdminBasController extends ControllerBase {

  /**
   * Constructs AdminBasController.
   */
  public function __construct(
    private readonly BasReportService $basReportService,
    private readonly BasCsvExportService $basCsvExportService,
    private readonly BasPdfExportService $basPdfExportService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_finance.bas_report'),
      $container->get('myeventlane_finance.bas_csv_export'),
      $container->get('myeventlane_finance.bas_pdf_export'),
    );
  }

  /**
   * Renders the admin BAS page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   A render array for the admin BAS page.
   */
  public function bas(Request $request): array {
    // Get period from query parameters or use default (current quarter).
    $startDate = $request->query->get('start');
    $endDate = $request->query->get('end');

    // Default to current quarter if not provided.
    if (!$startDate || !$endDate) {
      $now = new \DateTime();
      $quarter = (int) ceil($now->format('n') / 3);
      $startDate = $now->format('Y') . '-' . sprintf('%02d', ($quarter - 1) * 3 + 1) . '-01';
      $endDate = $now->format('Y') . '-' . sprintf('%02d', $quarter * 3) . '-' . sprintf('%02d', (int) $now->format('t'));
    }

    // Create date range.
    $startTimestamp = strtotime($startDate . ' 00:00:00');
    $endTimestamp = strtotime($endDate . ' 23:59:59');
    $dateRange = new DateRange($startTimestamp, $endTimestamp);

    // Get BAS summary.
    $summary = $this->basReportService->getAdminBasSummary($dateRange);

    return [
      '#theme' => 'admin_bas_page',
      '#summary' => $summary,
      '#start_date' => $startDate,
      '#end_date' => $endDate,
      '#attached' => [
        'library' => ['myeventlane_finance/bas'],
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args'],
        'tags' => ['commerce_order_list'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Exports admin BAS summary as CSV.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   CSV export response.
   */
  public function exportCsv(Request $request): StreamedResponse {
    $dateRange = $this->getDateRangeFromRequest($request);
    return $this->basCsvExportService->exportAdminBas($dateRange);
  }

  /**
   * Exports admin BAS summary as PDF.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   PDF export response.
   */
  public function exportPdf(Request $request): Response {
    $dateRange = $this->getDateRangeFromRequest($request);
    return $this->basPdfExportService->exportAdminBas($dateRange);
  }

  /**
   * Gets date range from request or defaults to current quarter.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Drupal\myeventlane_finance\ValueObject\DateRange
   *   Date range object.
   */
  private function getDateRangeFromRequest(Request $request): DateRange {
    $startDate = $request->query->get('start');
    $endDate = $request->query->get('end');

    // Default to current quarter if not provided.
    if (!$startDate || !$endDate) {
      $now = new \DateTime();
      $quarter = (int) ceil($now->format('n') / 3);
      $startDate = $now->format('Y') . '-' . sprintf('%02d', ($quarter - 1) * 3 + 1) . '-01';
      $endDate = $now->format('Y') . '-' . sprintf('%02d', $quarter * 3) . '-' . sprintf('%02d', (int) $now->format('t'));
    }

    $startTimestamp = strtotime($startDate . ' 00:00:00');
    $endTimestamp = strtotime($endDate . ' 23:59:59');

    return new DateRange($startTimestamp, $endTimestamp);
  }

}
