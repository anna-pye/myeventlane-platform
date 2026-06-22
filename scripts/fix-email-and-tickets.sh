#!/bin/bash

echo "=== FIXING EMAIL TEMPLATE AND TICKET DISPLAY ==="
echo ""

echo "1. Creating order_receipt email template..."
# Read the YAML file and extract body_html
BODY_HTML=$(sed -n '/^body_html:/,/^utm:/p' web/sites/default/config/sync/myeventlane_messaging.template.order_receipt.yml | sed '1d;$d' | sed 's/^  //')

ddev exec "drush php-eval \"
\\\$config = \\Drupal::configFactory()->getEditable('myeventlane_messaging.template.order_receipt');
\\\$config->set('enabled', TRUE);
\\\$config->set('subject', 'Your tickets for {{ event_name|default(\\\"your event\\\") }} – MyEventLane');
\\\$config->set('body_html', <<<'BODY'
$BODY_HTML
BODY
);
\\\$config->set('utm.enable', TRUE);
\\\$config->set('utm.params.utm_source', 'email');
\\\$config->set('utm.params.utm_medium', 'transactional');
\\\$config->set('utm.params.utm_campaign', 'receipt');
\\\$config->save();
echo 'Email template created!' . PHP_EOL;
\""

echo ""
echo "2. Checking recent attendees (49, 50) for UID matching..."
ddev exec "drush php-eval \"
\\\$attendeeStorage = \\Drupal::entityTypeManager()->getStorage('event_attendee');
\\\$attendees = \\\$attendeeStorage->loadMultiple([49, 50]);
foreach (\\\$attendees as \\\$attendee) {
  \\\$uid = \\\$attendee->get('uid')->target_id ?? 'NULL';
  \\\$email = \\\$attendee->get('email')->value ?? 'N/A';
  echo 'Attendee ' . \\\$attendee->id() . ': UID=' . \\\$uid . ', Email=' . \\\$email . ', Event=' . \\\$attendee->get('event')->target_id . PHP_EOL;
  
  // If UID is NULL, set it to 1
  if (\\\$uid === NULL || \\\$uid === 'NULL') {
    \\\$attendee->set('uid', 1);
    \\\$attendee->save();
    echo '  -> Fixed: Set UID to 1' . PHP_EOL;
  }
}
\""

echo ""
echo "3. Clearing cache..."
ddev exec "drush cr"

echo ""
echo "=== DONE ==="
echo ""
echo "Email template should now work, and tickets should appear in 'My Events'."
echo ""







