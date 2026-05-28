<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver
 *
 * @group myeventlane_commerce
 */
final class OperationalVariationStockResolverTest extends UnitTestCase {

  private function resolver(): OperationalVariationStockResolver {
    return new OperationalVariationStockResolver($this->getStringTranslationStub());
  }

  public function testBlankStockIsUnlimited(): void {
    $this->assertNull($this->resolver()->normalizeStockInput(''));
    $this->assertNull($this->resolver()->normalizeStockInput(NULL));
    $variation = $this->variationWithStock(NULL, NULL, FALSE);
    $this->assertTrue($this->resolver()->isUnlimited($variation));
  }

  public function testZeroStockIsSoldOut(): void {
    $variation = $this->variationWithStock(0, NULL, FALSE);
    $this->assertTrue($this->resolver()->isSoldOut($variation));
  }

  public function testLimitPerOrderCannotExceedStock(): void {
    $errors = $this->resolver()->validateStockInput([
      'stock_quantity' => 2,
      'limit_per_order' => 5,
    ]);
    $this->assertNotEmpty($errors);
  }

  public function testValidateAddToCartBlocksSoldOut(): void {
    $variation = $this->variationWithStock(0, NULL, FALSE);
    $errors = $this->resolver()->validateAddToCartQuantity($variation, 1, NULL);
    $this->assertNotEmpty($errors);
  }

  public function testValidateAddToCartBlocksAboveStock(): void {
    $variation = $this->variationWithStock(2, NULL, FALSE);
    $errors = $this->resolver()->validateAddToCartQuantity($variation, 3, NULL);
    $this->assertNotEmpty($errors);
  }

  public function testValidateAddToCartCountsExistingCartQuantity(): void {
    $variation = $this->variationWithStock(2, 2, FALSE);
    $variation->method('id')->willReturn('10');
    $cart = $this->createMock(OrderInterface::class);
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('getPurchasedEntity')->willReturn($variation);
    $item->method('getQuantity')->willReturn('1');
    $cart->method('getItems')->willReturn([$item]);
    $errors = $this->resolver()->validateAddToCartQuantity($variation, 2, $cart);
    $this->assertNotEmpty($errors);
  }

  public function testBuildVariationCustomerStockRemainingLabel(): void {
    $variation = $this->variationWithStock(4, 2, TRUE);
    $meta = $this->resolver()->buildVariationCustomerStock($variation);
    $this->assertSame('4 available', (string) $meta['remaining_label']);
    $this->assertSame('Limit 2 per order', (string) $meta['limit_label']);
  }

  private function variationWithStock(?int $stock, ?int $limit, bool $show): ProductVariationInterface {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('bundle')->willReturn('operational_merchandise_var');
    $variation->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array($field, [
        OperationalVariationStockResolver::FIELD_STOCK_QUANTITY,
        OperationalVariationStockResolver::FIELD_LIMIT_PER_ORDER,
        OperationalVariationStockResolver::FIELD_SHOW_REMAINING,
      ], TRUE),
    );
    $stock_field = $this->createMock(FieldItemListInterface::class);
    $stock_field->method('isEmpty')->willReturn($stock === NULL);
    $limit_field = $this->createMock(FieldItemListInterface::class);
    $limit_field->method('isEmpty')->willReturn($limit === NULL);
    $show_field = $this->createMock(FieldItemListInterface::class);
    $show_field->method('isEmpty')->willReturn(FALSE);
    $variation->method('get')->willReturnCallback(
      static function (string $field) use ($stock, $limit, $show, $stock_field, $limit_field, $show_field) {
        return match ($field) {
          OperationalVariationStockResolver::FIELD_STOCK_QUANTITY => $stock_field,
          OperationalVariationStockResolver::FIELD_LIMIT_PER_ORDER => $limit_field,
          default => $show_field,
        };
      },
    );
    $stock_field->method('__get')->willReturnMap([['value', $stock]]);
    $limit_field->method('__get')->willReturnMap([['value', $limit]]);
    $show_field->method('__get')->willReturnMap([['value', $show ? 1 : 0]]);
    return $variation;
  }

}
