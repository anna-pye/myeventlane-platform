<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;

/**
 * Allocates one advertised bundle total across component order items.
 */
final class TicketBundlePriceAllocator {

  public function __construct(
    private readonly RounderInterface $rounder,
  ) {}

  /**
   * Returns unit prices whose currency-rounded line totals equal the bundle.
   *
   * @param \Drupal\commerce_price\Price $bundlePrice
   *   Price for one complete bundle.
   * @param int $bundleQuantity
   *   Number of bundles selected.
   * @param array<int, array{quantity: int, face_line_total: string}> $lines
   *   Component quantities and their undiscounted line weights.
   *
   * @return array<int, \Drupal\commerce_price\Price>
   *   Unit prices keyed like the input lines.
   */
  public function allocateUnitPrices(Price $bundlePrice, int $bundleQuantity, array $lines): array {
    if ($bundleQuantity < 1 || $lines === []) {
      return [];
    }

    $currency = $bundlePrice->getCurrencyCode();
    $bundle_total = $this->rounder->round(new Price(
      Calculator::multiply($bundlePrice->getNumber(), (string) $bundleQuantity),
      $currency,
    ))->getNumber();
    $face_total = '0';
    $quantity_total = 0;
    foreach ($lines as $line) {
      if ($line['quantity'] < 1 || !is_numeric($line['face_line_total'])) {
        return [];
      }
      $face_total = Calculator::add($face_total, $line['face_line_total']);
      $quantity_total += $line['quantity'];
    }

    $unit_prices = [];
    $allocated = '0';
    $last_index = array_key_last($lines);
    foreach ($lines as $index => $line) {
      $remaining = Calculator::subtract($bundle_total, $allocated);
      if ($index === $last_index) {
        $line_total = $remaining;
      }
      else {
        $share = Calculator::compare($face_total, '0') > 0
          ? Calculator::divide($line['face_line_total'], $face_total, 12)
          : Calculator::divide((string) $line['quantity'], (string) $quantity_total, 12);
        $proportional_total = Calculator::multiply($bundle_total, $share, 12);
        $line_total = $this->rounder->round(new Price($proportional_total, $currency))->getNumber();
        if (Calculator::compare($line_total, $remaining) > 0) {
          $line_total = $remaining;
        }
      }
      $allocated = Calculator::add($allocated, $line_total);
      $unit_prices[$index] = new Price(
        Calculator::divide($line_total, (string) $line['quantity'], 6),
        $currency,
      );
    }

    return $unit_prices;
  }

}
