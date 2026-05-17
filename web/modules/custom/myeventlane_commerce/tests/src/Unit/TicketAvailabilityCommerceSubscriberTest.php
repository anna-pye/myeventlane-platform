<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_commerce\EventSubscriber\TicketAvailabilityCommerceSubscriber;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\EventSubscriber\TicketAvailabilityCommerceSubscriber
 *
 * @group myeventlane_commerce
 */
final class TicketAvailabilityCommerceSubscriberTest extends UnitTestCase {

  /**
   * @return array<string, bool>
   */
  public static function operationalBundleProvider(): array {
    $cases = [];
    foreach (OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES as $bundle) {
      $cases[$bundle] = [$bundle, TRUE];
    }
    $cases['default_ticket_product'] = ['default', FALSE];
    return $cases;
  }

  /**
   * @covers ::shouldValidateAsTicket
   * @dataProvider operationalBundleProvider
   */
  public function testShouldValidateAsTicketSkipsOperationalMerchandise(string $bundle, bool $skip_ticket_rules): void {
    $subscriber = $this->subscriber();
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('bundle')->willReturn('default');
    $order_item->method('hasField')->with('field_target_event')->willReturn(TRUE);
    $order_item->method('get')->willReturnCallback(function (string $field) {
      if ($field === 'field_target_event') {
        $field_item = new class {

          public bool $isEmpty = FALSE;

          public int $target_id = 1584;

        };
        return $field_item;
      }
      if ($field === 'purchased_entity') {
        $field_item = new class($this->variation($bundle)) {

          public function __construct(public object $entity) {}

        };
        return $field_item;
      }
      return NULL;
    });

    $should_validate = $this->invokeShouldValidateAsTicket($subscriber, $order_item);
    $this->assertSame(!$skip_ticket_rules, $should_validate);
  }

  public function testShouldValidateAsTicketStillAppliesToTicketLines(): void {
    $subscriber = $this->subscriber();
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('bundle')->willReturn('default');
    $order_item->method('hasField')->with('field_target_event')->willReturn(TRUE);
    $order_item->method('get')->willReturnCallback(function (string $field) {
      if ($field === 'field_target_event') {
        $field_item = new class {

          public bool $isEmpty = FALSE;

          public int $target_id = 1584;

        };
        return $field_item;
      }
      if ($field === 'purchased_entity') {
        $field_item = new class($this->variation('default')) {

          public function __construct(public object $entity) {}

        };
        return $field_item;
      }
      return NULL;
    });

    $this->assertTrue($this->invokeShouldValidateAsTicket($subscriber, $order_item));
  }

  private function subscriber(): TicketAvailabilityCommerceSubscriber {
    return new TicketAvailabilityCommerceSubscriber(
      $this->createMock(TicketAvailabilityService::class),
      new CapacityOrderInspector(),
      $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class),
      $this->createMock(LockBackendInterface::class),
      $this->createMock(RequestStack::class),
      $this->getStringTranslationStub(),
      $this->createMock(LoggerInterface::class),
    );
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
    $method->setAccessible(TRUE);
    return (bool) $method->invoke($subscriber, $order_item);
  }

}
