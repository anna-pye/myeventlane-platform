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
    $onboardingKeys = ['dashboard', 'events', 'support'];

    foreach ($keys as $key) {
      $this->assertContains($key, $onboardingKeys);
    }
    $this->assertNotContains('analytics', $keys);
    $this->assertNotContains('marketing', $keys);
    $this->assertNotContains('payments', $keys);
    $this->assertNotContains('event_editor', $keys);
    $this->assertNotContains('checkin', $keys);
    $this->assertNotContains('payouts', $keys);
  }

  /**
   * Convergence shell excludes Event Editor, Check-in, and Refund requests.
   *
   * @covers ::buildShellNavItems
   */
  public function testFullShellMatchesConvergenceIa(): void {
    $builder = $this->createBuilder(
      routeName: 'myeventlane_vendor.console.dashboard',
      path: '/vendor/dashboard',
      allowedRoutes: [
        'myeventlane_vendor.console.dashboard',
        'myeventlane_vendor.console.events',
        'myeventlane_checkout_flow.vendor_attendees',
        'myeventlane_vendor.console.payments',
        'myeventlane_vendor.console.payouts',
        'myeventlane_vendor.console.boost',
        'myeventlane_vendor.console.messaging_brand',
        'myeventlane_analytics.dashboard',
        'myeventlane_escalations_portal.vendor_list',
        'myeventlane_vendor.console.settings',
      ],
    );

    $items = $builder->buildShellNavItems('dashboard');
    $keys = array_column($items, 'key');

    $this->assertSame(
      [
        'dashboard',
        'events',
        'attendees',
        'orders',
        'messages',
        'payments',
        'analytics',
        'marketing',
        'settings',
        'support',
      ],
      $keys,
    );

    $labels = [];
    foreach ($items as $item) {
      $labels[$item['key']] = (string) $item['label'];
    }
    $this->assertSame('Dashboard', $labels['dashboard']);
    $this->assertSame('Attendees', $labels['attendees']);
    $this->assertSame('Messages', $labels['messages']);
    $this->assertSame('Payments', $labels['payments']);
    $this->assertSame('Analytics', $labels['analytics']);
    $this->assertSame('Marketing', $labels['marketing']);
    $this->assertSame('Settings', $labels['settings']);

    $this->assertNotContains('event_editor', $keys);
    $this->assertNotContains('checkin', $keys);
    $this->assertNotContains('refund_requests', $keys);
    $this->assertNotContains('ticket_holders', $keys);
    $this->assertNotContains('promote', $keys);
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
        'myeventlane_checkout_flow.vendor_attendees',
        'myeventlane_vendor.console.payments',
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
    $this->assertSame('primary', $orders['nav_section']);
    $this->assertSame('', $orders['nav_section_label']);

    $dashboard = $this->findItemByKey($items, 'dashboard');
    $this->assertNotNull($dashboard);
    $this->assertSame('primary', $dashboard['nav_section']);
    $this->assertSame('Dashboard', (string) $dashboard['label']);

    $settings = $this->findItemByKey($items, 'settings');
    $this->assertNotNull($settings);
    $this->assertSame('account', $settings['nav_section']);
    $this->assertSame('Account', $settings['nav_section_label']);
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
   * Attendees path maps to attendees section (not legacy ticket_holders).
   *
   * @covers ::resolveActiveSection
   */
  public function testResolveActiveSectionFromAttendeesPath(): void {
    $builder = $this->createBuilder(
      routeName: 'myeventlane_checkout_flow.vendor_attendees',
      path: '/vendor/attendees',
    );

    $this->assertSame('attendees', $builder->resolveActiveSection('myeventlane_checkout_flow.vendor_attendees'));
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
   * Builds a VendorNavBuilder with mocked route access.
   *
   * @param string $routeName
   *   Current route name.
   * @param string $path
   *   Current request path.
   * @param list<string> $allowedRoutes
   *   Named routes that checkNamedRoute should allow (empty = allow all).
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
      static function ($markup): string {
        return method_exists($markup, 'getUntranslatedString')
          ? $markup->getUntranslatedString()
          : (string) $markup;
      },
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
   * Finds a shell nav item by key.
   *
   * @param list<array<string, mixed>> $items
   *   Built shell nav items.
   * @param string $key
   *   Item key to find.
   *
   * @return array<string, mixed>|null
   *   Matching item or NULL.
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
