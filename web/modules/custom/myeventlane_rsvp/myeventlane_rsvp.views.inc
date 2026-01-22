<?php

/**
 * @file
 * Views integration for the rsvp_submission entity.
 */

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Implements hook_views_data().
 */
function myeventlane_rsvp_views_data() {
  $data = [];

  // Base table: rsvp_submission.
  $data['rsvp_submission']['table'] = [
    'group' => new TranslatableMarkup('RSVP Submissions'),
    'provider' => 'myeventlane_rsvp',
    'base' => [
      'field' => 'id',
      'title' => new TranslatableMarkup('RSVP Submissions'),
      'help'  => new TranslatableMarkup('RSVP submission records'),
      'weight' => -10,
    ],
    'entity type' => 'rsvp_submission',
  ];

  // ----------------------------
  // Field: id
  // ----------------------------
  $data['rsvp_submission']['id'] = [
    'title' => new TranslatableMarkup('RSVP ID'),
    'help'  => new TranslatableMarkup('RSVP submission ID'),
    'field' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];

  // ----------------------------
  // Field: uuid
  // ----------------------------
  $data['rsvp_submission']['uuid'] = [
    'title' => new TranslatableMarkup('UUID'),
    'help'  => new TranslatableMarkup('Universally unique ID'),
    'field' => [
      'id' => 'field',
    ],
    'argument' => [
      'id' => 'string',
    ],
    'filter' => [
      'id' => 'string',
      'allow empty' => TRUE,
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'entity field' => 'uuid',
  ];

  // ----------------------------
  // Field: attendee_name
  // ----------------------------
  $data['rsvp_submission']['attendee_name'] = [
    'title' => new TranslatableMarkup('Attendee name'),
    'help'  => new TranslatableMarkup('Name of the attendee'),
    'field' => [
      'id' => 'field',
    ],
    'argument' => [
      'id' => 'string',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'entity field' => 'attendee_name',
  ];

  // ----------------------------
  // Field: status
  // ----------------------------
  $data['rsvp_submission']['status'] = [
    'title' => new TranslatableMarkup('Status'),
    'help'  => new TranslatableMarkup('RSVP status (confirmed, waitlisted, cancelled)'),
    'field' => [
      'id' => 'field',
    ],
    'argument' => [
      'id' => 'string',
    ],
    'filter' => [
      'id' => 'string',
      'allow empty' => TRUE,
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'entity field' => 'status',
  ];

  // ----------------------------
  // Relationship: event_id → node_field_data
  // ----------------------------
  $data['rsvp_submission']['event_id'] = [
    'title' => new TranslatableMarkup('Event'),
    'help'  => new TranslatableMarkup('Event associated with this RSVP'),
    'relationship' => [
      'id' => 'standard',
      'label' => new TranslatableMarkup('Event'),
      'base' => 'node_field_data',
      'base field' => 'nid',
      'relationship field' => 'event_id',
    ],
  ];

  // ----------------------------
  // Relationship: user_id → users_field_data
  // ----------------------------
  $data['rsvp_submission']['user_id'] = [
    'title' => new TranslatableMarkup('User'),
    'help'  => new TranslatableMarkup('User who created this RSVP'),
    'relationship' => [
      'id' => 'standard',
      'label' => new TranslatableMarkup('RSVP User'),
      'base' => 'users_field_data',
      'base field' => 'uid',
      'relationship field' => 'user_id',
    ],
  ];

  // ----------------------------
  // Field: changed
  // ----------------------------
  $data['rsvp_submission']['changed'] = [
    'title' => new TranslatableMarkup('Changed'),
    'help'  => new TranslatableMarkup('The date the RSVP was last updated'),
    'field' => [
      'id' => 'field',
    ],
    'argument' => [
      'id' => 'date',
    ],
    'filter' => [
      'id' => 'date',
      'allow empty' => TRUE,
    ],
    'sort' => [
      'id' => 'date',
    ],
    'entity field' => 'changed',
  ];

  // ----------------------------
  // Field: rendered entity (view mode)
  // ----------------------------
  $data['rsvp_submission']['rendered_entity'] = [
    'field' => [
      'id' => 'entity',
    ],
    'title' => new TranslatableMarkup('Rendered entity'),
    'help'  => new TranslatableMarkup('Renders an RSVP submission in a view mode'),
  ];

  return $data;
}
