<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Unit;

use Drupal\commerce_cart\Event\OrderItemComparisonFieldsEvent;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\myeventlane_boost\EventSubscriber\BoostOrderSubscriber;
use Drupal\myeventlane_boost\Service\BoostEntitlementManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the cart comparison functionality in BoostOrderSubscriber.
 *
 * @group myeventlane_boost
 */
final class CartComparisonSubscriberTest extends TestCase {

  /**
   * Tests that boost order items include field_target_event in comparison.
   */
  public function testBoostAddsComparisonFields(): void {
    // Create mock order item.
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('boost');
    $item->method('hasField')->with('field_target_event')->willReturn(TRUE);

    // Create event.
    $event = new OrderItemComparisonFieldsEvent(['existing'], $item);

    // Create subscriber with minimal mocks.
    $entitlementManager = $this->createMock(BoostEntitlementManager::class);
    $entitlementManager->method('isBoostOrderItem')->willReturn(TRUE);
    $logger = $this->createMock(LoggerInterface::class);
    $requestStack = $this->createMock(RequestStack::class);

    $subscriber = new BoostOrderSubscriber($entitlementManager, $logger, $requestStack);

    // Execute.
    $subscriber->onComparisonFields($event);

    // Assert.
    $fields = $event->getComparisonFields();
    $this->assertContains('field_target_event', $fields);
    $this->assertContains('existing', $fields);
  }

  /**
   * Tests that non-boost items are not modified.
   */
  public function testNonBoostItemsIgnored(): void {
    // Create mock non-boost order item.
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn('default');

    // Create event.
    $event = new OrderItemComparisonFieldsEvent(['existing'], $item);

    // Create subscriber with minimal mocks.
    $entitlementManager = $this->createMock(BoostEntitlementManager::class);
    $entitlementManager->method('isBoostOrderItem')->willReturn(FALSE);
    $logger = $this->createMock(LoggerInterface::class);
    $requestStack = $this->createMock(RequestStack::class);

    $subscriber = new BoostOrderSubscriber($entitlementManager, $logger, $requestStack);

    // Execute.
    $subscriber->onComparisonFields($event);

    // Assert: fields unchanged.
    $fields = $event->getComparisonFields();
    $this->assertEquals(['existing'], $fields);
  }

}
