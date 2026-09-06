<?php

declare(strict_types=1);

namespace Drupal\Tests\mel_guide\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\Session\UserSession;
use Drupal\mel_guide\Service\MelGuideVisibility;
use Drupal\myeventlane_surface\MelFormClassificationResolver;
use Drupal\myeventlane_surface\SurfaceResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Covers organiser booking ownership without widening dashboard visibility.
 */
#[Group('mel_guide')]
final class MelGuideBookingVisibilityTest extends UnitTestCase {

  /**
   * Ownership only changes targeting on the booking detail route.
   */
  public function testBookingAudienceAndCacheDependencies(): void {
    $cases = [
      ['own booking', 44, 44, 'myeventlane_checkout_flow.order_detail', '/my-tickets/order/550', TRUE, TRUE, TRUE],
      ['other booking', 44, 45, 'myeventlane_checkout_flow.order_detail', '/my-tickets/order/550', TRUE, TRUE, FALSE],
      ['guest booking', 44, 0, 'myeventlane_checkout_flow.order_detail', '/my-tickets/order/550', TRUE, TRUE, FALSE],
      ['dashboard', 44, 44, 'myeventlane_vendor.dashboard', '/vendor/dashboard', TRUE, TRUE, FALSE],
      ['customer toggle off', 44, 44, 'myeventlane_checkout_flow.order_detail', '/my-tickets/order/550', FALSE, TRUE, FALSE],
      ['master toggle off', 44, 44, 'myeventlane_checkout_flow.order_detail', '/my-tickets/order/550', TRUE, FALSE, FALSE],
    ];
    foreach ($cases as [$label, $uid, $owner, $route_name, $path, $customers, $enabled, $expected]) {
      $account = new AccountProxy(new \Symfony\Component\EventDispatcher\EventDispatcher());
      $account->setAccount(new UserSession(['uid' => $uid, 'roles' => ['authenticated', 'vendor', 'mel_pro']]));
      $order = $this->createMock(OrderInterface::class);
      $order->method('getCustomerId')->willReturn($owner);
      $order->method('getCacheTags')->willReturn(['commerce_order:550']);
      $route = $this->createMock(RouteMatchInterface::class);
      $route->method('getRouteName')->willReturn($route_name);
      $route->method('getParameter')->with('commerce_order')->willReturn($order);
      $stack = new RequestStack();
      $stack->push(Request::create($path));
      $surface = new SurfaceResolver($route, $stack);
      $config = $this->getConfigFactoryStub(['mel_guide.settings' => [
        'enabled' => $enabled,
        'enabled_desktop' => TRUE,
        'show_for_authenticated' => TRUE,
        'show_for_customers' => $customers,
        'show_for_organisers' => FALSE,
      ]]);
      $visibility = new MelGuideVisibility($config, $account, $surface, new MelFormClassificationResolver($surface, $route, $stack), $route, $stack);
      self::assertSame($expected, $visibility->shouldAttach(), $label);
      self::assertSame(['commerce_order:550'], $visibility->getCacheTags(), $label);
    }
  }

}
