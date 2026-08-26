<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_commerce\Service\CartTicketTierHoldStoreInterface;
use Drupal\myeventlane_commerce\Service\TicketCapacityService;
use Drupal\myeventlane_commerce\Service\TicketVariationSoldService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the canonical ticket-tier held count.
 */
#[CoversClass(TicketCapacityService::class)]
#[Group('myeventlane_commerce')]
final class TicketCapacityServiceTest extends UnitTestCase {

  /**
   * Cart holds count toward capacity while the cart's own key is excluded.
   */
  public function testHeldCountIncludesCartHoldsAndForwardsExclusion(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')
      ->with('mel_ticket_waitlist_entry')
      ->willReturn(FALSE);

    $cartHolds = $this->createMock(CartTicketTierHoldStoreInterface::class);
    $cartHolds->expects($this->once())
      ->method('getHeldQuantity')
      ->with(44, 501, 'cart:27:event:44:tier:501')
      ->willReturn(2);

    $variationSold = (new \ReflectionClass(TicketVariationSoldService::class))
      ->newInstanceWithoutConstructor();
    $service = new TicketCapacityService(
      $entityTypeManager,
      $this->createMock(Connection::class),
      $variationSold,
      $this->createMock(TimeInterface::class),
      $cartHolds,
    );

    $this->assertSame(
      2,
      $service->getHeld(44, 501, 'cart:27:event:44:tier:501'),
    );
  }

}
