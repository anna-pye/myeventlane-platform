#!/bin/bash

echo "=== CHECKING SESSION AND ACCESS STATUS ==="
echo ""

echo "1. Checking cookie domain configuration..."
grep -A 3 "cookie_domain" web/sites/default/settings.php || echo "WARNING: cookie_domain not found in settings.php"

echo ""
echo "2. Checking current user session (run this while logged in)..."
echo "Visit: https://vendor.myeventlane.ddev.site/user"
echo "Then run: ddev exec \"drush php-eval 'echo \\\$user = \\Drupal::currentUser(); echo PHP_EOL; echo \\\"UID: \\\" . \\\$user->id() . PHP_EOL; echo \\\"Is anonymous: \\\" . (\\\$user->isAnonymous() ? \\\"YES\\\" : \\\"NO\\\") . PHP_EOL;'\""

echo ""
echo "3. Testing access check directly..."
ddev exec "drush php-eval \"
\\\$user = \\Drupal::entityTypeManager()->getStorage('user')->load(1);
\\\$route_match = \\Drupal::service('current_route_match');
\\\$access = \\Drupal\\myeventlane_vendor\\Access\\VendorConsoleAccess::access(\\\$route_match, \\\$user);
echo 'User UID: ' . \\\$user->id() . PHP_EOL;
echo 'Access check result: ' . (\\\$access->isAllowed() ? 'ALLOWED' : 'FORBIDDEN') . PHP_EOL;
\""

echo ""
echo "4. Checking recent access logs..."
ddev exec "drush watchdog-show vendor_access --count=5"

echo ""
echo "=== DONE ==="
echo ""
echo "If you're still getting access denied:"
echo "  1. Make sure you're logged in (check UID in step 2)"
echo "  2. Check if cookie domain is set correctly"
echo "  3. Try accessing from the same domain you logged in on"
echo ""







