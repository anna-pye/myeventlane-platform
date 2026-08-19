<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use Drupal\commerce_order\Event\OrderEvents;
use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\myeventlane_pro\EventSubscriber\ProSubscriptionSubscriber;
use Drupal\myeventlane_pro\Service\ProSubscriptionStateResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Proves the canonical Commerce subscription state categories.
 *
 * @group myeventlane_pro
 */
final class ProSubscriptionLifecycleStateTest extends TestCase {

  public function testOrderPaidRecoveryRunsAfterOtherOrderListeners(): void {
    $events = ProSubscriptionSubscriber::getSubscribedEvents();

    self::assertSame(['onOrderPaid', -100], $events[OrderEvents::ORDER_PAID]);
  }

  public function testTrialAndActiveAreEntitledStates(): void {
    $resolver = new ProSubscriptionStateResolver(new NullLogger());

    $trial = $this->subscriptionInState('trial', 'Trial');
    $active = $this->subscriptionInState('active', 'Active');

    self::assertTrue($resolver->isTrial($trial));
    self::assertTrue($resolver->isActive($trial));
    self::assertFalse($resolver->isPaymentFailure($trial));
    self::assertTrue($resolver->isActive($active));
    self::assertFalse($resolver->isTrial($active));
  }

  public function testFailedCancelledAndExpiredStatesAreNotEntitled(): void {
    $resolver = new ProSubscriptionStateResolver(new NullLogger());

    $pastDue = $this->subscriptionInState('past_due', 'Past due');
    $cancelled = $this->subscriptionInState('canceled', 'Canceled');
    $expired = $this->subscriptionInState('expired', 'Expired');

    self::assertTrue($resolver->isPaymentFailure($pastDue));
    self::assertFalse($resolver->isActive($pastDue));
    self::assertTrue($resolver->isCancelled($cancelled));
    self::assertFalse($resolver->isActive($cancelled));
    self::assertTrue($resolver->isExpired($expired));
    self::assertFalse($resolver->isActive($expired));
  }

  private function subscriptionInState(string $id, string $label): SubscriptionInterface {
    $subscription = $this->createMock(SubscriptionInterface::class);
    $subscription->method('getState')->willReturn(new class($id, $label) {
      public function __construct(
        private readonly string $id,
        private readonly string $label,
      ) {}

      public function getId(): string {
        return $this->id;
      }

      public function getLabel(): string {
        return $this->label;
      }
    });
    return $subscription;
  }

}
