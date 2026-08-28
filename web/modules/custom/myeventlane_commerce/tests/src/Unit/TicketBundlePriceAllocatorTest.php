<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\myeventlane_commerce\Service\TicketBundlePriceAllocator;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\TicketBundlePriceAllocator
 *
 * @group myeventlane_commerce
 */
final class TicketBundlePriceAllocatorTest extends TestCase {

  /**
   * @covers ::allocateUnitPrices
   */
  public function testRoundedComponentTotalsEqualAdvertisedBundleTotal(): void {
    $rounder = new class implements RounderInterface {
      public function round(Price $price, $mode = PHP_ROUND_HALF_UP): Price {
        return new Price(Calculator::round($price->getNumber(), 2, $mode), $price->getCurrencyCode());
      }
    };
    $allocator = new TicketBundlePriceAllocator($rounder);
    $lines = [
      ['quantity' => 3, 'face_line_total' => '1'],
      ['quantity' => 3, 'face_line_total' => '1'],
      ['quantity' => 3, 'face_line_total' => '1'],
    ];

    $unit_prices = $allocator->allocateUnitPrices(new Price('1.00', 'AUD'), 1, $lines);
    $rounded_total = new Price('0', 'AUD');
    foreach ($unit_prices as $index => $unit_price) {
      $line_total = $rounder->round($unit_price->multiply((string) $lines[$index]['quantity']));
      $rounded_total = $rounded_total->add($line_total);
    }

    $this->assertSame('1', $rounded_total->getNumber());
    $this->assertSame('0.34', $rounder->round($unit_prices[2]->multiply('3'))->getNumber());
  }

}
