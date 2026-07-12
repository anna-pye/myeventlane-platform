<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_core\MelSurfaceId;
use Symfony\Component\Routing\Route;

/**
 * Public/auth page shells must not inherit high-cardinality cache contexts.
 *
 * @group myeventlane_surface
 */
final class MelPublicPageCacheabilityKernelTest extends MelSurfaceGovernanceKernelTestBase {

  /**
   * Public surface page metadata must stay Dynamic Page Cache eligible.
   */
  public function testPublicSurfaceOmitsUserAndSessionContexts(): void {
    $this->loginAnonymous();
    $this->pushRouteRequest('/', '<front>', new Route('/'));

    $variables = ['#attached' => []];
    $this->container->get('myeventlane_surface.negotiator')->attachPageMetadata($variables);
    $this->popRequest();

    $this->assertSame(MelSurfaceId::Public->value, $variables['mel_surface'] ?? NULL);
    $contexts = $variables['#cache']['contexts'] ?? [];
    $this->assertIsArray($contexts);
    $this->assertNotContains('user', $contexts);
    $this->assertNotContains('session', $contexts);
    $this->assertContains('user.roles:authenticated', $contexts);
    $this->assertContains('user.permissions', $contexts);
    $this->assertContains('route', $contexts);
    $max_age = $variables['#cache']['max-age'] ?? \Drupal\Core\Cache\Cache::PERMANENT;
    $this->assertNotSame(0, (int) $max_age);
  }

  /**
   * Vendor surface retains per-user variation (personalised console).
   */
  public function testVendorSurfaceKeepsUserContext(): void {
    $this->loginAnonymous();
    $this->pushRouteRequest('/vendor/dashboard', 'myeventlane_vendor.console.dashboard', new Route('/vendor/dashboard'));

    $variables = ['#attached' => []];
    $this->container->get('myeventlane_surface.negotiator')->attachPageMetadata($variables);
    $this->popRequest();

    $this->assertSame(MelSurfaceId::Vendor->value, $variables['mel_surface'] ?? NULL);
    $contexts = $variables['#cache']['contexts'] ?? [];
    $this->assertContains('user', $contexts);
  }

}
