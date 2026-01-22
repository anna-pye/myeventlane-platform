<?php

namespace Drupal\myeventlane_event_attendees\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;

/**
 * Displays a single attendee.
 */
class EventAttendeeController extends ControllerBase {

  /**
   *
   */
  public function view(EventAttendee $event_attendee) {
    return [
      '#theme' => 'item_list',
      '#title' => 'Attendee details',
      '#items' => [
        'Event: ' . $event_attendee->get('event_id')->entity?->label(),
        'Name: ' . $event_attendee->get('first_name')->value . ' ' . $event_attendee->get('last_name')->value,
        'Email: ' . $event_attendee->get('email')->value,
        'Status: ' . $event_attendee->get('status')->value,
        'Created: ' . date('d M Y H:i', $event_attendee->get('created')->value),
      ],
    ];
  }

}
