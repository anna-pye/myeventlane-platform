<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_cart\Event\OrderItemComparisonFieldsEvent;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\myeventlane_commerce\EventSubscriber\TicketBundleOrderItemComparisonSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\EventSubscriber\TicketBundleOrderItemComparisonSubscriber
 *
 * @group myeventlane_commerce
 */
final class TicketBundleOrderItemComparisonSubscriberTest extends TestCase {

  /**
   * @covers ::onComparisonFields
   */
  public function testTicketMatchingIncludesLockState(): void {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('bundle')->willReturn('ticket_variation');
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getPurchasedEntity')->willReturn($variation);
    $order_item->method('hasField')->with('locked')->willReturn(TRUE);
    $event = new OrderItemComparisonFieldsEvent(['type', 'purchased_entity'], $order_item);

    (new TicketBundleOrderItemComparisonSubscriber())->onComparisonFields($event);

    $this->assertSame(['type', 'purchased_entity', 'locked'], $event->getComparisonFields());
  }

  /**
   * @covers ::onComparisonFields
   */
  public function testNonTicketMatchingIsUnchanged(): void {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('bundle')->willReturn('default');
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getPurchasedEntity')->willReturn($variation);
    $event = new OrderItemComparisonFieldsEvent(['type', 'purchased_entity'], $order_item);

    (new TicketBundleOrderItemComparisonSubscriber())->onComparisonFields($event);

    $this->assertSame(['type', 'purchased_entity'], $event->getComparisonFields());
  }

}
