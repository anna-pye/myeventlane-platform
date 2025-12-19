<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\TimeInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the customer dashboard (My Events).
 */
final class CustomerDashboardController extends ControllerBase {

  /**
   * Constructs CustomerDashboardController.
   */
  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('datetime.time')
    );
  }

  /**
   * Renders the customer "My Events" page.
   *
   * @return array
   *   A render array for the customer dashboard.
   */
  public function myEvents(): array {
    $currentUser = $this->currentUser();
    $userEmail = $currentUser->getEmail();
    $userId = (int) $currentUser->id();

    // Load all attendee records for this user (by purchaser account).
    $attendeeStorage = $this->entityTypeManager()->getStorage('event_attendee');
    $query = $attendeeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', EventAttendee::STATUS_CONFIRMED);

    // If user is logged in, match by uid (purchaser account).
    // For anonymous flows, fall back to purchaser email.
    if ($userId > 0) {
      $query->condition('uid', $userId);
    }
    elseif ($userEmail) {
      $query->condition('email', $userEmail);
    }
    else {
      // No way to identify user - return empty.
      return [
        '#theme' => 'myeventlane_customer_dashboard',
        '#upcoming_events' => [],
        '#past_events' => [],
        '#welcome_message' => $this->t('Please log in or provide your email to view your events.'),
        '#cache' => [
          'contexts' => ['user'],
        ],
      ];
    }

    $attendeeIds = $query->execute();
    $attendees = !empty($attendeeIds) ? $attendeeStorage->loadMultiple($attendeeIds) : [];

    // Also check Commerce orders for this user.
    $orderStorage = $this->entityTypeManager()->getStorage('commerce_order');
    $orderIds = [];
    
    if ($userId > 0 || $userEmail) {
      $orderQuery = $orderStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('state', 'completed');
      
      if ($userId > 0) {
        $orderQuery->condition('uid', $userId);
      }
      if ($userEmail) {
        // If we have both uid and email, use OR condition.
        if ($userId > 0) {
          $orGroup = $orderQuery->orConditionGroup()
            ->condition('uid', $userId)
            ->condition('mail', $userEmail);
          $orderQuery->condition($orGroup);
        }
        else {
          $orderQuery->condition('mail', $userEmail);
        }
      }
      
      $orderIds = $orderQuery->execute();
    }
    
    $orders = !empty($orderIds) ? $orderStorage->loadMultiple($orderIds) : [];

    // Build event data from attendees and orders.
    $eventMap = [];
    $now = $this->time->getRequestTime();
    $nodeStorage = $this->entityTypeManager()->getStorage('node');

    // Process attendees.
    foreach ($attendees as $attendee) {
      $eventId = $attendee->get('event')->target_id;
      if (!$eventId || isset($eventMap[$eventId])) {
        continue;
      }

      $event = $nodeStorage->load($eventId);
      if (!$event || $event->bundle() !== 'event') {
        continue;
      }

      $startTime = NULL;
      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        try {
          $startTime = strtotime($event->get('field_event_start')->value);
        }
        catch (\Exception) {
          // Ignore date parsing errors.
        }
      }

      $eventMap[$eventId] = [
        'id' => $eventId,
        'title' => $event->label(),
        'url' => $event->toUrl()->toString(),
        'ics_url' => Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $eventId])->toString(),
        'start_date' => $startTime ? date('M j, Y', $startTime) : '',
        'start_time' => $startTime ? date('g:ia', $startTime) : '',
        'start_timestamp' => $startTime ?: 0,
        'source' => $attendee->get('source')->value ?? 'ticket',
        'ticket_code' => $attendee->get('ticket_code')->value ?? '',
        'attendee_id' => $attendee->id(),
        'order_item_id' => $attendee->hasField('order_item') && !$attendee->get('order_item')->isEmpty()
          ? $attendee->get('order_item')->target_id
          : NULL,
      ];
    }

    // Process orders to find additional events.
    foreach ($orders as $order) {
      foreach ($order->getItems() as $orderItem) {
        if (!$orderItem->hasField('field_target_event') || $orderItem->get('field_target_event')->isEmpty()) {
          continue;
        }

        $eventId = $orderItem->get('field_target_event')->target_id;
        if (!$eventId || isset($eventMap[$eventId])) {
          continue;
        }

        $event = $nodeStorage->load($eventId);
        if (!$event || $event->bundle() !== 'event') {
          continue;
        }

        $startTime = NULL;
        if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
          try {
            $startTime = strtotime($event->get('field_event_start')->value);
          }
          catch (\Exception) {
            // Ignore date parsing errors.
          }
        }

        // Try to find attendee record for this order item.
        $attendeeQuery = $this->entityTypeManager()
          ->getStorage('event_attendee')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('event', $eventId)
          ->condition('order_item', $orderItem->id())
          ->range(0, 1);
        
        $attendeeIds = $attendeeQuery->execute();
        $ticketCode = '';
        if (!empty($attendeeIds)) {
          $attendee = $this->entityTypeManager()
            ->getStorage('event_attendee')
            ->load(reset($attendeeIds));
          if ($attendee) {
            $ticketCode = $attendee->get('ticket_code')->value ?? '';
          }
        }

        $eventMap[$eventId] = [
          'id' => $eventId,
          'title' => $event->label(),
          'url' => $event->toUrl()->toString(),
          'ics_url' => Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $eventId])->toString(),
          'start_date' => $startTime ? date('M j, Y', $startTime) : '',
          'start_time' => $startTime ? date('g:ia', $startTime) : '',
          'start_timestamp' => $startTime ?: 0,
          'source' => 'ticket',
          'ticket_code' => $ticketCode,
          'attendee_id' => !empty($attendeeIds) ? reset($attendeeIds) : NULL,
          'order_item_id' => $orderItem->id(),
        ];
      }
    }

    // Separate upcoming and past events.
    $upcomingEvents = [];
    $pastEvents = [];

    foreach ($eventMap as $eventData) {
      if ($eventData['start_timestamp'] > $now) {
        $upcomingEvents[] = $eventData;
      }
      else {
        $pastEvents[] = $eventData;
      }
    }

    // Sort upcoming by date (ascending), past by date (descending).
    usort($upcomingEvents, function ($a, $b) {
      return $a['start_timestamp'] <=> $b['start_timestamp'];
    });
    usort($pastEvents, function ($a, $b) {
      return $b['start_timestamp'] <=> $a['start_timestamp'];
    });

    return [
      '#theme' => 'myeventlane_customer_dashboard',
      '#upcoming_events' => $upcomingEvents,
      '#past_events' => $pastEvents,
      '#welcome_message' => $this->t('Welcome, @name! Here are your events.', [
        '@name' => $currentUser->getDisplayName(),
      ]),
      '#attached' => [
        'library' => ['myeventlane_dashboard/dashboard'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list', 'user:' . $userId],
        'max-age' => 300,
      ],
    ];
  }

}

