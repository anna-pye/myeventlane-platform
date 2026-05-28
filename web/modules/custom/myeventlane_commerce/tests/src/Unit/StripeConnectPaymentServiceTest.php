<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\myeventlane_commerce\Service\StripeConnectPaymentService;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\StripeConnectPaymentService
 *
 * @group myeventlane_commerce
 */
final class StripeConnectPaymentServiceTest extends UnitTestCase {

  /**
   * @covers ::calculateTicketRevenue
   */
  public function testCalculateTicketRevenueIncludesTicketBackedItemsOnly(): void {
    $ticket_item = $this->orderItem('default', '50.00');
    $merch_item = $this->orderItem('default', '20.00');
    $donation_item = $this->orderItem('checkout_donation', '10.00');
    $boost_item = $this->orderItem('boost', '5.00');

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$ticket_item, $merch_item, $donation_item, $boost_item]);

    $service = $this->service([$ticket_item]);
    $this->assertSame(5000, $service->calculateTicketRevenue($order));
  }

  /**
   * @covers ::calculateTicketRevenueCentsForEvent
   */
  public function testCalculateTicketRevenueCentsForEventScopesTicketBackedItems(): void {
    $ticket_item = $this->orderItem('default', '40.00', 42);
    $other_event_ticket = $this->orderItem('default', '30.00', 99);
    $addon_item = $this->orderItem('default', '15.00', 42);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$ticket_item, $other_event_ticket, $addon_item]);

    $service = $this->service([$ticket_item]);
    $this->assertSame(4000, $service->calculateTicketRevenueCentsForEvent($order, 42));
  }

  /**
   * @param list<\Drupal\commerce_order\Entity\OrderItemInterface> $ticket_backed_items
   */
  private function service(array $ticket_backed_items): StripeConnectPaymentService {
    $ticket_backed_ids = [];
    foreach ($ticket_backed_items as $item) {
      $ticket_backed_ids[spl_object_id($item)] = TRUE;
    }

    $classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $classifier->method('isTicketBackedOrderItem')->willReturnCallback(
      static fn (OrderItemInterface $item): bool => !empty($ticket_backed_ids[spl_object_id($item)]),
    );

    return new StripeConnectPaymentService(
      $this->createMock(EntityTypeManagerInterface::class),
      new StripeService(
        $this->createMock(ConfigFactoryInterface::class),
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(LoggerChannelFactoryInterface::class),
      ),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerInterface::class),
      new OrderItemClassifier(),
      $classifier,
    );
  }

  private function orderItem(string $bundle, string $amount, ?int $event_id = NULL): OrderItemInterface {
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn($bundle);
    $item->method('getTotalPrice')->willReturn(new Price($amount, 'AUD'));

    if ($event_id !== NULL) {
      $target_event = new StripeConnectTestFieldItemList($event_id);
      $item->method('hasField')->willReturnMap([
        ['field_target_event', TRUE],
      ]);
      $item->method('get')->willReturnMap([
        ['field_target_event', $target_event],
      ]);
    }
    else {
      $item->method('hasField')->willReturn(FALSE);
    }

    return $item;
  }

}

/**
 * Minimal field list stub for entity reference fields in unit tests.
 */
final class StripeConnectTestFieldItemList {

  public function __construct(public readonly int $target_id) {}

  public function isEmpty(): bool {
    return $this->target_id <= 0;
  }

}
