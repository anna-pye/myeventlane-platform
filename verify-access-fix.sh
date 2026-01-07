#!/bin/bash

echo "=== VERIFYING ACCESS FIX ==="
echo ""

echo "1. Clearing all caches..."
ddev exec "drush cr"

echo ""
echo "2. Verifying user 'anna' has administrator role..."
ddev exec "drush user:information anna" | grep -i administrator || echo "WARNING: Administrator role not found!"

echo ""
echo "3. Testing if VendorConsoleAccess class exists..."
ddev exec "drush php-eval \"echo class_exists('Drupal\\\\myeventlane_vendor\\\\Access\\\\VendorConsoleAccess') ? 'EXISTS' : 'NOT FOUND'; echo PHP_EOL;\""

echo ""
echo "4. Testing access check directly..."
ddev exec "drush php-eval \"
\\\$user = \\Drupal::entityTypeManager()->getStorage('user')->load(1);
if (!\\\$user) {
  echo 'ERROR: User UID 1 not found!' . PHP_EOL;
  exit;
}
\\\$route_match = \\Drupal::service('current_route_match');
\\\$access = \\Drupal\\myeventlane_vendor\\Access\\VendorConsoleAccess::access(\\\$route_match, \\\$user);
echo 'User UID: ' . \\\$user->id() . PHP_EOL;
echo 'Is UID 1: ' . (\\\$user->id() === 1 ? 'YES' : 'NO') . PHP_EOL;
echo 'Access result: ' . (\\\$access->isAllowed() ? 'ALLOWED' : 'FORBIDDEN') . PHP_EOL;
\""

echo ""
echo "=== DONE ==="
echo ""
echo "If access result shows ALLOWED but you still get access denied errors,"
echo "there may be a session cache issue. Try:"
echo "  1. Log out completely"
echo "  2. Clear browser cache"
echo "  3. Log back in"
echo ""







