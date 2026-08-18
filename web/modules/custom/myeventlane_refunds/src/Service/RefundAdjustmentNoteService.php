<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
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
    $registrations = $store ? array_column($store->get('tax_registrations')->getValue(), 'value') : [];
    $isGstRegistered = in_array('AU', $registrations, TRUE);
    $supplierName = $store ? trim((string) $store->label()) : '';
    $supplierAbn = '';
    if ($store && $store->hasField('field_abn') && !$store->get('field_abn')->isEmpty()) {
      $supplierAbn = preg_replace('/\D+/', '', (string) $store->get('field_abn')->value) ?? '';
    }

    $refundedCents = max(0, (int) ($refundLog['actual_refunded_cents'] ?? $refundLog['amount_cents'] ?? 0));
    $donationCents = 0;
    if (!empty($refundLog['donation_refunded']) && $order->hasField('field_mel_donation') && !$order->get('field_mel_donation')->isEmpty()) {
      $donationCents = (int) round(((float) $order->get('field_mel_donation')->value) * 100);
    }
    $gstCents = $this->calculateGstAdjustmentCents(
      $isGstRegistered,
      $refundedCents,
      $donationCents,
      (string) ($refundLog['refund_scope'] ?? ''),
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
   * Excludes genuine organiser gifts from the taxable refund amount.
   */
  public function calculateGstAdjustmentCents(
    bool $isGstRegistered,
    int $refundedCents,
    int $donationCents,
    string $refundScope,
  ): int {
    if (!$isGstRegistered || $refundScope === 'donation_only') {
      return 0;
    }

    return (int) round(max(0, $refundedCents - max(0, $donationCents)) / 11);
  }

}
