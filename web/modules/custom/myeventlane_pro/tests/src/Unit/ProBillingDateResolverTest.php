<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_pro\Service\ProBillingDateResolver;
use PHPUnit\Framework\TestCase;

/**
 * @group myeventlane_pro
 */
final class ProBillingDateResolverTest extends TestCase {

  private const NOW = 2_000_000_000;

  public function testFutureTrialEndIsTheFirstBillingDate(): void {
    $subscription = $this->createMock(SubscriptionInterface::class);
    $subscription->method('getTrialEndTime')->willReturn(self::NOW + (30 * 86400));

    self::assertSame([
      'timestamp' => self::NOW + (30 * 86400),
      'days' => 30,
      'stale' => FALSE,
    ], $this->resolver()->resolve($subscription, TRUE));
  }

  public function testMissingTrialEndDoesNotInventADate(): void {
    $subscription = $this->createMock(SubscriptionInterface::class);
    $subscription->method('getTrialEndTime')->willReturn(0);

    self::assertSame([
      'timestamp' => NULL,
      'days' => NULL,
      'stale' => FALSE,
    ], $this->resolver()->resolve($subscription, TRUE));
  }

  public function testPastTrialEndIsFlaggedAsStale(): void {
    $subscription = $this->createMock(SubscriptionInterface::class);
    $subscription->method('getTrialEndTime')->willReturn(self::NOW - 1);

    self::assertSame([
      'timestamp' => NULL,
      'days' => NULL,
      'stale' => TRUE,
    ], $this->resolver()->resolve($subscription, TRUE));
  }

  public function testActivePlanUsesNextRenewalTime(): void {
    $subscription = $this->createMock(SubscriptionInterface::class);
    $subscription->method('getNextRenewalTime')->willReturn(self::NOW + (2 * 86400));

    self::assertSame([
      'timestamp' => self::NOW + (2 * 86400),
      'days' => 2,
      'stale' => FALSE,
    ], $this->resolver()->resolve($subscription, FALSE));
  }

  private function resolver(): ProBillingDateResolver {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);
    return new ProBillingDateResolver($time);
  }

}
