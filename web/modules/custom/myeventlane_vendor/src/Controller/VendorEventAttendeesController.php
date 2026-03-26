<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\myeventlane_event_attendees\Service\VendorAttendeePresentationService;
use Drupal\myeventlane_growth\Service\GrowthTrackingService;
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
    MessengerInterface $messenger,
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly VendorEventTabsService $eventTabsService,
    private readonly VendorAttendeePresentationService $vendorPresentation,
    private readonly ?GrowthTrackingService $growthTracking = NULL,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * Displays attendees for an event.
   */
  public function attendees(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    if ($this->growthTracking) {
      $this->growthTracking->recordConversionForPrefixes(
        (int) $this->currentUser->id(),
        (int) $event->id(),
        'attendee_review',
        ['retention_'],
        [],
      );
    }
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

    $checked_in_count = 0;
    foreach ($attendees as $attendee) {
      if ($attendee->isCheckedIn()) {
        $checked_in_count++;
      }
    }

    // Build attendee rows for the table (one row per attendee, all info on one line).
    $rows = [];
    $pairTotal = 0;
    foreach ($attendees as $attendee) {
      $vm = $this->vendorPresentation->buildVendorRowFromEventAttendee($attendee);
      $pairTotal += count($vm['custom_answers']);
      $orderLink = $this->buildOrderLinkForAttendee($attendee, $event);

      $rows[] = [
        'name' => $vm['full_name'],
        'email' => $vm['email'],
        'phone' => $vm['phone'],
        'source' => ucfirst($vm['source']),
        'ticket_type' => $vm['ticket_type'],
        'order_link' => $orderLink,
        'custom_answers' => $vm['custom_answers'],
        'custom_answers_display' => $vm['custom_answers_display'],
        'extra_data' => $attendee->getExtraDataMap(),
        'status' => ucfirst($attendee->getStatus()),
        'checked_in' => $attendee->isCheckedIn(),
        'ticket_code' => $vm['ticket_code'],
      ];
    }
    $this->vendorPresentation->logVendorParityBatch('vendor_attendees_list', (int) $event->id(), count($rows), $pairTotal);

    $public_event_url = Url::fromRoute('entity.node.canonical', ['node' => $event->id()])->toString();

    return $this->buildVendorPage('mel_event_workspace', [
      'event' => $event,
      'tabs' => $tabs,
      'actions' => [
        [
          'label' => (string) $this->t('Check-in'),
          'url' => Url::fromRoute('myeventlane_rsvp.checkin_list', ['event' => $event->id()])->toString(),
          'class' => 'mel-btn--primary',
        ],
        [
          'label' => (string) $this->t('Export CSV'),
          'url' => Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $event->id()])->toString(),
          'class' => 'mel-btn--secondary',
        ],
      ],
      'meta' => NULL,
      'sidebar' => NULL,
      'content' => [
        '#theme' => 'myeventlane_vendor_event_attendees',
        '#event' => $event,
        '#attendees' => $rows,
        '#tabs' => $tabs,
        '#is_tickets_enabled' => $this->eventTabsService->isTicketsEnabled($event),
        '#public_event_url' => $public_event_url,
        '#summary' => [
          'total' => count($attendees),
          'checked_in' => $checked_in_count,
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
