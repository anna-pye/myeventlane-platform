<?php

namespace Drupal\myeventlane_rsvp\Plugin\views\data;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for the RSVP table.
 */
class RsvpViewsData extends EntityViewsData {

  /**
   *
   */
  public function getViewsData() {
    $data = [];

    $data['myeventlane_rsvp']['table'] = [
      'group' => 'RSVP Submissions',
      'provider' => 'myeventlane_rsvp',
    ];

    $data['myeventlane_rsvp']['id'] = [
      'title' => 'RSVP ID',
      'help' => 'Primary ID.',
      'field' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'numeric'],
    ];

    $data['myeventlane_rsvp']['event_nid'] = [
      'title' => 'Event',
      'help' => 'The event node.',
      'relationship' => [
        'base' => 'node_field_data',
        'base field' => 'nid',
        'id' => 'standard',
      ],
      'filter' => ['id' => 'numeric'],
      'field' => ['id' => 'numeric'],
    ];

    $data['myeventlane_rsvp']['email'] = [
      'title' => 'Email',
      'field' => ['id' => 'string'],
      'filter' => ['id' => 'string'],
    ];

    $data['myeventlane_rsvp']['name'] = [
      'title' => 'Name',
      'field' => ['id' => 'string'],
      'filter' => ['id' => 'string'],
    ];

    return $data;
  }

}
