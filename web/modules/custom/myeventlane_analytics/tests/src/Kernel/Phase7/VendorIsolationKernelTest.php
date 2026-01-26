<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_analytics\Kernel\Phase7;

use Drupal\myeventlane_analytics\Phase7\Exception\AccessDeniedAnalyticsException;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;

/**
 * Guardrail: vendor isolation is enforced server-side (fail-closed).
 *
 * Tests MUST assert exceptions, not empty arrays.
 *
 * @group myeventlane_analytics
 */
final class VendorIsolationKernelTest extends AnalyticsKernelTestBase {

  /**
   * Vendor attempts to use admin scope to override store context must throw.
   */
  public function testVendorCannotEscalateToAdminScopeWithStoreIds(): void {
    // Ensure UID 1 is reserved for admin override behaviour.
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id(), 'First created user should be UID 1 for admin override.');

    $vendor = $this->createUserAccount('vendor');
    $other_vendor = $this->createUserAccount('other_vendor');

    $vendor_store = $this->createOnlineStore($vendor, 'Vendor Store');
    $other_store = $this->createOnlineStore($other_vendor, 'Other Store');
    $this->assertNotSame((int) $vendor_store->id(), (int) $other_store->id());

    $this->switchToUser($vendor);

    $service = $this->createQueryService();
    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $other_store->id()],
      start_ts: 1,
      end_ts: 2,
      currency: 'AUD',
    );

    $this->expectException(AccessDeniedAnalyticsException::class);
    $service->getGrossRevenue($query);
  }

  /**
   * Vendor scope must fail-closed when no store can be resolved.
   */
  public function testVendorScopeRequiresExactlyOneResolvedStore(): void {
    $vendor_without_store = $this->createUserAccount('vendor_without_store');
    $this->switchToUser($vendor_without_store);

    $service = $this->createQueryService();
    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_VENDOR,
      store_ids: [],
      start_ts: 1,
      end_ts: 2,
      currency: 'AUD',
    );

    $this->expectException(AccessDeniedAnalyticsException::class);
    $service->getGrossRevenue($query);
  }

  /**
   * Vendor scope must fail-closed when multiple stores exist.
   */
  public function testVendorScopeFailsClosedForMultipleStores(): void {
    $vendor = $this->createUserAccount('vendor_multi_store');

    $this->createOnlineStore($vendor, 'Vendor Store A');
    $this->createOnlineStore($vendor, 'Vendor Store B');

    $this->switchToUser($vendor);

    $service = $this->createQueryService();
    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_VENDOR,
      store_ids: [],
      start_ts: 1,
      end_ts: 2,
      currency: 'AUD',
    );

    $this->expectException(AccessDeniedAnalyticsException::class);
    $service->getGrossRevenue($query);
  }

}

