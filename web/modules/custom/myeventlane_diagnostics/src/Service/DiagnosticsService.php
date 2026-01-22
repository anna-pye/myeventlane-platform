<?php

declare(strict_types=1);

namespace Drupal\myeventlane_diagnostics\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\myeventlane_metrics\Service\EventMetricsServiceInterface;
use Drupal\myeventlane_event\Service\EventModeManager;
use Drupal\node\NodeInterface;

/**
 * Service for analyzing event configuration and providing diagnostic feedback.
 */
final class DiagnosticsService implements DiagnosticsServiceInterface {

  use StringTranslationTrait;

  /**
   * Constructs DiagnosticsService.
   */
  public function __construct(
    private readonly EventStateResolverInterface $stateResolver,
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly EventMetricsServiceInterface $metricsService,
    private readonly EventModeManager $modeManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function analyzeEvent(NodeInterface $event, ?string $scope = NULL): array {
    $sections = [];

    // Collect all diagnostic sections.
    if (!$scope || $scope === 'basics') {
      $sections['basics'] = $this->checkBasics($event);
    }
    if (!$scope || $scope === 'sales_state') {
      $sections['sales_state'] = $this->checkSalesAndState($event);
    }
    if (!$scope || $scope === 'tickets_rsvp') {
      $sections['tickets_rsvp'] = $this->checkTicketsRsvp($event);
    }
    if (!$scope || $scope === 'capacity') {
      $sections['capacity'] = $this->checkCapacity($event);
    }
    if (!$scope || $scope === 'visibility') {
      $sections['visibility'] = $this->checkVisibility($event);
    }
    if (!$scope || $scope === 'automation') {
      $sections['automation'] = $this->checkAutomation($event);
    }
    if (!$scope || $scope === 'checkin') {
      $sections['checkin'] = $this->checkCheckin($event);
    }
    if (!$scope || $scope === 'exports') {
      $sections['exports'] = $this->checkExports($event);
    }

    // Calculate summary.
    $summary = $this->calculateSummary($sections);

    return [
      'summary' => $summary,
      'sections' => $sections,
    ];
  }

  /**
   * Checks basic event information.
   */
  private function checkBasics(NodeInterface $event): array {
    $items = [];

    // Title check.
    $title = $event->getTitle();
    if (empty($title) || strlen($title) < 3) {
      $items[] = [
        'key' => 'event_title',
        'status' => 'fail',
        'label' => $this->t('Event title'),
        'message' => $this->t('Please add a title for your event.'),
        'fix_route' => 'entity.node.edit_form',
        'fix_params' => ['node' => $event->id()],
        'fix_fragment' => 'title',
      ];
    }
    else {
      $items[] = [
        'key' => 'event_title',
        'status' => 'ok',
        'label' => $this->t('Event title'),
        'message' => $this->t('Title is set: @title', ['@title' => $title]),
      ];
    }

    // Event dates check.
    $startField = $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()
      ? $event->get('field_event_start')->date
      : NULL;
    $endField = $event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()
      ? $event->get('field_event_end')->date
      : NULL;

    if (!$startField) {
      $items[] = [
        'key' => 'event_dates',
        'status' => 'fail',
        'label' => $this->t('Event dates'),
        'message' => $this->t('Please set when your event starts.'),
        'fix_route' => 'entity.node.edit_form',
        'fix_params' => ['node' => $event->id()],
        'fix_fragment' => 'dates',
      ];
    }
    elseif ($endField && $endField->getTimestamp() <= $startField->getTimestamp()) {
      $items[] = [
        'key' => 'event_dates',
        'status' => 'fail',
        'label' => $this->t('Event dates'),
        'message' => $this->t('End date must be after start date.'),
        'fix_route' => 'entity.node.edit_form',
        'fix_params' => ['node' => $event->id()],
        'fix_fragment' => 'dates',
      ];
    }
    else {
      $items[] = [
        'key' => 'event_dates',
        'status' => 'ok',
        'label' => $this->t('Event dates'),
        'message' => $this->t('Event dates are set correctly.'),
      ];
    }

    // Venue or online check.
    $hasVenue = FALSE;
    $hasOnline = FALSE;
    if ($event->hasField('field_event_location_mode')) {
      $mode = $event->get('field_event_location_mode')->value ?? NULL;
      if ($mode === 'venue') {
        $hasVenue = $event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty();
        if (!$hasVenue) {
          $hasVenue = $event->hasField('field_location') && !$event->get('field_location')->isEmpty();
        }
      }
      elseif ($mode === 'online') {
        $hasOnline = $event->hasField('field_event_online_url') && !$event->get('field_event_online_url')->isEmpty();
      }
    }

    if (!$hasVenue && !$hasOnline) {
      $items[] = [
        'key' => 'event_location',
        'status' => 'warn',
        'label' => $this->t('Event location'),
        'message' => $this->t('Please specify whether this is a venue or online event.'),
        'fix_route' => 'entity.node.edit_form',
        'fix_params' => ['node' => $event->id()],
        'fix_fragment' => 'location',
      ];
    }
    else {
      $items[] = [
        'key' => 'event_location',
        'status' => 'ok',
        'label' => $this->t('Event location'),
        'message' => $hasVenue ? $this->t('Venue location is set.') : $this->t('Online event URL is set.'),
      ];
    }

    // Organisation (vendor) check - system-managed field.
    $hasOrganisation = $event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty();
    if (!$hasOrganisation) {
      $items[] = [
        'key' => 'event_organisation',
        'status' => 'fail',
        'label' => $this->t('Organisation'),
        'message' => $this->t('This event must be assigned to an organisation. Contact support if this is missing.'),
        'fix_route' => 'entity.node.edit_form',
        'fix_params' => ['node' => $event->id()],
        'fix_fragment' => NULL,
      ];
    }
    else {
      // Check if vendor matches current user (warn if mismatch for non-admins).
      $vendor = $event->get('field_event_vendor')->entity;
      $currentUser = $this->currentUser->getAccount();
      $isAdmin = $currentUser->hasPermission('administer nodes');

      if ($vendor && !$isAdmin) {
        // Check if current user owns this vendor.
        $vendorOwner = $vendor->getOwner();
        $userOwnsVendor = $vendorOwner && $vendorOwner->id() === $currentUser->id();

        if (!$userOwnsVendor) {
          $items[] = [
            'key' => 'event_organisation',
            'status' => 'warn',
            'label' => $this->t('Organisation'),
            'message' => $this->t('This event is assigned to a different organisation. Contact support if this is incorrect.'),
            'fix_route' => NULL,
            'fix_params' => NULL,
            'fix_fragment' => NULL,
          ];
        }
        else {
          $items[] = [
            'key' => 'event_organisation',
            'status' => 'ok',
            'label' => $this->t('Organisation'),
            'message' => $this->t('Organisation is correctly assigned.'),
          ];
        }
      }
      else {
        $items[] = [
          'key' => 'event_organisation',
          'status' => 'ok',
          'label' => $this->t('Organisation'),
          'message' => $this->t('Organisation is assigned.'),
        ];
      }
    }

    return $items;
  }

  /**
   * Checks sales windows and effective state.
   */
  private function checkSalesAndState(NodeInterface $event): array {
    $items = [];

    $state = $this->stateResolver->resolveState($event);
    $salesStart = $this->stateResolver->getSalesStart($event);
    $salesEnd = $this->stateResolver->getSalesEnd($event);
    $now = $this->time->getRequestTime();

    // Explain effective state.
    $stateMessages = [
      'draft' => $this->t('This event is not yet published.'),
      'scheduled' => $this->t('Ticket sales will open when the sales window starts.'),
      'live' => $this->t('This event is currently accepting bookings.'),
      'sold_out' => $this->t('This event is sold out.'),
      'ended' => $this->t('This event has ended.'),
      'cancelled' => $this->t('This event has been cancelled.'),
      'archived' => $this->t('This event is archived.'),
    ];

    $stateStatus = in_array($state, ['live'], TRUE) ? 'ok' : (in_array($state, ['draft', 'scheduled'], TRUE) ? 'warn' : 'fail');
    $items[] = [
      'key' => 'effective_state',
      'status' => $stateStatus,
      'label' => $this->t('Event status'),
      'message' => $stateMessages[$state] ?? $this->t('Status: @state', ['@state' => $state]),
    ];

    // Sales window validity.
    if ($salesStart && $salesEnd && $salesStart > $salesEnd) {
      $items[] = [
        'key' => 'sales_window',
        'status' => 'fail',
        'label' => $this->t('Sales window'),
        'message' => $this->t('Sales start date must be before sales end date.'),
        'fix_route' => 'entity.node.edit_form',
        'fix_params' => ['node' => $event->id()],
        'fix_fragment' => 'sales',
      ];
    }
    elseif ($salesStart && $now < $salesStart) {
      $items[] = [
        'key' => 'sales_window',
        'status' => 'warn',
        'label' => $this->t('Sales window'),
        'message' => $this->t('Ticket sales are scheduled to start in the future.'),
      ];
    }
    elseif ($salesEnd && $now > $salesEnd) {
      $items[] = [
        'key' => 'sales_window',
        'status' => 'warn',
        'label' => $this->t('Sales window'),
        'message' => $this->t('Sales window has ended.'),
      ];
    }
    else {
      $items[] = [
        'key' => 'sales_window',
        'status' => 'ok',
        'label' => $this->t('Sales window'),
        'message' => $salesStart ? $this->t('Sales window is configured.') : $this->t('Ticket sales will open as soon as you publish.'),
      ];
    }

    return $items;
  }

  /**
   * Checks tickets and RSVP configuration.
   */
  private function checkTicketsRsvp(NodeInterface $event): array {
    $items = [];
    $mode = $this->modeManager->getEffectiveMode($event);

    if ($mode === EventModeManager::MODE_NONE) {
      $items[] = [
        'key' => 'booking_mode',
        'status' => 'fail',
        'label' => $this->t('Booking type'),
        'message' => $this->t('Please select how people can attend (RSVP, Tickets, or External link).'),
        'fix_route' => 'entity.node.edit_form',
        'fix_params' => ['node' => $event->id()],
        'fix_fragment' => 'tickets',
      ];
      return $items;
    }

    // RSVP check.
    if (in_array($mode, [EventModeManager::MODE_RSVP, EventModeManager::MODE_BOTH], TRUE)) {
      $items[] = [
        'key' => 'rsvp_enabled',
        'status' => 'ok',
        'label' => $this->t('RSVP'),
        'message' => $this->t('RSVP is enabled.'),
      ];
    }

    // Paid tickets check.
    if (in_array($mode, [EventModeManager::MODE_PAID, EventModeManager::MODE_BOTH], TRUE)) {
      $hasProduct = $event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty();
      if (!$hasProduct) {
        $items[] = [
          'key' => 'tickets_product',
          'status' => 'fail',
          'label' => $this->t('Paid tickets'),
          'message' => $this->t('Please link a ticket product for paid events.'),
          'fix_route' => 'entity.node.edit_form',
          'fix_params' => ['node' => $event->id()],
          'fix_fragment' => 'tickets',
        ];
      }
      else {
        $product = $event->get('field_product_target')->entity;
        if ($product && $product->isPublished()) {
          $items[] = [
            'key' => 'tickets_product',
            'status' => 'ok',
            'label' => $this->t('Paid tickets'),
            'message' => $this->t('Ticket product is linked and published.'),
          ];
        }
        else {
          $items[] = [
            'key' => 'tickets_product',
            'status' => 'warn',
            'label' => $this->t('Paid tickets'),
            'message' => $this->t('Ticket product is linked but not published.'),
            'fix_route' => 'entity.node.edit_form',
            'fix_params' => ['node' => $event->id()],
            'fix_fragment' => 'tickets',
          ];
        }
      }
    }

    // External link check.
    if ($mode === EventModeManager::MODE_EXTERNAL) {
      $hasExternalUrl = $event->hasField('field_external_url') && !$event->get('field_external_url')->isEmpty();
      if (!$hasExternalUrl) {
        $items[] = [
          'key' => 'external_url',
          'status' => 'fail',
          'label' => $this->t('External booking link'),
          'message' => $this->t('Please provide the external booking URL.'),
          'fix_route' => 'entity.node.edit_form',
          'fix_params' => ['node' => $event->id()],
          'fix_fragment' => 'tickets',
        ];
      }
      else {
        $url = $event->get('field_external_url')->uri;
        if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
          $items[] = [
            'key' => 'external_url',
            'status' => 'ok',
            'label' => $this->t('External booking link'),
            'message' => $this->t('External booking URL is set.'),
          ];
        }
        else {
          $items[] = [
            'key' => 'external_url',
            'status' => 'fail',
            'label' => $this->t('External booking link'),
            'message' => $this->t('Please provide a valid URL.'),
            'fix_route' => 'entity.node.edit_form',
            'fix_params' => ['node' => $event->id()],
            'fix_fragment' => 'tickets',
          ];
        }
      }
    }

    return $items;
  }

  /**
   * Checks capacity configuration.
   */
  private function checkCapacity(NodeInterface $event): array {
    $items = [];

    $capacity = $this->metricsService->getCapacityTotal($event);
    $availability = $this->attendanceManager->getAvailability($event);

    if ($capacity === NULL) {
      $items[] = [
        'key' => 'capacity_set',
        'status' => 'ok',
        'label' => $this->t('Capacity'),
        'message' => $this->t('Unlimited capacity is set.'),
      ];
    }
    elseif ($capacity > 0) {
      $items[] = [
        'key' => 'capacity_set',
        'status' => 'ok',
        'label' => $this->t('Capacity'),
        'message' => $this->t('Capacity is set to @capacity attendees.', ['@capacity' => $capacity]),
      ];
    }
    else {
      $items[] = [
        'key' => 'capacity_set',
        'status' => 'warn',
        'label' => $this->t('Capacity'),
        'message' => $this->t('Capacity is not set. Consider setting a limit for better planning.'),
        'fix_route' => 'entity.node.edit_form',
        'fix_params' => ['node' => $event->id()],
        'fix_fragment' => 'capacity',
      ];
    }

    // Remaining capacity.
    if ($capacity !== NULL && $capacity > 0) {
      $remaining = $this->metricsService->getRemainingCapacity($event);
      if ($remaining !== NULL && $remaining <= 0) {
        $items[] = [
          'key' => 'remaining_capacity',
          'status' => 'warn',
          'label' => $this->t('Remaining capacity'),
          'message' => $this->t('This event is at capacity.'),
        ];
      }
      elseif ($remaining !== NULL) {
        $items[] = [
          'key' => 'remaining_capacity',
          'status' => 'ok',
          'label' => $this->t('Remaining capacity'),
          'message' => $this->t('@count spots remaining.', ['@count' => $remaining]),
        ];
      }
    }

    // Waitlist configuration.
    $waitlistCapacity = 0;
    if ($event->hasField('field_waitlist_capacity') && !$event->get('field_waitlist_capacity')->isEmpty()) {
      $waitlistCapacity = (int) $event->get('field_waitlist_capacity')->value;
    }

    if ($capacity !== NULL && $capacity > 0 && $waitlistCapacity > 0) {
      $items[] = [
        'key' => 'waitlist',
        'status' => 'ok',
        'label' => $this->t('Waitlist'),
        'message' => $this->t('Waitlist capacity is set to @count.', ['@count' => $waitlistCapacity]),
      ];
    }
    elseif ($capacity !== NULL && $capacity > 0) {
      $items[] = [
        'key' => 'waitlist',
        'status' => 'warn',
        'label' => $this->t('Waitlist'),
        'message' => $this->t('Consider enabling waitlist for sold-out events.'),
        'fix_route' => 'entity.node.edit_form',
        'fix_params' => ['node' => $event->id()],
        'fix_fragment' => 'capacity',
      ];
    }

    return $items;
  }

  /**
   * Checks visibility settings.
   */
  private function checkVisibility(NodeInterface $event): array {
    $items = [];

    $isPublished = $event->isPublished();
    if (!$isPublished) {
      $items[] = [
        'key' => 'visibility',
        'status' => 'warn',
        'label' => $this->t('Visibility'),
        'message' => $this->t('This event is not published. It will not appear in listings.'),
      ];
    }
    else {
      $items[] = [
        'key' => 'visibility',
        'status' => 'ok',
        'label' => $this->t('Visibility'),
        'message' => $this->t('This event is published and visible.'),
      ];
    }

    // Check for promotion/boost if applicable.
    if ($event->hasField('field_promoted') && $event->get('field_promoted')->value) {
      $items[] = [
        'key' => 'promotion',
        'status' => 'ok',
        'label' => $this->t('Promotion'),
        'message' => $this->t('This event is promoted in listings.'),
      ];
    }

    return $items;
  }

  /**
   * Checks automation settings (reminders, etc.).
   */
  private function checkAutomation(NodeInterface $event): array {
    $items = [];

    // Check if event has start date for reminder eligibility.
    $hasStartDate = $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty();
    if ($hasStartDate) {
      $items[] = [
        'key' => 'reminders',
        'status' => 'ok',
        'label' => $this->t('Event reminders'),
        'message' => $this->t('Reminders will be sent automatically 24 hours before the event.'),
      ];
    }
    else {
      $items[] = [
        'key' => 'reminders',
        'status' => 'warn',
        'label' => $this->t('Event reminders'),
        'message' => $this->t('Event start date is required for automatic reminders.'),
      ];
    }

    return $items;
  }

  /**
   * Checks check-in configuration.
   */
  private function checkCheckin(NodeInterface $event): array {
    $items = [];

    $attendeeCount = $this->metricsService->getAttendeeCount($event);
    if ($attendeeCount > 0) {
      $checkinRoute = Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $event->id()]);
      if ($checkinRoute->access($this->currentUser)) {
        $items[] = [
          'key' => 'checkin_route',
          'status' => 'ok',
          'label' => $this->t('Check-in access'),
          'message' => $this->t('Check-in page is accessible. @count attendee(s) registered.', ['@count' => $attendeeCount]),
          'fix_route' => 'myeventlane_event_attendees.vendor_list',
          'fix_params' => ['node' => $event->id()],
        ];
      }
      else {
        $items[] = [
          'key' => 'checkin_route',
          'status' => 'warn',
          'label' => $this->t('Check-in access'),
          'message' => $this->t('You do not have permission to access the check-in page.'),
        ];
      }
    }
    else {
      $items[] = [
        'key' => 'checkin_route',
        'status' => 'ok',
        'label' => $this->t('Check-in'),
        'message' => $this->t('Check-in will be available once attendees register.'),
      ];
    }

    return $items;
  }

  /**
   * Checks export permissions and availability.
   */
  private function checkExports(NodeInterface $event): array {
    $items = [];

    $exportRoute = Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $event->id()]);
    if ($exportRoute->access($this->currentUser)) {
      $items[] = [
        'key' => 'export_access',
        'status' => 'ok',
        'label' => $this->t('Export permissions'),
        'message' => $this->t('You can export attendee lists as CSV.'),
        'fix_route' => 'myeventlane_event_attendees.vendor_export',
        'fix_params' => ['node' => $event->id()],
      ];
    }
    else {
      $items[] = [
        'key' => 'export_access',
        'status' => 'warn',
        'label' => $this->t('Export permissions'),
        'message' => $this->t('You do not have permission to export attendee data.'),
      ];
    }

    return $items;
  }

  /**
   * Calculates summary statistics from sections.
   */
  private function calculateSummary(array $sections): array {
    $totalIssues = 0;
    $hasFailures = FALSE;
    $hasWarnings = FALSE;

    foreach ($sections as $sectionItems) {
      foreach ($sectionItems as $item) {
        if ($item['status'] === 'fail') {
          $hasFailures = TRUE;
          $totalIssues++;
        }
        elseif ($item['status'] === 'warn') {
          $hasWarnings = TRUE;
          $totalIssues++;
        }
      }
    }

    $status = 'ok';
    if ($hasFailures) {
      $status = 'fail';
    }
    elseif ($hasWarnings) {
      $status = 'warn';
    }

    return [
      'status' => $status,
      'issue_count' => $totalIssues,
    ];
  }

}
