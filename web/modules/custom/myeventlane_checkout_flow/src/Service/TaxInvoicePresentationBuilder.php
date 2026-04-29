<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Builds vendor + GST + line data for tax invoices (UI, email, PDF).
 *
 * Uses Commerce order adjustments for GST/tax totals — no hardcoded rates.
 */
final class TaxInvoicePresentationBuilder {

  public function __construct(
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Builds presentation variables from the order store and adjustments.
   *
   * @return array{
   *   vendor_name: string,
   *   vendor_abn: string,
   *   invoice_lines: array<int, array{title: string, quantity: int, unit_price: string, line_total: string}>,
   *   fee_lines: array<int, array{label: string, amount: string}>,
   *   tax_lines: array<int, array{label: string, amount: string}>,
   *   order_total_gst: string,
   *   order_total: string,
   *   invoice_date_display: string,
   * }
   */
  public function build(OrderInterface $order): array {
    $store = $order->getStore();
    $vendor_name = $store ? $store->label() : '';
    $vendor_abn = '';
    if ($store && $store->hasField('field_abn') && !$store->get('field_abn')->isEmpty()) {
      $vendor_abn = trim((string) $store->get('field_abn')->value);
    }

    $invoice_lines = [];
    foreach ($order->getItems() as $item) {
      $unit = $item->getUnitPrice();
      $total = $item->getTotalPrice();
      $invoice_lines[] = [
        'title' => $item->label(),
        'quantity' => (int) $item->getQuantity(),
        'unit_price' => $this->formatPrice($unit),
        'line_total' => $this->formatPrice($total),
      ];
    }

    $fee_lines = [];
    $tax_lines = [];
    $gst_accumulator = NULL;

    foreach ($order->getAdjustments() as $adjustment) {
      $amount = $adjustment->getAmount();
      if (!$amount || (float) $amount->getNumber() == 0.0) {
        continue;
      }
      $label = trim((string) $adjustment->getLabel());
      $type = $adjustment->getType();
      if ($type === 'tax') {
        if ($label === '') {
          $label = 'GST';
        }
        $tax_lines[] = [
          'label' => $label,
          'amount' => $this->formatPrice($amount),
        ];
        $gst_accumulator = $gst_accumulator instanceof Price ? $gst_accumulator->add($amount) : $amount;
      }
      elseif ($type === 'fee') {
        if ($label === '') {
          $label = 'Fee';
        }
        $fee_lines[] = [
          'label' => $label,
          'amount' => $this->formatPrice($amount),
        ];
      }
    }

    $order_total_gst = $gst_accumulator instanceof Price ? $this->formatPrice($gst_accumulator) : '';

    $total_price = $order->getTotalPrice();
    $order_total = $this->formatPrice($total_price);

    $placed = $order->getPlacedTime();
    $invoice_date_display = $placed
      ? $this->dateFormatter->format((int) $placed, 'custom', 'd M Y')
      : '';

    return [
      'vendor_name' => $vendor_name,
      'vendor_abn' => $vendor_abn,
      'invoice_lines' => $invoice_lines,
      'fee_lines' => $fee_lines,
      'tax_lines' => $tax_lines,
      'order_total_gst' => $order_total_gst,
      'order_total' => $order_total,
      'invoice_date_display' => $invoice_date_display,
    ];
  }

  private function formatPrice(?Price $price): string {
    if (!$price) {
      return '';
    }
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}
