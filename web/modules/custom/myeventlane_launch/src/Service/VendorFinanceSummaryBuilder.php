<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_domain_events\ProjectionReadModel\FinanceReadModel;
use Drupal\myeventlane_domain_events\ProjectionReadModel\VendorMetricsReadModel;
use Drupal\myeventlane_commerce\Service\StripeConnectPaymentService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolver;
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Psr\Log\LoggerInterface;

/**
 * Builds vendor finance summary by orchestrating existing MEL services.
 */
final class VendorFinanceSummaryBuilder {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrentVendorResolver $currentVendorResolver,
    private readonly TicketSalesService $ticketSalesService,
    private readonly MetricsAggregator $metricsAggregator,
    private readonly StripeConnectPaymentService $stripeConnectPaymentService,
    private readonly FinanceReadModel $financeReadModel,
    private readonly VendorMetricsReadModel $vendorMetricsReadModel,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns finance summary for current vendor user.
   *
   * @return array<string, mixed>
   *   Summary values for dashboard rendering.
   */
  public function buildCurrentVendorSummary(): array {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return $this->emptySummary();
    }

    $vendor = $this->currentVendorResolver->resolveFromCurrentUser();
    if (!$vendor instanceof Vendor) {
      return $this->emptySummary();
    }

    $eventIds = $this->getVendorEventIds($vendor);
    $finance = $this->buildVendorFinanceTotals((int) $vendor->id(), $eventIds);
    $vendorMetrics = $this->vendorMetricsReadModel->getVendorMetrics((int) $vendor->id());

    $tickets = (int) ($vendorMetrics['tickets_sold'] ?? 0);
    if ($tickets === 0 && $eventIds !== []) {
      // Align with finance totals: same published-event scope for this vendor org.
      $revenue = $this->ticketSalesService->getVendorRevenueSummaryForPublishedEventIds($eventIds);
      $tickets = (int) ($revenue['tickets'] ?? 0);
    }
    if ($tickets === 0) {
      $kpis = $this->metricsAggregator->getVendorKpis($uid);
      if (!empty($kpis[3]['value'])) {
        $tickets = (int) $kpis[3]['value'];
      }
    }

    $gross = (float) ($finance['gross_revenue'] ?? 0.0);
    $melFees = (float) ($finance['mel_fee'] ?? 0.0);
    $stripeFees = (float) ($finance['stripe_fee'] ?? 0.0);
    $netPayout = (float) ($finance['net_revenue'] ?? 0.0);

    return [
      'tickets_sold' => $tickets,
      'gross_revenue' => $gross,
      'mel_platform_fee' => $melFees,
      'stripe_processing_fee' => $stripeFees,
      'net_payout' => $netPayout,
      'gross_revenue_formatted' => $this->formatCurrency($gross),
      'mel_platform_fee_formatted' => $this->formatCurrency($melFees),
      'stripe_processing_fee_formatted' => $this->formatCurrency($stripeFees),
      'net_payout_formatted' => $this->formatCurrency($netPayout),
      'vendor_name' => $vendor->label(),
    ];
  }

  /**
   * Aggregates per-event finance read model rows to vendor summary totals.
   *
   * @param int $vendorId
   *   Vendor entity ID.
   * @param int[] $eventIds
   *   Event IDs owned by the vendor.
   *
   * @return array<string, float>
   *   Aggregate finance totals.
   */
  private function buildVendorFinanceTotals(int $vendorId, array $eventIds): array {
    $totals = [
      'gross_revenue' => 0.0,
      'mel_fee' => 0.0,
      'stripe_fee' => 0.0,
      'net_revenue' => 0.0,
    ];

    if ($vendorId <= 0 || $eventIds === []) {
      return $totals;
    }

    foreach ($eventIds as $eventId) {
      $eventFinance = $this->financeReadModel->getEventFinance($vendorId, (int) $eventId);
      $totals['gross_revenue'] += (float) ($eventFinance['gross_revenue'] ?? 0.0);
      $totals['mel_fee'] += (float) ($eventFinance['mel_fee'] ?? 0.0);
      $totals['stripe_fee'] += (float) ($eventFinance['stripe_fee'] ?? 0.0);
      $totals['net_revenue'] += (float) ($eventFinance['net_revenue'] ?? 0.0);
    }

    $totals['gross_revenue'] = round($totals['gross_revenue'], 2);
    $totals['mel_fee'] = round($totals['mel_fee'], 2);
    $totals['stripe_fee'] = round($totals['stripe_fee'], 2);
    $totals['net_revenue'] = round($totals['net_revenue'], 2);

    return $totals;
  }

  /**
   * Sums processing fees by reusing Stripe Connect fee service logic.
   */
  private function sumStripeProcessingFeesForVendor(Vendor $vendor): float {
    $eventIds = $this->getVendorEventIds($vendor);
    if ($eventIds === []) {
      return 0.0;
    }

    $totalFeesCents = 0;
    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventIds,
      ]);

      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
      $processed = [];
      foreach ($orderItems as $item) {
        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }
        $orderId = (int) $item->get('order_id')->target_id;
        if ($orderId <= 0 || isset($processed[$orderId])) {
          continue;
        }
        $processed[$orderId] = TRUE;

        $order = $orderStorage->load($orderId);
        if (!$order instanceof OrderInterface || $order->getState()->getId() !== 'completed') {
          continue;
        }

        $totalFeesCents += $this->stripeConnectPaymentService->calculateApplicationFee($order);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed building Stripe fee summary for vendor @vendor_id: @message', [
        '@vendor_id' => (int) $vendor->id(),
        '@message' => $e->getMessage(),
      ]);
      return 0.0;
    }

    return round($totalFeesCents / 100, 2);
  }

  /**
   * Resolves published event IDs for this vendor org (owner-authored or linked).
   *
   * Matches managed-event semantics: includes events tied via field_event_vendor,
   * not only node authorship (team-authored events).
   *
   * @return int[]
   *   Event IDs.
   */
  private function getVendorEventIds(Vendor $vendor): array {
    try {
      $vendorId = (int) $vendor->id();
      $ownerUid = (int) $vendor->getOwnerId();
      if ($vendorId <= 0) {
        return [];
      }

      $storage = $this->entityTypeManager->getStorage('node');
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('status', 1);
      $or = $query->orConditionGroup();
      if ($ownerUid > 0) {
        $or->condition('uid', $ownerUid);
      }
      $or->condition('field_event_vendor', $vendorId);
      $query->condition($or);

      $ids = $query->execute();

      return array_values(array_map('intval', $ids));
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed loading vendor event IDs for finance summary: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Parses a currency string into float.
   */
  private function extractCurrencyAmount(string $value): float {
    $numeric = preg_replace('/[^0-9.\-]/', '', $value);
    return is_numeric($numeric) ? (float) $numeric : 0.0;
  }

  /**
   * Formats a float as AUD currency.
   */
  private function formatCurrency(float $value): string {
    return '$' . number_format($value, 2);
  }

  /**
   * Empty summary fallback.
   *
   * @return array<string, mixed>
   *   Empty row.
   */
  private function emptySummary(): array {
    return [
      'tickets_sold' => 0,
      'gross_revenue' => 0.0,
      'mel_platform_fee' => 0.0,
      'stripe_processing_fee' => 0.0,
      'net_payout' => 0.0,
      'gross_revenue_formatted' => '$0.00',
      'mel_platform_fee_formatted' => '$0.00',
      'stripe_processing_fee_formatted' => '$0.00',
      'net_payout_formatted' => '$0.00',
      'vendor_name' => '',
    ];
  }

}
