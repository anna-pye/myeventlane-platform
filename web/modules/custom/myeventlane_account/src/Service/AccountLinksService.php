<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Single canonical navigation source for the public customer account hub.
 *
 * Sidebar, mobile nav, account dropdown, quick actions, and breadcrumbs must
 * derive from this service only.
 */
final class AccountLinksService {

  use StringTranslationTrait;

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Builds all customer hub navigation items with active flags.
   *
   * Each item:
   * - id: machine id (stable)
   * - title: TranslatableMarkup
   * - url: string (internal path or absolute path)
   * - active: bool
   * - in_quick: bool (shown in dashboard quick actions)
   * - routes_match: list<string> route names that mark this item active
   *
   * @return list<array<string, mixed>>
   */
  public function buildNavigationItems(): array {
    $currentRoute = $this->routeMatch->getRouteName() ?? '';

    $supportUrl = '/support/tickets';
    if ($this->moduleHandler->moduleExists('myeventlane_escalations_portal')) {
      try {
        $supportUrl = Url::fromRoute('myeventlane_escalations_portal.customer_support_tickets', [], ['absolute' => FALSE])->toString();
      }
      catch (\Throwable) {
        try {
          $supportUrl = Url::fromRoute('myeventlane_escalations_portal.customer_support', [], ['absolute' => FALSE])->toString();
        }
        catch (\Throwable) {
        }
      }
    }

    $savedEventsUrl = '/my-saved-events';
    try {
      $savedEventsUrl = Url::fromRoute('view.mel_saved_events.page_1', [], ['absolute' => FALSE])->toString();
    }
    catch (\Throwable) {
    }

    $notificationsUrl = '/my-notifications';
    try {
      $notificationsUrl = Url::fromRoute('myeventlane_notifications.inbox', [], ['absolute' => FALSE])->toString();
    }
    catch (\Throwable) {
    }

    $categoriesUrl = '/my-categories';
    try {
      $categoriesUrl = Url::fromRoute('myeventlane_core.my_categories', [], ['absolute' => FALSE])->toString();
    }
    catch (\Throwable) {
    }

    $followedOrganisersUrl = '/my-organisers';
    try {
      $followedOrganisersUrl = Url::fromRoute('myeventlane_account.followed_organisers', [], ['absolute' => FALSE])->toString();
    }
    catch (\Throwable) {
    }

    $settingsUrl = '/my-settings';
    if ($this->currentUser->isAuthenticated()) {
      try {
        $settingsUrl = Url::fromRoute('myeventlane_account.settings', [
          'user' => $this->currentUser->id(),
        ], ['absolute' => FALSE])->toString();
      }
      catch (\Throwable) {
      }
    }

    $discoverUrl = '/events';

    $ticketsUrl = Url::fromRoute('myeventlane_checkout_flow.my_tickets', [], ['absolute' => FALSE])->toString();
    $dashboardUrl = Url::fromRoute('myeventlane_account.dashboard', [], ['absolute' => FALSE])->toString();

    // myeventlane_dashboard is optional; mirror other hub links that guard optional routes.
    $eventsUrl = $dashboardUrl;
    try {
      $eventsUrl = Url::fromRoute('myeventlane_dashboard.customer', [], ['absolute' => FALSE])->toString();
    }
    catch (\Throwable) {
    }

    $supportRoutesMatch = [];
    if ($this->moduleHandler->moduleExists('myeventlane_escalations_portal')) {
      $supportRoutesMatch = [
        'myeventlane_escalations_portal.customer_support',
        'myeventlane_escalations_portal.customer_support_tickets',
        'myeventlane_escalations_portal.customer_list',
        'myeventlane_escalations_portal.customer_add',
        'myeventlane_escalations_portal.customer_view',
        'myeventlane_escalations_portal.customer_reopen',
      ];
    }

    $definitions = [
      [
        'id' => 'discover',
        'title' => $this->t('Discover'),
        'url' => $discoverUrl,
        'in_quick' => TRUE,
        'routes_match' => [],
      ],
      [
        'id' => 'dashboard',
        'title' => $this->t('Dashboard'),
        'url' => $dashboardUrl,
        'in_quick' => FALSE,
        'show_in_sidebar' => FALSE,
        'routes_match' => [
          'myeventlane_account.dashboard',
        ],
      ],
      [
        'id' => 'tickets',
        'title' => $this->t('Tickets'),
        'url' => $ticketsUrl,
        'in_quick' => TRUE,
        'show_in_sidebar' => TRUE,
        'routes_match' => [
          'myeventlane_checkout_flow.my_tickets',
          'myeventlane_checkout_flow.order_detail',
          'myeventlane_checkout_flow.order_tax_invoice_pdf',
        ],
      ],
      [
        'id' => 'my_events',
        'title' => $this->t('All my events'),
        'url' => $eventsUrl,
        'in_quick' => TRUE,
        'show_in_sidebar' => FALSE,
        'routes_match' => [
          'myeventlane_dashboard.customer',
        ],
      ],
      [
        'id' => 'saved_events',
        'title' => $this->t('Saved events'),
        'url' => $savedEventsUrl,
        'in_quick' => TRUE,
        'routes_match' => [
          'view.mel_saved_events.page_1',
        ],
      ],
      [
        'id' => 'categories',
        'title' => $this->t('Categories'),
        'url' => $categoriesUrl,
        'in_quick' => TRUE,
        'routes_match' => [
          'myeventlane_core.my_categories',
        ],
      ],
      [
        'id' => 'followed_organisers',
        'title' => $this->t('Organisers'),
        'url' => $followedOrganisersUrl,
        'in_quick' => TRUE,
        'routes_match' => [
          'myeventlane_account.followed_organisers',
        ],
      ],
      [
        'id' => 'support',
        'title' => $this->t('Support'),
        'url' => $supportUrl,
        'in_quick' => TRUE,
        'routes_match' => $supportRoutesMatch,
      ],
      [
        'id' => 'notifications',
        'title' => $this->t('Notifications'),
        'url' => $notificationsUrl,
        'in_quick' => TRUE,
        'routes_match' => [
          'myeventlane_notifications.inbox',
          'myeventlane_notifications.preferences',
        ],
      ],
      [
        'id' => 'settings',
        'title' => $this->t('Settings'),
        'url' => $settingsUrl,
        'in_quick' => TRUE,
        'routes_match' => [
          'myeventlane_account.settings',
          'myeventlane_account.settings_redirect',
        ],
      ],
      [
        'id' => 'logout',
        'title' => $this->t('Log out'),
        'url' => Url::fromRoute('user.logout', [], ['absolute' => FALSE])->toString(),
        'in_quick' => FALSE,
        'show_in_sidebar' => FALSE,
        'routes_match' => [],
      ],
    ];

    $items = [];
    foreach ($definitions as $def) {
      $active = $this->routeMatches($currentRoute, (array) $def['routes_match']);
      $items[] = [
        'id' => $def['id'],
        'title' => $def['title'],
        'url' => $def['url'],
        'active' => $active,
        'in_quick' => (bool) $def['in_quick'],
        'show_in_sidebar' => (bool) ($def['show_in_sidebar'] ?? TRUE),
        'routes_match' => $def['routes_match'],
      ];
    }

    // Past events lives under same hub but not primary IA; highlight Dashboard when on past.
    if ($currentRoute === 'myeventlane_account.past_events') {
      foreach ($items as &$item) {
        $item['active'] = ($item['id'] ?? '') === 'dashboard';
      }
      unset($item);
    }

    // RSVP list: no dedicated sidebar item; keep hub context on Dashboard.
    if ($currentRoute === 'myeventlane_rsvp.user_list') {
      foreach ($items as &$item) {
        $item['active'] = ($item['id'] ?? '') === 'dashboard';
      }
      unset($item);
    }

    return $items;
  }

  /**
   * @deprecated Use buildNavigationItems(); retained for BC in tests.
   *
   * @return list<array<string, mixed>>
   */
  public function buildLinks(string $active = ''): array {
    return $this->buildNavigationItems();
  }

  /**
   * Items intended for compact quick-action grids (subset + browse).
   *
   * @return list<array<string, mixed>>
   */
  public function buildQuickActionItems(): array {
    $out = [];
    foreach ($this->buildNavigationItems() as $item) {
      if (!empty($item['in_quick']) && ($item['id'] ?? '') !== 'logout') {
        $out[] = $item;
      }
    }
    return $out;
  }

  private function routeMatches(string $currentRoute, array $routesMatch): bool {
    if ($routesMatch === []) {
      return FALSE;
    }
    return in_array($currentRoute, $routesMatch, TRUE);
  }

}
