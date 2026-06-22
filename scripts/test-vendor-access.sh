#!/bin/bash

# Test vendor access for user anna
echo "=== TESTING VENDOR ACCESS FOR USER ANNA ==="
echo ""

echo "1. Checking user information..."
ddev exec "drush user:information anna"

echo ""
echo "2. Testing access check via PHP eval..."
ddev exec "drush php-eval \"
\\\$user = \\Drupal\\entityTypeManager()->getStorage('user')->load(1);
if (!\\\$user) {
  echo 'ERROR: User UID 1 not found!' . PHP_EOL;
  exit;
}
echo 'UID: ' . \\\$user->id() . PHP_EOL;
echo 'Username: ' . \\\$user->getAccountName() . PHP_EOL;
echo 'Has administrator role: ' . (\\\$user->hasRole('administrator') ? 'YES' : 'NO') . PHP_EOL;
echo 'Has administer site configuration: ' . (\\\$user->hasPermission('administer site configuration') ? 'YES' : 'NO') . PHP_EOL;
echo PHP_EOL;

// Test VendorConsoleAccess
\\\$route_match = \\Drupal::service('current_route_match');
\\\$access = \\Drupal\\myeventlane_vendor\\Access\\VendorConsoleAccess::access(\\\$route_match, \\\$user);
echo 'VendorConsoleAccess result: ' . (\\\$access->isAllowed() ? 'ALLOWED' : 'FORBIDDEN') . PHP_EOL;
\""

echo ""
echo "3. Checking domain configuration..."
ddev exec "drush config:get myeventlane_core.domain_settings"

echo ""
echo "=== DONE ==="







