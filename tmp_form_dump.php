<?php
use Drupal\node\Entity\Node;

$node = Node::create(['type' => 'event']);
$form = \Drupal::service('entity.form_builder')->getForm($node, 'default');

$sections = ['event_basics', 'date_time', 'location', 'booking_config', 'visibility'];
foreach ($sections as $section) {
  echo "\n== SECTION: {$section} ==\n";
  if (!isset($form[$section])) {
    echo "(missing)\n";
    continue;
  }
  $keys = array_keys($form[$section]);
  echo 'Top-level keys: ' . implode(', ', $keys) . "\n";
  if (isset($form[$section]['content']) && is_array($form[$section]['content'])) {
    echo 'Content keys: ' . implode(', ', array_keys($form[$section]['content'])) . "\n";
    if (isset($form[$section]['content']['content']) && is_array($form[$section]['content']['content'])) {
      echo 'Nested content keys: ' . implode(', ', array_keys($form[$section]['content']['content'])) . "\n";
      foreach (['field_event_type','field_ticket_types','field_category','field_accessibility'] as $fld) {
        if (isset($form[$section]['content']['content'][$fld])) {
          echo "  \xE2\x9C\x93 {$fld} in nested content\n";
        }
      }
    }
  }
}

echo "\nField presence summary:\n";
foreach (['field_event_type','field_ticket_types','field_category','field_accessibility'] as $fld) {
  $exists = array_key_exists($fld, $form) ? 'top-level' : (strpos(print_r($form, TRUE), $fld) !== FALSE ? 'nested' : 'missing');
  echo "- {$fld}: {$exists}\n";
}

