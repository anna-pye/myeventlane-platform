#!/bin/bash

echo "=== TESTING EMAIL AND TICKET DISPLAY ==="
echo ""

echo "1. Checking if template has body_html..."
ddev exec "drush config:get myeventlane_messaging.template.order_receipt body_html 2>&1 | head -5"

echo ""
echo "2. Testing 'My Events' query for UID 1..."
ddev exec "drush php-eval \"
\\\$attendeeStorage = \\Drupal::entityTypeManager()->getStorage('event_attendee');
\\\$query = \\\$attendeeStorage->getQuery()
  ->accessCheck(FALSE)
  ->condition('status', 'confirmed')
  ->condition('uid', 1);
\\\$attendeeIds = \\\$query->execute();
echo 'Found ' . count(\\\$attendeeIds) . ' confirmed attendees for UID 1' . PHP_EOL;
if (count(\\\$attendeeIds) > 0) {
  \\\$attendees = \\\$attendeeStorage->loadMultiple(\\\$attendeeIds);
  echo 'Sample attendees:' . PHP_EOL;
  \\\$count = 0;
  foreach (\\\$attendees as \\\$attendee) {
    if (\\\$count++ >= 5) break;
    \\\$eventId = \\\$attendee->get('event')->target_id;
    \\\$event = \\Drupal::entityTypeManager()->getStorage('node')->load(\\\$eventId);
    echo '  - Attendee ' . \\\$attendee->id() . ': Event ' . \\\$eventId . ' (' . (\\\$event ? \\\$event->label() : 'NOT FOUND') . ')' . PHP_EOL;
  }
}
\""

echo ""
echo "3. Testing email send for order 147..."
ddev exec "drush php-eval \"
\\\$order = \\Drupal::entityTypeManager()->getStorage('commerce_order')->load(147);
if (\\\$order) {
  \\\$mail = \\\$order->getEmail();
  \\\$customer = \\\$order->getCustomer();
  \\\$first_name = \\\$customer ? \\\$customer->getDisplayName() : 'there';
  
  \\\$context = [
    'first_name' => \\\$first_name,
    'order_number' => \\\$order->label(),
    'order_url' => \\\$order->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
    'order_email' => \\\$mail,
    'events' => [],
    'ticket_items' => [],
    'donation_total' => NULL,
    'total_paid' => '\\\$' . number_format((float) \\\$order->getTotalPrice()->getNumber(), 2),
    'event_name' => 'your event',
  ];
  
  \\Drupal::service('myeventlane_messaging.manager')->queue('order_receipt', \\\$mail, \\\$context);
  echo 'Email queued for ' . \\\$mail . PHP_EOL;
} else {
  echo 'Order 147 not found' . PHP_EOL;
}
\""

echo ""
echo "4. Processing queue..."
ddev exec "drush mel:msg-run"

echo ""
echo "=== DONE ==="







