<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;

/**
 * Event attendees controller for vendor console.
 */
final class VendorEventAttendeesController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly VendorEventTabsService $eventTabsService,
    private readonly TicketLabelResolver $ticketLabelResolver,
  ) {
    parent::__construct($domain_detector, $current_user);
  }

  /**
   * Displays attendees for an event.
   */
  public function attendees(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    $tabs = $this->eventTabsService->getTabs($event, 'attendees');

    $attendees = $this->attendanceManager->getAttendeesForEvent((int) $event->id());
    $availability = $this->attendanceManager->getAvailability($event);

    // Group attendees by source.
    $grouped = [
      'ticket' => [],
      'rsvp' => [],
      'manual' => [],
    ];

    foreach ($attendees as $attendee) {
      $source = $attendee->getSource();
      $grouped[$source][] = $attendee;
    }

    // Build attendee rows for the table (one row per attendee, all info on one line).
    $rows = [];
    foreach ($attendees as $attendee) {
      $ticketType = $this->getTicketTypeForAttendee($attendee);
      $orderLink = $this->buildOrderLinkForAttendee($attendee, $event);
      $extraData = $attendee->hasField('extra_data') && !$attendee->get('extra_data')->isEmpty()
        ? (array) $attendee->get('extra_data')->value
        : [];
      $phone = $attendee->hasField('phone') && !$attendee->get('phone')->isEmpty()
        ? (string) $attendee->get('phone')->value
        : '';

      $rows[] = [
        'name' => $attendee->getName(),
        'email' => $attendee->getEmail(),
        'phone' => $phone,
        'source' => ucfirst($attendee->getSource()),
        'ticket_type' => $ticketType,
        'order_link' => $orderLink,
        'extra_data' => $extraData,
        'status' => ucfirst($attendee->getStatus()),
        'checked_in' => $attendee->isCheckedIn(),
        'ticket_code' => $attendee->getTicketCode() ?? '',
      ];
    }

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $event->label() . ' — Attendees',
      'tabs' => $tabs,
      'header_actions' => [
        [
          'label' => 'Export CSV',
          'url' => Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $event->id()])->toString(),
          'class' => 'mel-btn--secondary',
        ],
      ],
      'body' => [
        '#theme' => 'myeventlane_vendor_event_attendees',
        '#event' => $event,
        '#attendees' => $rows,
        '#tabs' => $tabs,
        '#is_tickets_enabled' => $this->eventTabsService->isTicketsEnabled($event),
        '#summary' => [
          'total' => count($attendees),
          'ticket' => count($grouped['ticket']),
          'rsvp' => count($grouped['rsvp']),
          'manual' => count($grouped['manual']),
          'capacity' => $availability['capacity'] > 0 ? $availability['capacity'] : 'Unlimited',
          'remaining' => $availability['remaining'],
        ],
      ],
    ]);
  }

  /**
   * Resolves ticket type (product variation label) for a ticket-source attendee.
   *
   * Uses the order item's purchased entity (variation) label. Excludes Boost.
   * Returns '—' for RSVP, manual, or when variation cannot be resolved.
   *
   * @param \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee
   *   The event attendee.
   *
   * @return string
   *   Product variation label (e.g. Full price, Concession) or '—'.
   */
  private function getTicketTypeForAttendee(EventAttendee $attendee): string {
    if ($attendee->getSource() !== 'ticket') {
      return '—';
    }
    if (!$attendee->hasField('order_item') || $attendee->get('order_item')->isEmpty()) {
      return '—';
    }
    $orderItem = $attendee->get('order_item')->entity;
    if (!$orderItem || (method_exists($orderItem, 'bundle') && $orderItem->bundle() === 'boost')) {
      return '—';
    }
    return $this->ticketLabelResolver->getTicketLabel($orderItem);
  }

  /**
   * Builds a link to the order for a ticket-source attendee.
   *
   * @param \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee
   *   The event attendee.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array{url: string, label: string}|null
   *   URL and label for the order link, or NULL if no order.
   */
  private function buildOrderLinkForAttendee(EventAttendee $attendee, NodeInterface $event): ?array {
    if ($attendee->getSource() !== 'ticket') {
      return NULL;
    }
    if (!$attendee->hasField('order_item') || $attendee->get('order_item')->isEmpty()) {
      return NULL;
    }
    $orderItem = $attendee->get('order_item')->entity;
    if (!$orderItem || (method_exists($orderItem, 'bundle') && $orderItem->bundle() === 'boost')) {
      return NULL;
    }
    try {
      $order = $orderItem->getOrder();
    }
    catch (\Throwable $e) {
      return NULL;
    }
    if (!$order) {
      return NULL;
    }
    $url = Url::fromRoute('myeventlane_vendor.console.event_order_view', [
      'event' => $event->id(),
      'order' => $order->id(),
    ]);
    $label = '#' . ($order->getOrderNumber() ?: $order->id());
    return ['url' => $url->toString(), 'label' => $label];
  }

}
