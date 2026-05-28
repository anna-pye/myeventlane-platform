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
 * Builds event console tabs for RSVP vs ticketed mode.
 *
 * Primary sections stay consistent; items that do not apply are shown disabled.
 * URLs are generated from canonical routes (TASK 8).
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

    $rows = [
      [
        'key' => 'overview',
        'label' => (string) $t->translate('Manage event'),
        'route' => 'myeventlane_vendor.console.event_workspace',
        'params' => ['event' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'tickets',
        'label' => (string) $t->translate('Advanced ticket manager'),
        'route' => 'myeventlane_vendor.console.event_tickets',
        'params' => ['event' => $id],
        'disabled' => !$isTickets,
        'disabled_reason' => (string) $t->translate('Turn on paid tickets for this event to use this section.'),
      ],
      [
        'key' => 'rsvps',
        'label' => (string) $t->translate('RSVPs'),
        'route' => 'myeventlane_vendor.console.event_rsvps',
        'params' => ['event' => $id],
        'disabled' => !$isRsvp,
        'disabled_reason' => (string) $t->translate('RSVPs apply when this event has RSVP enabled.'),
      ],
      [
        'key' => 'attendees',
        'label' => (string) $t->translate('Attendees'),
        'route' => 'myeventlane_event_attendees.vendor_list',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'operations',
        'label' => (string) $t->translate('Live operations'),
        'route' => 'myeventlane_event_attendees.vendor_operations',
        'params' => ['node' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
      ],
      [
        'key' => 'orders',
        'label' => (string) $t->translate('Orders'),
        'route' => 'myeventlane_vendor.console.event_orders',
        'params' => ['event' => $id],
        'disabled' => !$isTickets,
        'disabled_reason' => (string) $t->translate('Turn on paid tickets for this event to use this section.'),
      ],
    ];

    if ($this->routeExists('myeventlane_vendor.console.event_operational_addon_orders')) {
      // Match workspace paid/both gate: linked ticket product, not MODE_PAID alone
      // (RSVP events with a non-hybrid product still surface operational add-ons).
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

    if ($this->moduleHandler->moduleExists('myeventlane_refunds')) {
      $rows[] = [
        'key' => 'refund_requests',
        'label' => (string) $t->translate('Refund requests'),
        'route' => 'myeventlane_refunds.vendor_refund_requests',
        'params' => ['node' => $id],
        'disabled' => !$isTickets,
        'disabled_reason' => (string) $t->translate('Refund requests apply to ticketed events.'),
      ];
    }

    $rows[] = [
      'key' => 'analytics',
      'label' => (string) $t->translate('Analytics'),
      'route' => 'myeventlane_vendor.console.event_analytics',
      'params' => ['event' => $id],
      'disabled' => FALSE,
      'disabled_reason' => '',
    ];

    if ($this->routeExists('myeventlane_vendor.console.event_promotion')) {
      $rows[] = [
        'key' => 'promotion',
        'label' => (string) $t->translate('Promotion'),
        'route' => 'myeventlane_vendor.console.event_promotion',
        'params' => ['event' => $id],
        'disabled' => FALSE,
        'disabled_reason' => '',
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

    $rows[] = [
      'key' => 'settings',
      'label' => (string) $t->translate('Settings'),
      'route' => 'myeventlane_vendor.console.event_settings',
      'params' => ['event' => $id],
      'disabled' => FALSE,
      'disabled_reason' => '',
    ];

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
  private function routeUrlIfAccessible(string $routeName, array $parameters, AccountInterface $account): ?Url {
    if (!$account->isAuthenticated()) {
      return NULL;
    }
    if (!$this->routeExists($routeName)) {
      return NULL;
    }
    try {
      $access = $this->accessManager->checkNamedRoute($routeName, $parameters, $account, TRUE);
      if (!$access->isAllowed()) {
        return NULL;
      }
    }
    catch (\Throwable) {
      return NULL;
    }

    try {
      return Url::fromRoute($routeName, $parameters);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
