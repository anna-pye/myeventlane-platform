<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_commerce\Service\CartTicketAvailabilityInterface;
use Drupal\myeventlane_commerce\Service\CartTicketHoldManager;
use Drupal\myeventlane_commerce\Service\CartTicketTierHoldStoreInterface;
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
   * A finite ticket tier produces a timer even when the event is unlimited.
   */
  public function testSummaryUsesFiniteTierHoldForUnlimitedEvent(): void {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn('ticket');
    $variation = $this->variation(101, $product);
    $cart = $this->refreshCart($variation);
    $tier = $this->tier(501, 50);

    $eventStorage = $this->createMock(EntityStorageInterface::class);
    $eventStorage->method('loadMultiple')->with([44])->willReturn([44 => $event]);
    $variationStorage = $this->createMock(EntityStorageInterface::class);
    $variationStorage->method('loadMultiple')->with([101])->willReturn([101 => $variation]);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['node', $eventStorage],
      ['commerce_product_variation', $variationStorage],
    ]);

    $capacity = $this->createMock(EventCapacityServiceInterface::class);
    $capacity->method('getReservationTtl')->willReturn(900);
    $capacity->method('getCapacityTotal')->with($event)->willReturn(NULL);
    $availability = $this->createMock(CartTicketAvailabilityInterface::class);
    $availability->method('resolveTierForVariation')->with($event, $variation)->willReturn($tier);
    $tierHolds = $this->createMock(CartTicketTierHoldStoreInterface::class);
    $tierHolds->method('getActive')->with(27, 44, 501)->willReturn([
      'event_id' => 44,
      'tier_id' => 501,
      'variation_id' => 101,
      'quantity' => 1,
      'created' => 1_000,
      'expires' => 1_900,
    ]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_300);

    $manager = new CartTicketHoldManager(
      $capacity,
      new CapacityOrderInspector(),
      $availability,
      $entityTypeManager,
      $time,
      $this->createMock(Connection::class),
      $tierHolds,
      $this->createMock(LockBackendInterface::class),
    );

    $summary = $manager->summary($cart);
    $this->assertSame('active', $summary['state']);
    $this->assertTrue($summary['has_hold']);
    $this->assertSame(1_900, $summary['expires_at']);
    $this->assertSame(600, $summary['seconds_remaining']);
  }

  /**
   * A later invalid tier must fail before any event reservation is written.
   */
  public function testRefreshValidatesEveryTierBeforeWritingReservation(): void {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');

    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn('ticket');

    $firstVariation = $this->variation(101, $product);
    $secondVariation = $this->variation(102, $product);
    $cart = $this->refreshCart($firstVariation, $secondVariation);

    $eventStorage = $this->createMock(EntityStorageInterface::class);
    $eventStorage->method('loadMultiple')->with([44])->willReturn([44 => $event]);
    $variationStorage = $this->createMock(EntityStorageInterface::class);
    $variationStorage->method('loadMultiple')
      ->with([101, 102])
      ->willReturn([101 => $firstVariation, 102 => $secondVariation]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['node', $eventStorage],
      ['commerce_product_variation', $variationStorage],
    ]);

    $availability = $this->createMock(CartTicketAvailabilityInterface::class);
    $tier = $this->tier(501, NULL);
    $availability->method('resolveTierForVariation')->willReturn($tier);
    $availability->expects($this->exactly(2))
      ->method('assertPaidVariationLineConstraints')
      ->willReturnCallback(static function (
        NodeInterface $event,
        ProductInterface $product,
        ProductVariationInterface $variation,
        int $quantity,
      ): void {
        if ((int) $variation->id() === 102) {
          throw new CapacityExceededException('This ticket type is sold out.');
        }
      });
    $availability->expects($this->never())->method('assertEventTotalBookable');

    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('startTransaction');
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())->method('release');

    $manager = new CartTicketHoldManager(
      $this->createMock(EventCapacityServiceInterface::class),
      new CapacityOrderInspector(),
      $availability,
      $entityTypeManager,
      $this->createMock(TimeInterface::class),
      $database,
      $this->createMock(CartTicketTierHoldStoreInterface::class),
      $lock,
    );

    $this->expectException(CapacityExceededException::class);
    $this->expectExceptionMessage('This ticket type is sold out.');
    $manager->refresh($cart);
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
    $variationStorage = $this->createMock(EntityStorageInterface::class);
    $variationStorage->method('loadMultiple')->with([])->willReturn([]);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['node', $storage],
      ['commerce_product_variation', $variationStorage],
    ]);

    $capacity = $this->createMock(EventCapacityServiceInterface::class);
    $capacity->method('getReservationTtl')->willReturn(900);
    $capacity->method('getCapacityTotal')->with($event)->willReturn(30);
    $capacity->method('getActiveReservation')
      ->with('cart:27:event:44')
      ->willReturn($reservation);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_300);

    $availability = $this->createMock(CartTicketAvailabilityInterface::class);

    return new CartTicketHoldManager(
      $capacity,
      new CapacityOrderInspector(),
      $availability,
      $entityTypeManager,
      $time,
      $this->createMock(Connection::class),
      $this->createMock(CartTicketTierHoldStoreInterface::class),
      $this->createMock(LockBackendInterface::class),
    );
  }

  /**
   * Builds a ticket tier with optional finite capacity.
   */
  private function tier(int $tierId, ?int $capacity): TicketTypeInterface {
    $capacityField = $this->createMock(FieldItemListInterface::class);
    $capacityField->method('isEmpty')->willReturn($capacity === NULL);
    $capacityField->method('getValue')->willReturn(
      $capacity === NULL ? [] : [['value' => $capacity]],
    );

    $tier = $this->createMock(TicketTypeInterface::class);
    $tier->method('id')->willReturn($tierId);
    $tier->method('get')->with('capacity')->willReturn($capacityField);
    return $tier;
  }

  /**
   * Builds a product variation used by a refresh fixture.
   */
  private function variation(int $variationId, ProductInterface $product): ProductVariationInterface {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('id')->willReturn($variationId);
    $variation->method('getProduct')->willReturn($product);
    return $variation;
  }

  /**
   * Builds a two-tier cart for event 44.
   */
  private function refreshCart(
    ProductVariationInterface $firstVariation,
    ?ProductVariationInterface $secondVariation = NULL,
  ): OrderInterface {
    $items = [$this->refreshItem($firstVariation)];
    if ($secondVariation !== NULL) {
      $items[] = $this->refreshItem($secondVariation);
    }
    $cart = $this->createMock(OrderInterface::class);
    $cart->method('id')->willReturn('27');
    $cart->method('getItems')->willReturn($items);
    return $cart;
  }

  /**
   * Builds one ticket line for the refresh fixture.
   */
  private function refreshItem(ProductVariationInterface $variation): OrderItemInterface {
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
    $purchased = new class($variation) {

      /**
       * Creates a populated purchased-entity field item.
       */
      public function __construct(public ProductVariationInterface $entity) {}

      /**
       * The purchased-entity field is populated.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('default');
    $item->method('getPurchasedEntity')->willReturn($variation);
    $item->method('hasField')->willReturnMap([
      ['field_target_event', TRUE],
      ['purchased_entity', TRUE],
    ]);
    $item->method('get')->willReturnMap([
      ['field_target_event', $target],
      ['purchased_entity', $purchased],
    ]);
    $item->method('getQuantity')->willReturn('1');
    return $item;
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
    $item->method('hasField')->willReturnMap([
      ['field_target_event', TRUE],
      ['purchased_entity', FALSE],
    ]);
    $item->method('get')->with('field_target_event')->willReturn($target);
    $item->method('getQuantity')->willReturn('2');

    $cart = $this->createMock(OrderInterface::class);
    $cart->method('id')->willReturn('27');
    $cart->method('getItems')->willReturn([$item]);
    return $cart;
  }

}
