<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\node\NodeInterface;

/**
 * Base controller for Tickets workspace pages in vendor console.
 *
 * Provides common functionality for all ticket management pages:
 * - Event ownership assertion
 * - Tickets sub-navigation
 * - Vendor console page rendering.
 */
abstract class VendorEventTicketsBaseController extends VendorConsoleBaseController {

  /**
   * Builds a Tickets workspace page with sub-navigation.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param array $body
   *   The main content render array.
   * @param string $title
   *   The page title (will be prefixed with event label).
   * @param string $active_key
   *   The key of the currently active section in tickets navigation.
   * @param array|null $header_actions
   *   Optional array of header action buttons.
   *
   * @return array
   *   The render array for the vendor console page.
   */
  protected function buildTicketsPage(
    NodeInterface $event,
    array $body,
    string $title,
    string $active_key,
    ?array $header_actions = NULL,
  ): array {
    // Assert event ownership.
    $this->assertEventOwnership($event);

    // Build tickets sub-navigation.
    $tabs = $this->buildTicketsNavigation($event, $active_key);

    // Build full page title.
    $full_title = $event->label() . ' — ' . $title;

    // Build page variables.
    $page_vars = [
      'title' => $full_title,
      'tabs' => $tabs,
      'body' => $body,
    ];

    // Add header actions if provided.
    if ($header_actions !== NULL) {
      $page_vars['header_actions'] = $header_actions;
    }

    // Render via vendor console.
    return $this->buildVendorPage('myeventlane_vendor_console_page', $page_vars);
  }

  /**
   * Builds the Tickets sub-navigation tabs.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $active_key
   *   The key of the currently active section.
   *
   * @return array
   *   Array of tab definitions with 'label', 'url', and 'active' keys.
   */
  protected function buildTicketsNavigation(NodeInterface $event, string $active_key): array {
    $event_id = $event->id();

    $tabs = [
      [
        'label' => $this->t('Overview'),
        'url' => Url::fromRoute('myeventlane_tickets.event_tickets_overview', ['event' => $event_id])->toString(),
        'key' => 'overview',
      ],
      [
        'label' => $this->t('Ticket types'),
        'url' => Url::fromRoute('myeventlane_tickets.event_tickets_types', ['event' => $event_id])->toString(),
        'key' => 'types',
      ],
      [
        'label' => $this->t('Groups'),
        'url' => Url::fromRoute('myeventlane_tickets.event_tickets_groups', ['event' => $event_id])->toString(),
        'key' => 'groups',
      ],
      [
        'label' => $this->t('Access codes'),
        'url' => Url::fromRoute('myeventlane_tickets.event_tickets_access_codes', ['event' => $event_id])->toString(),
        'key' => 'access_codes',
      ],
      [
        'label' => $this->t('Settings'),
        'url' => Url::fromRoute('myeventlane_tickets.event_tickets_settings', ['event' => $event_id])->toString(),
        'key' => 'settings',
      ],
      [
        'label' => $this->t('Widgets'),
        'url' => Url::fromRoute('myeventlane_tickets.event_tickets_widgets', ['event' => $event_id])->toString(),
        'key' => 'widgets',
      ],
    ];

    // Mark active tab.
    foreach ($tabs as &$tab) {
      $tab['active'] = ($tab['key'] === $active_key);
    }

    return $tabs;
  }

}
