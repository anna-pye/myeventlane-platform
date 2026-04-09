<?php

/**
 * @file
 * Views data hooks for MyEventLane Views.
 */

/**
 * Implements hook_views_data().
 */
function myeventlane_views_views_data(): array {
  $data['node_field_data']['mel_event_no_end_upcoming'] = [
    'title' => t('MEL: empty event end + start >= now'),
    'help' => t('Matches events with no end datetime where start is still in the future (fallback branch).'),
    'filter' => [
      'id' => 'mel_event_no_end_upcoming',
      'label' => t('MEL no-end upcoming branch'),
    ],
  ];

  return $data;
}
