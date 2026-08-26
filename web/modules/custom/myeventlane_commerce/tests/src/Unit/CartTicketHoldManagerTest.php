<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_commerce\Service\CartTicketHoldManager;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the cart ticket hold coordinator.
 */
#[CoversClass(CartTicketHoldManager::class)]
#[Group('myeventlane_commerce')]
final class CartTicketHoldManagerTest extends UnitTestCase {

  /**
   * Active timer state comes from the server reservation, not the browser.
   */
  public function testSummaryUsesActiveServerReservation(): void {
    $manager = $this->manager([
      'event_id' => 44,
      'quantity' => 2,
      'created' => 1_000,
      'expires' => 1_900,
    ]);

    $summary = $manager->summary($this->cart());

    $this->assertSame('active', $summary['state']);
    $this->assertTrue($summary['has_hold']);
    $this->assertSame(900, $summary['duration']);
    $this->assertSame(1_900, $summary['expires_at']);
    $this->assertSame(600, $summary['seconds_remaining']);
  }

  /**
   * Missing or expired reservation rows block checkout instead of faking time.
   */
  public function testSummaryMarksMissingReservationExpired(): void {
    $summary = $this->manager(NULL)->summary($this->cart());

    $this->assertSame('expired', $summary['state']);
    $this->assertTrue($summary['has_hold']);
    $this->assertNull($summary['expires_at']);
  }

  /**
   * Reservation keys remain stable from cart through order placement.
   */
  public function testReservationKeyIsCanonical(): void {
    $this->assertSame(
      'cart:27:event:44',
      CartTicketHoldManager::reservationKey(27, 44),
    );
  }

  /**
   * Creates a manager whose event has a fixed limited capacity.
   *
   * @param array{event_id: int, quantity: int, created: int, expires: int}|null $reservation
   *   Active reservation returned by the capacity service.
   */
  private function manager(?array $reservation): CartTicketHoldManager {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->with([44])->willReturn([44 => $event]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $capacity = $this->createMock(EventCapacityServiceInterface::class);
    $capacity->method('getReservationTtl')->willReturn(900);
    $capacity->method('getCapacityTotal')->with($event)->willReturn(30);
    $capacity->method('getActiveReservation')
      ->with('cart:27:event:44')
      ->willReturn($reservation);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_300);

    $availability = (new \ReflectionClass(TicketAvailabilityService::class))
      ->newInstanceWithoutConstructor();

    return new CartTicketHoldManager(
      $capacity,
      new CapacityOrderInspector(),
      $availability,
      $entityTypeManager,
      $time,
    );
  }

  /**
   * Builds a two-ticket cart for event 44.
   */
  private function cart(): OrderInterface {
    $target = new class() {

      /**
       * Matches Drupal field-item magic property naming.
       *
       * @phpcs:disable Drupal.NamingConventions.ValidVariableName.LowerCamelName
       */
      public int $target_id = 44;

      /**
       * The target field is populated.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('default');
    $item->method('getPurchasedEntity')->willReturn(NULL);
    $item->method('hasField')->with('field_target_event')->willReturn(TRUE);
    $item->method('get')->with('field_target_event')->willReturn($target);
    $item->method('getQuantity')->willReturn('2');

    $cart = $this->createMock(OrderInterface::class);
    $cart->method('id')->willReturn('27');
    $cart->method('getItems')->willReturn([$item]);
    return $cart;
  }

}
