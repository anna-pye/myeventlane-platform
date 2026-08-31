<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;

/**
 * Base controller for Tickets workspace pages in vendor console.
 *
 * Provides common functionality for all ticket management pages:
 * - Event ownership assertion
 * - Primary event tabs + consistent tickets sub-navigation
 * - MEL event workspace rendering.
 */
abstract class VendorEventTicketsBaseController extends VendorConsoleBaseController {

  /**
   * Constructs the base controller.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    protected readonly VendorEventTabsService $eventTabsService,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger);
  }

  /**
   * Builds a Tickets workspace page with event shell + ticket tools navigation.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param array $body
   *   The main content render array.
   * @param string $active_key
   *   The key of the currently active section in tickets navigation.
   * @param array|null $header_actions
   *   Optional array of header action buttons.
   *
   * @return array
   *   The render array for the event workspace.
   */
  protected function buildTicketsPage(
    NodeInterface $event,
    array $body,
    string $active_key,
    ?array $header_actions = NULL,
  ): array {
    $this->assertEventOwnership($event);

    $event_tabs = $this->eventTabsService->getTabs($event, 'tickets');
    $ticket_tabs = $this->buildTicketsNavigation($event, $active_key);

    $ticket_tools = [
      '#theme' => 'mel_workspace_ticket_sidebar',
      '#ticket_tabs' => $ticket_tabs,
      '#cache' => [
        'contexts' => $event->getCacheContexts(),
        'tags' => $event->getCacheTags(),
      ],
    ];

    $page_vars = [
      'event' => $event,
      'tabs' => $event_tabs,
      'content' => $body,
      'actions' => $header_actions ?? [],
      'meta' => NULL,
      'sidebar' => NULL,
      'ticket_tools' => $ticket_tools,
    ];

    return $this->buildVendorPage('mel_event_workspace', $page_vars);
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
        'url' => Url::fromRoute('myeventlane_vendor.console.event_tickets', ['event' => $event_id])->toString(),
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

    foreach ($tabs as &$tab) {
      $tab['active'] = ($tab['key'] === $active_key);
    }
    unset($tab);

    return $tabs;
  }

}
