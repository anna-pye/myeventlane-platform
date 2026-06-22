#!/bin/bash

echo "=== CHECKING EMAIL QUEUE STATUS ==="
echo ""

echo "1. Checking if order_receipt email was queued for order 147..."
ddev exec "drush php-eval \"
\\\$queue = \\Drupal::queue('myeventlane_messaging');
\\\$count = \\\$queue->numberOfItems();
echo 'Queue has ' . \\\$count . ' items' . PHP_EOL;

// Try to peek at items (this won't remove them)
if (\\\$count > 0) {
  echo 'Checking first few items...' . PHP_EOL;
  for (\\\$i = 0; \\\$i < min(5, \\\$count); \\\$i++) {
    \\\$item = \\\$queue->claimItem();
    if (\\\$item) {
      \\\$data = \\\$item->data;
      echo '  Item ' . (\\\$i + 1) . ': type=' . (\\\$data['type'] ?? 'N/A') . ', to=' . (\\\$data['to'] ?? 'N/A') . PHP_EOL;
      \\\$queue->releaseItem(\\\$item);
    }
  }
}
\""

echo ""
echo "2. Checking if order_receipt template exists and is enabled..."
ddev exec "drush config:get myeventlane_messaging.template.order_receipt enabled 2>&1"

echo ""
echo "3. Processing queue manually (this will send any queued emails)..."
ddev exec "drush mel:msg-run"

echo ""
echo "=== DONE ==="







