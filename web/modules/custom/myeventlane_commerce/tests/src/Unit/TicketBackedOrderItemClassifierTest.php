<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifier;
use Drupal\myeventlane_commerce\Service\TicketTierForVariationResolverInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifier
 *
 * @group myeventlane_commerce
 */
final class TicketBackedOrderItemClassifierTest extends UnitTestCase {

  /**
   * @covers ::isTicketBackedOrderItem
   */
  public function testTicketVariationWithMelTicketTypeIsTicketBacked(): void {
    $variation = $this->createMock(ProductVariationInterface::class);
    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn('ticket');
    $variation->method('getProduct')->willReturn($product);

    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');

    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getPurchasedEntity')->willReturn($variation);
    $order_item->method('bundle')->willReturn('default');
    $order_item->method('getOrderId')->willReturn(1);
    $order_item->method('id')->willReturn(10);

    $target_event = new TicketBackedTestFieldItemList($event);
    $order_item->method('hasField')->willReturnMap([
      ['field_target_event', TRUE],
    ]);
    $order_item->method('get')->willReturnMap([
      ['field_target_event', $target_event],
    ]);
    $order_item->method('get')->with('field_target_event')->willReturn($target_event);

    $tier = $this->createMock(TicketTypeInterface::class);
    $tier->method('label')->willReturn('Full price');

    $tier_resolver = $this->createMock(TicketTierForVariationResolverInterface::class);
    $tier_resolver->method('resolveTierForVariation')->with($event, $variation)->willReturn($tier);

    $classifier = $this->classifier($tier_resolver);
    $this->assertTrue($classifier->isTicketBackedOrderItem($order_item));
    $this->assertSame('Full price', $classifier->resolveTicketTierLabel($order_item));
  }

  /**
   * @covers ::isTicketBackedOrderItem
   * @covers ::isOperationalOrderItem
   */
  public function testOperationalMerchandiseVariationIsNotTicketBacked(): void {
    $variation = $this->createMock(ProductVariationInterface::class);
    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn('operational_merchandise');
    $variation->method('getProduct')->willReturn($product);

    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getPurchasedEntity')->willReturn($variation);
    $order_item->method('bundle')->willReturn('default');

    $classifier = $this->classifier();
    $this->assertTrue($classifier->isOperationalOrderItem($order_item));
    $this->assertFalse($classifier->isTicketBackedOrderItem($order_item));
  }

  /**
   * @covers ::isTicketBackedOrderItem
   */
  public function testMissingPurchasedEntityIsNotTicketBacked(): void {
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getPurchasedEntity')->willReturn(NULL);
    $order_item->method('bundle')->willReturn('default');

    $classifier = $this->classifier();
    $this->assertFalse($classifier->isTicketBackedOrderItem($order_item));
  }

  private function classifier(?TicketTierForVariationResolverInterface $tier_resolver = NULL): TicketBackedOrderItemClassifier {
    return new TicketBackedOrderItemClassifier(
      $this->createMock(EntityTypeManagerInterface::class),
      new OperationalMerchandiseManager(
        $this->createMock(EntityTypeManagerInterface::class),
        $this->getStringTranslationStub(),
        $this->createMock(LoggerInterface::class),
      ),
      $tier_resolver ?? $this->createMock(TicketTierForVariationResolverInterface::class),
      new OrderItemClassifier(),
      $this->createMock(LoggerInterface::class),
    );
  }

}

/**
 * Minimal field list stub for entity reference fields in unit tests.
 */
final class TicketBackedTestFieldItemList {

  public function __construct(public readonly ?NodeInterface $entity) {}

  public function isEmpty(): bool {
    return $this->entity === NULL;
  }

}
