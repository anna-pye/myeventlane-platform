<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Kernel;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\commerce_recurring\Entity\BillingScheduleInterface;
use Drupal\commerce_recurring\Event\PaymentDeclinedEvent;
use Drupal\commerce_recurring\Event\SubscriptionEvent;
use Drupal\commerce_recurring\RecurringOrderManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_store\Entity\StoreType;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_pro\Service\ProEntitlementReconciler;
use Drupal\myeventlane_pro\EventSubscriber\ProSubscriptionSubscriber;
use Drupal\myeventlane_pro\Service\ProSubscriptionHealthService;
use Drupal\myeventlane_pro\Service\ProSubscriptionStateResolver;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Kernel tests for Pro subscription commercial hardening.
 *
 * @group myeventlane_pro
 */
#[RunTestsInSeparateProcesses]
final class ProSubscriptionHardeningKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'address',
    'block',
    'user',
    'field',
    'field_ui',
    'file',
    'node',
    'path_alias',
    'datetime',
    'datetime_range',
    'image',
    'link',
    'media',
    'media_library',
    'text',
    'views',
    'options',
    'serialization',
    'taxonomy',
    'token',
    'paragraphs',
    'entity',
    'entity_reference_revisions',
    'geofield',
    'flag',
    'focal_point',
    'crop',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_order',
    'commerce_checkout',
    'commerce_product',
    'commerce_cart',
    'commerce_payment',
    'commerce_stripe',
    'commerce_recurring',
    'state_machine',
    'advancedqueue',
    'mel_ticket',
    'myeventlane_account',
    'myeventlane_ai',
    'myeventlane_analytics',
    'myeventlane_api',
    'myeventlane_attendee',
    'myeventlane_core',
    'myeventlane_capacity',
    'myeventlane_commerce',
    'myeventlane_donations',
    'myeventlane_dashboard',
    'myeventlane_event',
    'myeventlane_event_state',
    'myeventlane_event_attendees',
    'myeventlane_event_studio',
    'myeventlane_checkout_paragraph',
    'myeventlane_checkout_flow',
    'myeventlane_legal',
    'myeventlane_location',
    'myeventlane_metrics',
    'myeventlane_questions',
    'myeventlane_rsvp',
    'myeventlane_schema',
    'myeventlane_surface',
    'myeventlane_tickets',
    'myeventlane_messaging',
    'myeventlane_vendor_analytics',
    'myeventlane_vendor',
    'myeventlane_venue',
    'myeventlane_boost',
    'myeventlane_pro',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('commerce_subscription');
    $this->installEntitySchema('myeventlane_vendor');
    $this->installSchema('myeventlane_messaging', [
      'myeventlane_message',
      'myeventlane_message_preference',
    ]);
    $this->installSchema('myeventlane_pro', [
      'myeventlane_pro_renewal_notifications',
      'myeventlane_pro_failed_payment_sequence',
    ]);
    if (!StoreType::load('online')) {
      StoreType::create([
        'id' => 'online',
        'label' => 'Online',
      ])->save();
    }
    $this->installConfig(['myeventlane_pro']);

    if (!Role::load('mel_pro')) {
      Role::create([
        'id' => 'mel_pro',
        'label' => 'MEL Pro',
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

    $subscriber = $this->container->get('myeventlane_pro.pro_subscription_subscriber');
    $this->assertInstanceOf(ProSubscriptionSubscriber::class, $subscriber);
    $subscriber->onSubscriptionInsert(new SubscriptionEvent($subscription));

    $reloaded = User::load((int) $user->id());
    $this->assertNotNull($reloaded);
    $this->assertTrue($reloaded->hasField('field_pro_grace_expires'));
    $this->assertGreaterThan(0, (int) $reloaded->get('field_pro_grace_expires')->value);
  }

  /**
   * The real Commerce decline and recovery events drive grace and dunning.
   */
  public function testPaymentDeclinedAndPaidEventLifecycle(): void {
    $user = $this->createManagedProUser('declined_event@example.com');

    $schedule = $this->createMock(BillingScheduleInterface::class);
    $schedule->method('id')->willReturn('mel_pro_monthly');

    $subscription = $this->createMock(SubscriptionInterface::class);
    $subscription->method('id')->willReturn(1002);
    $subscription->method('getCustomer')->willReturn($user);
    $subscription->method('getBillingSchedule')->willReturn($schedule);
    $subscription->method('getState')->willReturn(new class {
      public function getId(): string {
        return 'active';
      }
      public function getLabel(): string {
        return 'Active';
      }
    });

    $order = $this->createMock(OrderInterface::class);
    $orderManager = $this->createMock(RecurringOrderManagerInterface::class);
    $orderManager->method('collectSubscriptions')->with($order)->willReturn([$subscription]);

    $subscriber = new ProSubscriptionSubscriber(
      $this->reconcilerWithSubscription($subscription),
      $this->container->get('myeventlane_pro.subscription_state_resolver'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.myeventlane_pro'),
      $this->container->get('myeventlane_pro.pro_boost_provisioner'),
      $this->container->get('entity_type.manager'),
      $this->container->get('myeventlane_pro.subscription_lifecycle_scheduler'),
      $this->container->get('config.factory'),
      $orderManager,
    );

    $declinedEvent = new PaymentDeclinedEvent($order, 1, 0, 3);
    $subscriber->onPaymentDeclined($declinedEvent);
    $this->assertTrue($declinedEvent->isPropagationStopped());

    $reloaded = User::load((int) $user->id());
    $this->assertNotNull($reloaded);
    $this->assertGreaterThan(time(), (int) $reloaded->get('field_pro_grace_expires')->value);
    $this->assertTrue($reloaded->hasRole('mel_pro'));

    $rows = $this->container->get('database')
      ->select('myeventlane_pro_failed_payment_sequence', 'f')
      ->fields('f', ['step', 'status'])
      ->condition('subscription_id', 1002)
      ->orderBy('step')
      ->execute()
      ->fetchAllKeyed();
    $this->assertSame([0 => 'sent', 3 => 'scheduled', 6 => 'scheduled'], array_map('strval', $rows));

    // A duplicate first-attempt event must not create another message.
    $subscriber->onPaymentDeclined(new PaymentDeclinedEvent($order, 1, 0, 3));
    $messageCount = $this->container->get('database')
      ->select('myeventlane_message', 'm')
      ->condition('template', 'pro_subscription_payment_failed_day_0')
      ->condition('recipient', 'declined_event@example.com')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame('1', (string) $messageCount);

    $subscriber->onOrderPaid(new OrderEvent($order));
    $reloaded = User::load((int) $user->id());
    $this->assertNotNull($reloaded);
    $this->assertSame('', (string) $reloaded->get('field_pro_grace_expires')->value);
    $this->assertTrue($reloaded->hasRole('mel_pro'));
    $rows = $this->container->get('database')
      ->select('myeventlane_pro_failed_payment_sequence', 'f')
      ->fields('f', ['step', 'status'])
      ->condition('subscription_id', 1002)
      ->orderBy('step')
      ->execute()
      ->fetchAllKeyed();
    $this->assertSame([0 => 'sent', 3 => 'cancelled', 6 => 'cancelled'], array_map('strval', $rows));

    $recoveryCount = $this->container->get('database')
      ->select('myeventlane_message', 'm')
      ->condition('template', 'pro_subscription_payment_recovered')
      ->condition('recipient', 'declined_event@example.com')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame('1', (string) $recoveryCount);

    // A replayed order-paid event must not duplicate the recovery email.
    $subscriber->onOrderPaid(new OrderEvent($order));
    $recoveryCount = $this->container->get('database')
      ->select('myeventlane_message', 'm')
      ->condition('template', 'pro_subscription_payment_recovered')
      ->condition('recipient', 'declined_event@example.com')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame('1', (string) $recoveryCount);
  }

  /**
   * Trial and active subscriptions both grant managed Pro entitlement.
   */
  public function testTrialAndActiveSubscriptionsGrantEntitlement(): void {
    foreach (['trial', 'active'] as $state) {
      $user = User::create([
        'name' => $state . '_entitlement',
        'mail' => $state . '_entitlement@example.com',
        'status' => 1,
      ]);
      $user->save();

      $subscription = $this->createMock(SubscriptionInterface::class);
      $subscription->method('getState')->willReturn(new class($state) {
        public function __construct(private readonly string $state) {}
        public function getId(): string {
          return $this->state;
        }
        public function getLabel(): string {
          return ucfirst($this->state);
        }
      });

      $this->reconcilerWithSubscription($subscription)->reconcileUser($user);

      $reloaded = User::load((int) $user->id());
      $this->assertNotNull($reloaded);
      $this->assertTrue($reloaded->hasRole('mel_pro'), $state . ' grants the Pro role.');
      $this->assertSame('1', (string) $reloaded->get('field_pro_subscription_managed')->value);
    }
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
    $this->assertTrue($reloaded->hasRole('mel_pro'));
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
    $this->assertFalse($reloaded->hasRole('mel_pro'));
    $this->assertSame('', (string) $reloaded->get('field_pro_grace_expires')->value);
  }

  /**
   * A recovered subscription clears grace without removing Pro entitlement.
   */
  public function testPaymentRecoveryClearsGraceAndKeepsRole(): void {
    $user = $this->createManagedProUser('grace_recovered@example.com');
    $user->set('field_pro_grace_expires', time() + 86400);
    $user->save();

    $cleared = $this->container
      ->get('myeventlane_pro.entitlement_reconciler')
      ->clearGracePeriod($user);

    $reloaded = User::load((int) $user->id());
    $this->assertTrue($cleared);
    $this->assertNotNull($reloaded);
    $this->assertTrue($reloaded->hasRole('mel_pro'));
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
   * A dunning step cannot be scheduled twice for one subscription.
   */
  public function testFailedPaymentReminderScheduledOnce(): void {
    $scheduler = $this->container->get('myeventlane_pro.subscription_lifecycle_scheduler');
    $method = new \ReflectionMethod($scheduler, 'insertFailedSequence');
    $method->setAccessible(TRUE);

    $first = $method->invoke($scheduler, 23456, 3, 2000000000);
    $second = $method->invoke($scheduler, 23456, 3, 2000000000);

    $count = (int) $this->container->get('database')
      ->select('myeventlane_pro_failed_payment_sequence', 'f')
      ->condition('subscription_id', 23456)
      ->condition('step', 3)
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertSame(1, $first);
    $this->assertSame(0, $second);
    $this->assertSame(1, $count);
  }

  /**
   * The canonical message ledger rejects a duplicate dunning notification.
   */
  public function testDuplicateDunningNotificationIsNotQueued(): void {
    $manager = $this->container->get('myeventlane_messaging.manager');
    $context = [
      'first_name' => 'Grace',
      'subscription_id' => 34567,
      'step' => 0,
    ];

    $first = $manager->queue('pro_subscription_payment_failed_day_0', 'grace@example.com', $context);
    $second = $manager->queue('pro_subscription_payment_failed_day_0', 'grace@example.com', $context);

    $count = (int) $this->container->get('database')
      ->select('myeventlane_message', 'm')
      ->condition('template', 'pro_subscription_payment_failed_day_0')
      ->condition('recipient', 'grace@example.com')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertIsString($first);
    $this->assertNotSame('', $first);
    $this->assertNull($second);
    $this->assertSame(1, $count);
  }

  /**
   * Essential Pro billing notices ignore marketing and reminder opt-outs.
   */
  public function testProBillingMessagesAreTransactional(): void {
    $recipient = 'billing-opt-out@example.com';
    $preferences = $this->container->get('myeventlane_messaging.message_preference_storage');
    $preferences->setMarketingOptOut($recipient, 'email', TRUE);
    $preferences->setOperationalReminderOptOut($recipient, 'email', TRUE);

    $manager = $this->container->get('myeventlane_messaging.manager');
    $method = new \ReflectionMethod($manager, 'allowByPreference');

    foreach ([
      'pro_subscription_payment_failed_day_0',
      'pro_subscription_payment_failed_day_3',
      'pro_subscription_payment_failed_day_6',
      'pro_subscription_payment_recovered',
    ] as $template) {
      $this->assertTrue(
        $method->invoke($manager, $template, $recipient, []),
        $template . ' must remain transactional.',
      );
    }
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

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([1, 2]);

    $storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
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
      'roles' => ['authenticated', 'mel_pro'],
      'field_pro_subscription_managed' => 1,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Creates a reconciler whose subscription lookup returns one fixture.
   */
  private function reconcilerWithSubscription(SubscriptionInterface $subscription): ProEntitlementReconciler {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([1]);

    $subscriptionStorage = $this->createMock(EntityStorageInterface::class);
    $subscriptionStorage->method('getQuery')->willReturn($query);
    $subscriptionStorage->method('loadMultiple')->willReturn([1 => $subscription]);

    $emptyQuery = $this->createMock(QueryInterface::class);
    $emptyQuery->method('accessCheck')->willReturnSelf();
    $emptyQuery->method('condition')->willReturnSelf();
    $emptyQuery->method('execute')->willReturn([]);

    $vendorStorage = $this->createMock(EntityStorageInterface::class);
    $vendorStorage->method('getQuery')->willReturn($emptyQuery);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_subscription', $subscriptionStorage],
      ['myeventlane_vendor', $vendorStorage],
    ]);

    return new ProEntitlementReconciler(
      $entityTypeManager,
      $this->container->get('datetime.time'),
      new ProSubscriptionStateResolver($this->container->get('logger.channel.myeventlane_pro')),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
      $this->createMock(EventDispatcherInterface::class),
    );
  }

}
