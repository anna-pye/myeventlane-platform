<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;

/**
 * Event tickets controller.
 *
 * Displays real ticket sales data from Commerce.
 */
final class VendorEventTicketsController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    private readonly TicketSalesService $ticketSalesService,
    private readonly VendorEventTabsService $eventTabsService,
  ) {
    parent::__construct($domain_detector, $current_user);
  }

  /**
   * Displays tickets configuration for an event.
   */
  public function tickets(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    $this->assertStripeConnected();
    $tabs = $this->eventTabsService->getTabs($event, 'tickets');
    $sales = $this->ticketSalesService->getSalesSummary($event);
    $tickets = $this->ticketSalesService->getTicketBreakdown($event);

    // Get edit URL for product.
    $editProductUrl = NULL;
    if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
      $product = $event->get('field_product_target')->entity;
      if ($product) {
        $editProductUrl = '/admin/commerce/products/' . $product->id() . '/edit';
      }
    }

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $event->label() . ' — Tickets',
      'tabs' => $tabs,
      'header_actions' => $editProductUrl ? [
        [
          'label' => 'Manage Tickets',
          'url' => $editProductUrl,
          'class' => 'mel-btn--secondary',
        ],
      ] : [],
      'body' => [
        '#theme' => 'myeventlane_vendor_event_tickets',
        '#event' => $event,
        '#sales' => $sales,
        '#tickets' => $tickets,
      ],
    ]);
  }

}
