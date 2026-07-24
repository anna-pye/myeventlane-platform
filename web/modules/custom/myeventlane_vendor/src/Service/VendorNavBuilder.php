<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds organiser console shell sidebar navigation (single source of truth).
 *
 * VX2 Convergence IA (≤10 primary items):
 * Dashboard · Events · Attendees · Orders · Messages · Payments · Analytics ·
 * Marketing · Settings · Support.
 *
 * Output shape matches templates/includes/sidebar.html.twig expectations.
 */
final class VendorNavBuilder {

  use StringTranslationTrait;

  /**
   * Cache contexts required for shell navigation render arrays.
   *
   * @var list<string>
   */
  public const SHELL_NAV_CACHE_CONTEXTS = [
    'user.permissions',
    'route',
  ];

  /**
   * Sidebar IA section keys (primary console vs account).
   *
   * @var array<string, string>
   */
  private const NAV_SECTION_BY_KEY = [
    'dashboard' => 'primary',
    'events' => 'primary',
    'attendees' => 'primary',
    'orders' => 'primary',
    'messages' => 'primary',
    'payments' => 'primary',
    'analytics' => 'primary',
    'marketing' => 'primary',
    'settings' => 'account',
    'support' => 'account',
  ];

  public function __construct(
    private readonly AccessManagerInterface $accessManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly RequestStack $requestStack,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Resolves the active top-level sidebar section for the current request.
   */
  public function resolveActiveSection(?string $routeName): string {
    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';

    if ($routeName === 'myeventlane_vendor.console.messages'
      || $routeName === 'myeventlane_vendor.console.messaging_brand'
      || $routeName === 'myeventlane_vendor.console.event_promotion'
      || $routeName === 'myeventlane_vendor_comms.branding'
      || $routeName === 'myeventlane_vendor.message_attendees'
      || $routeName === 'myeventlane_pro.vendor_comms') {
      return 'messages';
    }
    if (str_starts_with($path, '/vendor/messages')
      || str_contains($path, '/vendor/dashboard/messaging/brand')
      || preg_match('#^/vendor/events/\d+/promotion(/|$)#', $path) === 1
      || preg_match('#^/vendor/events/\d+/message(/|$)#', $path) === 1
      || str_starts_with($path, '/vendor/pro/settings/comms')) {
      return 'messages';
    }
    if (str_starts_with($path, '/vendor/settings')) {
      return 'settings';
    }
    if (str_starts_with($path, '/vendor/analytics') || str_starts_with($path, '/vendor/insights')) {
      return 'analytics';
    }
    if ($path === '/vendor/attendees' || str_starts_with($path, '/vendor/attendees/')) {
      return 'attendees';
    }
    if (str_starts_with($path, '/vendor/marketing')
      || str_starts_with($path, '/vendor/boost')
      || str_starts_with($path, '/vendor/audience')) {
      return 'marketing';
    }
    if (str_starts_with($path, '/vendor/payments')
      || str_starts_with($path, '/vendor/payouts')
      || str_starts_with($path, '/vendor/finance')
      || str_starts_with($path, '/vendor/billing')
      || str_contains($path, '/refund')) {
      return 'payments';
    }
    if (str_starts_with($path, '/vendor/support') || str_starts_with($path, '/vendor/help')) {
      return 'support';
    }
    if (str_starts_with($path, '/vendor/studio') || str_contains($path, '/editor')) {
      return 'events';
    }
    if (str_starts_with($path, '/vendor/events')) {
      return 'events';
    }
    if ($path === '/vendor/dashboard' || str_starts_with($path, '/vendor/dashboard/')) {
      return 'dashboard';
    }
    if (preg_match('#^/event/\d+/tickets/checkin#', $path)) {
      return 'attendees';
    }

    if ($routeName === NULL || $routeName === '') {
      return 'dashboard';
    }

    $mapping = [
      'myeventlane_vendor.console.dashboard' => 'dashboard',
      'myeventlane_vendor.console.events' => 'events',
      'myeventlane_vendor.console.events_add' => 'events',
      'myeventlane_vendor.console.event_workspace' => 'events',
      'myeventlane_vendor.console.event_overview' => 'events',
      'myeventlane_vendor.console.event_tickets' => 'events',
      'myeventlane_vendor.console.event_publish' => 'events',
      'myeventlane_vendor.console.event_promotion' => 'messages',
      'myeventlane_vendor_comms.branding' => 'messages',
      'myeventlane_vendor.console.messages' => 'messages',
      'myeventlane_vendor.console.messaging_brand' => 'messages',
      'myeventlane_vendor.message_attendees' => 'messages',
      'myeventlane_event_studio.workspace_messaging' => 'events',
      'myeventlane_event_studio.workspace_messages' => 'events',
      'myeventlane_pro.vendor_comms' => 'messages',
      'myeventlane_vendor.console.event_analytics' => 'analytics',
      'myeventlane_vendor.console.event_settings' => 'events',
      'myeventlane_vendor.console.event_orders' => 'orders',
      'myeventlane_vendor.console.event_order_view' => 'orders',
      'myeventlane_refunds.vendor_refund_requests' => 'payments',
      'myeventlane_refunds.vendor_refund_request_approve' => 'payments',
      'myeventlane_refunds.vendor_refund_request_reject' => 'payments',
      'myeventlane_checkout_flow.vendor_attendees' => 'attendees',
      'myeventlane_event_attendees.vendor_list' => 'attendees',
      'myeventlane_vendor.console.event_rsvps' => 'attendees',
      'myeventlane_tickets.ticket_checkin' => 'attendees',
      'myeventlane_tickets.ticket_checkin_validate' => 'attendees',
      'myeventlane_vendor.console.payments' => 'payments',
      'myeventlane_vendor.console.payouts' => 'payments',
      'myeventlane_launch.vendor_finance' => 'payments',
      'myeventlane_finance.vendor_bas' => 'payments',
      'myeventlane_donations.vendor_mel_billing' => 'payments',
      'myeventlane_escalations_refunds.vendor_refund_summary' => 'payments',
      'myeventlane_vendor.console.marketing' => 'marketing',
      'myeventlane_vendor.console.boost' => 'marketing',
      'myeventlane_vendor.console.audience' => 'marketing',
      'myeventlane_vendor.console.settings' => 'settings',
      'myeventlane_pro.branding' => 'settings',
      'myeventlane_venue.vendor_venues' => 'settings',
      'myeventlane_venue.vendor_venue_add' => 'settings',
      'myeventlane_venue.vendor_venue_edit' => 'settings',
      'myeventlane_venue.vendor_venue_delete' => 'settings',
      'myeventlane_analytics.dashboard' => 'analytics',
      'myeventlane_analytics.event' => 'analytics',
      'myeventlane_analytics.export_pdf' => 'analytics',
      'myeventlane_analytics.export_excel' => 'analytics',
      'myeventlane_escalations_portal.vendor_list' => 'support',
      'myeventlane_escalations_portal.vendor_view' => 'support',
      'myeventlane_escalations_portal.vendor_resolve' => 'support',
      'myeventlane_escalations_portal.vendor_reopen' => 'support',
      'myeventlane_help_centre.vendor_help' => 'support',
      'myeventlane_help_centre.home' => 'support',
      'myeventlane_event_studio.create' => 'events',
      'myeventlane_event_studio.edit' => 'events',
      'myeventlane_vendor.console.event_editor' => 'events',
      'myeventlane_vendor.console.studio' => 'events',
      'myeventlane_vendor.create_event_gateway' => 'events',
      'myeventlane_vendor.create_event_draft_choice' => 'events',
      'myeventlane_event.wizard.basics' => 'events',
      'myeventlane_event.wizard.when_where' => 'events',
      'myeventlane_event.wizard.tickets' => 'events',
      'myeventlane_event.wizard.details' => 'events',
      'myeventlane_event.wizard.review' => 'events',
      'myeventlane_event.wizard.publish' => 'events',
      'myeventlane_event.wizard.success' => 'events',
      'myeventlane_vendor.dashboard' => 'dashboard',
    ];

    return $mapping[$routeName] ?? 'dashboard';
  }

  /**
   * Builds sidebar navigation items for the vendor shell.
   *
   * @return list<array<string, mixed>>
   *   Sidebar items for vendor shell (Twig-compatible arrays).
   */
  public function buildShellNavItems(string $activeSection): array {
    $routeName = $this->routeMatch->getRouteName();
    if ($this->isVendorOnboardRoute($routeName)) {
      return $this->buildOnboardingShellNavItems($activeSection);
    }
    return $this->buildFullShellNavItems($activeSection, $routeName);
  }

  /**
   * Reduced shell during onboarding: Dashboard · Events · Support.
   *
   * @return list<array<string, mixed>>
   *   Sidebar items for the onboarding shell.
   */
  private function buildOnboardingShellNavItems(string $activeSection): array {
    $routeName = $this->routeMatch->getRouteName();
    $editChildren = $this->buildEventEditSubmenu(
      is_string($routeName) ? $routeName : NULL,
    );
    $candidates = [
      [
        'key' => 'dashboard',
        'label' => $this->t('Dashboard'),
        'icon' => 'dashboard',
        'route' => 'myeventlane_vendor.console.dashboard',
      ],
      [
        'key' => 'events',
        'label' => $this->t('Events'),
        'icon' => 'events',
        'route' => 'myeventlane_vendor.console.events',
        'children' => $editChildren,
      ],
      [
        'key' => 'support',
        'label' => $this->t('Support'),
        'icon' => 'help',
        'route' => 'myeventlane_help_centre.home',
        'path_fallback' => '/help',
      ],
    ];
    $built = [];
    foreach ($candidates as $item) {
      $route = (string) $item['route'];
      if (!$this->namedRouteAccessible($route, [])) {
        continue;
      }
      $url = $this->safeRouteUrl($route);
      if ($url === NULL && !empty($item['path_fallback'])) {
        $url = (string) $item['path_fallback'];
      }
      if ($url === NULL) {
        continue;
      }
      $item['url'] = $url;
      $item['is_disabled'] = FALSE;
      $built[] = $this->decorateShellNavItem($item, $activeSection);
    }
    return $built;
  }

  /**
   * Full Convergence shell navigation.
   *
   * @return list<array<string, mixed>>
   *   Sidebar items for the full organiser shell.
   */
  private function buildFullShellNavItems(string $activeSection, ?string $routeName): array {
    $editChildren = $this->buildEventEditSubmenu($routeName);
    $eventId = $this->resolveEventId();

    $definitions = [
      [
        'key' => 'dashboard',
        'label' => $this->t('Dashboard'),
        'icon' => 'dashboard',
        'route' => 'myeventlane_vendor.console.dashboard',
        'children' => [],
      ],
      [
        'key' => 'events',
        'label' => $this->t('Events'),
        'icon' => 'events',
        'route' => 'myeventlane_vendor.console.events',
        'children' => $editChildren,
      ],
      [
        'key' => 'attendees',
        'label' => $this->t('Attendees'),
        'icon' => 'attendees',
        'route' => 'myeventlane_checkout_flow.vendor_attendees',
        'children' => [],
      ],
      [
        'key' => 'orders',
        'label' => $this->t('Orders'),
        'icon' => 'orders',
        'route' => NULL,
        'children' => [],
        'event_route' => 'myeventlane_vendor.console.event_orders',
      ],
      [
        'key' => 'messages',
        'label' => $this->t('Messages'),
        'icon' => 'notifications',
        'route' => 'myeventlane_vendor.console.messages',
        'children' => [],
      ],
      [
        'key' => 'payments',
        'label' => $this->t('Payments'),
        'icon' => 'payouts',
        'route' => 'myeventlane_vendor.console.payments',
        'children' => [],
      ],
      [
        'key' => 'analytics',
        'label' => $this->t('Analytics'),
        'icon' => 'analytics',
        'route' => 'myeventlane_analytics.dashboard',
        'children' => [],
      ],
      [
        'key' => 'marketing',
        'label' => $this->t('Marketing'),
        'icon' => 'growth',
        'route' => 'myeventlane_vendor.console.marketing',
        'children' => [],
      ],
      [
        'key' => 'settings',
        'label' => $this->t('Settings'),
        'icon' => 'settings',
        'route' => 'myeventlane_vendor.console.settings',
        'children' => [],
      ],
      [
        'key' => 'support',
        'label' => $this->t('Support'),
        'icon' => 'help',
        'route' => 'myeventlane_escalations_portal.vendor_list',
        'children' => [],
      ],
    ];

    $built = [];
    foreach ($definitions as $def) {
      if (!empty($def['requires_module'])
        && !$this->moduleHandler->moduleExists((string) $def['requires_module'])) {
        continue;
      }
      if (!empty($def['requires_permission'])
        && !$this->currentUser->hasPermission((string) $def['requires_permission'])) {
        continue;
      }

      $item = [
        'key' => $def['key'],
        'label' => $def['label'],
        'icon' => $def['icon'],
        'children' => $def['children'] ?? [],
      ];

      if (!empty($def['node_route'])) {
        $nodeRoute = (string) $def['node_route'];
        $item['route'] = $nodeRoute;
        $nodeId = $this->resolveEventId();
        if ($nodeId === NULL) {
          $item['url'] = NULL;
          $item['is_disabled'] = TRUE;
        }
        elseif (!$this->namedRouteAccessible($nodeRoute, ['node' => $nodeId])) {
          $item['url'] = NULL;
          $item['is_disabled'] = TRUE;
        }
        else {
          $url = $this->safeRouteUrl($nodeRoute, ['node' => $nodeId]);
          $item['url'] = $url;
          $item['is_disabled'] = $url === NULL;
        }
        $built[] = $this->decorateShellNavItem($item, $activeSection);
        continue;
      }

      if (!empty($def['event_route'])) {
        $eventRoute = (string) $def['event_route'];
        $item['route'] = $eventRoute;
        if ($eventId === NULL) {
          $item['url'] = NULL;
          $item['is_disabled'] = TRUE;
        }
        else {
          $params = ['event' => $eventId];
          if (!$this->namedRouteAccessible($eventRoute, $params)) {
            $item['url'] = NULL;
            $item['is_disabled'] = TRUE;
          }
          else {
            $url = $this->safeRouteUrl($eventRoute, $params);
            $item['url'] = $url;
            $item['is_disabled'] = $url === NULL;
          }
        }
        $built[] = $this->decorateShellNavItem($item, $activeSection);
        continue;
      }

      $route = (string) ($def['route'] ?? '');
      if ($route === '') {
        continue;
      }
      if (!$this->namedRouteAccessible($route, [])) {
        continue;
      }
      $url = $this->safeRouteUrl($route);
      if ($url === NULL && !empty($def['path_fallback'])) {
        $url = (string) $def['path_fallback'];
      }
      $item['route'] = $route;
      $item['url'] = $url;
      $item['is_disabled'] = $url === NULL;
      $built[] = $this->decorateShellNavItem($item, $activeSection);
    }

    return $built;
  }

  /**
   * Event Workspace deep-link under Events (not a peer shell item).
   *
   * @return list<array<string, mixed>>
   *   Nested Events submenu items when an event is in context.
   */
  private function buildEventEditSubmenu(?string $routeName): array {
    $eventId = $this->resolveEventId();
    if ($eventId === NULL) {
      return [];
    }

    if (!$this->namedRouteAccessible('myeventlane_event_studio.edit', ['node' => $eventId])) {
      return [];
    }

    $url = $this->safeRouteUrl('myeventlane_event_studio.edit', ['node' => $eventId]);

    return [
      [
        'label' => $this->t('Edit event'),
        'url' => $url,
        'is_disabled' => $url === NULL,
        'is_active' => in_array($routeName, $this->eventStudioRoutes(), TRUE),
      ],
    ];
  }

  /**
   * Adds active state, accessibility, and section metadata to a nav item.
   *
   * @param array<string, mixed> $item
   *   Raw nav item definition.
   * @param string $activeSection
   *   Active top-level section key for the current request.
   *
   * @return array<string, mixed>
   *   Decorated nav item for Twig.
   */
  private function decorateShellNavItem(array $item, string $activeSection): array {
    $route = $item['route'] ?? NULL;
    $item['route_name'] = is_string($route) ? $route : NULL;
    $url = $item['url'] ?? NULL;
    $disabled = !empty($item['is_disabled']);
    $item['is_accessible'] = is_string($url) && $url !== '' && !$disabled;
    $item['is_active'] = (($item['key'] ?? '') === $activeSection);

    $key = (string) ($item['key'] ?? '');
    $section = (string) ($item['nav_section'] ?? self::NAV_SECTION_BY_KEY[$key] ?? '');
    if ($section !== '') {
      $item['nav_section'] = $section;
      $item['nav_section_label'] = $this->navSectionLabel($section);
    }

    return $item;
  }

  /**
   * Returns the human label for a sidebar section key.
   */
  private function navSectionLabel(string $section): string {
    return match ($section) {
      'primary' => '',
      'account' => (string) $this->t('Account'),
      default => '',
    };
  }

  /**
   * Whether the current route is an organiser onboarding step.
   *
   * @param string|null $routeName
   *   Current route name, if any.
   */
  private function isVendorOnboardRoute(?string $routeName): bool {
    return is_string($routeName) && str_starts_with($routeName, 'myeventlane_vendor.onboard');
  }

  /**
   * Checks named-route access for the current account.
   *
   * @param string $routeName
   *   Named route to check.
   * @param array<string, mixed> $parameters
   *   Route parameters.
   */
  private function namedRouteAccessible(string $routeName, array $parameters): bool {
    try {
      return $this->accessManager
        ->checkNamedRoute($routeName, $parameters, $this->currentUser->getAccount(), TRUE)
        ->isAllowed();
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * Builds a URL string for a named route, or NULL if generation fails.
   *
   * @param string $routeName
   *   Named route to build.
   * @param array<string, mixed> $parameters
   *   Route parameters.
   */
  private function safeRouteUrl(string $routeName, array $parameters = []): ?string {
    try {
      return Url::fromRoute($routeName, $parameters)->toString();
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Resolves the event node ID from route match parameters, if present.
   */
  private function resolveEventId(): ?int {
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface && $node->bundle() === 'event') {
      return (int) $node->id();
    }
    if (is_numeric($node) && (int) $node > 0) {
      return (int) $node;
    }

    $event = $this->routeMatch->getParameter('event');
    if ($event instanceof NodeInterface && $event->bundle() === 'event') {
      return (int) $event->id();
    }
    if (is_numeric($event) && (int) $event > 0) {
      return (int) $event;
    }

    return NULL;
  }

  /**
   * Route names that belong to Event Studio workspace editing.
   *
   * @return list<string>
   *   Event Studio route names used for submenu active state.
   */
  private function eventStudioRoutes(): array {
    return [
      'myeventlane_event_studio.create',
      'myeventlane_event_studio.edit',
      'myeventlane_event_studio.workspace',
      'myeventlane_event_studio.workspace_information',
      'myeventlane_event_studio.workspace_branding',
      'myeventlane_event_studio.workspace_content',
      'myeventlane_event_studio.workspace_tickets',
      'myeventlane_event_studio.workspace_questions',
      'myeventlane_event_studio.workspace_capacity',
      'myeventlane_event_studio.workspace_extras',
      'myeventlane_event_studio.workspace_merchandise',
      'myeventlane_event_studio.workspace_addons',
      'myeventlane_event_studio.workspace_add_ons',
      'myeventlane_event_studio.workspace_messaging',
      'myeventlane_event_studio.workspace_attendees',
      'myeventlane_event_studio.workspace_fulfilment',
      'myeventlane_event_studio.workspace_orders',
      'myeventlane_event_studio.workspace_analytics',
      'myeventlane_event_studio.workspace_settings',
    ];
  }

}
