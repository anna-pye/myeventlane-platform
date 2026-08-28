<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\myeventlane_commerce\OrderPreprocessor\TicketBundlePricePreprocessor;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\OrderPreprocessor\TicketBundlePricePreprocessor
 *
 * @group myeventlane_commerce
 */
final class TicketBundlePricePreprocessorTest extends UnitTestCase {

  private TicketBackedOrderItemClassifierInterface $classifier;

  protected function setUp(): void {
    parent::setUp();
    $adjustment_type_manager = $this->createMock(PluginManagerInterface::class);
    $adjustment_type_manager->method('getDefinitions')->willReturn([
      'tax' => ['id' => 'tax'],
    ]);
    $container = new ContainerBuilder();
    $container->set('plugin.manager.commerce_adjustment_type', $adjustment_type_manager);
    \Drupal::setContainer($container);
    $this->classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
  }

  /**
   * @covers ::preprocess
   */
  public function testRestoresGrossBundlePriceBeforeOrderProcessorsRun(): void {
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getData')->willReturnCallback(static fn (string $key, mixed $default = NULL): mixed => match ($key) {
      'mel_ticket_bundle_gross_unit_price' => '10.625000',
      'mel_ticket_bundle_currency' => 'aud',
      default => $default,
    });
    $order_item->expects($this->once())
      ->method('setUnitPrice')
      ->with(
        $this->callback(static fn (Price $price): bool => $price->getNumber() === '10.625000' && $price->getCurrencyCode() === 'AUD'),
        TRUE,
      );

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$order_item]);

    (new TicketBundlePricePreprocessor($this->classifier))->preprocess($order);
  }

  /**
   * @covers ::preprocess
   */
  public function testLeavesOrdinaryTicketLinesUntouched(): void {
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getData')->willReturnArgument(1);
    $order_item->expects($this->never())->method('setUnitPrice');

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$order_item]);

    (new TicketBundlePricePreprocessor($this->classifier))->preprocess($order);
  }

  /**
   * @covers ::process
   */
  public function testPreservesOrdinaryTicketGrossPriceBeforeBillingProfileExists(): void {
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getData')->willReturnArgument(1);
    $order_item->method('getUnitPrice')->willReturn(new Price('50.00', 'AUD'));
    $order_item->expects($this->once())
      ->method('addAdjustment')
      ->with($this->callback(static fn (Adjustment $adjustment): bool =>
        $adjustment->getSourceId() === TicketBundlePricePreprocessor::TAX_PLACEHOLDER_SOURCE
        && $adjustment->getAmount()->getNumber() === '0'
        && $adjustment->isIncluded()
      ));
    $this->classifier->expects($this->once())
      ->method('isTicketBackedOrderItem')
      ->with($order_item)
      ->willReturn(TRUE);

    $order = $this->taxInclusiveOrderWithoutBillingProfile([$order_item]);

    (new TicketBundlePricePreprocessor($this->classifier))->process($order);
  }

  /**
   * @covers ::process
   */
  public function testLeavesNonTicketLinesUntouched(): void {
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getData')->willReturnArgument(1);
    $order_item->expects($this->never())->method('addAdjustment');
    $this->classifier->expects($this->once())
      ->method('isTicketBackedOrderItem')
      ->with($order_item)
      ->willReturn(FALSE);

    $order = $this->taxInclusiveOrderWithoutBillingProfile([$order_item]);

    (new TicketBundlePricePreprocessor($this->classifier))->process($order);
  }

  /**
   * @covers ::process
   */
  public function testDefersToCommerceTaxWhenBillingProfileExists(): void {
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->expects($this->never())->method('addAdjustment');
    $this->classifier->expects($this->never())->method('isTicketBackedOrderItem');

    $tax_field = (object) ['value' => '1'];
    $store = $this->createMock(StoreInterface::class);
    $store->method('get')->with('prices_include_tax')->willReturn($tax_field);
    $order = $this->createMock(OrderInterface::class);
    $order->method('getStore')->willReturn($store);
    $order->method('getBillingProfile')->willReturn($this->createMock(ProfileInterface::class));
    $order->method('getItems')->willReturn([$order_item]);

    (new TicketBundlePricePreprocessor($this->classifier))->process($order);
  }

  /**
   * Builds a tax-inclusive order without a billing profile.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface[] $items
   *   Order items returned by the mock order.
   */
  private function taxInclusiveOrderWithoutBillingProfile(array $items): OrderInterface {
    $tax_field = (object) ['value' => '1'];
    $store = $this->createMock(StoreInterface::class);
    $store->method('get')->with('prices_include_tax')->willReturn($tax_field);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getStore')->willReturn($store);
    $order->method('getBillingProfile')->willReturn(NULL);
    $order->method('getItems')->willReturn($items);
    return $order;
  }

}
