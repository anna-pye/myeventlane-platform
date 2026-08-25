<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Freezes MEL identity and GST for platform-fee receipt lines.
 */
final class PlatformFeeTaxSnapshotResolver {

  public const ORDER_DATA_KEY = 'mel_platform_fee_tax_snapshot';

  private const PLATFORM_FEE_SOURCES = [
    'myeventlane_platform_fee',
    'myeventlane_operational_extras_platform_fee',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RounderInterface $rounder,
  ) {}

  /**
   * Captures platform fee tax evidence before the order is placed.
   *
   * @return array<string, mixed>
   *   The scalar snapshot stored with the order.
   */
  public function capture(OrderInterface $order): array {
    $existing = $this->snapshot($order);
    if ($existing !== NULL) {
      return $existing;
    }

    $snapshot = $this->buildCurrent($order);
    if ($snapshot['fee_lines'] !== []) {
      $order->setData(self::ORDER_DATA_KEY, $snapshot);
    }
    return $snapshot;
  }

  /**
   * Resolves immutable evidence, with a narrow fallback for legacy orders.
   *
   * Legacy fallback is limited to adjustments carrying MEL-owned source IDs;
   * it never treats an organiser line or arbitrary fee as MEL revenue.
   *
   * @return array<string, mixed>
   *   The resolved scalar snapshot.
   */
  public function resolve(OrderInterface $order): array {
    return $this->snapshot($order) ?? $this->buildCurrent($order);
  }

  /**
   * Whether an adjustment belongs to MEL rather than the organiser.
   */
  public static function isPlatformFeeSource(?string $sourceId): bool {
    return in_array((string) $sourceId, self::PLATFORM_FEE_SOURCES, TRUE);
  }

  /**
   * Reads the order snapshot when one has been recorded.
   *
   * @return array<string, mixed>|null
   *   The stored snapshot, or NULL for a legacy order.
   */
  private function snapshot(OrderInterface $order): ?array {
    $snapshot = $order->getData(self::ORDER_DATA_KEY);
    if (!is_array($snapshot) || !isset($snapshot['fee_lines']) || !is_array($snapshot['fee_lines'])) {
      return NULL;
    }
    return $snapshot;
  }

  /**
   * Builds MEL fee tax evidence from recognised order adjustments.
   *
   * @return array<string, mixed>
   *   The scalar snapshot data.
   */
  private function buildCurrent(OrderInterface $order): array {
    $settings = $this->configFactory->get('myeventlane_core.settings');
    $gstInclusive = (bool) $settings->get('platform_fee_gst_inclusive');
    $feeLines = [];

    foreach ($order->getAdjustments() as $adjustment) {
      if (!self::isPlatformFeeSource($adjustment->getSourceId())) {
        continue;
      }
      $amount = $adjustment->getAmount();
      if (!$amount instanceof Price || $amount->isZero()) {
        continue;
      }

      $gst = $gstInclusive
        ? $this->rounder->round($amount->divide('11'))
        : new Price('0', $amount->getCurrencyCode());
      $feeLines[] = [
        'label' => trim((string) $adjustment->getLabel()) ?: 'MEL platform fee',
        'amount_number' => $amount->getNumber(),
        'gst_number' => $gst->getNumber(),
        'currency_code' => $amount->getCurrencyCode(),
        'gst_inclusive' => $gstInclusive,
        'source_id' => (string) $adjustment->getSourceId(),
      ];
    }

    return [
      'platform_name' => trim((string) $settings->get('platform_legal_name')) ?: 'MyEventLane Inc',
      'platform_abn' => trim((string) $settings->get('platform_abn')),
      'fee_lines' => $feeLines,
    ];
  }

}
