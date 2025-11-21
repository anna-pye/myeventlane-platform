<?php

namespace Drupal\myeventlane_rsvp\Entity;

use Drupal\views\EntityViewsData;

class RsvpSubmissionViewsData extends EntityViewsData {

  public function getViewsData(): array {
    $data = parent::getViewsData();

    // Table group.
    $data['rsvp_submission']['table']['group'] = t('RSVP Submissions');

    // Base table definition.
    $data['rsvp_submission']['table']['base'] = [
      'field' => 'id',
      'title' => t('RSVP Submissions'),
      'help' => t('RSVP Submission records'),
      'weight' => -10,
    ];

    // ID.
    $data['rsvp_submission']['id'] = [
      'title' => t('RSVP ID'),
      'help' => t('RSVP submission ID'),
      'field' => [
        'id' => 'numeric',
      ],
      'sort' => [
        'id' => 'standard',
      ],
      'filter' => [
        'id' => 'numeric',
      ],
    ];

    // Attendee name.
    $data['rsvp_submission']['attendee_name'] = [
      'title' => t('Attendee name'),
      'help' => t('Name of attendee'),
      'field' => [
        'id' => 'field',
      ],
      'filter' => [
        'id' => 'string',
      ],
      'sort' => [
        'id' => 'standard',
      ],
    ];

    // Status.
    $data['rsvp_submission']['status'] = [
      'title' => t('Status'),
      'help' => t('RSVP status'),
      'field' => [
        'id' => 'field',
      ],
      'filter' => [
        'id' => 'string',
        'allow empty' => TRUE,
      ],
      'sort' => [
        'id' => 'standard',
      ],
    ];

    // Changed timestamp.
    $data['rsvp_submission']['changed'] = [
      'title' => t('Updated'),
      'help' => t('Last updated timestamp'),
      'field' => [
        'id' => 'date',
      ],
      'filter' => [
        'id' => 'date',
        'allow empty' => TRUE,
      ],
      'sort' => [
        'id' => 'date',
      ],
    ];

    // Relationship: event_id → node.nid.
    $data['rsvp_submission']['event_id'] = [
      'title' => t('Event'),
      'help' => t('Event for this RSVP'),
      'relationship' => [
        'id' => 'standard',
        'label' => t('Event'),
        'base' => 'node_field_data',
        'base field' => 'nid',
        'relationship field' => 'event_id',
      ],
    ];

    // Relationship: user_id → users.uid.
    $data['rsvp_submission']['user_id'] = [
      'title' => t('User'),
      'help' => t('User who RSVP’d'),
      'relationship' => [
        'id' => 'standard',
        'label' => t('RSVP User'),
        'base' => 'users_field_data',
        'base field' => 'uid',
        'relationship field' => 'user_id',
      ],
    ];

    return $data;
  }
}