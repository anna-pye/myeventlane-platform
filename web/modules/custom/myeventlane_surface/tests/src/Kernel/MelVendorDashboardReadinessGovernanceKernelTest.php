<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

/**
 * Vendor dashboard readiness copy parity and governance helper smoke checks.
 *
 * @group myeventlane_surface
 */
final class MelVendorDashboardReadinessGovernanceKernelTest extends MelSurfaceGovernanceKernelTestBase {

  /**
   * Urgent Stripe action queue copy must mirror the blocking readiness headline.
   */
  public function testStripeUrgentCopyMatchesBlockingReadiness(): void {
    /** @var \Drupal\myeventlane_core\MelReadinessHelper $helper */
    $helper = $this->container->get('myeventlane_surface.state_readiness_helper');
    $blocking = $helper->vendorStripeReadinessBlockingLine();
    $this->assertSame($blocking, $helper->vendorActionStripePayoutStrings(TRUE)['message']);
  }

  /**
   * Smoke: governance sorts and retains single operational action when suppression is inactive.
   */
  public function testActionQueueGovernanceRetainsOperationalAction(): void {
    /** @var \Drupal\myeventlane_surface\MelVendorDashboardActionQueueGovernance $gov */
    $gov = $this->container->get('myeventlane_surface.vendor_dashboard_action_queue_governance');
    $seed = [
      ['key' => 'no_events_yet', 'priority' => 40, 'severity' => 'info'],
    ];
    $out = $gov->apply($seed);
    $this->assertCount(1, $out);
    $this->assertSame('no_events_yet', $out[0]['key'] ?? '');
  }

}
