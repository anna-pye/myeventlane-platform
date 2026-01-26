<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_analytics\Kernel\Phase7;

use Drupal\myeventlane_analytics\Phase7\Exception\AccessDeniedAnalyticsException;
use Drupal\myeventlane_analytics\Phase7\Exception\InvariantViolationException;
use Drupal\myeventlane_analytics\Phase7\Guard\AnalyticsQueryGuard;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;

/**
 * Guardrails for multi-store/multi-event allocation (pre-metrics).
 *
 * This suite ensures:
 * - Platform-wide "sum everything" queries are fail-closed by requiring admin
 *   store IDs explicitly.
 * - Attempts to introduce non-anchored or unknown metrics fail-closed.
 *
 * Tests MUST assert exceptions, not empty arrays.
 *
 * @group myeventlane_analytics
 */
final class MultiEventMultiStoreAllocationKernelTest extends AnalyticsKernelTestBase {

  /**
   * Admin scope must require explicit store IDs (no "sum all stores" shortcut).
   */
  public function testAdminScopeMissingStoreIdsThrows(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id(), 'First created user should be UID 1 for admin override.');
    $this->switchToUser($admin);

    $resolver = $this->createScopeResolver();
    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [],
      start_ts: 1,
      end_ts: 2,
      currency: 'AUD',
    );

    $this->expectException(AccessDeniedAnalyticsException::class);
    $resolver->resolveEffectiveStoreIds($query);
  }

  /**
   * Unknown / whole-order metrics must be impossible by design (fail-closed).
   */
  public function testUnknownMetricFailsClosed(): void {
    $guard = $this->createGuard();

    $this->expectException(InvariantViolationException::class);
    $guard->assertNoSemanticMixing('Whole Order Total');
  }

  /**
   * Order-item anchoring must not be applied to non-order-item metrics.
   */
  public function testOrderItemAnchoringNotApplicableThrows(): void {
    $guard = $this->createGuard();

    // Non-order-item metrics must fail-closed if someone attempts to anchor them.
    $this->expectException(InvariantViolationException::class);
    $guard->assertOrderItemAnchoringRequired(AnalyticsQueryGuard::METRIC_ACTIVE_EVENTS);
  }

}

