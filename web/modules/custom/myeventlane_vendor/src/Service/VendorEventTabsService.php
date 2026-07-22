<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\VendorOperationalAddonOrderBuilder;
use Drupal\myeventlane_core\Service\EventStateResolver;
use Drupal\myeventlane_event\Service\EventModeManager;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Builds event console tabs aligned to VX2 Event Workspace IA.
 *
 * Primary sections stay consistent; items that do not apply are shown disabled.
 * Organisers are redirected into Event Workspace (Studio shell) for most routes.
 */
final class VendorEventTabsService {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventModeManager $eventModeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    TranslationInterface $string_translation,
    private readonly RouteProviderInterface $routeProvider,
    private readonly AccessManagerInterface $accessManager,
    private readonly AccountInterface $currentUser,
    private readonly VendorOperationalAddonOrderBuilder $vendorOperationalAddonOrderBuilder,
    private readonly EventStateResolver $eventStateResolver,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Gets tabs for the event console (legacy shape for workspace-tabs Twig).
   *
   * @return array<int, array<string, mixed>>
   *   Tab definitions: label, url, key, active, disabled (optional).
   */
  public function getTabs(NodeInterface $event, string $active): array {
    $account = $this->currentUser;
    $rows = $this->buildTabRows($event);
    $tabs = [];
    foreach ($rows as $row) {
      /** @var array<string, mixed> $row */
      $disabled = (bool) ($row['disabled'] ?? FALSE);
      $urlObj = NULL;
      if (!$disabled) {
        $urlObj = $this->routeUrlIfAccessible((string) $row['route'], (array) $row['params'], $account);
      }
      $tab = [
        'label' => (string) $row['label'],
        'url' => $urlObj instanceof Url ? $urlObj->toString() : '',
        'key' => (string) $row['key'],
        'disabled' => $disabled,
        'active' => !$disabled && ((string) $row['key'] === $active),
      ];
      $reason = (string) ($row['disabled_reason'] ?? '');
      if ($reason !== '') {
        $tab['disabled_reason'] = $reason;
      }
      $tabs[] = $tab;
    }

    return $tabs;
  }

  /**
   * Workspace view-model tabs (TASK 8): Url objects + availability flags.
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceTabs(NodeInterface $event, string $active, AccountInterface $account): array {
    $out = [];
    foreach ($this->buildTabRows($event) as $row) {
      $key = (string) $row['key'];
      $disabled = (bool) ($row['disabled'] ?? FALSE);
      $url = NULL;
      if (!$disabled) {
        $url = $this->routeUrlIfAccessible((string) $row['route'], (array) $row['params'], $account);
      }
      $available = !$disabled && $url instanceof Url;
      $out[] = [
        'key' => $key,
        'label' => (string) $row['label'],
        'url' => $url,
        'active' => $available && ($key === $active),
        'available' => $available,
      ];
    }

    return $out;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildTabRows(NodeInterface $event): array {
    $id = (int) $event->id();
    $isRsvp = $this->eventModeManager->isRsvpEnabled($event);
    $isTickets = $this->eventModeManager->isTicketsEnabled($event);
    $t = $this->stringTranslation;

    // Convergence secondary nav: mirror Event Workspace section map.
    $rows = [
      [
        'key' => 'overview',
        'label' => (string) $t->translate('Overview'),
        'route' => 'myeventlane_event_studio.workspace',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'details',
        'label' => (string) $t->translate('Details'),
        'route' => 'myeventlane_event_studio.workspace_information',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'schedule',
        'label' => (string) $t->translate('Schedule'),
        'route' => 'myeventlane_event_studio.workspace_schedule',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'venue',
        'label' => (string) $t->translate('Venue'),
        'route' => 'myeventlane_event_studio.workspace_venue',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'images',
        'label' => (string) $t->translate('Images'),
        'route' => 'myeventlane_event_studio.workspace_branding',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'tickets',
        'label' => (string) $t->translate('Tickets'),
        'route' => 'myeventlane_event_studio.workspace_tickets',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'attendees',
        'label' => (string) $t->translate('Attendees'),
        'route' => 'myeventlane_event_studio.workspace_attendees',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'messages',
        'label' => (string) $t->translate('Messages'),
        'route' => 'myeventlane_event_studio.workspace_messaging',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'marketing',
        'label' => (string) $t->translate('Marketing'),
        'route' => 'myeventlane_event_studio.workspace_marketing',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'orders',
        'label' => (string) $t->translate('Orders'),
        'route' => 'myeventlane_event_studio.workspace_orders',
        'params' => ['node' => $id],
        'disabled' => !$isTickets,
        'disabled_reason' => (string) $t->translate('Turn on paid tickets for this event to use this section.'),
      ],
      [
        'key' => 'analytics',
        'label' => (string) $t->translate('Analytics'),
        'route' => 'myeventlane_event_studio.workspace_analytics',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'publishing',
        'label' => (string) $t->translate('Publishing'),
        'route' => 'myeventlane_event_studio.workspace_publishing',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'settings',
        'label' => (string) $t->translate('Settings'),
        'route' => 'myeventlane_event_studio.workspace_settings',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
    ];

    // Door Mode remains available as Attendees mode (not a competing product).
    $rows[] = [
      'key' => 'operations',
      'label' => (string) $t->translate('Door Mode'),
      'route' => 'myeventlane_event_attendees.vendor_operations',
      'params' => ['node' => $id],
      'disabled' => FALSE,
      'disabled_reason' => '',
    ];

    if ($this->routeExists('myeventlane_vendor.console.event_operational_addon_orders')) {
      $showAddons = $this->eventStateResolver->hasProductTarget($event)
        && $this->vendorOperationalAddonOrderBuilder->shouldSurfaceVendorAddonsTab($event);
      $rows[] = [
        'key' => 'addon_orders',
        'label' => (string) $t->translate('Add-on orders'),
        'route' => 'myeventlane_vendor.console.event_operational_addon_orders',
        'params' => ['event' => $id],
        'disabled' => !$showAddons,
        'disabled_reason' => (string) $t->translate('Add-on orders appear when this event has operational extras configured or purchased.'),
      ];
    }

    if ($isRsvp && $this->routeExists('myeventlane_vendor.console.event_rsvps')) {
      $rows[] = [
        'key' => 'rsvps',
        'label' => (string) $t->translate('RSVPs'),
        'route' => 'myeventlane_vendor.console.event_rsvps',
        'params' => ['event' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ];
    }

    // Legacy Manager pages still call getTabs(..., 'refund_requests'|'boost').
    // Keep these keys so refund/Boost screens retain an active tab and entry point.
    if ($this->moduleHandler->moduleExists('myeventlane_refunds')) {
      $rows[] = [
        'key' => 'refund_requests',
        'label' => (string) $t->translate('Refunds'),
        'route' => 'myeventlane_refunds.vendor_refund_requests',
        'params' => ['node' => $id],
        'disabled' => !$isTickets,
        'disabled_reason' => (string) $t->translate('Refunds apply to ticketed events.'),
      ];
    }

    if ($this->routeExists('myeventlane_boost.vendor_event_boost')) {
      $rows[] = [
        'key' => 'boost',
        'label' => (string) $t->translate('Boost'),
        'route' => 'myeventlane_boost.vendor_event_boost',
        'params' => ['event' => $id],
        'disabled' => !$isTickets,
        'disabled_reason' => (string) $t->translate('Turn on paid tickets for this event to use this section.'),
      ];
    }

    return $rows;
  }

  /**
   * Checks if paid tickets are enabled for the event.
   */
  public function isTicketsEnabled(NodeInterface $event): bool {
    return $this->eventModeManager->isTicketsEnabled($event);
  }

  /**
   * Checks if RSVP is enabled for the event.
   */
  public function isRsvpEnabled(NodeInterface $event): bool {
    return $this->eventModeManager->isRsvpEnabled($event);
  }

  private function routeExists(string $name): bool {
    try {
      $this->routeProvider->getRouteByName($name);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

  /**
   * @param array<string, mixed> $parameters
   */
  private function routeUrlIfAccessible(string $route_name, array $parameters, AccountInterface $account): ?Url {
    if (!$this->routeExists($route_name)) {
      return NULL;
    }
    try {
      if (!$this->accessManager->checkNamedRoute($route_name, $parameters, $account)) {
        return NULL;
      }
      return Url::fromRoute($route_name, $parameters);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
