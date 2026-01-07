#!/bin/bash

# Fix script to clear cache after route changes
echo "=== CLEARING CACHE FOR ROUTE CHANGES ==="
echo ""

ddev exec "drush cr"

echo ""
echo "=== DONE ==="
echo ""
echo "The following routes have been updated to use VendorConsoleAccess:"
echo "  - /vendor/analytics"
echo "  - /vendor/stripe/manage"
echo ""
echo "These routes now properly allow:"
echo "  - UID 1 (super admin)"
echo "  - Users with 'administer site configuration' permission (administrators)"
echo "  - Users with 'access vendor console' permission (vendors)"
echo ""
echo "Please log out and log back in, then try accessing:"
echo "  - https://vendor.myeventlane.ddev.site/vendor/dashboard"
echo "  - https://vendor.myeventlane.ddev.site/vendor/analytics"
echo "  - https://vendor.myeventlane.ddev.site/vendor/settings"
echo ""







