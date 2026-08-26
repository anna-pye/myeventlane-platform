<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_commerce\Service\CartTicketTierHoldStore;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves durable ticket-tier cart hold storage and expiry semantics.
 *
 * @group myeventlane_commerce
 */
#[RunTestsInSeparateProcesses]
final class CartTicketTierHoldStoreKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * A cart's own hold can be excluded and expired holds stop counting.
   */
  public function testHeldCountExclusionAndExpiry(): void {
    require_once DRUPAL_ROOT . '/modules/custom/myeventlane_commerce/myeventlane_commerce.install';
    $schema = myeventlane_commerce_schema();
    $this->container->get('database')->schema()->createTable(
      'myeventlane_commerce_ticket_hold',
      $schema['myeventlane_commerce_ticket_hold'],
    );

    $now = 1_000;
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')
      ->willReturnCallback(static function () use (&$now): int {
        return $now;
      });
    $capacity = $this->createMock(EventCapacityServiceInterface::class);
    $capacity->method('getReservationTtl')->willReturn(900);
    $store = new CartTicketTierHoldStore(
      $this->container->get('database'),
      $time,
      $capacity,
    );

    $store->upsert(27, 44, 501, 101, 2);
    $store->upsert(28, 44, 501, 101, 1);
    self::assertSame(3, $store->getHeldQuantity(44, 501));
    self::assertSame(
      1,
      $store->getHeldQuantity(
        44,
        501,
        CartTicketTierHoldStore::reservationKey(27, 44, 501),
      ),
    );
    self::assertSame(2, $store->getActive(27, 44, 501)['quantity']);

    $now = 1_901;
    self::assertSame(0, $store->getHeldQuantity(44, 501));
    self::assertNull($store->getActive(27, 44, 501));
    self::assertSame(2, $store->purgeExpired());
  }

}
