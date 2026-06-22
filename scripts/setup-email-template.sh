#!/bin/bash

echo "=== SETTING UP ORDER RECEIPT EMAIL TEMPLATE ==="
echo ""

echo "Creating email template config..."
ddev exec "drush php-eval \"
\\\$config = \\Drupal::configFactory()->getEditable('myeventlane_messaging.template.order_receipt');
\\\$config->set('enabled', TRUE);
\\\$config->set('subject', 'Your tickets for {{ event_name|default(\\\"your event\\\") }} – MyEventLane');
\\\$body = file_get_contents('modules/custom/myeventlane_messaging/config/install/myeventlane_messaging.template.order_receipt.yml');
\\\$body = preg_replace('/^body_html: \\|\\\\n/', '', \\\$body);
\\\$body = preg_replace('/^utm:.*/s', '', \\\$body);
\\\$body = trim(\\\$body);
\\\$config->set('body_html', \\\$body);
\\\$config->set('utm.enable', TRUE);
\\\$config->set('utm.params.utm_source', 'email');
\\\$config->set('utm.params.utm_medium', 'transactional');
\\\$config->set('utm.params.utm_campaign', 'receipt');
\\\$config->save();
echo 'Template created!' . PHP_EOL;
\""

echo ""
echo "Clearing cache..."
ddev exec "drush cr"

echo ""
echo "Verifying template..."
ddev exec "drush config:get myeventlane_messaging.template.order_receipt enabled"

echo ""
echo "=== DONE ==="







