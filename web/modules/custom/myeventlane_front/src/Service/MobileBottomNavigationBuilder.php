<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_surface\MelCustomerRouteCatalog;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Canonical mobile bottom navigation for the public marketplace theme.
 *
 * Route names and active-state rules live here only. Twig renders labels and
 * icons.
 */
final class MobileBottomNavigationBuilder {

  use StringTranslationTrait;

  /**
   * Upcoming-events browse routes that highlight the Events tab.
   *
   * Community Favourites (page_popular) is excluded — it highlights Community.
   *
   * @var list<string>
   */
  private const EVENTS_ROUTE_NAMES = [
    'view.upcoming_events.page_events',
    'view.upcoming_events.page_category',
    'view.upcoming_events.page_today',
    'view.upcoming_events.page_this_weekend',
    'view.upcoming_events.page_free',
    'view.upcoming_events.page_hidden_gems',
    'mel_search.view',
  ];

  /**
   * Auth routes that highlight the anonymous Sign in tab.
   *
   * @var list<string>
   */
  private const AUTH_ROUTE_NAMES = [
    'user.login',
    'user.register',
    'user.pass',
  ];

  /**
   * Focused routes where persistent marketplace navigation is distracting.
   *
   * @var list<string>
   */
  private const CHROME_FREE_ROUTE_NAMES = [
    'entity.commerce_order.user_view',
    'myeventlane_core.error_403',
    'myeventlane_core.error_404',
  ];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly PathMatcherInterface $pathMatcher,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Whether the bottom navigation should render on the active request.
   */
  public function shouldRender(bool $auth_minimal = FALSE): bool {
    if ($auth_minimal) {
      return FALSE;
    }

    $route = $this->routeMatch->getRouteName() ?? '';
    if ($route === '') {
      return FALSE;
    }

    return !str_starts_with($route, 'commerce_checkout.')
      && !str_starts_with($route, 'commerce_cart.')
      && !in_array($route, self::CHROME_FREE_ROUTE_NAMES, TRUE);
  }

  /**
   * Builds bottom navigation items for Twig.
   *
   * Each item: id, title, url, icon, active, route_name (for debugging/docs).
   *
   * @return list<array<string, mixed>>
   *   Bottom navigation items for Twig.
   */
  public function buildItems(): array {
    $currentRoute = $this->routeMatch->getRouteName() ?? '';
    $request = $this->requestStack->getCurrentRequest();
    $currentPath = $request ? $request->getPathInfo() : '/';

    $account_tab = $this->currentUser->isAuthenticated()
      ? [
        'id' => 'account',
        'title' => $this->t('Account'),
        'route_name' => 'myeventlane_account.dashboard',
        'icon' => 'user',
        'active' => MelCustomerRouteCatalog::isCustomerSurface($currentRoute, $currentPath),
      ]
      : [
        'id' => 'sign_in',
        'title' => $this->t('Sign in'),
        'route_name' => 'user.login',
        'icon' => 'user',
        'active' => in_array($currentRoute, self::AUTH_ROUTE_NAMES, TRUE),
      ];

    $definitions = [
      [
        'id' => 'home',
        'title' => $this->t('Home'),
        'route_name' => '<front>',
        'icon' => 'home',
        'active' => $this->pathMatcher->isFrontPage() || $currentRoute === 'view.frontpage.page_1',
      ],
      [
        'id' => 'events',
        'title' => $this->t('Events'),
        'route_name' => 'view.upcoming_events.page_events',
        'icon' => 'ticket',
        'active' => $this->isEventsActive($currentRoute),
      ],
      [
        'id' => 'calendar',
        'title' => $this->t('Calendar'),
        'route_name' => 'view.events_calendar.page_calendar',
        'icon' => 'calendar',
        'active' => $currentRoute === 'view.events_calendar.page_calendar',
      ],
      [
        'id' => 'community',
        'title' => $this->t('Community'),
        'route_name' => 'view.upcoming_events.page_popular',
        'icon' => 'heart',
        'active' => $currentRoute === 'view.upcoming_events.page_popular',
      ],
      $account_tab,
    ];

    $items = [];
    foreach ($definitions as $definition) {
      $url = $this->routeUrl((string) $definition['route_name']);
      if ($url === NULL) {
        continue;
      }
      $items[] = [
        'id' => $definition['id'],
        'title' => $definition['title'],
        'url' => $url,
        'icon' => $definition['icon'],
        'active' => (bool) $definition['active'],
        'route_name' => $definition['route_name'],
      ];
    }

    return $items;
  }

  /**
   * Whether the current route belongs to public event discovery.
   */
  public function isEventsActive(string $current_route): bool {
    if (in_array($current_route, self::EVENTS_ROUTE_NAMES, TRUE)) {
      return TRUE;
    }

    $node = $this->routeMatch->getParameter('node');
    return $current_route === 'entity.node.canonical'
      && $node instanceof EntityInterface
      && $node->bundle() === 'event';
  }

  /**
   * Resolves a route URL, skipping optional routes that are not available.
   */
  private function routeUrl(string $route_name): ?string {
    try {
      return Url::fromRoute($route_name, [], ['absolute' => FALSE])->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
