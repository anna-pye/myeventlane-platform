<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_vendor\Service\VendorNavBuilder;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\myeventlane_vendor\Service\VendorNavBuilder
 *
 * @group myeventlane_vendor
 */
final class VendorNavBuilderTest extends UnitTestCase {

  /**
   * Onboarding routes never include full-console-only nav keys.
   *
   * @covers ::buildShellNavItems
   */
  public function testOnboardingRouteUsesRestrictedNavSubset(): void {
    $builder = $this->createBuilder(
      routeName: 'myeventlane_vendor.onboard.profile',
      path: '/vendor/onboard/profile',
      allowedRoutes: [],
    );

    $items = $builder->buildShellNavItems('dashboard');
    $keys = array_column($items, 'key');
    $onboardingKeys = ['dashboard', 'events', 'payouts', 'settings', 'support'];

    foreach ($keys as $key) {
      $this->assertContains($key, $onboardingKeys);
    }
    $this->assertNotContains('analytics', $keys);
    $this->assertNotContains('promote', $keys);
    $this->assertNotContains('event_editor', $keys);
    $this->assertNotContains('checkin', $keys);
  }

  /**
   * Event-scoped orders link is disabled when no event is in the route match.
   *
   * @covers ::buildShellNavItems
   */
  public function testOrdersNavDisabledWithoutEventContext(): void {
    $builder = $this->createBuilder(
      routeName: 'myeventlane_vendor.console.dashboard',
      path: '/vendor/dashboard',
      allowedRoutes: [
        'myeventlane_vendor.console.dashboard',
        'myeventlane_vendor.console.events',
        'myeventlane_event_studio.create',
        'myeventlane_checkout_flow.vendor_attendees',
        'myeventlane_vendor.console.payouts',
        'myeventlane_vendor.console.boost',
        'myeventlane_vendor.console.messaging_brand',
        'myeventlane_analytics.dashboard',
        'myeventlane_escalations_portal.vendor_list',
        'myeventlane_vendor.console.settings',
      ],
    );

    $items = $builder->buildShellNavItems('dashboard');
    $orders = $this->findItemByKey($items, 'orders');
    $this->assertNotNull($orders);
    $this->assertTrue($orders['is_disabled']);
    $this->assertNull($orders['url']);
  }

  /**
   * Active section resolves from path prefixes before route mapping.
   *
   * @covers ::resolveActiveSection
   */
  public function testResolveActiveSectionFromAnalyticsPath(): void {
    $builder = $this->createBuilder(
      routeName: 'myeventlane_analytics.dashboard',
      path: '/vendor/analytics',
    );

    $this->assertSame('analytics', $builder->resolveActiveSection('myeventlane_analytics.dashboard'));
  }

  /**
   * Shell nav cache contexts include permissions and route.
   */
  public function testShellNavCacheContexts(): void {
    $this->assertSame(
      ['user.permissions', 'route'],
      VendorNavBuilder::SHELL_NAV_CACHE_CONTEXTS,
    );
  }

  /**
   * @param list<string> $allowedRoutes
   */
  private function createBuilder(
    string $routeName,
    string $path,
    array $allowedRoutes = [],
  ): VendorNavBuilder {
    $accessManager = $this->createMock(AccessManagerInterface::class);
    $accessManager->method('checkNamedRoute')
      ->willReturnCallback(static function (string $route) use ($allowedRoutes): AccessResult {
        if ($allowedRoutes === [] || in_array($route, $allowedRoutes, TRUE)) {
          return AccessResult::allowed();
        }
        return AccessResult::forbidden();
      });

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('getAccount')->willReturn($account);

    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getRouteName')->willReturn($routeName);
    $routeMatch->method('getParameter')->willReturn(NULL);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $request = Request::create($path);
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnCallback(
      static fn (string $string, array $args = [], array $options = []): string => $string,
    );
    $translation->method('translateString')->willReturnCallback(
      static fn ($string) => (string) $string,
    );

    $container = new ContainerBuilder();
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);

    return new VendorNavBuilder(
      $accessManager,
      $currentUser,
      $routeMatch,
      $moduleHandler,
      $requestStack,
      $translation,
    );
  }

  /**
   * @param list<array<string, mixed>> $items
   *
   * @return array<string, mixed>|null
   */
  private function findItemByKey(array $items, string $key): ?array {
    foreach ($items as $item) {
      if (($item['key'] ?? '') === $key) {
        return $item;
      }
    }
    return NULL;
  }

}
