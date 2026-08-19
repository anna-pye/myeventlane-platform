<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\myeventlane_pro\Service\ProBillingSchedule;
use Drupal\myeventlane_pro\Service\ProTrialEligibility;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the once-per-organiser Pro trial policy.
 *
 * @group myeventlane_pro
 */
final class ProTrialEligibilityTest extends TestCase {

  /**
   * A user with no Pro subscription history receives the trial.
   */
  public function testOrganiserWithoutSubscriptionHistoryIsEligible(): void {
    $service = $this->serviceReturningSubscriptionIds([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(42);

    self::assertTrue($service->isEligible($user));
  }

  /**
   * Any historical Pro subscription consumes the one-time trial.
   */
  public function testAnyHistoricalProSubscriptionConsumesTrial(): void {
    $service = $this->serviceReturningSubscriptionIds([6]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(42);

    self::assertFalse($service->isEligible($user));
  }

  /**
   * Both trial and paid restart schedules are recognised as MEL Pro.
   */
  public function testBothBillingSchedulesAreCanonicalProSchedules(): void {
    self::assertTrue(ProBillingSchedule::isPro(ProBillingSchedule::TRIAL));
    self::assertTrue(ProBillingSchedule::isPro(ProBillingSchedule::RESTART));
    self::assertFalse(ProBillingSchedule::isPro('event_ticket_payments'));
  }

  /**
   * Returning-organiser reminders do not promise another free trial.
   */
  public function testAbandonedCartCopyDistinguishesPaidRestart(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $job = file_get_contents($moduleRoot . '/src/Plugin/AdvancedQueue/JobType/ProAbandonedCartJob.php');
    $weekOne = file_get_contents($moduleRoot . '/config/install/myeventlane_messaging.template.pro_cart_abandoned_w1.yml');
    $weekTwo = file_get_contents($moduleRoot . '/config/install/myeventlane_messaging.template.pro_cart_abandoned_w2.yml');

    self::assertIsString($job);
    self::assertStringContainsString('resolveTrialDays($order)', $job);
    self::assertStringContainsString('Pro restarts at %s per month', $job);
    self::assertIsString($weekOne);
    self::assertStringContainsString('{% if trial_days > 0 %}', $weekOne);
    self::assertStringContainsString('There is no new trial period.', $weekOne);
    self::assertIsString($weekTwo);
    self::assertStringContainsString('{% if trial_days > 0 %}', $weekTwo);
    self::assertStringContainsString('Pro restarts at {{ monthly_price }}', $weekTwo);
  }

  /**
   * Starting Pro never deletes an existing ticket or Boost cart.
   */
  public function testSubscribeFormPreservesNonProCart(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/ProSubscribeForm.php');

    self::assertIsString($form);
    self::assertStringNotContainsString('emptyCart($cart)', $form);
    self::assertStringContainsString('Preserving non-Pro cart', $form);
    self::assertStringContainsString("setRedirect('commerce_cart.page')", $form);
  }

  /**
   * Builds an eligibility service with a deterministic history query.
   *
   * @param int[] $subscriptionIds
   *   Subscription IDs returned by the query.
   */
  private function serviceReturningSubscriptionIds(array $subscriptionIds): ProTrialEligibility {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($subscriptionIds);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('commerce_subscription')
      ->willReturn($storage);

    return new ProTrialEligibility($entityTypeManager);
  }

}
