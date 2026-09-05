<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_stock\StockCheckInterface;
use Drupal\commerce_stock\StockServiceInterface;
use Drupal\commerce_stock\StockServiceManager;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\myeventlane_commerce\Exception\OperationalStockUnavailableException;
use Drupal\myeventlane_commerce\Service\OperationalStockHoldManager;
use Drupal\myeventlane_commerce\Service\OperationalStockHoldStore;
use Drupal\myeventlane_commerce\Service\OperationalStockHoldStoreInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\OperationalStockHoldManager
 *
 * @group myeventlane_commerce
 */
final class OperationalStockHoldManagerTest extends UnitTestCase {

  /**
   * Confirms other carts' holds reduce the available stock pool.
   */
  public function testRefreshHoldsOnlyStockRemainingAfterOtherCarts(): void {
    [$manager, $store] = $this->buildManager(stockLevel: 3, heldByOthers: 1);

    $store->expects(self::once())
      ->method('upsert')
      ->with(41, 12, 2);
    $store->expects(self::once())
      ->method('releaseStaleForOrder')
      ->with(41, [OperationalStockHoldStore::reservationKey(41, 12)]);

    $manager->refresh($this->orderWithVariation('operational_merchandise_var', 2));
  }

  /**
   * Confirms a hold cannot exceed ledger stock left after other carts.
   */
  public function testRefreshRejectsOversellAfterSubtractingOtherHolds(): void {
    [$manager, $store] = $this->buildManager(stockLevel: 2, heldByOthers: 1);
    $store->expects(self::never())->method('upsert');

    $this->expectException(OperationalStockUnavailableException::class);
    $manager->refresh($this->orderWithVariation('operational_merchandise_var', 2));
  }

  /**
   * Confirms no hold is extended when a later cart line fails validation.
   */
  public function testRefreshValidatesWholeCartBeforeWritingHolds(): void {
    $stockManager = $this->getMockBuilder(StockServiceManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getService', 'getStockLevel'])
      ->getMock();
    $checker = $this->createMock(StockCheckInterface::class);
    $checker->method('getIsAlwaysInStock')->willReturn(FALSE);
    $service = $this->createMock(StockServiceInterface::class);
    $service->method('getStockChecker')->willReturn($checker);
    $stockManager->method('getService')->willReturn($service);
    $stockManager->method('getStockLevel')->willReturn(1);

    $store = $this->createMock(OperationalStockHoldStoreInterface::class);
    $store->method('getHeldQuantity')->willReturn(0);
    $store->expects(self::never())->method('upsert');
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $lock->expects(self::exactly(2))->method('release');

    $manager = new OperationalStockHoldManager(
      $stockManager,
      $store,
      $lock,
      $this->createMock(LoggerInterface::class),
    );

    $this->expectException(OperationalStockUnavailableException::class);
    $manager->refresh($this->orderWithOperationalLines([12 => 1, 13 => 2]));
  }

  /**
   * Confirms ticket variations never enter the operational hold path.
   */
  public function testTicketVariationNeverUsesOperationalStock(): void {
    $stockManager = $this->getMockBuilder(StockServiceManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getService', 'getStockLevel'])
      ->getMock();
    $stockManager->expects(self::never())->method('getService');
    $stockManager->expects(self::never())->method('getStockLevel');

    $store = $this->createMock(OperationalStockHoldStoreInterface::class);
    $store->expects(self::once())->method('releaseOrder')->with(41);
    $manager = new OperationalStockHoldManager(
      $stockManager,
      $store,
      $this->createMock(LockBackendInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $manager->refresh($this->orderWithVariation('default', 2));
  }

  /**
   * Builds a manager with deterministic stock and held quantities.
   *
   * @return array{OperationalStockHoldManager, OperationalStockHoldStoreInterface}
   *   The manager and its mocked hold store.
   */
  private function buildManager(int $stockLevel, int $heldByOthers): array {
    $stockManager = $this->getMockBuilder(StockServiceManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getService', 'getStockLevel'])
      ->getMock();
    $checker = $this->createMock(StockCheckInterface::class);
    $checker->method('getIsAlwaysInStock')->willReturn(FALSE);
    $service = $this->createMock(StockServiceInterface::class);
    $service->method('getStockChecker')->willReturn($checker);
    $stockManager->method('getService')->willReturn($service);
    $stockManager->method('getStockLevel')->willReturn($stockLevel);

    $store = $this->createMock(OperationalStockHoldStoreInterface::class);
    $store->method('getHeldQuantity')->willReturn($heldByOthers);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $lock->expects(self::once())->method('release')->with('myeventlane_operational_stock:12');

    return [
      new OperationalStockHoldManager(
        $stockManager,
        $store,
        $lock,
        $this->createMock(LoggerInterface::class),
      ),
      $store,
    ];
  }

  /**
   * Builds a saved order containing one variation line.
   */
  private function orderWithVariation(string $bundle, int $quantity): OrderInterface {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('id')->willReturn('12');
    $variation->method('bundle')->willReturn($bundle);
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('getPurchasedEntity')->willReturn($variation);
    $item->method('getQuantity')->willReturn((string) $quantity);
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn('41');
    $order->method('getItems')->willReturn([$item]);
    return $order;
  }

  /**
   * Builds a saved order containing operational variation lines.
   *
   * @param array<int, int> $quantities
   *   Quantities keyed by variation ID.
   */
  private function orderWithOperationalLines(array $quantities): OrderInterface {
    $items = [];
    foreach ($quantities as $variationId => $quantity) {
      $variation = $this->createMock(ProductVariationInterface::class);
      $variation->method('id')->willReturn((string) $variationId);
      $variation->method('bundle')->willReturn('operational_merchandise_var');
      $item = $this->createMock(OrderItemInterface::class);
      $item->method('getPurchasedEntity')->willReturn($variation);
      $item->method('getQuantity')->willReturn((string) $quantity);
      $items[] = $item;
    }
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn('41');
    $order->method('getItems')->willReturn($items);
    return $order;
  }

}
