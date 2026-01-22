<?php

namespace Drupal\myeventlane_event_attendees;

use Drupal\views\EntityViewsData;

/**
 * Views data for EventAttendee entities.
 */
class EventAttendeeViewsData extends EntityViewsData {

  /**
   *
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();

    // Group in Views UI.
    $data['event_attendee']['table']['group'] = t('Event Attendees');

    // Title of base table.
    $data['event_attendee']['table']['base'] = [
      'field' => 'id',
      'title' => t('Event Attendees'),
      'help' => t('Attendee records for events'),
    ];

    // First Name.
    $data['event_attendee']['first_name'] = [
      'title' => t('First name'),
      'help' => t('Attendee first name'),
      'field' => [
        'id' => 'standard',
      ],
    ];

    // Last Name.
    $data['event_attendee']['last_name'] = [
      'title' => t('Last name'),
      'help' => t('Attendee last name'),
      'field' => [
        'id' => 'standard',
      ],
    ];

    // Email.
    $data['event_attendee']['email'] = [
      'title' => t('Email'),
      'help' => t('Attendee email'),
      'field' => [
        'id' => 'standard',
      ],
    ];

    // Status.
    $data['event_attendee']['status'] = [
      'title' => t('Status'),
      'help' => t('confirmed / waitlisted / cancelled'),
      'field' => [
        'id' => 'standard',
      ],
      'filter' => [
        'id' => 'string',
      ],
    ];

    // Event reference.
    $data['event_attendee']['event_id'] = [
      'title' => t('Event ID'),
      'help' => t('Event node reference'),
      'relationship' => [
        'id' => 'standard',
        'base' => 'node_field_data',
        'base field' => 'nid',
        'relationship field' => 'event_id',
      ],
    ];

    return $data;
  }

}
