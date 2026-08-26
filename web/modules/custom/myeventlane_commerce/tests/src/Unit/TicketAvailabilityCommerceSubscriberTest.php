<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_commerce\EventSubscriber\TicketAvailabilityCommerceSubscriber;
use Drupal\myeventlane_commerce\Service\CartTicketAvailabilityInterface;
use Drupal\myeventlane_commerce\Service\CartTicketHoldManager;
use Drupal\myeventlane_commerce\Service\CartTicketTierHoldStoreInterface;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\Tests\UnitTestCase;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests ticket availability subscriber routing.
 */
#[CoversClass(TicketAvailabilityCommerceSubscriber::class)]
#[Group('myeventlane_commerce')]
final class TicketAvailabilityCommerceSubscriberTest extends UnitTestCase {

  /**
   * @return array<string, array{string, bool}>
   */
  public static function operationalBundleProvider(): array {
    $cases = [];
    foreach (OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES as $bundle) {
      $cases[$bundle] = [$bundle, TRUE];
    }
    $cases['default_ticket_product'] = ['default', FALSE];
    return $cases;
  }

  #[DataProvider('operationalBundleProvider')]
  public function testShouldValidateAsTicketSkipsOperationalMerchandise(string $bundle, bool $skip_ticket_rules): void {
    $subscriber = $this->subscriber();
    $variation = $this->variation($bundle);
    $order_item = $this->orderItemWithTargetEventAndVariation($variation);

    $should_validate = $this->invokeShouldValidateAsTicket($subscriber, $order_item);
    $this->assertSame(!$skip_ticket_rules, $should_validate);
  }

  public function testShouldValidateAsTicketStillAppliesToTicketLines(): void {
    $subscriber = $this->subscriber();
    $order_item = $this->orderItemWithTargetEventAndVariation($this->variation('default'));

    $this->assertTrue($this->invokeShouldValidateAsTicket($subscriber, $order_item));
  }

  /**
   * The subscriber follows updates, removals, and an emptied cart.
   */
  public function testSubscriberTracksTheWholeCartHoldLifecycle(): void {
    $events = TicketAvailabilityCommerceSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(CartEvents::CART_ENTITY_ADD, $events);
    $this->assertArrayHasKey(CartEvents::CART_ORDER_ITEM_UPDATE, $events);
    $this->assertArrayHasKey(CartEvents::CART_ORDER_ITEM_REMOVE, $events);
    $this->assertArrayHasKey(CartEvents::CART_EMPTY, $events);
  }

  /**
   * Successful placement releases both global and ticket-tier cart holds.
   */
  public function testPostPlacementReleasesCartHolds(): void {
    $variation = $this->variation('default');
    $item = $this->orderItemWithTargetEventAndVariation($variation);
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn('27');
    $order->method('getItems')->willReturn([$item]);

    $capacity = $this->createMock(EventCapacityServiceInterface::class);
    $capacity->expects($this->once())
      ->method('releaseReservation')
      ->with('cart:27:event:1584');
    $tierHolds = $this->createMock(CartTicketTierHoldStoreInterface::class);
    $tierHolds->expects($this->once())
      ->method('releaseEvent')
      ->with(27, 1584);
    $manager = new CartTicketHoldManager(
      $capacity,
      new CapacityOrderInspector(),
      $this->createMock(CartTicketAvailabilityInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(Connection::class),
      $tierHolds,
      $this->createMock(LockBackendInterface::class),
    );
    $subscriber = new TicketAvailabilityCommerceSubscriber(
      $manager,
      new CapacityOrderInspector(),
      $this->createMock(LockBackendInterface::class),
      new RequestStack(),
      $this->createMock(TranslationInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $event = $this->createMock(WorkflowTransitionEvent::class);
    $event->method('getEntity')->willReturn($order);

    $subscriber->onOrderPlacePostTransition($event);
  }

  private function orderItemWithTargetEventAndVariation(
    ProductVariationInterface $variation,
  ): OrderItemInterface&MockObject {
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('bundle')->willReturn('default');
    $order_item->method('hasField')->with('field_target_event')->willReturn(TRUE);
    $order_item->method('getPurchasedEntity')->willReturn($variation);
    $order_item->method('get')->willReturnCallback(function (string $field) use ($variation) {
      if ($field === 'field_target_event') {
        return $this->fieldTargetEventStub();
      }
      if ($field === 'purchased_entity') {
        return $this->purchasedEntityFieldStub($variation);
      }
      return NULL;
    });
    return $order_item;
  }

  private function fieldTargetEventStub(): object {
    return new class {

      public function isEmpty(): bool {
        return FALSE;
      }

      public int $target_id = 1584;

    };
  }

  private function purchasedEntityFieldStub(ProductVariationInterface $variation): object {
    return new class($variation) {

      public function __construct(public ProductVariationInterface $entity) {}

      public function isEmpty(): bool {
        return FALSE;
      }

    };
  }

  private function subscriber(): TicketAvailabilityCommerceSubscriber {
    $ref = new \ReflectionClass(TicketAvailabilityCommerceSubscriber::class);
    /** @var \Drupal\myeventlane_commerce\EventSubscriber\TicketAvailabilityCommerceSubscriber $subscriber */
    $subscriber = $ref->newInstanceWithoutConstructor();
    $order_inspector = new CapacityOrderInspector();
    $prop = $ref->getProperty('orderInspector');
    $prop->setValue($subscriber, $order_inspector);
    return $subscriber;
  }

  private function variation(string $product_bundle): ProductVariationInterface&MockObject {
    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn($product_bundle);

    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('getProduct')->willReturn($product);
    return $variation;
  }

  private function invokeShouldValidateAsTicket(
    TicketAvailabilityCommerceSubscriber $subscriber,
    OrderItemInterface $order_item,
  ): bool {
    $method = new \ReflectionMethod($subscriber, 'shouldValidateAsTicket');
    return (bool) $method->invoke($subscriber, $order_item);
  }

}
