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
 * Builds vendor console shell sidebar navigation (single source of truth).
 *
 * Replaces procedural navigation builders previously in the vendor theme.
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
   * Sidebar IA section keys (HOME, EVENTS, OPERATIONS, GROWTH, ACCOUNT).
   *
   * @var array<string, string>
   */
  private const NAV_SECTION_BY_KEY = [
    'dashboard' => 'home',
    'events' => 'events',
    'event_editor' => 'events',
    'orders' => 'operations',
    'ticket_holders' => 'operations',
    'checkin' => 'operations',
    'refund_requests' => 'operations',
    'payouts' => 'operations',
    'promote' => 'growth',
    'messaging' => 'growth',
    'analytics' => 'growth',
    'support' => 'account',
    'settings' => 'account',
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

    if ($routeName === 'myeventlane_vendor.console.messaging_brand') {
      return 'messaging';
    }
    if (str_starts_with($path, '/vendor/settings')) {
      return 'settings';
    }
    if (str_starts_with($path, '/vendor/analytics')) {
      return 'analytics';
    }
    if ($path === '/vendor/attendees') {
      return 'ticket_holders';
    }
    if (preg_match('#^/event/\d+/tickets/checkin#', $path)) {
      return 'checkin';
    }
    if (str_starts_with($path, '/vendor/support')) {
      return 'support';
    }
    if (str_starts_with($path, '/vendor/studio')) {
      return 'event_editor';
    }
    if (str_starts_with($path, '/vendor/events')) {
      return 'events';
    }
    if (str_contains($path, '/vendor/dashboard/messaging/brand')) {
      return 'messaging';
    }
    if ($path === '/vendor/dashboard' || str_starts_with($path, '/vendor/dashboard/')) {
      return 'dashboard';
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
      'myeventlane_vendor.console.event_promotion' => 'events',
      'myeventlane_vendor_comms.branding' => 'events',
      'myeventlane_vendor.console.event_analytics' => 'events',
      'myeventlane_vendor.console.event_settings' => 'events',
      'myeventlane_vendor.console.event_orders' => 'orders',
      'myeventlane_vendor.console.event_order_view' => 'orders',
      'myeventlane_refunds.vendor_refund_requests' => 'refund_requests',
      'myeventlane_refunds.vendor_refund_request_approve' => 'refund_requests',
      'myeventlane_refunds.vendor_refund_request_reject' => 'refund_requests',
      'myeventlane_checkout_flow.vendor_attendees' => 'ticket_holders',
      'myeventlane_event_attendees.vendor_list' => 'ticket_holders',
      'myeventlane_vendor.console.event_rsvps' => 'ticket_holders',
      'myeventlane_tickets.ticket_checkin' => 'checkin',
      'myeventlane_tickets.ticket_checkin_validate' => 'checkin',
      'myeventlane_vendor.console.payouts' => 'payouts',
      'myeventlane_vendor.console.boost' => 'promote',
      'myeventlane_vendor.console.audience' => 'dashboard',
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
      'myeventlane_help_centre.home' => 'dashboard',
      'myeventlane_event_studio.create' => 'events',
      'myeventlane_event_studio.edit' => 'event_editor',
      'myeventlane_vendor.console.event_editor' => 'event_editor',
      'myeventlane_vendor.console.studio' => 'event_editor',
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
   * @return list<array<string, mixed>>
   */
  private function buildOnboardingShellNavItems(string $activeSection): array {
    $routeName = $this->routeMatch->getRouteName();
    $wizardChildren = $this->buildEventWizardSubmenu(
      is_string($routeName) ? $routeName : NULL,
    );
    $candidates = [
      [
        'key' => 'dashboard',
        'label' => $this->t('Home'),
        'icon' => 'dashboard',
        'route' => 'myeventlane_vendor.console.dashboard',
      ],
      [
        'key' => 'events',
        'label' => $this->t('Events'),
        'icon' => 'events',
        'route' => 'myeventlane_vendor.console.events',
        'children' => $wizardChildren,
      ],
      [
        'key' => 'payouts',
        'label' => $this->t('Payouts'),
        'icon' => 'payouts',
        'route' => 'myeventlane_vendor.console.payouts',
      ],
      [
        'key' => 'settings',
        'label' => $this->t('Organiser settings'),
        'icon' => 'settings',
        'route' => 'myeventlane_vendor.console.settings',
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
   * @return list<array<string, mixed>>
   */
  private function buildFullShellNavItems(string $activeSection, ?string $routeName): array {
    $wizardChildren = $this->buildEventWizardSubmenu($routeName);
    $eventId = $this->resolveEventId();

    $definitions = [
      [
        'key' => 'dashboard',
        'label' => $this->t('Home'),
        'icon' => 'dashboard',
        'route' => 'myeventlane_vendor.console.dashboard',
        'children' => [],
      ],
      [
        'key' => 'events',
        'label' => $this->t('Events'),
        'icon' => 'events',
        'route' => 'myeventlane_vendor.console.events',
        'children' => $wizardChildren,
      ],
      [
        'key' => 'event_editor',
        'label' => $this->t('Event Editor'),
        'icon' => 'events',
        'route' => 'myeventlane_event_studio.create',
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
        'key' => 'ticket_holders',
        'label' => $this->t('Ticket holders'),
        'icon' => 'attendees',
        'route' => 'myeventlane_checkout_flow.vendor_attendees',
        'children' => [],
      ],
      [
        'key' => 'checkin',
        'label' => $this->t('Check-in'),
        'icon' => 'attendees',
        'route' => NULL,
        'children' => [],
        'event_route' => 'myeventlane_tickets.ticket_checkin',
      ],
      [
        'key' => 'refund_requests',
        'label' => $this->t('Refund requests'),
        'icon' => 'refunds',
        'route' => NULL,
        'children' => [],
        'node_route' => 'myeventlane_refunds.vendor_refund_requests',
        'requires_module' => 'myeventlane_refunds',
        'requires_permission' => 'manage_refunds',
      ],
      [
        'key' => 'payouts',
        'label' => $this->t('Payouts'),
        'icon' => 'payouts',
        'route' => 'myeventlane_vendor.console.payouts',
        'children' => [],
      ],
      [
        'key' => 'promote',
        'label' => $this->t('Grow event'),
        'icon' => 'growth',
        'route' => 'myeventlane_vendor.console.boost',
        'children' => [],
      ],
      [
        'key' => 'messaging',
        'label' => $this->t('Messaging'),
        'icon' => 'notifications',
        'route' => 'myeventlane_vendor.console.messaging_brand',
        'children' => [],
      ],
      [
        'key' => 'analytics',
        'label' => $this->t('Insights'),
        'icon' => 'analytics',
        'route' => 'myeventlane_analytics.dashboard',
        'children' => [],
      ],
      [
        'key' => 'support',
        'label' => $this->t('Support'),
        'icon' => 'help',
        'route' => 'myeventlane_escalations_portal.vendor_list',
        'children' => [],
      ],
      [
        'key' => 'settings',
        'label' => $this->t('Organiser settings'),
        'icon' => 'settings',
        'route' => 'myeventlane_vendor.console.settings',
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
   * @return list<array<string, mixed>>
   */
  private function buildEventWizardSubmenu(?string $routeName): array {
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
        'label' => $this->t('Event Editor'),
        'url' => $url,
        'is_disabled' => $url === NULL,
        'is_active' => in_array($routeName, $this->eventStudioRoutes(), TRUE),
      ],
    ];
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
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

  private function navSectionLabel(string $section): string {
    return match ($section) {
      'home' => (string) $this->t('Home'),
      'events' => (string) $this->t('Events'),
      'operations' => (string) $this->t('Operations'),
      'growth' => (string) $this->t('Growth'),
      'account' => (string) $this->t('Account'),
      default => '',
    };
  }

  private function isVendorOnboardRoute(?string $routeName): bool {
    return is_string($routeName) && str_starts_with($routeName, 'myeventlane_vendor.onboard');
  }

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
   * @param array<string, mixed> $parameters
   */
  private function safeRouteUrl(string $routeName, array $parameters = []): ?string {
    try {
      return Url::fromRoute($routeName, $parameters)->toString();
    }
    catch (\Exception) {
      return NULL;
    }
  }

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
   * @return list<string>
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
