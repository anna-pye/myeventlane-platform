<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Records an immutable tax adjustment snapshot after a confirmed refund.
 */
final class RefundAdjustmentNoteService {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates the adjustment snapshot once and returns the canonical log row.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order whose confirmed payment was refunded.
   * @param array<string, mixed> $refundLog
   *   The confirmed refund log row.
   *
   * @return array<string, mixed>
   *   The canonical persisted refund log row.
   */
  public function record(OrderInterface $order, array $refundLog): array {
    $logId = (int) ($refundLog['id'] ?? 0);
    if ($logId <= 0 || (string) ($refundLog['status'] ?? '') !== RefundProcessor::STATUS_COMPLETED) {
      return $refundLog;
    }
    if (!empty($refundLog['adjustment_note_number'])) {
      return $refundLog;
    }

    $store = $order->getStore();
    $supplierName = $store ? trim((string) $store->label()) : '';
    $supplierAbn = '';
    if ($store && $store->hasField('field_abn') && !$store->get('field_abn')->isEmpty()) {
      $supplierAbn = preg_replace('/\D+/', '', (string) $store->get('field_abn')->value) ?? '';
    }

    $refundedCents = max(0, (int) ($refundLog['actual_refunded_cents'] ?? $refundLog['amount_cents'] ?? 0));
    $orderDonationCents = 0;
    if ($order->hasField('field_mel_donation') && !$order->get('field_mel_donation')->isEmpty()) {
      $orderDonationCents = (int) round(((float) $order->get('field_mel_donation')->value) * 100);
    }
    $refundedDonationCents = !empty($refundLog['donation_refunded'])
      ? min($orderDonationCents, $refundedCents)
      : 0;
    $orderTaxCents = $this->sumRecordedTaxCents($order);
    // Untaxed sales receive the ordinary refund confirmation only. They must
    // not be represented as GST adjustment notes.
    if ($orderTaxCents <= 0) {
      return $refundLog;
    }
    $orderGrossCents = $this->priceToCents($order->getTotalPrice());
    $taxableOrderGrossCents = max(0, $orderGrossCents - $orderDonationCents);
    $refundedTaxableCents = (string) ($refundLog['refund_scope'] ?? '') === 'donation_only'
      ? 0
      : max(0, $refundedCents - $refundedDonationCents);
    $gstCents = $this->calculateGstAdjustmentCents(
      $orderTaxCents,
      $taxableOrderGrossCents,
      $refundedTaxableCents,
    );
    $orderNumber = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($order->getOrderNumber() ?: $order->id())) ?: (string) $order->id();
    $noteNumber = sprintf('ADJ-%s-%d', $orderNumber, $logId);

    $this->database->update('myeventlane_refund_log')
      ->fields([
        'adjustment_note_number' => $noteNumber,
        'gst_adjustment_cents' => $gstCents,
        'supplier_name_snapshot' => $supplierName,
        'supplier_abn_snapshot' => $supplierAbn,
        'adjustment_note_created' => $this->time->getRequestTime(),
      ])
      ->condition('id', $logId)
      ->isNull('adjustment_note_number')
      ->execute();

    $row = $this->database->select('myeventlane_refund_log', 'r')
      ->fields('r')
      ->condition('id', $logId)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : $refundLog;
  }

  /**
   * Prorates the order's recorded GST over the refunded taxable amount.
   */
  public function calculateGstAdjustmentCents(
    int $orderTaxCents,
    int $orderTaxableGrossCents,
    int $refundedTaxableCents,
  ): int {
    if ($orderTaxCents <= 0 || $orderTaxableGrossCents <= 0 || $refundedTaxableCents <= 0) {
      return 0;
    }

    return min(
      $orderTaxCents,
      (int) round($orderTaxCents * min($refundedTaxableCents, $orderTaxableGrossCents) / $orderTaxableGrossCents),
    );
  }

  /**
   * Sums tax adjustments persisted on the order and its line items.
   */
  private function sumRecordedTaxCents(OrderInterface $order): int {
    $taxCents = 0;
    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getType() === 'tax') {
        $taxCents += $this->priceToCents($adjustment->getAmount());
      }
    }
    foreach ($order->getItems() as $item) {
      foreach ($item->getAdjustments() as $adjustment) {
        if ($adjustment->getType() === 'tax') {
          $taxCents += $this->priceToCents($adjustment->getAmount());
        }
      }
    }
    return max(0, $taxCents);
  }

  /**
   * Converts a Commerce price to integer minor units.
   */
  private function priceToCents(?Price $price): int {
    return $price instanceof Price ? (int) round((float) $price->getNumber() * 100) : 0;
  }

}
