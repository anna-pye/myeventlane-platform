<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_surface\MelGovernanceDebugAccess;
use Drupal\myeventlane_core\MelSurfaceId;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\Routing\Route;

/**
 * Staff-only governance debug attachments (separate from observability diagnostics).
 *
 * @group myeventlane_surface
 */
final class MelGovernanceDebugKernelTest extends MelSurfaceGovernanceKernelTestBase {

  use UserCreationTrait;

  public function testVendorSurfaceNeverAttachesGovernanceDebug(): void {
    $this->loginAnonymous();
    $user = $this->createUser([MelGovernanceDebugAccess::PERMISSION]);
    $this->assertNotFalse($user);
    $this->setCurrentUser($user);
    $this->pushRouteRequest('/vendor/dashboard', 'myeventlane_vendor.console.dashboard', new Route('/vendor/dashboard'));

    $variables = ['#attached' => []];
    $this->container->get('myeventlane_surface.negotiator')->attachPageMetadata($variables);
    $libs = $variables['#attached']['library'] ?? [];
    $this->assertNotContains('myeventlane_surface/governance_debug', $libs);
    $this->assertArrayNotHasKey('melGovernanceDebug', $variables['#attached']['drupalSettings'] ?? []);

    $this->popRequest();
  }

  public function testStaffWithPermissionAttachesGovernanceDebugSummary(): void {
    $this->loginAnonymous();
    $user = $this->createUser([MelGovernanceDebugAccess::PERMISSION]);
    $this->assertNotFalse($user);
    $this->setCurrentUser($user);
    $this->pushRouteRequest('/admin/modules', 'system.modules_list', $this->adminRoute('/admin/modules'));

    $variables = ['#attached' => []];
    $this->container->get('myeventlane_surface.negotiator')->attachPageMetadata($variables);
    $libs = $variables['#attached']['library'] ?? [];
    $this->assertContains('myeventlane_surface/governance_debug', $libs);
    $debug = $variables['#attached']['drupalSettings']['melGovernanceDebug'] ?? NULL;
    $this->assertIsArray($debug);
    $this->assertTrue($debug['enabled']);
    $this->assertSame('system.modules_list', $debug['route']);
    $this->assertSame('staff', $debug['surface']);

    $this->popRequest();
  }

  public function testStaffWithoutPermissionOmitsGovernanceDebug(): void {
    $this->loginAnonymous();
    $user = $this->createUser();
    $this->assertNotFalse($user);
    $this->setCurrentUser($user);
    $this->assertNotSame('1', $user->id(), 'Drupal uid 1 bypasses permission checks via SuperUserAccessPolicy.');
    $this->assertFalse($user->hasPermission(MelGovernanceDebugAccess::PERMISSION));
    /** @var \Drupal\myeventlane_surface\MelGovernanceDebugAccess $access */
    $access = $this->container->get('myeventlane_surface.governance_debug_access');
    $this->assertFalse($access->mayViewDebug(MelSurfaceId::Staff, $user));
    $this->pushRouteRequest('/admin/modules', 'system.modules_list', $this->adminRoute('/admin/modules'));

    $variables = ['#attached' => []];
    $this->container->get('myeventlane_surface.negotiator')->attachPageMetadata($variables);
    $libs = $variables['#attached']['library'] ?? [];
    $this->assertNotContains('myeventlane_surface/governance_debug', $libs);

    $this->popRequest();
  }

}
