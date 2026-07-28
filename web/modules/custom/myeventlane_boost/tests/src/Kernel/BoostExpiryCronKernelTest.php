<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_boost\Cron\BoostExpiryCron;
use Drupal\myeventlane_boost\Entity\BoostEntitlementInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Tests idempotent Boost expiry notification processing.
 *
 * @group myeventlane_boost
 */
#[RunTestsInSeparateProcesses]
final class BoostExpiryCronKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'datetime',
    'text',
    'options',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_product',
    'commerce_order',
    'myeventlane_boost',
  ];

  /**
   * The test event.
   */
  private Node $event;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    foreach ([
      'myeventlane_analytics.data',
      'myeventlane_event_state.resolver',
      'myeventlane_vendor.access.vendor_console',
      'myeventlane_vendor.service.boost_status',
    ] as $serviceId) {
      $container->setDefinition($serviceId, new Definition(\stdClass::class));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('myeventlane_boost_entitlement');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node']);
    $this->ensureEventSchema();

    $owner = User::create([
      'name' => 'boost-owner',
      'mail' => 'boost-owner@example.test',
      'status' => 1,
    ]);
    $owner->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Expired Boost Event',
      'status' => 1,
      'uid' => $owner->id(),
      'field_promoted' => 1,
      'field_promo_expires' => gmdate('Y-m-d\TH:i:s', time() - 60),
    ]);
    $this->event->save();
  }

  /**
   * Repeated cron execution sends and logs exactly once.
   */
  public function testRepeatedCronSendsOnce(): void {
    $entitlement = $this->createExpiredEntitlement();
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => TRUE]);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->with(
        $this->stringContains('Sent boost expiry notification'),
        $this->callback(static fn (array $context): bool => $context['@attempt'] === 1),
      );

    $cron = $this->createCron($mail, $logger);
    $cron->process();
    $cron->process();

    $entitlement = $this->reloadEntitlement((int) $entitlement->id());
    $this->assertSame(BoostEntitlementInterface::STATUS_EXPIRED, $entitlement->get('status')->value);
    $this->assertSame(BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT, $entitlement->get('expiry_notification_status')->value);
    $this->assertSame('1', $entitlement->get('expiry_notification_attempts')->value);
    $this->assertFalse($entitlement->get('expiry_notified_at')->isEmpty());
  }

  /**
   * The sent marker is durable before the external transport is invoked.
   */
  public function testSentMarkerPrecedesMailTransport(): void {
    $entitlement = $this->createExpiredEntitlement();
    $entitlementId = (int) $entitlement->id();
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->once())
      ->method('mail')
      ->willReturnCallback(function () use ($entitlementId): array {
        $claimed = $this->reloadEntitlement($entitlementId);
        $this->assertSame(
          BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT,
          $claimed->get('expiry_notification_status')->value,
        );
        $this->assertFalse($claimed->get('expiry_notified_at')->isEmpty());
        return ['result' => TRUE];
      });

    $this->createCron($mail)->process();
  }

  /**
   * A confirmed send failure remains retryable on the same entitlement.
   */
  public function testFailedSendRetriesSameEntitlement(): void {
    $entitlement = $this->createExpiredEntitlement();
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->exactly(2))
      ->method('mail')
      ->willReturnOnConsecutiveCalls(
        ['result' => FALSE],
        ['result' => TRUE],
      );

    $cron = $this->createCron($mail);
    $cron->process();

    $failed = $this->reloadEntitlement((int) $entitlement->id());
    $this->assertSame(BoostEntitlementInterface::EXPIRY_NOTIFICATION_PENDING, $failed->get('expiry_notification_status')->value);
    $this->assertSame('1', $failed->get('expiry_notification_attempts')->value);
    $this->assertTrue($failed->get('expiry_notified_at')->isEmpty());

    $cron->process();

    $sent = $this->reloadEntitlement((int) $entitlement->id());
    $this->assertSame(BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT, $sent->get('expiry_notification_status')->value);
    $this->assertSame('2', $sent->get('expiry_notification_attempts')->value);
  }

  /**
   * A mail transport exception restores retryable notification state.
   */
  public function testMailExceptionRetriesSameEntitlement(): void {
    $entitlement = $this->createExpiredEntitlement();
    $mail = $this->createMock(MailManagerInterface::class);
    $calls = 0;
    $mail->expects($this->exactly(2))
      ->method('mail')
      ->willReturnCallback(static function () use (&$calls): array {
        if ($calls++ === 0) {
          throw new \RuntimeException('Transport failed.');
        }
        return ['result' => TRUE];
      });

    $cron = $this->createCron($mail);
    $cron->process();

    $failed = $this->reloadEntitlement((int) $entitlement->id());
    $this->assertSame(
      BoostEntitlementInterface::EXPIRY_NOTIFICATION_PENDING,
      $failed->get('expiry_notification_status')->value,
    );
    $this->assertTrue($failed->get('expiry_notified_at')->isEmpty());

    $cron->process();
    $sent = $this->reloadEntitlement((int) $entitlement->id());
    $this->assertSame(
      BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT,
      $sent->get('expiry_notification_status')->value,
    );
  }

  /**
   * Permanently undeliverable notifications stop after the retry limit.
   */
  public function testFailedSendStopsAfterRetryLimit(): void {
    $entitlement = $this->createExpiredEntitlement();
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->exactly(5))
      ->method('mail')
      ->willReturn(['result' => FALSE]);
    $cron = $this->createCron($mail);

    for ($attempt = 0; $attempt < 6; $attempt++) {
      $cron->process();
    }

    $failed = $this->reloadEntitlement((int) $entitlement->id());
    $this->assertSame('5', $failed->get('expiry_notification_attempts')->value);
    $this->assertSame(
      BoostEntitlementInterface::EXPIRY_NOTIFICATION_PENDING,
      $failed->get('expiry_notification_status')->value,
    );
  }

  /**
   * A competing worker cannot deliver while the entitlement lock is held.
   */
  public function testConcurrentWorkerCannotSendDuplicate(): void {
    $entitlement = $this->createExpiredEntitlement();
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => TRUE]);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))
      ->method('acquire')
      ->willReturnOnConsecutiveCalls(FALSE, TRUE);
    $lock->expects($this->once())
      ->method('release');

    $cron = $this->createCron($mail, NULL, $lock);
    $cron->process();
    $locked = $this->reloadEntitlement((int) $entitlement->id());
    $this->assertSame(BoostEntitlementInterface::EXPIRY_NOTIFICATION_PENDING, $locked->get('expiry_notification_status')->value);
    $this->assertSame('0', $locked->get('expiry_notification_attempts')->value);

    $cron->process();
    $cron->process();

    $sent = $this->reloadEntitlement((int) $entitlement->id());
    $this->assertSame(BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT, $sent->get('expiry_notification_status')->value);
    $this->assertSame('1', $sent->get('expiry_notification_attempts')->value);
  }

  /**
   * A processing record stranded by worker termination is retryable.
   */
  public function testStrandedProcessingStateRetries(): void {
    $entitlement = $this->createExpiredEntitlement();
    $entitlement->set('expiry_notification_status', BoostEntitlementInterface::EXPIRY_NOTIFICATION_PROCESSING);
    $entitlement->save();

    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => TRUE]);

    $cron = $this->createCron($mail);
    $cron->process();
    $cron->process();

    $sent = $this->reloadEntitlement((int) $entitlement->id());
    $this->assertSame(BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT, $sent->get('expiry_notification_status')->value);
    $this->assertSame('1', $sent->get('expiry_notification_attempts')->value);
  }

  /**
   * Multiple simultaneously ended entitlements produce one event notice.
   */
  public function testMultipleEndedEntitlementsForEventSendOnce(): void {
    $first = $this->createExpiredEntitlement();
    $second = $this->createExpiredEntitlement();
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => TRUE]);

    $this->createCron($mail)->process();

    foreach ([$first, $second] as $entitlement) {
      $reloaded = $this->reloadEntitlement((int) $entitlement->id());
      $this->assertSame(
        BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT,
        $reloaded->get('expiry_notification_status')->value,
      );
    }
  }

  /**
   * Expiry notice waits until the event has no active overlapping boost.
   */
  public function testOverlappingBoostDefersExpiryNotice(): void {
    $ended = $this->createExpiredEntitlement();
    $active = $this->createExpiredEntitlement(time() + 3600);
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => TRUE]);
    $cron = $this->createCron($mail);

    $cron->process();
    $this->assertSame(
      BoostEntitlementInterface::EXPIRY_NOTIFICATION_PENDING,
      $this->reloadEntitlement((int) $ended->id())
        ->get('expiry_notification_status')->value,
    );

    $active->set('ends', time() - 1);
    $active->save();
    $cron->process();

    foreach ([$ended, $active] as $entitlement) {
      $this->assertSame(
        BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT,
        $this->reloadEntitlement((int) $entitlement->id())
          ->get('expiry_notification_status')->value,
      );
    }
  }

  /**
   * Entitlement expiry itself is not limited by the 500-notification batch.
   */
  public function testBulkExpiryIsNotNotificationBatchLimited(): void {
    for ($i = 0; $i < 501; $i++) {
      $entitlement = $this->createExpiredEntitlement();
      $entitlement->set(
        'expiry_notification_status',
        BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT,
      );
      $entitlement->save();
    }
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->never())->method('mail');

    $this->createCron($mail)->process();

    $activeCount = $this->container->get('entity_type.manager')
      ->getStorage('myeventlane_boost_entitlement')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', BoostEntitlementInterface::STATUS_ACTIVE)
      ->count()
      ->execute();
    $this->assertSame(0, (int) $activeCount);
  }

  /**
   * Creates an expired active entitlement.
   */
  private function createExpiredEntitlement(?int $ends = NULL): BoostEntitlementInterface {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('myeventlane_boost_entitlement');
    $entitlement = $storage->create([
      'label' => 'Expired entitlement',
      'uid' => $this->event->getOwnerId(),
      'event' => $this->event->id(),
      'starts' => time() - 3600,
      'ends' => $ends ?? time() - 60,
      'status' => BoostEntitlementInterface::STATUS_ACTIVE,
      'source' => BoostEntitlementInterface::SOURCE_PRO,
    ]);
    $entitlement->save();
    return $entitlement;
  }

  /**
   * Creates the cron handler with controlled delivery collaborators.
   */
  private function createCron(
    MailManagerInterface $mail,
    ?LoggerInterface $logger = NULL,
    ?LockBackendInterface $lock = NULL,
  ): BoostExpiryCron {
    return new BoostExpiryCron(
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
      $logger ?? $this->createStub(LoggerInterface::class),
      $mail,
      $this->container->get('myeventlane_boost.entitlement_manager'),
      $lock ?? $this->container->get('lock'),
    );
  }

  /**
   * Reloads an entitlement without the entity memory cache.
   */
  private function reloadEntitlement(int $id): BoostEntitlementInterface {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('myeventlane_boost_entitlement');
    $storage->resetCache([$id]);
    $entitlement = $storage->load($id);
    $this->assertInstanceOf(BoostEntitlementInterface::class, $entitlement);
    return $entitlement;
  }

  /**
   * Creates the event bundle and denormalized Boost fields.
   */
  private function ensureEventSchema(): void {
    NodeType::create([
      'type' => 'event',
      'name' => 'Event',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_promoted',
      'entity_type' => 'node',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_promoted',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Promoted',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_promo_expires',
      'entity_type' => 'node',
      'type' => 'datetime',
      'settings' => [
        'datetime_type' => 'datetime',
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_promo_expires',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Promo expires',
    ])->save();
  }

}
