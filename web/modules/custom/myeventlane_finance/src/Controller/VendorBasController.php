<?php

declare(strict_types=1);

namespace Drupal\myeventlane_finance\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_finance\Service\BasCsvExportService;
use Drupal\myeventlane_finance\Service\BasPdfExportService;
use Drupal\myeventlane_finance\Service\BasReportService;
use Drupal\myeventlane_finance\ValueObject\DateRange;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for vendor BAS reporting page.
 */
final class VendorBasController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * The BAS report service.
   *
   * @var \Drupal\myeventlane_finance\Service\BasReportService
   */
  private readonly BasReportService $basReportService;

  /**
   * The BAS CSV export service.
   *
   * @var \Drupal\myeventlane_finance\Service\BasCsvExportService
   */
  private readonly BasCsvExportService $basCsvExportService;

  /**
   * The BAS PDF export service.
   *
   * @var \Drupal\myeventlane_finance\Service\BasPdfExportService
   */
  private readonly BasPdfExportService $basPdfExportService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private readonly EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs VendorBasController.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    BasReportService $basReportService,
    EntityTypeManagerInterface $entity_type_manager,
    BasCsvExportService $basCsvExportService,
    BasPdfExportService $basPdfExportService,
  ) {
    parent::__construct($domain_detector, $current_user);
    $this->basReportService = $basReportService;
    $this->entityTypeManager = $entity_type_manager;
    $this->basCsvExportService = $basCsvExportService;
    $this->basPdfExportService = $basPdfExportService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('myeventlane_finance.bas_report'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_finance.bas_csv_export'),
      $container->get('myeventlane_finance.bas_pdf_export'),
    );
  }

  /**
   * Renders the vendor BAS page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   A render array for the vendor BAS page.
   */
  public function bas(Request $request): array {
    // Get current vendor for the logged-in user.
    $vendor = $this->getCurrentUserVendor();

    if (!$vendor) {
      return [
        '#markup' => $this->t('No vendor account found. Please contact support.'),
        '#cache' => [
          'contexts' => ['user'],
        ],
      ];
    }

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

    // Get vendor-specific BAS summary.
    $summary = $this->basReportService->getVendorBasSummary((int) $vendor->id(), $dateRange);

    // Build the BAS page content.
    $basContent = [
      '#theme' => 'vendor_bas_page',
      '#summary' => $summary,
      '#vendor' => $vendor,
      '#start_date' => $startDate,
      '#end_date' => $endDate,
      '#attached' => [
        'library' => ['myeventlane_finance/bas'],
      ],
    ];

    // Build vendor console page with proper theme structure.
    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => 'BAS Report',
      'body' => $basContent,
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args'],
        'tags' => ['commerce_order_list', 'myeventlane_vendor:' . $vendor->id()],
        'max-age' => 300,
      ],
    ]);
  }

  /**
   * Gets the vendor entity for the current user.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  private function getCurrentUserVendor(): ?Vendor {
    $userId = (int) $this->currentUser->id();

    if ($userId === 0) {
      return NULL;
    }

    $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    
    // First, try to find vendor where user is the owner.
    $vendorIds = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();

    if (!empty($vendorIds)) {
      $vendor = $vendorStorage->load(reset($vendorIds));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    // If not found, check vendors where user is in field_vendor_users.
    $vendorIds = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_vendor_users', $userId)
      ->range(0, 1)
      ->execute();

    if (!empty($vendorIds)) {
      $vendor = $vendorStorage->load(reset($vendorIds));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    return NULL;
  }

  /**
   * Exports vendor BAS summary as CSV.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   CSV export response.
   */
  public function exportCsv(Request $request): StreamedResponse {
    $vendor = $this->getCurrentUserVendor();

    if (!$vendor) {
      throw new \RuntimeException('No vendor account found.');
    }

    $dateRange = $this->getDateRangeFromRequest($request);
    return $this->basCsvExportService->exportVendorBas((int) $vendor->id(), $dateRange);
  }

  /**
   * Exports vendor BAS summary as PDF.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   PDF export response.
   */
  public function exportPdf(Request $request): Response {
    $vendor = $this->getCurrentUserVendor();

    if (!$vendor) {
      throw new \RuntimeException('No vendor account found.');
    }

    $dateRange = $this->getDateRangeFromRequest($request);
    return $this->basPdfExportService->exportVendorBas((int) $vendor->id(), $dateRange);
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













