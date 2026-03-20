<?php

/**
 * @file
 * Views data definitions for MyEventLane Help Centre reporting tables.
 */

declare(strict_types=1);

/**
 * Implements hook_views_data().
 */
function myeventlane_help_centre_views_data(): array {
  $data = [];

  $data['myeventlane_help_feedback']['table']['group'] = t('MEL Help Centre');
  $data['myeventlane_help_feedback']['table']['base'] = [
    'field' => 'id',
    'title' => t('Help feedback'),
    'help' => t('Feedback events captured from help article pages.'),
  ];

  $data['myeventlane_help_feedback']['id'] = [
    'title' => t('ID'),
    'help' => t('Feedback row ID.'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
  ];
  $data['myeventlane_help_feedback']['node_id'] = [
    'title' => t('Node ID'),
    'help' => t('Help article node ID.'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'relationship' => [
      'id' => 'standard',
      'title' => t('Node'),
      'help' => t('Relates feedback to the referenced node.'),
      'base' => 'node_field_data',
      'base field' => 'nid',
      'field' => 'node_id',
      'label' => t('Node'),
    ],
  ];
  $data['myeventlane_help_feedback']['node_id_feedback_rel'] = [
    'title' => t('Related feedback by node'),
    'help' => t('Relates a feedback row to other feedback rows on the same node.'),
    'relationship' => [
      'id' => 'standard',
      'base' => 'myeventlane_help_feedback',
      'base field' => 'node_id',
      'field' => 'node_id',
      'label' => t('Feedback rows for same node'),
    ],
  ];
  $data['myeventlane_help_feedback']['helpful'] = [
    'title' => t('Helpful'),
    'help' => t('Whether the feedback marked the article as helpful.'),
    'field' => ['id' => 'boolean'],
    'filter' => ['id' => 'boolean'],
    'sort' => ['id' => 'standard'],
  ];
  $data['myeventlane_help_feedback']['created'] = [
    'title' => t('Created'),
    'help' => t('Created timestamp.'),
    'field' => ['id' => 'date'],
    'filter' => ['id' => 'date'],
    'sort' => ['id' => 'date'],
  ];
  $data['myeventlane_help_feedback']['uid'] = [
    'title' => t('User ID'),
    'help' => t('Submitting user ID when available.'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'relationship' => [
      'id' => 'standard',
      'title' => t('User'),
      'help' => t('Relates feedback to the submitting user.'),
      'base' => 'users_field_data',
      'base field' => 'uid',
      'field' => 'uid',
      'label' => t('User'),
    ],
  ];
  $data['myeventlane_help_feedback']['session_id'] = [
    'title' => t('Session ID'),
    'help' => t('Session identifier used by anonymous feedback submissions.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['myeventlane_help_analytics']['table']['group'] = t('MEL Help Centre');
  $data['myeventlane_help_analytics']['table']['base'] = [
    'field' => 'id',
    'title' => t('Help analytics'),
    'help' => t('Analytics events captured from Help Centre activity.'),
  ];

  $data['myeventlane_help_analytics']['id'] = [
    'title' => t('ID'),
    'help' => t('Analytics row ID.'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
  ];
  $data['myeventlane_help_analytics']['event_type'] = [
    'title' => t('Event type'),
    'help' => t('Event type such as view, search, feedback, or zero_result.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];
  $data['myeventlane_help_analytics']['node_id'] = [
    'title' => t('Node ID'),
    'help' => t('Node referenced by the analytics event, when present.'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'relationship' => [
      'id' => 'standard',
      'title' => t('Node'),
      'help' => t('Relates analytics to the referenced node.'),
      'base' => 'node_field_data',
      'base field' => 'nid',
      'field' => 'node_id',
      'label' => t('Node'),
    ],
  ];
  $data['myeventlane_help_analytics']['query'] = [
    'title' => t('Query'),
    'help' => t('Search query string when applicable.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];
  $data['myeventlane_help_analytics']['created'] = [
    'title' => t('Created'),
    'help' => t('Created timestamp.'),
    'field' => ['id' => 'date'],
    'filter' => ['id' => 'date'],
    'sort' => ['id' => 'date'],
  ];
  $data['myeventlane_help_analytics']['uid'] = [
    'title' => t('User ID'),
    'help' => t('Submitting user ID when available.'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'relationship' => [
      'id' => 'standard',
      'title' => t('User'),
      'help' => t('Relates analytics to the submitting user.'),
      'base' => 'users_field_data',
      'base field' => 'uid',
      'field' => 'uid',
      'label' => t('User'),
    ],
  ];

  return $data;
}
