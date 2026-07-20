<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
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
   * Ticket + Boost carts stay eligible, but Boost dollars are not ledger gross.
   *
   * @covers ::isPayoutLedgerEligibleOrder
   * @covers ::getPayoutLedgerEligibleGross
   */
  public function testMixedTicketAndBoostGrossExcludesBoost(): void {
    $ticketVariation = $this->createMock(ProductVariationInterface::class);
    $ticketVariation->method('getEntityTypeId')->willReturn('commerce_product_variation');
    $ticketVariation->method('bundle')->willReturn('ticket_variation');
    $ticketVariation->method('getProduct')->willReturn(NULL);

    $ticketItem = $this->createMock(OrderItemInterface::class);
    $ticketItem->method('bundle')->willReturn('default');
    $ticketItem->method('getPurchasedEntity')->willReturn($ticketVariation);
    $ticketItem->method('getTotalPrice')->willReturn(new Price('80.00', 'AUD'));

    $boostItem = $this->createMock(OrderItemInterface::class);
    $boostItem->method('bundle')->willReturn('boost');
    $boostItem->method('getPurchasedEntity')->willReturn(NULL);
    $boostItem->method('getTotalPrice')->willReturn(new Price('25.00', 'AUD'));

    $order = $this->createMock(OrderInterface::class);
    $order->method('bundle')->willReturn('default');
    $order->method('getItems')->willReturn([$ticketItem, $boostItem]);
    $order->method('getAdjustments')->willReturn([]);

    $this->assertTrue($this->classifier->isPayoutLedgerEligibleOrder($order));
    $this->assertSame(80.0, $this->classifier->getPayoutLedgerEligibleGross($order));
  }

  /**
   * Booking Contribution adjustment is vendor-payable and included in gross.
   *
   * @covers ::getPayoutLedgerEligibleGross
   */
  public function testEligibleGrossIncludesMelOrderDonationAdjustment(): void {
    $adjustmentTypeManager = $this->createMock(PluginManagerInterface::class);
    $adjustmentTypeManager->method('getDefinitions')->willReturn([
      'custom' => ['id' => 'custom'],
    ]);
    $container = new ContainerBuilder();
    $container->set('plugin.manager.commerce_adjustment_type', $adjustmentTypeManager);
    \Drupal::setContainer($container);

    try {
      $ticketVariation = $this->createMock(ProductVariationInterface::class);
      $ticketVariation->method('getEntityTypeId')->willReturn('commerce_product_variation');
      $ticketVariation->method('bundle')->willReturn('ticket_variation');
      $ticketVariation->method('getProduct')->willReturn(NULL);

      $ticketItem = $this->createMock(OrderItemInterface::class);
      $ticketItem->method('bundle')->willReturn('default');
      $ticketItem->method('getPurchasedEntity')->willReturn($ticketVariation);
      $ticketItem->method('getTotalPrice')->willReturn(new Price('50.00', 'AUD'));

      $contribution = new Adjustment([
        'type' => 'custom',
        'label' => 'Contribution',
        'amount' => new Price('10.00', 'AUD'),
        'included' => FALSE,
        'locked' => TRUE,
        'source_id' => 'myeventlane_order_donation',
      ]);

      $order = $this->createMock(OrderInterface::class);
      $order->method('bundle')->willReturn('default');
      $order->method('getItems')->willReturn([$ticketItem]);
      $order->method('getAdjustments')->willReturn([$contribution]);

      $this->assertSame(60.0, $this->classifier->getPayoutLedgerEligibleGross($order));
    }
    finally {
      \Drupal::unsetContainer();
    }
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
