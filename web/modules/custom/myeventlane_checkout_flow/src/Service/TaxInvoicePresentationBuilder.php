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
  ) {}

  /**
   * Builds presentation variables from the order store and adjustments.
   *
   * @return array{
   *   document_title: string,
   *   is_tax_invoice: bool,
   *   vendor_name: string,
   *   vendor_abn: string,
   *   invoice_lines: array<int, array{title: string, quantity: int, unit_price: string, line_total: string, gst: string}>,
   *   invoice_lines_include_gst_column: bool,
   *   fee_lines: array<int, array{label: string, amount: string}>,
   *   tax_lines: array<int, array{label: string, amount: string}>,
   *   order_total_gst: string,
   *   order_total: string,
   *   invoice_date_display: string,
   * }
   */
  public function build(OrderInterface $order): array {
    $seller = $this->sellerIdentity->resolve($order);
    $vendor_name = $seller['seller_name'];
    $vendor_abn = $seller['seller_abn'];

    $invoice_lines = [];
    $invoice_lines_include_gst_column = FALSE;
    foreach ($order->getItems() as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }
      $unit = $item->getUnitPrice();
      $total = $item->getTotalPrice();
      $line_gst = $this->sumLineItemTax($item);
      $gst_formatted = $line_gst instanceof Price ? $this->formatPrice($line_gst) : '';
      if ($gst_formatted !== '') {
        $invoice_lines_include_gst_column = TRUE;
      }
      $invoice_lines[] = [
        'title' => $item->label(),
        'quantity' => (int) $item->getQuantity(),
        'unit_price' => $this->formatPrice($unit),
        'line_total' => $this->formatPrice($total),
        'gst' => $gst_formatted,
      ];
    }

    $fee_lines = [];
    foreach ($order->getAdjustments() as $adjustment) {
      $amount = $adjustment->getAmount();
      if (!$amount || (float) $amount->getNumber() == 0.0) {
        continue;
      }
      $label = trim((string) $adjustment->getLabel());
      $type = $adjustment->getType();
      if ($type === 'fee') {
        if ($label === '') {
          $label = 'Fee';
        }
        $fee_lines[] = [
          'label' => $label,
          'amount' => $this->formatPrice($amount),
        ];
      }
    }

    $aggregated = $this->orderPricingBreakdown->buildAggregatedTaxInvoiceRows($order);
    $tax_lines = [];
    foreach ($aggregated['tax_rows'] as $row) {
      $tax_lines[] = [
        'label' => $row['label'],
        'amount' => $row['amount_formatted'],
      ];
    }
    $order_total_gst = $aggregated['total_formatted'];
    // Historical documents must reflect tax recorded on this order. The
    // organiser's current GST registration can change after the sale.
    $isTaxInvoice = $aggregated['tax_rows'] !== [];

    $total_price = $order->getTotalPrice();
    $order_total = $this->formatPrice($total_price);

    $placed = $order->getPlacedTime();
    $invoice_date_display = $placed
      ? $this->dateFormatter->format((int) $placed, 'custom', 'd M Y')
      : '';

    return [
      'document_title' => $isTaxInvoice ? 'Tax invoice' : 'Receipt',
      'is_tax_invoice' => $isTaxInvoice,
      'vendor_name' => $vendor_name,
      'vendor_abn' => $vendor_abn,
      'invoice_lines' => $invoice_lines,
      'invoice_lines_include_gst_column' => $invoice_lines_include_gst_column,
      'fee_lines' => $fee_lines,
      'tax_lines' => $isTaxInvoice ? $tax_lines : [],
      'order_total_gst' => $isTaxInvoice ? $order_total_gst : '',
      'order_total' => $order_total,
      'invoice_date_display' => $invoice_date_display,
    ];
  }

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

  private function formatPrice(?Price $price): string {
    if (!$price) {
      return '';
    }
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}
