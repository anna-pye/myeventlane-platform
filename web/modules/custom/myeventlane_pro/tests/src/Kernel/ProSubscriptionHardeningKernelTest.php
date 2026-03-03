<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Kernel;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\commerce_recurring\Event\SubscriptionEvent;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_pro\EventSubscriber\ProSubscriptionSubscriber;
use Drupal\myeventlane_pro\Service\ProSubscriptionHealthService;
use Drupal\myeventlane_pro\Service\ProSubscriptionStateResolver;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Kernel tests for Pro subscription commercial hardening.
 *
 * @group myeventlane_pro
 */
final class ProSubscriptionHardeningKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_order',
    'commerce_recurring',
    'state_machine',
    'advancedqueue',
    'myeventlane_messaging',
    'myeventlane_vendor',
    'myeventlane_pro',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('commerce_subscription');
    $this->installSchema('myeventlane_pro', [
      'myeventlane_pro_renewal_notifications',
      'myeventlane_pro_failed_payment_sequence',
    ]);
    $this->installConfig(['myeventlane_pro']);

    if (!Role::load('pro_organiser')) {
      Role::create([
        'id' => 'pro_organiser',
        'label' => 'Pro Organiser',
      ])->save();
    }
  }

  /**
   * Grace period is set when a subscription enters failed-payment state.
   */
  public function testGracePeriodSetOnFailedState(): void {
    $user = $this->createManagedProUser('grace_failed@example.com');

    $subscription = $this->createMock(SubscriptionInterface::class);
    $subscription->method('id')->willReturn(1001);
    $subscription->method('getCustomer')->willReturn($user);
    $subscription->method('getState')->willReturn(new class {
      public function getId(): string {
        return 'past_due';
      }
      public function getLabel(): string {
        return 'Past due';
      }
    });

    $subscriber = new ProSubscriptionSubscriber(
      $this->container->get('myeventlane_pro.entitlement_reconciler'),
      $this->container->get('myeventlane_pro.subscription_state_resolver'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.myeventlane_pro'),
    );
    $subscriber->onSubscriptionInsert(new SubscriptionEvent($subscription));

    $reloaded = User::load((int) $user->id());
    $this->assertNotNull($reloaded);
    $this->assertTrue($reloaded->hasField('field_pro_grace_expires'));
    $this->assertGreaterThan(0, (int) $reloaded->get('field_pro_grace_expires')->value);
  }

  /**
   * Pro role remains while user is still within grace period.
   */
  public function testRoleNotRemovedBeforeGraceExpiry(): void {
    $user = $this->createManagedProUser('grace_keep@example.com');
    $user->set('field_pro_grace_expires', time() + 86400);
    $user->save();

    $this->container->get('myeventlane_pro.entitlement_reconciler')->reconcileUser($user);

    $reloaded = User::load((int) $user->id());
    $this->assertNotNull($reloaded);
    $this->assertTrue($reloaded->hasRole('pro_organiser'));
  }

  /**
   * Pro role is revoked once grace expires and subscription is not active.
   */
  public function testRoleRemovedAfterGraceExpiry(): void {
    $user = $this->createManagedProUser('grace_revoke@example.com');
    $user->set('field_pro_grace_expires', time() - 10);
    $user->save();

    $reconciler = $this->container->get('myeventlane_pro.entitlement_reconciler');
    $reconciler->reconcileExpiredGracePeriods();

    $reloaded = User::load((int) $user->id());
    $this->assertNotNull($reloaded);
    $this->assertFalse($reloaded->hasRole('pro_organiser'));
    $this->assertSame('', (string) $reloaded->get('field_pro_grace_expires')->value);
  }

  /**
   * Renewal reminder rows are scheduled idempotently.
   */
  public function testRenewalReminderScheduledOnce(): void {
    $scheduler = $this->container->get('myeventlane_pro.subscription_lifecycle_scheduler');
    $method = new \ReflectionMethod($scheduler, 'insertRenewalReminder');
    $method->setAccessible(TRUE);

    $first = $method->invoke($scheduler, 12345, 2000000000);
    $second = $method->invoke($scheduler, 12345, 2000000000);

    $count = (int) $this->container->get('database')
      ->select('myeventlane_pro_renewal_notifications', 'r')
      ->condition('subscription_id', 12345)
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertSame(1, $first);
    $this->assertSame(0, $second);
    $this->assertSame(1, $count);
  }

  /**
   * Churn is cancelled during period divided by active at period start.
   */
  public function testChurnRateCalculation(): void {
    $now = time();
    $periodStart = $now - (30 * 86400);

    $activeSubscription = $this->createMock(SubscriptionInterface::class);
    $activeSubscription->method('getCreatedTime')->willReturn($periodStart - 3600);
    $activeSubscription->method('getChangedTime')->willReturn($periodStart - 1800);
    $activeSubscription->method('getState')->willReturn(new class {
      public function getId(): string {
        return 'active';
      }
      public function getLabel(): string {
        return 'Active';
      }
    });

    $cancelledSubscription = $this->createMock(SubscriptionInterface::class);
    $cancelledSubscription->method('getCreatedTime')->willReturn($periodStart - 7200);
    $cancelledSubscription->method('getChangedTime')->willReturn($now - 60);
    $cancelledSubscription->method('getState')->willReturn(new class {
      public function getId(): string {
        return 'canceled';
      }
      public function getLabel(): string {
        return 'Canceled';
      }
    });

    $query = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['accessCheck', 'condition', 'sort', 'execute'])
      ->getMock();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([1, 2]);

    $storage = $this->getMockBuilder(\Drupal\Core\Entity\EntityStorageInterface::class)
      ->addMethods(['getQuery'])
      ->getMock();
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn([
      1 => $activeSubscription,
      2 => $cancelledSubscription,
    ]);

    $entityTypeManager = $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('commerce_subscription')->willReturn($storage);

    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);

    $healthService = new ProSubscriptionHealthService(
      $entityTypeManager,
      $this->container->get('config.factory'),
      $time,
      $this->container->get('database'),
      new ProSubscriptionStateResolver($this->container->get('logger.channel.myeventlane_pro')),
      $this->container->get('logger.channel.myeventlane_pro'),
    );

    $this->assertSame(100.0, $healthService->getChurnRate(30));
  }

  /**
   * Creates a managed Pro user fixture.
   */
  private function createManagedProUser(string $email): User {
    $name = strstr($email, '@', TRUE) ?: 'pro_user';
    $user = User::create([
      'name' => $name,
      'mail' => $email,
      'status' => 1,
      'roles' => ['authenticated', 'pro_organiser'],
      'field_pro_subscription_managed' => 1,
    ]);
    $user->save();
    return $user;
  }

}
