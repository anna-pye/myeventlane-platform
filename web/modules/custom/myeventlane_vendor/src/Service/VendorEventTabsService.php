<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\myeventlane_event\Service\EventModeManager;
use Drupal\node\NodeInterface;

/**
 * Builds event console tabs filtered by RSVP vs ticketed mode.
 *
 * - RSVP-only: Overview, Attendees, RSVPs, Analytics, Settings.
 * - Ticketed (paid or both): plus Orders, Tickets, Boost.
 */
final class VendorEventTabsService {

  /**
   * Constructs the service.
   *
   * @param \Drupal\myeventlane_event\Service\EventModeManager $eventModeManager
   *   The event mode manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    private readonly EventModeManager $eventModeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Gets tabs for the event console, filtered by event mode.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $active
   *   The key of the currently active tab.
   *
   * @return array
   *   Tab definitions with keys: label, url, key, active.
   */
  public function getTabs(NodeInterface $event, string $active): array {
    $id = $event->id();
    $isRsvp = $this->eventModeManager->isRsvpEnabled($event);
    $isTickets = $this->eventModeManager->isTicketsEnabled($event);

    $all = [
      'overview' => ['label' => 'Overview', 'url' => "/vendor/events/{$id}/overview", 'key' => 'overview'],
      'orders' => ['label' => 'Orders', 'url' => "/vendor/events/{$id}/orders", 'key' => 'orders'],
      'refund_requests' => [
        'label' => 'Refund requests',
        'url' => "/vendor/events/{$id}/refund-requests",
        'key' => 'refund_requests',
      ],
      'tickets' => ['label' => 'Tickets', 'url' => "/vendor/events/{$id}/tickets", 'key' => 'tickets'],
      'attendees' => ['label' => 'Attendees', 'url' => "/vendor/events/{$id}/attendees", 'key' => 'attendees'],
      'rsvps' => ['label' => 'RSVPs', 'url' => "/vendor/events/{$id}/rsvps", 'key' => 'rsvps'],
      'analytics' => ['label' => 'Analytics', 'url' => "/vendor/events/{$id}/analytics", 'key' => 'analytics'],
      'boost' => ['label' => 'Boost', 'url' => "/event/{$id}/boost", 'key' => 'boost'],
      'settings' => ['label' => 'Settings', 'url' => "/vendor/events/{$id}/settings", 'key' => 'settings'],
    ];

    $tabs = [];
    foreach ($all as $key => $tab) {
      if ($key === 'refund_requests') {
        if (!$this->moduleHandler->moduleExists('myeventlane_refunds') || !$isTickets) {
          continue;
        }
      }
      if ($key === 'orders' || $key === 'tickets' || $key === 'boost') {
        if (!$isTickets) {
          continue;
        }
      }
      if ($key === 'rsvps') {
        if (!$isRsvp) {
          continue;
        }
      }
      $tab['active'] = ($key === $active);
      $tabs[] = $tab;
    }

    return $tabs;
  }

  /**
   * Checks if paid tickets are enabled for the event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if ticketed (paid or both).
   */
  public function isTicketsEnabled(NodeInterface $event): bool {
    return $this->eventModeManager->isTicketsEnabled($event);
  }

  /**
   * Checks if RSVP is enabled for the event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if RSVP (rsvp or both).
   */
  public function isRsvpEnabled(NodeInterface $event): bool {
    return $this->eventModeManager->isRsvpEnabled($event);
  }

}
