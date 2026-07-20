<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\OrderItemClassifier
 * @group myeventlane_commerce
 */
final class OrderItemClassifierPayoutLedgerTest extends UnitTestCase {

  private OrderItemClassifier $classifier;

  protected function setUp(): void {
    parent::setUp();
    $this->classifier = new OrderItemClassifier();
  }

  /**
   * @covers ::isPayoutLedgerEligibleItem
   */
  public function testCheckoutDonationIsLedgerEligible(): void {
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('checkout_donation');
    $item->method('getPurchasedEntity')->willReturn(NULL);
    $this->assertTrue($this->classifier->isPayoutLedgerEligibleItem($item));
  }

  /**
   * @covers ::isPayoutLedgerEligibleItem
   */
  public function testBoostIsNotLedgerEligible(): void {
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('boost');
    $item->method('getPurchasedEntity')->willReturn(NULL);
    $this->assertFalse($this->classifier->isPayoutLedgerEligibleItem($item));
  }

  /**
   * @covers ::isPayoutLedgerEligibleItem
   */
  public function testTicketVariationIsLedgerEligible(): void {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('getEntityTypeId')->willReturn('commerce_product_variation');
    $variation->method('bundle')->willReturn('ticket_variation');
    $variation->method('getProduct')->willReturn(NULL);

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('default');
    $item->method('getPurchasedEntity')->willReturn($variation);
    $this->assertTrue($this->classifier->isPayoutLedgerEligibleItem($item));
  }

  /**
   * @covers ::isPayoutLedgerEligibleItem
   * @covers ::isOperationalVendorRevenue
   */
  public function testOperationalMerchandiseVariationIsLedgerEligible(): void {
    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn('operational_merchandise');

    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('getEntityTypeId')->willReturn('commerce_product_variation');
    $variation->method('bundle')->willReturn('operational_merchandise_var');
    $variation->method('getProduct')->willReturn($product);

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('default');
    $item->method('getPurchasedEntity')->willReturn($variation);
    $this->assertTrue($this->classifier->isOperationalVendorRevenue($item));
    $this->assertTrue($this->classifier->isPayoutLedgerEligibleItem($item));
  }

  /**
   * @covers ::isPayoutLedgerEligibleItem
   * @covers ::isOperationalVendorRevenue
   */
  public function testOperationalBundleVariationIsLedgerEligible(): void {
    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn('operational_bundle');

    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('getEntityTypeId')->willReturn('commerce_product_variation');
    $variation->method('bundle')->willReturn('operational_bundle_var');
    $variation->method('getProduct')->willReturn($product);

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('default');
    $item->method('getPurchasedEntity')->willReturn($variation);
    $this->assertTrue($this->classifier->isOperationalVendorRevenue($item));
    $this->assertTrue($this->classifier->isPayoutLedgerEligibleItem($item));
  }

  /**
   * @covers ::isPayoutLedgerEligibleOrder
   */
  public function testMerchOnlyOrderIsLedgerEligible(): void {
    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn('operational_merchandise');

    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('getEntityTypeId')->willReturn('commerce_product_variation');
    $variation->method('bundle')->willReturn('operational_merchandise_var');
    $variation->method('getProduct')->willReturn($product);

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('default');
    $item->method('getPurchasedEntity')->willReturn($variation);

    $order = $this->createMock(OrderInterface::class);
    $order->method('bundle')->willReturn('default');
    $order->method('getItems')->willReturn([$item]);
    $this->assertTrue($this->classifier->isPayoutLedgerEligibleOrder($order));
  }

  /**
   * @covers ::isPayoutLedgerEligibleItem
   */
  public function testMelProIsNotLedgerEligible(): void {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('getEntityTypeId')->willReturn('commerce_product_variation');
    $variation->method('bundle')->willReturn('mel_pro_subscription_variation');
    $variation->method('getProduct')->willReturn(NULL);

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('default');
    $item->method('getPurchasedEntity')->willReturn($variation);
    $this->assertFalse($this->classifier->isPayoutLedgerEligibleItem($item));
  }

  /**
   * @covers ::isPayoutLedgerEligibleOrder
   */
  public function testPlatformDonationOrderTypeExcluded(): void {
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('checkout_donation');
    $item->method('getPurchasedEntity')->willReturn(NULL);

    $order = $this->createMock(OrderInterface::class);
    $order->method('bundle')->willReturn('platform_donation');
    $order->method('getItems')->willReturn([$item]);
    $this->assertFalse($this->classifier->isPayoutLedgerEligibleOrder($order));
  }

  /**
   * @covers ::requiresRecurringPaymentGateway
   */
  public function testRequiresRecurringForMelPro(): void {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('getEntityTypeId')->willReturn('commerce_product_variation');
    $variation->method('bundle')->willReturn('mel_pro_subscription_variation');
    $variation->method('getProduct')->willReturn(NULL);

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('default');
    $item->method('getPurchasedEntity')->willReturn($variation);

    $order = $this->createMock(OrderInterface::class);
    $order->method('bundle')->willReturn('default');
    $order->method('getItems')->willReturn([$item]);
    $this->assertTrue($this->classifier->requiresRecurringPaymentGateway($order));
  }

}
