<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_payment\Event\PaymentEvents;
use Drupal\commerce_payment\Event\RequirePaymentMethodEvent;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\myeventlane_pro\EventSubscriber\ProTrialPaymentMethodSubscriber;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_pro\EventSubscriber\ProTrialPaymentMethodSubscriber
 * @group myeventlane_pro
 */
final class ProTrialPaymentMethodSubscriberTest extends UnitTestCase {

  /**
   * @covers ::getSubscribedEvents
   * @covers ::onRequirePaymentMethod
   */
  public function testProOrderRequiresPaymentMethod(): void {
    $event = new RequirePaymentMethodEvent($this->orderWithVariationType('mel_pro_subscription_variation'), FALSE);

    (new ProTrialPaymentMethodSubscriber())->onRequirePaymentMethod($event);

    $this->assertTrue($event->isRequired());
    $this->assertArrayHasKey(PaymentEvents::REQUIRE_PAYMENT_METHOD, ProTrialPaymentMethodSubscriber::getSubscribedEvents());
  }

  /**
   * @covers ::onRequirePaymentMethod
   */
  public function testNonProZeroBalanceOrderRemainsOptional(): void {
    $event = new RequirePaymentMethodEvent($this->orderWithVariationType('ticket_variation'), FALSE);

    (new ProTrialPaymentMethodSubscriber())->onRequirePaymentMethod($event);

    $this->assertFalse($event->isRequired());
  }

  /**
   * Builds an order containing one variation of the requested bundle.
   */
  private function orderWithVariationType(string $bundle): OrderInterface {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('getEntityTypeId')->willReturn('commerce_product_variation');
    $variation->method('bundle')->willReturn($bundle);

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('getPurchasedEntity')->willReturn($variation);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$item]);
    return $order;
  }

}
