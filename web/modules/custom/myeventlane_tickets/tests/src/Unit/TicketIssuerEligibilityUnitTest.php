<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;
use Drupal\myeventlane_tickets\Ticket\TicketCodeGenerator;
use Drupal\myeventlane_tickets\Ticket\TicketIssuer;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Ticket\TicketIssuer
 *
 * @group myeventlane_tickets
 */
final class TicketIssuerEligibilityUnitTest extends UnitTestCase {

  /**
   * @covers ::isOrderItemEligibleForTicketIssuance
   */
  public function testTicketBackedOrderItemIsEligible(): void {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');

    $order_item = $this->createMock(OrderItemInterface::class);
    $variation = $this->createMock(ProductVariationInterface::class);
    $order_item->method('getPurchasedEntity')->willReturn($variation);

    $target_event = new TicketIssuerTestFieldItemList($event);
    $order_item->method('hasField')->willReturnMap([
      ['field_target_event', TRUE],
    ]);
    $order_item->method('get')->willReturnMap([
      ['field_target_event', $target_event],
    ]);

    $classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $classifier->expects($this->once())
      ->method('isTicketBackedOrderItem')
      ->with($order_item)
      ->willReturn(TRUE);

    $issuer = $this->issuer($classifier);
    $this->assertTrue($issuer->isOrderItemEligibleForTicketIssuance($order_item));
  }

  /**
   * @covers ::isOrderItemEligibleForTicketIssuance
   */
  public function testOperationalMerchandiseOrderItemIsNotEligible(): void {
    $order_item = $this->createMock(OrderItemInterface::class);
    $variation = $this->createMock(ProductVariationInterface::class);
    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn('operational_merchandise');
    $variation->method('getProduct')->willReturn($product);
    $order_item->method('getPurchasedEntity')->willReturn($variation);

    $classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $classifier->expects($this->once())
      ->method('isTicketBackedOrderItem')
      ->with($order_item)
      ->willReturn(FALSE);

    $issuer = $this->issuer($classifier);
    $this->assertFalse($issuer->isOrderItemEligibleForTicketIssuance($order_item));
  }

  private function issuer(TicketBackedOrderItemClassifierInterface $classifier): TicketIssuer {
    return new TicketIssuer(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(TicketCodeGenerator::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $classifier,
    );
  }

}

/**
 * Minimal field list stub for entity reference fields in unit tests.
 */
final class TicketIssuerTestFieldItemList {

  public function __construct(public readonly ?NodeInterface $entity) {}

  public function isEmpty(): bool {
    return $this->entity === NULL;
  }

}
