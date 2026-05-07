<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_surface\MelSurfaceId;
use Symfony\Component\Routing\Route;

/**
 * Cross-surface routing contracts resolved by SurfaceResolver.
 *
 * @group myeventlane_surface
 */
final class MelSurfaceRouteGovernanceKernelTest extends MelSurfaceGovernanceKernelTestBase {

  public function testAdminRouteResolvesStaffSurface(): void {
    $this->pushRouteRequest('/admin/modules', 'system.modules_list', $this->adminRoute('/admin/modules'));
    $surface = $this->container->get('myeventlane_surface.resolver')->resolve();
    $this->assertSame(MelSurfaceId::Staff, $surface);
    $this->popRequest();
  }

  public function testVendorPathResolvesVendorSurface(): void {
    $this->pushRouteRequest('/vendor/dashboard', 'myeventlane_vendor.console.dashboard', new Route('/vendor/dashboard'));
    $surface = $this->container->get('myeventlane_surface.resolver')->resolve();
    $this->assertSame(MelSurfaceId::Vendor, $surface);
    $this->popRequest();
  }

  public function testEventStudioCreatePathResolvesVendorSurface(): void {
    $this->pushRouteRequest('/vendor/events/create', 'myeventlane_event_studio.create', new Route('/vendor/events/create'));
    $surface = $this->container->get('myeventlane_surface.resolver')->resolve();
    $this->assertSame(MelSurfaceId::Vendor, $surface);
    $this->popRequest();
  }

  public function testMyAccountPathResolvesCustomerSurface(): void {
    $this->pushRouteRequest('/my-account', 'myeventlane_account.dashboard', new Route('/my-account'));
    $surface = $this->container->get('myeventlane_surface.resolver')->resolve();
    $this->assertSame(MelSurfaceId::Customer, $surface);
    $this->popRequest();
  }

  public function testCheckoutPathResolvesPublicSurfaceWithCheckoutSensitivity(): void {
    $this->loginAnonymous();
    $this->pushRouteRequest('/checkout/review', 'commerce_checkout.review', new Route('/checkout/review'));
    $surface = $this->container->get('myeventlane_surface.resolver')->resolve();
    $this->assertSame(MelSurfaceId::Public, $surface);
    $this->assertTrue($this->container->get('myeventlane_surface.access_helper')->isCheckoutSensitiveRequest());
    $this->popRequest();
  }

}
