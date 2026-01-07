#!/bin/bash

echo "=== TESTING COOKIE DOMAIN CONFIGURATION ==="
echo ""

echo "1. Checking if cookie_domain is set in settings.php..."
if grep -q "cookie_domain.*myeventlane.ddev.site" web/sites/default/settings.php; then
  echo "   ✓ Cookie domain is configured"
  grep "cookie_domain" web/sites/default/settings.php
else
  echo "   ✗ Cookie domain NOT found!"
fi

echo ""
echo "2. Testing if Drupal recognizes the setting..."
ddev exec "drush php-eval \"
\\\$settings = \\Drupal::config('system.site')->get();
\\\$cookie_domain = \\Drupal::configFactory()->get('system.site')->get('cookie_domain');
echo 'Cookie domain from config: ' . (\\\$cookie_domain ?: 'NOT SET') . PHP_EOL;
\\\$cookie_domain_setting = \\Drupal::configFactory()->getEditable('system.site')->get('cookie_domain');
echo 'Cookie domain setting: ' . (isset(\\\$GLOBALS['settings']['cookie_domain']) ? \\\$GLOBALS['settings']['cookie_domain'] : 'NOT SET') . PHP_EOL;
\""

echo ""
echo "3. Checking services.yml for session configuration..."
if [ -f web/sites/default/services.yml ]; then
  echo "   services.yml exists"
  grep -i "session\|cookie" web/sites/default/services.yml || echo "   No session/cookie config in services.yml"
else
  echo "   services.yml does not exist (this is OK)"
fi

echo ""
echo "=== INSTRUCTIONS ==="
echo ""
echo "1. Make sure you're on the login page:"
echo "   https://vendor.myeventlane.ddev.site/user/login"
echo ""
echo "2. Log in with:"
echo "   Username: anna"
echo "   Password: admin"
echo ""
echo "3. After logging in, check the browser cookies again:"
echo "   - You should see a cookie named 'SESS*' or similar"
echo "   - The Domain should be '.myeventlane.ddev.site'"
echo "   - If you see domain '.vendor.myeventlane.ddev.site', the setting isn't working"
echo ""
echo "4. If cookies still have wrong domain, try:"
echo "   - Clear ALL cookies for *.ddev.site"
echo "   - Close browser completely"
echo "   - Reopen and log in again"
echo ""







