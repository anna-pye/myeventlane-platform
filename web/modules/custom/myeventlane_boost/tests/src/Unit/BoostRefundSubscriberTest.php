<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\myeventlane_boost\EventSubscriber\BoostRefundSubscriber;
use Drupal\myeventlane_boost\Service\BoostEntitlementManager;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\state_machine\Plugin\Workflow\WorkflowInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowTransition;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the BoostRefundSubscriber.
 *
 * @group myeventlane_boost
 * @coversDefaultClass \Drupal\myeventlane_boost\EventSubscriber\BoostRefundSubscriber
 */
final class BoostRefundSubscriberTest extends TestCase {

  /**
   * Tests that refund revokes boost from targeted event.
   */
  public function testRefundRevokesBoost(): void {
    $entitlementManager = $this->createMock(BoostEntitlementManager::class);
    $entitlementManager->expects($this->once())
      ->method('revokeEntitlementsForOrder')
      ->willReturn(1);

    $logger = $this->createMock(LoggerInterface::class);

    // Create subscriber.
    $subscriber = new BoostRefundSubscriber($entitlementManager, $logger);

    // Create mock order.
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn(99);

    // Create mock payment.
    $payment = $this->createMock(PaymentInterface::class);
    $payment->method('getOrder')->willReturn($order);
    $payment->method('id')->willReturn(77);

    // Create workflow transition event.
    $transition = $this->createMock(WorkflowTransition::class);
    $transition->method('getId')->willReturn('refund');
    $workflow = $this->createMock(WorkflowInterface::class);

    $event = new WorkflowTransitionEvent($transition, $workflow, $payment, 'payment_state');

    // Execute.
    $subscriber->onRefundOrVoid($event);
  }

}
