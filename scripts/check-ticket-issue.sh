#!/bin/bash

echo "=== CHECKING WHY TICKET ISN'T IN MY EVENTS ==="
echo ""

echo "1. Checking if order_receipt template is enabled..."
ddev exec "drush config:get myeventlane_messaging.template.order_receipt enabled"

echo ""
echo "2. Checking recent orders for user 'anna' (UID 1)..."
ddev exec "drush php-eval \"
\\\$orderStorage = \\Drupal::entityTypeManager()->getStorage('commerce_order');
\\\$orders = \\\$orderStorage->loadByProperties(['uid' => 1, 'state' => 'completed']);
echo 'Found ' . count(\\\$orders) . ' completed orders for UID 1' . PHP_EOL;
foreach (\\\$orders as \\\$order) {
  echo 'Order ID: ' . \\\$order->id() . ', Number: ' . \\\$order->getOrderNumber() . ', Email: ' . \\\$order->getEmail() . PHP_EOL;
}
\""

echo ""
echo "3. Checking event attendees for user 'anna' (UID 1)..."
ddev exec "drush php-eval \"
\\\$attendeeStorage = \\Drupal::entityTypeManager()->getStorage('event_attendee');
\\\$attendees = \\\$attendeeStorage->loadByProperties(['uid' => 1, 'status' => 'confirmed']);
echo 'Found ' . count(\\\$attendees) . ' confirmed attendees for UID 1' . PHP_EOL;
foreach (\\\$attendees as \\\$attendee) {
  echo 'Attendee ID: ' . \\\$attendee->id() . ', Event: ' . \\\$attendee->get('event')->target_id . ', Email: ' . (\\\$attendee->get('email')->value ?? 'N/A') . PHP_EOL;
}
\""

echo ""
echo "4. Checking if template has body_html field..."
ddev exec "drush config:get myeventlane_messaging.template.order_receipt body_html" | head -5

echo ""
echo "=== DONE ==="
echo ""
echo "If no attendees found, the attendee record may not have been created."
echo "If no orders found, check if the order was completed."
echo ""







