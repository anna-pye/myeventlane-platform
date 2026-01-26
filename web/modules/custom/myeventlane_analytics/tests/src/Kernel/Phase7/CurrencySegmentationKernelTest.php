<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_analytics\Kernel\Phase7;

use Drupal\myeventlane_analytics\Phase7\Exception\InvariantViolationException;
use Drupal\myeventlane_analytics\Phase7\Exception\MissingCurrencyException;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;

/**
 * Guardrail: currencies must not be mixed; money metrics require currency.
 *
 * Tests MUST assert exceptions, not empty arrays.
 *
 * @group myeventlane_analytics
 */
final class CurrencySegmentationKernelTest extends AnalyticsKernelTestBase {

  /**
   * Missing currency for a money metric must throw (fail-closed).
   */
  public function testMissingCurrencyThrowsForMoneyMetric(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id(), 'First created user should be UID 1 for admin override.');
    $this->switchToUser($admin);

    $store = $this->createOnlineStore($admin, 'Admin Store');

    $service = $this->createQueryService();
    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store->id()],
      start_ts: 1,
      end_ts: 2,
      currency: NULL,
    );

    $this->expectException(MissingCurrencyException::class);
    $service->getGrossRevenue($query);
  }

  /**
   * Providing a currency for a count metric must throw (no currency mixing).
   */
  public function testCurrencyNotAllowedForCountMetricThrows(): void {
    $admin = $this->createUserAccount('admin2');
    $this->assertSame(1, (int) $admin->id(), 'First created user should be UID 1 for admin override.');
    $this->switchToUser($admin);

    $store = $this->createOnlineStore($admin, 'Admin Store 2');

    $service = $this->createQueryService();
    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store->id()],
      start_ts: 1,
      end_ts: 2,
      currency: 'AUD',
    );

    $this->expectException(InvariantViolationException::class);
    $service->getTicketsSold($query);
  }

}

