<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\myeventlane_commerce\Service\EventOperationalAddonBuilder;
use Drupal\myeventlane_commerce\Service\EventOperationalExtrasSalesSummaryBuilder;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_commerce\Service\OperationalPurchaseCompositionManager;
use Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;
use Drupal\myeventlane_commerce\Service\VendorOperationalAddonOrderBuilder;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Routing\Route;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\EventOperationalExtrasSalesSummaryBuilder
 *
 * @group myeventlane_commerce
 */
final class EventOperationalExtrasSalesSummaryBuilderTest extends UnitTestCase {

  /**
   * @covers ::buildExtrasSalesPanel
   */
  public function testTicketBackedItemsAreExcludedFromExtrasSummary(): void {
    $event = $this->event(12);
    $extra_item = $this->orderItem(2, 200, '3');
    $order = $this->qualifyingOrder();
    $order->method('getItems')->willReturn([$extra_item]);

    $classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $classifier->method('isTicketBackedOrderItem')->willReturn(FALSE);

    $composition = $this->createMock(OperationalPurchaseCompositionManager::class);
    $composition->method('composeForOrder')->willReturn([
      'groups' => [
        'merchandise' => [
          [
            'order_item_id' => 2,
            'bundle_group' => 'operational_merchandise',
            'presentation' => ['operational_product_type' => 'merch_pickup'],
          ],
        ],
      ],
    ]);

    $addon_order_builder = $this->createMock(VendorOperationalAddonOrderBuilder::class);
    $addon_order_builder->method('loadQualifyingOrdersForEvent')->willReturn([$order]);
    $addon_order_builder->method('orderHasOperationalAddons')->willReturn(TRUE);
    $addon_order_builder->method('collectCacheTagsForEvent')->willReturn(['node:12']);

    $builder = $this->builder($classifier, $composition, $addon_order_builder);
    $panel = $builder->buildExtrasSalesPanel($event);

    $this->assertSame(3, $panel['summary']['total_items_sold']);
    $this->assertSame('operational_composition_lines', $panel['sold_basis']);
  }

  /**
   * @covers ::buildExtrasSalesPanel
   */
  public function testGroupsByOperationalCompositionCategory(): void {
    $event = $this->event(15);
    $order = $this->qualifyingOrder();
    $parking_item = $this->orderItem(3, 300, '1', 15);
    $food_item = $this->orderItem(4, 301, '1', 15);
    $food_item->method('getAdjustedTotalPrice')->willReturn(new Price('12.50', 'AUD'));
    $order->method('getItems')->willReturn([$parking_item, $food_item]);

    $classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $classifier->method('isTicketBackedOrderItem')->willReturn(FALSE);

    $composition = $this->createMock(OperationalPurchaseCompositionManager::class);
    $composition->method('composeForOrder')->willReturn([
      'groups' => [
        'parking' => [
          [
            'order_item_id' => 3,
            'bundle_group' => 'operational_merchandise',
            'presentation' => ['operational_product_type' => 'merch_pickup'],
          ],
        ],
        'timed_collection' => [
          [
            'order_item_id' => 4,
            'bundle_group' => 'timed_collection_product',
            'presentation' => ['operational_product_type' => 'food_drink'],
          ],
        ],
      ],
    ]);

    $addon_order_builder = $this->createMock(VendorOperationalAddonOrderBuilder::class);
    $addon_order_builder->method('loadQualifyingOrdersForEvent')->willReturn([$order]);
    $addon_order_builder->method('orderHasOperationalAddons')->willReturn(TRUE);
    $addon_order_builder->method('collectCacheTagsForEvent')->willReturn(['node:15']);

    $builder = $this->builder($classifier, $composition, $addon_order_builder);
    $panel = $builder->buildExtrasSalesPanel($event);

    $by_key = [];
    foreach ($panel['rows'] as $row) {
      $by_key[$row['key']] = $row;
    }
    $this->assertSame(1, $by_key['parking']['items_sold']);
    $this->assertSame(1, $by_key['food_drink']['items_sold']);
    $this->assertSame(0, $by_key['merchandise']['items_sold']);
  }

  /**
   * @covers ::buildExtrasSalesPanelRenderArray
   */
  public function testRenderArrayUsesExtrasSalesTheme(): void {
    $event = $this->event(9);
    $builder = $this->builder();
    $render = $builder->buildExtrasSalesPanelRenderArray($event);
    $this->assertSame('mel_event_studio_extras_sales_panel', $render['#theme']);
  }

  private function builder(
    ?TicketBackedOrderItemClassifierInterface $classifier = NULL,
    ?OperationalPurchaseCompositionManager $composition = NULL,
    ?VendorOperationalAddonOrderBuilder $addon_order_builder = NULL,
  ): EventOperationalExtrasSalesSummaryBuilder {
    $classifier ??= $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $composition ??= $this->createMock(OperationalPurchaseCompositionManager::class);
    $composition->method('composeForOrder')->willReturn(['groups' => []]);

    $addon_order_builder ??= $this->createMock(VendorOperationalAddonOrderBuilder::class);
    $addon_order_builder->method('loadQualifyingOrdersForEvent')->willReturn([]);
    $addon_order_builder->method('collectCacheTagsForEvent')->willReturn([]);

    $route_provider = $this->createMock(RouteProviderInterface::class);
    $route_provider->method('getRouteByName')->willReturnCallback(
      static fn (string $name): Route => new Route('/' . $name),
    );

    $currency = $this->createMock(CurrencyFormatter::class);
    $currency->method('format')->willReturnCallback(
      static fn (string $number, string $code): string => '$' . $number . ' ' . $code,
    );

    return new EventOperationalExtrasSalesSummaryBuilder(
      $this->createMock(EntityTypeManagerInterface::class),
      $addon_order_builder,
      $composition,
      $this->createMock(OperationalMerchandiseManager::class),
      $this->createMock(EventOperationalAddonBuilder::class),
      new OperationalVariationStockResolver($this->getStringTranslationStub()),
      $classifier,
      $currency,
      $route_provider,
      $this->getStringTranslationStub(),
    );
  }

  private function event(int $id): NodeInterface&MockObject {
    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn((string) $id);
    $event->method('bundle')->willReturn('event');
    $event->method('getCacheTags')->willReturn(['node:' . $id]);
    return $event;
  }

  private function qualifyingOrder(): OrderInterface&MockObject {
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn('1');
    return $order;
  }

  private function orderItem(int $id, int $variation_id, string $qty, int $event_id = 12): OrderItemInterface&MockObject {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('id')->willReturn((string) $variation_id);
    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn('operational_merchandise');
    $variation->method('getProduct')->willReturn($product);

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('id')->willReturn((string) $id);
    $item->method('bundle')->willReturn('default');
    $item->method('getPurchasedEntity')->willReturn($variation);
    $item->method('getQuantity')->willReturn($qty);
    $item->method('getAdjustedTotalPrice')->willReturn(new Price('10.00', 'AUD'));
    $item->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_target_event',
    );
    $target = new class($event_id) {

      public function __construct(public int $target_id) {}

      public function isEmpty(): bool {
        return FALSE;
      }

    };
    $item->method('get')->with('field_target_event')->willReturn($target);
    return $item;
  }

}
