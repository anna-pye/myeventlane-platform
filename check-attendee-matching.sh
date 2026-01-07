#!/bin/bash

echo "=== CHECKING ATTENDEE MATCHING FOR MY EVENTS ==="
echo ""

echo "1. Checking user account email..."
ddev exec "drush user:information anna" | grep -i "mail"

echo ""
echo "2. Checking recent attendees with email anna@annapye.com.au..."
ddev exec "drush php-eval \"
\\\$attendeeStorage = \\Drupal::entityTypeManager()->getStorage('event_attendee');
\\\$attendees = \\\$attendeeStorage->loadByProperties(['email' => 'anna@annapye.com.au', 'status' => 'confirmed']);
echo 'Found ' . count(\\\$attendees) . ' confirmed attendees with email anna@annapye.com.au' . PHP_EOL;
foreach (\\\$attendees as \\\$attendee) {
  echo 'Attendee ID: ' . \\\$attendee->id() . ', UID: ' . (\\\$attendee->get('uid')->target_id ?? 'NULL') . ', Event: ' . \\\$attendee->get('event')->target_id . PHP_EOL;
}
\""

echo ""
echo "3. Checking recent order 137 details..."
ddev exec "drush php-eval \"
\\\$order = \\Drupal::entityTypeManager()->getStorage('commerce_order')->load(137);
if (\\\$order) {
  echo 'Order 137 - UID: ' . \\\$order->getCustomerId() . ', Email: ' . \\\$order->getEmail() . PHP_EOL;
  echo 'Items: ' . count(\\\$order->getItems()) . PHP_EOL;
  foreach (\\\$order->getItems() as \\\$item) {
    if (\\\$item->hasField('field_target_event') && !\\\$item->get('field_target_event')->isEmpty()) {
      echo '  - Item ' . \\\$item->id() . ': Event ' . \\\$item->get('field_target_event')->target_id . PHP_EOL;
    }
  }
} else {
  echo 'Order 137 not found' . PHP_EOL;
}
\""

echo ""
echo "4. Testing what CustomerDashboardController would find..."
ddev exec "drush php-eval \"
\\\$userId = 1;
\\\$userEmail = 'anna@annapye.com.au';
\\\$attendeeStorage = \\Drupal::entityTypeManager()->getStorage('event_attendee');
\\\$query = \\\$attendeeStorage->getQuery()
  ->accessCheck(FALSE)
  ->condition('status', 'confirmed');
if (\\\$userId > 0) {
  \\\$query->condition('uid', \\\$userId);
}
\\\$attendeeIds = \\\$query->execute();
echo 'Attendees found by UID ' . \\\$userId . ': ' . count(\\\$attendeeIds) . PHP_EOL;
\""

echo ""
echo "=== DONE ==="







