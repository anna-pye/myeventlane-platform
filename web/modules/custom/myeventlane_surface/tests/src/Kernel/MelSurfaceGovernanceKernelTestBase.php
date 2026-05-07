<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Shared bootstrap for MEL governance Kernel tests.
 *
 * Enables the real myeventlane_surface service graph (via myeventlane_core).
 */
abstract class MelSurfaceGovernanceKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'flag',
    'mel_kernel_route_fixtures',
    'myeventlane_core',
    'myeventlane_surface',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('user_role');
    // Skip onboarding_state schema here: it references myeventlane_vendor, which pulls a
    // large DI graph unsuitable for lightweight governance Kernel runs. Tests that need
    // onboarding persistence extend a dedicated base or enable vendor modules explicitly.
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user']);
    // Drupal's SuperUserAccessPolicy grants uid 1 every permission. The first saved user in
    // a kernel environment is often uid 1, which makes negative permission assertions fail.
    // Reserve uid 1 so UserCreationTrait::createUser() yields uid >= 2 by default.
    $this->ensureDrupalSuperUserPlaceholderExists();
  }

  /**
   * Ensures user 1 exists so later createUser() calls are not assigned the super-user ID.
   */
  protected function ensureDrupalSuperUserPlaceholderExists(): void {
    if (User::load(1) instanceof UserInterface) {
      return;
    }
    User::create([
      'name' => '_mel_kernel_super_user_placeholder',
      'mail' => 'mel-kernel-super-user@example.com',
      'status' => 1,
    ])->save();
  }

  /**
   * Pushes a request with an explicit matched route (kernel routing is minimal).
   */
  protected function pushRouteRequest(string $path, string $route_name, Route $route): void {
    $request = Request::create($path);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    \Drupal::requestStack()->push($request);
  }

  protected function popRequest(): void {
    \Drupal::requestStack()->pop();
  }

  protected function setCurrentUser(UserInterface $account): void {
    $this->container->get('current_user')->setAccount($account);
  }

  protected function loginAnonymous(): void {
    $this->setCurrentUser(User::getAnonymousUser());
  }

  /**
   * Builds a staff/admin route object for SurfaceResolver (path under /admin optional).
   */
  protected function adminRoute(string $path): Route {
    $route = new Route($path);
    $route->setOption('_admin_route', TRUE);
    return $route;
  }

}
