<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_refunds\Service\RefundRequestStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests customer-visible refund request state storage.
 */
#[Group('myeventlane_refunds')]
#[RunTestsInSeparateProcesses]
final class RefundRequestStorageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Tests newest request lookup and order cache invalidation.
   */
  public function testLatestBuyerRequestAndCacheInvalidation(): void {
    require_once dirname(__DIR__, 3) . '/myeventlane_refunds.install';
    $schema = $this->container->get('database')->schema();
    $table = myeventlane_refunds_schema()['myeventlane_refund_request'];
    $schema->createTable('myeventlane_refund_request', $table);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(300);
    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->expects(self::exactly(3))
      ->method('invalidateTags')
      ->with(['commerce_order:77']);

    $storage = new RefundRequestStorage(
      $this->container->get('database'),
      $time,
      $invalidator,
    );
    $base = [
      'order_id' => 77,
      'event_id' => 1615,
      'buyer_uid' => 42,
      'vendor_uid' => 7,
      'amount_cents' => 2273,
      'currency' => 'aud',
    ];
    $firstId = $storage->create($base + [
      'status' => RefundRequestStorage::STATUS_COMPLETED,
      'created' => 100,
    ]);
    $secondId = $storage->create($base + [
      'status' => RefundRequestStorage::STATUS_REQUESTED,
      'created' => 200,
    ]);
    $storage->update($secondId, [
      'status' => RefundRequestStorage::STATUS_REJECTED,
      'decision_reason' => 'Already refunded.',
    ]);

    $latest = $storage->loadLatestForBuyer(77, 1615, 42);
    self::assertNotNull($latest);
    self::assertSame($secondId, (int) $latest['id']);
    self::assertNotSame($firstId, (int) $latest['id']);
    self::assertSame(RefundRequestStorage::STATUS_REJECTED, $latest['status']);
    self::assertSame('Already refunded.', $latest['decision_reason']);
    self::assertSame(300, (int) $latest['updated']);
  }

}
