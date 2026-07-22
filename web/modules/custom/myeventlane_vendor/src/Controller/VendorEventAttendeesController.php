<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Extension\ModuleHandlerInterface;
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

  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly VendorEventTabsService $eventTabsService,
    private readonly VendorAttendeePresentationService $vendorPresentation,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ?GrowthTrackingService $growthTracking = NULL,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  public function attendees(NodeInterface $node): array {
    $this->assertEventOwnership($node);
    if ($this->growthTracking) {
      $this->growthTracking->recordConversionForPrefixes(
        (int) $this->currentUser->id(),
        (int) $node->id(),
        'attendee_review',
        ['retention_'],
        [],
      );
    }
    $tabs = $this->eventTabsService->getTabs($node, 'attendees');

    $attendees = $this->attendanceManager->getAttendeesForEvent((int) $node->id());
    $availability = $this->attendanceManager->getAvailability($node);

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

    $rows = [];
    $pairTotal = 0;
    foreach ($attendees as $attendee) {
      $vm = $this->vendorPresentation->buildVendorRowFromEventAttendee($attendee);
      $pairTotal += count($vm['custom_answers']);
      $orderLink = $this->buildOrderLinkForAttendee($attendee, $node);

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
    $this->vendorPresentation->logVendorParityBatch('vendor_attendees_list', (int) $node->id(), count($rows), $pairTotal);

    $public_event_url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString();

    $checkin_url = '#';
    try {
      $checkin_url = Url::fromRoute('myeventlane_event_attendees.vendor_operations_door', ['node' => $node->id()])->toString();
    }
    catch (\Throwable $e) {
      try {
        $checkin_url = Url::fromRoute('myeventlane_event_attendees.vendor_operations', ['node' => $node->id()])->toString();
      }
      catch (\Throwable $e2) {
        if ($this->moduleHandler->moduleExists('myeventlane_checkin')) {
          try {
            $checkin_url = Url::fromRoute('myeventlane_checkin.page', ['node' => $node->id()])->toString();
          }
          catch (\Throwable $e3) {
          }
        }
      }
    }

    return $this->buildVendorPage('mel_event_workspace', [
      'event' => $node,
      'tabs' => $tabs,
      'actions' => [
        [
          'label' => $this->t('Door Mode'),
          'url' => $checkin_url,
          'class' => 'mel-btn--primary',
        ],
        [
          'label' => $this->t('Export attendees'),
          'url' => Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $node->id()])->toString(),
          'class' => 'mel-btn--secondary',
        ],
      ],
      'meta' => NULL,
      'sidebar' => NULL,
      'content' => [
        '#theme' => 'myeventlane_vendor_event_attendees',
        '#event' => $node,
        '#attendees' => $rows,
        '#tabs' => $tabs,
        '#is_tickets_enabled' => $this->eventTabsService->isTicketsEnabled($node),
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
