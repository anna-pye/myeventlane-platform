<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_core\EventSubscriber\VendorConsoleGateRedirectSubscriber;
use Drupal\myeventlane_core\MelVendorOrganiserRole;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_core\EventSubscriber\VendorConsoleGateRedirectSubscriber
 *
 * @group myeventlane_vendor
 */
final class VendorConsoleGateRedirectSubscriberKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'flag',
    'myeventlane_core',
    'myeventlane_boost',
    'myeventlane_vendor',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('user_role');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
    $this->installDomainSettings();
  }

  /**
   * Anonymous GET /vendor/dashboard on vendor host → redirect to public login with destination.
   */
  public function testAnonymousRedirectedToPublicLogin(): void {
    $this->container->get('account_switcher')->switchTo(User::getAnonymousUser());

    $request = $this->vendorDashboardRequest();
    $this->container->get('request_stack')->push($request);

    $event = $this->newMainRequestEvent($request);
    $this->gate()->onRequest($event);

    $this->assertTrue($event->hasResponse());
    $response = $event->getResponse();
    $this->assertSame(302, $response->getStatusCode());
    $location = $response->headers->get('Location');
    $this->assertNotFalse($location);
    $this->assertStringContainsString('public.example.com', (string) $location);
    $this->assertStringContainsString('/user/login', (string) $location);
    $this->assertStringContainsString('destination=', (string) $location);

    $this->container->get('request_stack')->pop();
  }

  /**
   * Authenticated buyer (no vendor role, no console permission) → organiser switch on public.
   */
  public function testAuthenticatedBuyerRedirectedToOrganiserSwitch(): void {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->save();

    $this->container->get('account_switcher')->switchTo($user);

    $request = $this->vendorDashboardRequest();
    $this->container->get('request_stack')->push($request);

    $event = $this->newMainRequestEvent($request);
    $this->gate()->onRequest($event);

    $this->assertTrue($event->hasResponse());
    $location = (string) $event->getResponse()->headers->get('Location');
    $this->assertStringContainsString('public.example.com', $location);
    $this->assertStringContainsString('/user/switch-for-organiser', $location);
    $this->assertStringContainsString('destination=', $location);

    $this->container->get('request_stack')->pop();
  }

  /**
   * Vendor role without console permission → gate lets request through (no redirect).
   */
  public function testAuthenticatedVendorRoleOnlyNoGateRedirect(): void {
    $this->ensureVendorRoleExists();
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->addRole(MelVendorOrganiserRole::MACHINE_NAME);
    $user->save();

    $this->container->get('account_switcher')->switchTo($user);

    $request = $this->vendorDashboardRequest();
    $this->container->get('request_stack')->push($request);

    $event = $this->newMainRequestEvent($request);
    $this->gate()->onRequest($event);

    $this->assertFalse($event->hasResponse());

    $this->container->get('request_stack')->pop();
  }

  /**
   * Console permission without vendor role → gate lets request through.
   */
  public function testAuthenticatedConsolePermissionOnlyNoGateRedirect(): void {
    $this->ensureConsolePermissionRole();
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->addRole('console_perm_only');
    $user->save();

    $this->container->get('account_switcher')->switchTo($user);

    $request = $this->vendorDashboardRequest();
    $this->container->get('request_stack')->push($request);

    $event = $this->newMainRequestEvent($request);
    $this->gate()->onRequest($event);

    $this->assertFalse($event->hasResponse());

    $this->container->get('request_stack')->pop();
  }

  /**
   * Site admin + vendor role → no gate redirect (admin path matches allow branch via permission or role).
   */
  public function testAuthenticatedAdminPlusVendorNoGateRedirect(): void {
    $this->ensureSiteAdminRole();
    $this->ensureVendorRoleExists();
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->addRole('site_admin');
    $user->addRole(MelVendorOrganiserRole::MACHINE_NAME);
    $user->save();

    $this->container->get('account_switcher')->switchTo($user);

    $request = $this->vendorDashboardRequest();
    $this->container->get('request_stack')->push($request);

    $event = $this->newMainRequestEvent($request);
    $this->gate()->onRequest($event);

    $this->assertFalse($event->hasResponse());

    $this->container->get('request_stack')->pop();
  }

  private function gate(): VendorConsoleGateRedirectSubscriber {
    return $this->container->get('myeventlane_core.vendor_console_gate_redirect_subscriber');
  }

  private function newMainRequestEvent(Request $request): RequestEvent {
    return new RequestEvent(
      $this->container->get('http_kernel'),
      $request,
      HttpKernelInterface::MAIN_REQUEST
    );
  }

  private function vendorDashboardRequest(): Request {
    return Request::create('https://vendor.example.com/vendor/dashboard', 'GET', [], [], [], [
      'HTTP_HOST' => 'vendor.example.com',
      'HTTPS' => 'on',
    ]);
  }

  private function installDomainSettings(): void {
    $this->container->get('config.factory')->getEditable('myeventlane_core.domain_settings')
      ->setData([
        'public_domain' => 'https://public.example.com',
        'vendor_domain' => 'https://vendor.example.com',
        'admin_domain' => 'https://admin.example.com',
        'force_redirects' => FALSE,
      ])
      ->save(TRUE);
  }

  private function ensureVendorRoleExists(): void {
    if (Role::load(MelVendorOrganiserRole::MACHINE_NAME)) {
      return;
    }
    Role::create([
      'id' => MelVendorOrganiserRole::MACHINE_NAME,
      'label' => 'Vendor',
    ])->save();
  }

  private function ensureConsolePermissionRole(): void {
    if (Role::load('console_perm_only')) {
      return;
    }
    Role::create([
      'id' => 'console_perm_only',
      'label' => 'Console perm only',
    ])->save();
    user_role_grant_permissions('console_perm_only', ['access vendor console']);
  }

  private function ensureSiteAdminRole(): void {
    if (Role::load('site_admin')) {
      return;
    }
    Role::create([
      'id' => 'site_admin',
      'label' => 'Site admin',
    ])->save();
    user_role_grant_permissions('site_admin', ['administer site configuration']);
  }

}
