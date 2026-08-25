<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Builds vendor + GST + line data for tax invoices (UI, email, PDF).
 *
 * Tax totals combine order-level and line-item Commerce adjustments (GST on
 * lines is common for tax-inclusive pricing).
 */
final class TaxInvoicePresentationBuilder {

  public function __construct(
    private readonly CurrencyFormatterInterface $currencyFormatter,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly OrderPricingBreakdownBuilder $orderPricingBreakdown,
    private readonly SellerIdentityResolver $sellerIdentity,
    private readonly PlatformFeeTaxSnapshotResolver $platformFeeTax,
  ) {}

  /**
   * Builds presentation variables from the order store and adjustments.
   *
   * @return array{
   *   document_title: string,
   *   is_tax_invoice: bool,
   *   vendor_name: string,
   *   vendor_abn: string,
   *   invoice_lines: array<int, array<string, int|string>>,
   *   invoice_lines_include_gst_column: bool,
   *   fee_lines: array<int, array{label: string, amount: string}>,
   *   platform_name: string,
   *   platform_abn: string,
   *   platform_fee_lines: array<int, array<string, bool|string>>,
   *   platform_total_gst: string,
   *   tax_lines: array<int, array{label: string, amount: string}>,
   *   order_total_gst: string,
   *   order_total: string,
   *   invoice_date_display: string,
   *   }
   */
  public function build(OrderInterface $order): array {
    $seller = $this->sellerIdentity->resolve($order);
    $vendor_name = $seller['seller_name'];
    $vendor_abn = $seller['seller_abn'];

    $invoice_lines = [];
    $invoice_lines_include_gst_column = $order->getItems() !== [];
    $orderCurrency = $order->getTotalPrice()?->getCurrencyCode() ?: 'AUD';
    foreach ($order->getItems() as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }
      $unit = $item->getUnitPrice();
      $total = $item->getTotalPrice();
      $line_gst = $this->sumLineItemTax($item);
      $gst_formatted = $this->formatPrice(
        $line_gst ?? new Price('0', $unit?->getCurrencyCode() ?: $orderCurrency),
      );
      $invoice_lines[] = [
        'title' => $item->label(),
        'quantity' => (int) $item->getQuantity(),
        'unit_price' => $this->formatPrice($unit),
        'line_total' => $this->formatPrice($total),
        'gst' => $gst_formatted,
      ];
    }

    // Organiser-owned order adjustments, including contributions, must remain
    // visible. MEL fee lines are deliberately separated below because MEL is
    // the supplier for those amounts and carries its own GST evidence.
    $fee_lines = [];
    foreach ($order->getAdjustments() as $adjustment) {
      $amount = $adjustment->getAmount();
      if (!$amount || (float) $amount->getNumber() == 0.0) {
        continue;
      }
      if ($adjustment->getType() === 'tax'
        || PlatformFeeTaxSnapshotResolver::isPlatformFeeSource(
          $adjustment->getSourceId(),
        )) {
        continue;
      }
      $label = trim((string) $adjustment->getLabel());
      if ($label === '') {
        $label = 'Order adjustment';
      }
      $fee_lines[] = [
        'label' => $label,
        'amount' => $this->formatPrice($amount),
      ];
    }

    $aggregated = $this->orderPricingBreakdown->buildAggregatedTaxInvoiceRows($order);
    $tax_lines = [];
    foreach ($aggregated['tax_rows'] as $row) {
      $tax_lines[] = [
        'label' => $row['label'],
        'amount' => $row['amount_formatted'],
      ];
    }
    // Keep this empty when the order has no organiser tax adjustment. A
    // formatted zero is truthy in Twig and would falsely imply that organiser
    // GST was recorded on otherwise untaxed organiser charges.
    $order_total_gst = $aggregated['total_formatted'];

    $platform = $this->platformFeeTax->resolve($order);
    $platform_fee_lines = [];
    $platformTotalGst = NULL;
    foreach ($platform['fee_lines'] ?? [] as $line) {
      if (!is_array($line)) {
        continue;
      }
      $currency = (string) ($line['currency_code'] ?? $orderCurrency);
      $amount = new Price((string) ($line['amount_number'] ?? '0'), $currency);
      $gst = new Price((string) ($line['gst_number'] ?? '0'), $currency);
      $platform_fee_lines[] = [
        'label' => (string) ($line['label'] ?? 'MEL platform fee'),
        'amount' => $this->formatPrice($amount),
        'gst' => $this->formatPrice($gst),
        'gst_inclusive' => (bool) ($line['gst_inclusive'] ?? FALSE),
      ];
      $platformTotalGst = $platformTotalGst instanceof Price
        ? $platformTotalGst->add($gst)
        : $gst;
    }
    $platform_total_gst = $this->formatPrice(
      $platformTotalGst ?? new Price('0', $orderCurrency),
    );
    // Historical documents must reflect tax recorded on this order. The
    // organiser's current GST registration can change after the sale.
    $isTaxInvoice = $aggregated['tax_rows'] !== []
      || ($platformTotalGst instanceof Price && !$platformTotalGst->isZero());

    $total_price = $order->getTotalPrice();
    $order_total = $this->formatPrice($total_price);

    $placed = $order->getPlacedTime();
    $invoice_date_display = $placed
      ? $this->dateFormatter->format((int) $placed, 'custom', 'd M Y')
      : '';

    return [
      'document_title' => $isTaxInvoice ? 'Tax invoice and receipt' : 'Receipt',
      'is_tax_invoice' => $isTaxInvoice,
      'vendor_name' => $vendor_name,
      'vendor_abn' => $vendor_abn,
      'invoice_lines' => $invoice_lines,
      'invoice_lines_include_gst_column' => $invoice_lines_include_gst_column,
      'fee_lines' => $fee_lines,
      'platform_name' => (string) ($platform['platform_name'] ?? ''),
      'platform_abn' => (string) ($platform['platform_abn'] ?? ''),
      'platform_fee_lines' => $platform_fee_lines,
      'platform_total_gst' => $platform_total_gst,
      'tax_lines' => $isTaxInvoice ? $tax_lines : [],
      'order_total_gst' => $order_total_gst,
      'order_total' => $order_total,
      'invoice_date_display' => $invoice_date_display,
    ];
  }

  /**
   * Sums tax adjustments recorded on an order item.
   */
  private function sumLineItemTax(OrderItemInterface $item): ?Price {
    $sum = NULL;
    foreach ($item->getAdjustments() as $adjustment) {
      if ($adjustment->getType() !== 'tax') {
        continue;
      }
      $amount = $adjustment->getAmount();
      if (!$amount instanceof Price || $amount->isZero()) {
        continue;
      }
      $sum = $sum instanceof Price ? $sum->add($amount) : $amount;
    }
    return $sum;
  }

  /**
   * Formats a Commerce price for buyer presentation.
   */
  private function formatPrice(?Price $price): string {
    if (!$price) {
      return '';
    }
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}
