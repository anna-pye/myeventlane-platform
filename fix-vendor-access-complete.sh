#!/bin/bash

echo "=== COMPLETE VENDOR ACCESS FIX ==="
echo ""

echo "1. Verifying cookie domain is set..."
if grep -q "cookie_domain" web/sites/default/settings.php; then
  echo "   ✓ Cookie domain is configured"
  grep "cookie_domain" web/sites/default/settings.php | head -1
else
  echo "   ✗ Cookie domain NOT found - this is the problem!"
  exit 1
fi

echo ""
echo "2. Clearing all caches..."
ddev exec "drush cr"

echo ""
echo "3. Verifying user 'anna' has administrator role..."
ddev exec "drush user:information anna" | grep -i administrator || echo "   WARNING: Administrator role not found!"

echo ""
echo "=== IMPORTANT: SESSION FIX REQUIRED ==="
echo ""
echo "The cookie domain has been configured, but you MUST:"
echo ""
echo "1. LOG OUT completely from ALL domains:"
echo "   - https://myeventlane.ddev.site/user/logout"
echo "   - https://vendor.myeventlane.ddev.site/user/logout"
echo "   - https://admin.myeventlane.ddev.site/user/logout"
echo ""
echo "2. CLEAR ALL BROWSER COOKIES for *.ddev.site (or use incognito/private mode)"
echo ""
echo "3. CLOSE ALL BROWSER TABS for the site"
echo ""
echo "4. LOG BACK IN at:"
echo "   https://vendor.myeventlane.ddev.site/user/login"
echo "   Username: anna"
echo "   Password: admin"
echo ""
echo "5. THEN try accessing:"
echo "   https://vendor.myeventlane.ddev.site/vendor/dashboard"
echo ""
echo "=== WHY THIS IS NEEDED ==="
echo "The cookie domain change only affects NEW sessions. Your current"
echo "session was created before the cookie domain was set, so it won't"
echo "work across subdomains. You must create a NEW session by logging"
echo "out and back in."
echo ""







