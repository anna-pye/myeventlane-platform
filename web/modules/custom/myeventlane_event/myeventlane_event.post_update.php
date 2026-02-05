<?php

declare(strict_types=1);

use Drupal\Core\Entity\Entity\EntityFormDisplay;

/**
 * Repair Event wizard form displays (wizard_step_1, wizard_step_2, wizard_step_4).
 *
 * Active config was wrong: wizard_step_1 had body instead of field_event_intro,
 * wizard_step_2 had lat/lng visible (validation errors) and address_default
 * instead of lookup widget, wizard_step_4 had empty content.
 */
function myeventlane_event_post_update_repair_event_wizard_form_displays(array &$sandbox): void {
  $modes = [
    'wizard_step_1',
    'wizard_step_2',
    'wizard_step_4',
  ];

  foreach ($modes as $mode) {
    $id = "node.event.$mode";
    $display = EntityFormDisplay::load($id);

    if (!$display) {
      $display = EntityFormDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => 'event',
        'mode' => $mode,
        'status' => TRUE,
      ]);
    }

    _myeventlane_event_repair_event_wizard_display($display, $mode);
    $display->save();
  }
}

/**
 * Apply canonical field components to a wizard form display.
 *
 * Removes all existing components first to clear drift (lat/lng, body, etc.)
 * then adds only the allowed fields per step.
 */
function _myeventlane_event_repair_event_wizard_display(EntityFormDisplay $display, string $mode): void {
  $existing = array_keys($display->getComponents());
  foreach ($existing as $name) {
    $display->removeComponent($name);
  }

  $field_exists = static function (string $field_name): bool {
    return (bool) \Drupal\field\Entity\FieldConfig::loadByName('node', 'event', $field_name);
  };

  if ($mode === 'wizard_step_1') {
    $display->setComponent('title', [
      'type' => 'string_textfield',
      'weight' => 0,
      'region' => 'content',
      'settings' => ['size' => 60, 'placeholder' => ''],
    ]);

    if ($field_exists('field_event_intro')) {
      $display->setComponent('field_event_intro', [
        'type' => 'text_textarea',
        'weight' => 10,
        'region' => 'content',
        'settings' => ['rows' => 4, 'placeholder' => 'What to expect…'],
      ]);
    }

    if ($field_exists('field_category')) {
      $display->setComponent('field_category', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 20,
        'region' => 'content',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'match_limit' => 10,
          'size' => 60,
          'placeholder' => '',
        ],
      ]);
    }

    if ($field_exists('field_event_image')) {
      $display->setComponent('field_event_image', [
        'type' => 'image_image',
        'weight' => 30,
        'region' => 'content',
        'settings' => [
          'progress_indicator' => 'throbber',
          'preview_image_style' => 'thumbnail',
        ],
      ]);
    }
    return;
  }

  if ($mode === 'wizard_step_2') {
    if ($field_exists('field_event_start')) {
      $display->setComponent('field_event_start', [
        'type' => 'datetime_default',
        'weight' => 0,
        'region' => 'content',
        'settings' => [],
      ]);
    }

    if ($field_exists('field_event_end')) {
      $display->setComponent('field_event_end', [
        'type' => 'datetime_default',
        'weight' => 10,
        'region' => 'content',
        'settings' => [],
      ]);
    }

    if ($field_exists('field_location')) {
      $display->setComponent('field_location', [
        'type' => 'myeventlane_location_address_autocomplete',
        'weight' => 20,
        'region' => 'content',
        'settings' => [],
      ]);
    }

    if ($field_exists('field_venue_name')) {
      $display->setComponent('field_venue_name', [
        'type' => 'string_textfield',
        'weight' => 30,
        'region' => 'content',
        'settings' => ['size' => 60, 'placeholder' => 'Venue name'],
      ]);
    }

    // lat/lng are NOT added - they are computed by the lookup widget.
    return;
  }

  if ($mode === 'wizard_step_4') {
    if ($field_exists('field_event_type')) {
      $display->setComponent('field_event_type', [
        'type' => 'options_select',
        'weight' => -10,
        'region' => 'content',
        'settings' => [],
      ]);
    }

    $optional = [
      'field_capacity' => ['type' => 'number', 'weight' => 0, 'settings' => ['placeholder' => '']],
      'field_waitlist_capacity' => ['type' => 'number', 'weight' => 1, 'settings' => ['placeholder' => 'Leave empty for unlimited']],
      'field_external_url' => [
        'type' => 'link_default',
        'weight' => 2,
        'settings' => ['placeholder_url' => 'https://', 'placeholder_title' => ''],
      ],
      'field_collect_per_ticket' => ['type' => 'boolean_checkbox', 'weight' => 5, 'settings' => ['display_label' => TRUE]],
      'field_ticket_types' => [
        'type' => 'paragraphs',
        'weight' => 10,
        'settings' => [
          'title' => 'Ticket Type',
          'title_plural' => 'Ticket Types',
          'edit_mode' => 'open',
          'closed_mode' => 'summary',
          'autocollapse' => 'none',
          'closed_mode_threshold' => 0,
          'add_mode' => 'dropdown',
          'form_display_mode' => 'default',
          'default_paragraph_type' => 'ticket_type_config',
          'features' => [
            'add_above' => '0',
            'collapse_edit_all' => 'collapse_edit_all',
            'duplicate' => 'duplicate',
          ],
        ],
      ],
      'field_attendee_questions' => [
        'type' => 'paragraphs',
        'weight' => 20,
        'settings' => [
          'title' => 'Question',
          'title_plural' => 'Questions',
          'edit_mode' => 'open',
          'closed_mode' => 'summary',
          'autocollapse' => 'none',
          'closed_mode_threshold' => 0,
          'add_mode' => 'dropdown',
          'form_display_mode' => 'default',
          'default_paragraph_type' => 'attendee_extra_field',
          'features' => [
            'add_above' => '0',
            'collapse_edit_all' => 'collapse_edit_all',
            'duplicate' => 'duplicate',
          ],
        ],
      ],
      'field_product_target' => [
        'type' => 'entity_reference_autocomplete',
        'weight' => 30,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'match_limit' => 10,
          'size' => 60,
          'placeholder' => '',
        ],
      ],
    ];

    foreach ($optional as $field_name => $component) {
      if ($field_exists($field_name)) {
        $display->setComponent($field_name, [
          'type' => $component['type'],
          'weight' => $component['weight'],
          'region' => 'content',
          'settings' => $component['settings'] ?? [],
        ]);
      }
    }
  }
}

/**
 * Repair wizard_step_details: remove meta fields, add age restriction.
 *
 * Details step must have: refund, age, highlights, attendee questions,
 * accessibility + conditional subfields. No created/path/status/title/uid.
 */
function myeventlane_event_post_update_repair_wizard_step_details(array &$sandbox): void {
  $id = 'node.event.wizard_step_details';
  $display = EntityFormDisplay::load($id);

  if (!$display) {
    $display = EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'event',
      'mode' => 'wizard_step_details',
      'status' => TRUE,
    ]);
  }

  $existing = array_keys($display->getComponents());
  foreach ($existing as $name) {
    $display->removeComponent($name);
  }

  $field_exists = static function (string $field_name): bool {
    return (bool) \Drupal\field\Entity\FieldConfig::loadByName('node', 'event', $field_name);
  };

  $components = [
    'field_event_highlights' => [
      'type' => 'paragraphs',
      'weight' => 0,
      'settings' => [
        'title' => 'Highlight',
        'title_plural' => 'Highlights',
        'edit_mode' => 'open',
        'closed_mode' => 'summary',
        'autocollapse' => 'none',
        'closed_mode_threshold' => 0,
        'add_mode' => 'dropdown',
        'form_display_mode' => 'default',
        'default_paragraph_type' => 'event_highlight',
        'features' => ['add_above' => '0', 'collapse_edit_all' => 'collapse_edit_all', 'duplicate' => 'duplicate'],
      ],
    ],
    'field_refund_policy' => ['type' => 'options_select', 'weight' => 10, 'settings' => []],
    'field_age_restriction' => ['type' => 'options_select', 'weight' => 20, 'settings' => []],
    'field_attendee_questions' => [
      'type' => 'paragraphs',
      'weight' => 30,
      'settings' => [
        'title' => 'Question',
        'title_plural' => 'Questions',
        'edit_mode' => 'open',
        'closed_mode' => 'summary',
        'autocollapse' => 'none',
        'closed_mode_threshold' => 0,
        'add_mode' => 'dropdown',
        'form_display_mode' => 'default',
        'default_paragraph_type' => 'attendee_extra_field',
        'features' => ['add_above' => '0', 'collapse_edit_all' => 'collapse_edit_all', 'duplicate' => 'duplicate'],
      ],
    ],
    'field_accessibility' => [
      'type' => 'entity_reference_autocomplete',
      'weight' => 40,
      'settings' => ['match_operator' => 'CONTAINS', 'match_limit' => 10, 'size' => 60, 'placeholder' => ''],
    ],
    'field_accessibility_contact' => ['type' => 'text_textarea', 'weight' => 41, 'settings' => ['rows' => 3, 'placeholder' => '']],
    'field_accessibility_directions' => ['type' => 'text_textarea', 'weight' => 42, 'settings' => ['rows' => 3, 'placeholder' => '']],
    'field_accessibility_entry' => ['type' => 'text_textarea', 'weight' => 43, 'settings' => ['rows' => 3, 'placeholder' => '']],
    'field_accessibility_parking' => ['type' => 'text_textarea', 'weight' => 44, 'settings' => ['rows' => 3, 'placeholder' => '']],
  ];

  foreach ($components as $field_name => $component) {
    if ($field_exists($field_name)) {
      $display->setComponent($field_name, [
        'type' => $component['type'],
        'weight' => $component['weight'],
        'region' => 'content',
        'settings' => $component['settings'] ?? [],
      ]);
    }
  }

  $display->save();
}
