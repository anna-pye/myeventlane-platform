<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event\Service\EventModeManager;
use Drupal\node\NodeInterface;

/**
 * Builds event console tabs for RSVP vs ticketed mode.
 *
 * All primary sections are always listed so the nav stays consistent; items
 * that do not apply to the current event mode are shown disabled.
 */
final class VendorEventTabsService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EventModeManager $eventModeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly TranslationInterface $stringTranslation,
  ) {}

  /**
   * Gets tabs for the event console.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $active
   *   The key of the currently active tab.
   *
   * @return array<int, array<string, mixed>>
   *   Tab definitions: label, url, key, active, disabled (optional).
   */
  public function getTabs(NodeInterface $event, string $active): array {
    $id = $event->id();
    $isRsvp = $this->eventModeManager->isRsvpEnabled($event);
    $isTickets = $this->eventModeManager->isTicketsEnabled($event);

    $t = $this->stringTranslation;

    // Fixed order: same structure on every event page.
    $all = [
      'overview' => [
        'label' => (string) $t->translate('Overview'),
        'url' => "/vendor/events/{$id}/overview",
        'key' => 'overview',
      ],
      'tickets' => [
        'label' => (string) $t->translate('Tickets'),
        'url' => "/vendor/events/{$id}/tickets",
        'key' => 'tickets',
      ],
      'orders' => [
        'label' => (string) $t->translate('Orders'),
        'url' => "/vendor/events/{$id}/orders",
        'key' => 'orders',
      ],
      'refund_requests' => [
        'label' => (string) $t->translate('Refund requests'),
        'url' => "/vendor/events/{$id}/refund-requests",
        'key' => 'refund_requests',
      ],
      'attendees' => [
        'label' => (string) $t->translate('Attendees'),
        'url' => "/vendor/events/{$id}/attendees",
        'key' => 'attendees',
      ],
      'rsvps' => [
        'label' => (string) $t->translate('RSVPs'),
        'url' => "/vendor/events/{$id}/rsvps",
        'key' => 'rsvps',
      ],
      'analytics' => [
        'label' => (string) $t->translate('Analytics'),
        'url' => "/vendor/events/{$id}/analytics",
        'key' => 'analytics',
      ],
      'boost' => [
        'label' => (string) $t->translate('Boost'),
        'url' => "/vendor/events/{$id}/boost",
        'key' => 'boost',
      ],
      'settings' => [
        'label' => (string) $t->translate('Settings'),
        'url' => "/vendor/events/{$id}/settings",
        'key' => 'settings',
      ],
    ];

    $tabs = [];
    foreach ($all as $key => $tab) {
      if ($key === 'refund_requests' && !$this->moduleHandler->moduleExists('myeventlane_refunds')) {
        continue;
      }

      $disabled = FALSE;
      $disabled_reason = '';
      if ($key === 'refund_requests' && !$isTickets) {
        $disabled = TRUE;
        $disabled_reason = (string) $t->translate('Refund requests apply to ticketed events.');
      }
      elseif (($key === 'orders' || $key === 'tickets' || $key === 'boost') && !$isTickets) {
        $disabled = TRUE;
        $disabled_reason = (string) $t->translate('Turn on paid tickets for this event to use this section.');
      }
      elseif ($key === 'rsvps' && !$isRsvp) {
        $disabled = TRUE;
        $disabled_reason = (string) $t->translate('RSVPs apply when this event has RSVP enabled.');
      }

      $tab['disabled'] = $disabled;
      if ($disabled_reason !== '') {
        $tab['disabled_reason'] = $disabled_reason;
      }
      $tab['active'] = !$disabled && ($key === $active);
      $tabs[] = $tab;
    }

    return $tabs;
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

}
