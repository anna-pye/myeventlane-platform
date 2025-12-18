<?php

/**
 * @file
 * Debug script to inspect event form structure.
 * 
 * Usage: ddev drush php:script web/modules/custom/myeventlane_event/debug-form.php
 */

use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityFormBuilder;

// Create a new event node.
$node = Node::create(['type' => 'event']);
$form_builder = \Drupal::service('entity.form_builder');
$form_object = $form_builder->getFormObject('node', 'default');
$form_object->setEntity($node);

$form_state = \Drupal::service('form_state')->createFormState();
$form_state->setFormObject($form_object);

// Build the form.
$form = $form_object->buildForm([], $form_state);

// Check for wizard structure.
echo "=== WIZARD STRUCTURE CHECK ===\n";
echo "Has wizard_stepper: " . (isset($form['wizard_stepper']) ? 'YES' : 'NO') . "\n";
echo "Has event_basics: " . (isset($form['event_basics']) ? 'YES' : 'NO') . "\n";
if (isset($form['event_basics'])) {
  echo "event_basics access: " . (isset($form['event_basics']['#access']) ? ($form['event_basics']['#access'] ? 'TRUE' : 'FALSE') : 'NOT SET') . "\n";
  echo "Has event_basics.content: " . (isset($form['event_basics']['content']) ? 'YES' : 'NO') . "\n";
  if (isset($form['event_basics']['content'])) {
    echo "event_basics.content access: " . (isset($form['event_basics']['content']['#access']) ? ($form['event_basics']['content']['#access'] ? 'TRUE' : 'FALSE') : 'NOT SET') . "\n";
    echo "event_basics.content keys: " . implode(', ', array_filter(array_keys($form['event_basics']['content']), function($k) { return !str_starts_with($k, '#'); })) . "\n";
  }
}

echo "\n=== ROOT LEVEL FIELDS ===\n";
echo "Has title: " . (isset($form['title']) ? 'YES' : 'NO') . "\n";
if (isset($form['title'])) {
  echo "title access: " . (isset($form['title']['#access']) ? ($form['title']['#access'] ? 'TRUE' : 'FALSE') : 'NOT SET') . "\n";
}
echo "Has body: " . (isset($form['body']) ? 'YES' : 'NO') . "\n";
if (isset($form['body'])) {
  echo "body access: " . (isset($form['body']['#access']) ? ($form['body']['#access'] ? 'TRUE' : 'FALSE') : 'NOT SET') . "\n";
}
echo "Has field_event_image: " . (isset($form['field_event_image']) ? 'YES' : 'NO') . "\n";
if (isset($form['field_event_image'])) {
  echo "field_event_image access: " . (isset($form['field_event_image']['#access']) ? ($form['field_event_image']['#access'] ? 'TRUE' : 'FALSE') : 'NOT SET') . "\n";
}

echo "\n=== ALL ROOT KEYS ===\n";
$root_keys = array_filter(array_keys($form), function($k) { return !str_starts_with($k, '#'); });
echo implode(', ', $root_keys) . "\n";
