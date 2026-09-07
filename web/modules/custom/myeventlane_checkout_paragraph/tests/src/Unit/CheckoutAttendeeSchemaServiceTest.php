<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_paragraph\Unit;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_checkout_paragraph\Service\CheckoutAttendeeSchemaService;
use Drupal\myeventlane_commerce\Service\CartTicketAvailabilityInterface;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_paragraph\Service\CheckoutAttendeeSchemaService
 * @group myeventlane_checkout_paragraph
 */
final class CheckoutAttendeeSchemaServiceTest extends UnitTestCase {

  /**
   * @covers ::shouldCollectTicketHolders
   */
  public function testOnlyTicketBackedItemsCollectAttendeeDetails(): void {
    $classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $service = new CheckoutAttendeeSchemaService(
      $this->createMock(CartTicketAvailabilityInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $classifier,
    );

    $merchandise = $this->createMock(OrderItemInterface::class);
    $merchandise->method('hasField')->with('field_ticket_holder')->willReturn(TRUE);
    $ticket = $this->createMock(OrderItemInterface::class);
    $ticket->method('hasField')->with('field_ticket_holder')->willReturn(TRUE);
    $plainLine = $this->createMock(OrderItemInterface::class);
    $plainLine->method('hasField')->with('field_ticket_holder')->willReturn(FALSE);

    $classifier->method('isTicketBackedOrderItem')->willReturnMap([
      [$merchandise, FALSE],
      [$ticket, TRUE],
    ]);

    $this->assertFalse($service->shouldCollectTicketHolders($merchandise));
    $this->assertTrue($service->shouldCollectTicketHolders($ticket));
    $this->assertFalse($service->shouldCollectTicketHolders($plainLine));
  }

}
