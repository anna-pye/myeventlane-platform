#!/bin/bash

echo "=== HARD RESET: REMOVE EXISTING EVENT TYPE ==="
ddev drush php:eval "
if (\Drupal\node\Entity\NodeType::load('event')) {
  \Drupal\node\Entity\NodeType::load('event')->delete();
}
"

ddev drush cr

echo "=== CREATE EVENT CONTENT TYPE ==="
ddev drush php:eval "
use Drupal\node\Entity\NodeType;

\$type = NodeType::create([
  'type' => 'event',
  'name' => 'Event',
  'description' => 'Events on MyEventLane',
  'new_revision' => TRUE,
  'display_submitted' => FALSE,
]);
\$type->save();
"

echo "=== CREATE FIELDS ==="

# Field: field_event_image (Image)
ddev drush php:eval "
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

FieldStorageConfig::create([
  'field_name' => 'field_event_image',
  'entity_type' => 'node',
  'type' => 'image',
  'cardinality' => 1,
])->save();

FieldConfig::create([
  'field_name' => 'field_event_image',
  'entity_type' => 'node',
  'bundle' => 'event',
  'label' => 'Event Image',
])->save();
"

# Field: field_event_date (Datetime)
ddev drush php:eval "
FieldStorageConfig::create([
  'field_name' => 'field_event_date',
  'entity_type' => 'node',
  'type' => 'datetime',
  'settings' => ['datetime_type' => 'datetime'],
])->save();

FieldConfig::create([
  'field_name' => 'field_event_date',
  'entity_type' => 'node',
  'bundle' => 'event',
  'label' => 'Event Date & Time',
])->save();
"

# Field: field_event_location (Text)
ddev drush php:eval "
FieldStorageConfig::create([
  'field_name' => 'field_event_location',
  'entity_type' => 'node',
  'type' => 'string',
])->save();

FieldConfig::create([
  'field_name' => 'field_event_location',
  'entity_type' => 'node',
  'bundle' => 'event',
  'label' => 'Location',
])->save();
"

echo "=== REBUILD CACHE ==="
ddev drush cr

echo "=== EVENT CONTENT TYPE READY ==="