<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_commerce\Service\EventOperationalStockSummaryBuilder;
use Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\EventOperationalStockSummaryBuilder
 *
 * @group myeventlane_commerce
 */
final class EventOperationalStockSummaryBuilderTest extends UnitTestCase {

  public function testFiniteStockIsPresentedAsAvailableAddOnStock(): void {
    $summary = $this->builder()->buildForProducts([
      $this->productWithStock(49),
    ]);

    $this->assertNotNull($summary);
    $this->assertSame('healthy', $summary['state']);
    $this->assertSame('49 available', (string) $summary['label']);
    $this->assertSame(49, $summary['available_quantity']);
  }

  public function testSoldOutAndLowProductsTakePriorityOverTotals(): void {
    $summary = $this->builder()->buildForProducts([
      $this->productWithStock(0),
      $this->productWithStock(4),
      $this->productWithStock(20),
    ]);

    $this->assertNotNull($summary);
    $this->assertSame('sold_out', $summary['state']);
    $this->assertSame('1 sold out · 1 low', (string) $summary['label']);
    $this->assertSame(1, $summary['sold_out_count']);
    $this->assertSame(1, $summary['low_stock_count']);
  }

  public function testUnlimitedStockIsNotReportedAsZero(): void {
    $summary = $this->builder()->buildForProducts([
      $this->productWithStock(NULL),
    ]);

    $this->assertNotNull($summary);
    $this->assertSame('unlimited', $summary['state']);
    $this->assertSame('Unlimited stock', (string) $summary['label']);
  }

  private function builder(): EventOperationalStockSummaryBuilder {
    $resolver = new OperationalVariationStockResolver($this->getStringTranslationStub());
    return new EventOperationalStockSummaryBuilder(
      $this->createMock(EntityTypeManagerInterface::class),
      $resolver,
      $this->getStringTranslationStub(),
    );
  }

  private function productWithStock(?int $stock): ProductInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($stock === NULL);
    $field->method('__get')->willReturnMap([['value', $stock]]);

    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('isPublished')->willReturn(TRUE);
    $variation->method('bundle')->willReturn('operational_merchandise_var');
    $variation->method('hasField')->willReturnCallback(
      static fn (string $name): bool => $name === OperationalVariationStockResolver::FIELD_STOCK_QUANTITY,
    );
    $variation->method('get')->with(OperationalVariationStockResolver::FIELD_STOCK_QUANTITY)->willReturn($field);

    $product = $this->createMock(ProductInterface::class);
    $product->method('getVariations')->willReturn([$variation]);
    return $product;
  }

}
