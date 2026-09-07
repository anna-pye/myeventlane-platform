<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

require_once dirname(__DIR__, 3) . '/src/Service/MobileBottomNavigationBuilder.php';

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_front\Service\MobileBottomNavigationBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests focused-route suppression for the public mobile navigation.
 *
 * @group myeventlane_front
 */
final class MobileBottomNavigationBuilderTest extends TestCase {

  /**
   * Event detail pages remain part of event discovery.
   */
  public function testEventDetailHighlightsEvents(): void {
    $event = $this->createMock(EntityInterface::class);
    $event->method('bundle')->willReturn('event');

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameter')->with('node')->willReturn($event);

    $builder = new MobileBottomNavigationBuilder(
      $route_match,
      $this->createMock(PathMatcherInterface::class),
      new RequestStack(),
      $this->createMock(AccountProxyInterface::class),
    );

    self::assertTrue($builder->isEventsActive('entity.node.canonical'));
  }

  /**
   * Transactional and recovery routes must not show marketplace navigation.
   */
  #[DataProvider('chromeFreeRoutes')]
  public function testFocusedRoutesDoNotRenderNavigation(string $route): void {
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteName')->willReturn($route);

    $builder = new MobileBottomNavigationBuilder(
      $route_match,
      $this->createMock(PathMatcherInterface::class),
      new RequestStack(),
      $this->createMock(AccountProxyInterface::class),
    );

    self::assertFalse($builder->shouldRender());
  }

  /**
   * Provides routes that use focused chrome.
   *
   * @return iterable<string, array{string}>
   *   Routes that must suppress mobile navigation.
   */
  public static function chromeFreeRoutes(): iterable {
    yield 'cart' => ['commerce_cart.page'];
    yield 'checkout' => ['commerce_checkout.form'];
    yield 'order details' => ['entity.commerce_order.user_view'];
    yield '403' => ['myeventlane_core.error_403'];
    yield '404' => ['myeventlane_core.error_404'];
  }

}
