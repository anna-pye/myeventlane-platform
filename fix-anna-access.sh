#!/bin/bash

# Quick fix script to ensure user "anna" has proper access
# Run this if you're getting access denied errors on /vendor/dashboard

set -e

echo "=== FIXING ANNA USER ACCESS ==="
echo ""

# Check if DDEV is running
if ! ddev describe > /dev/null 2>&1; then
  echo "ERROR: DDEV is not running. Please run 'ddev start' first."
  exit 1
fi

echo "1. Ensuring user 'anna' has administrator role..."
ddev exec "drush user:role:add administrator anna" || echo "  (Role may already be assigned)"

echo ""
echo "2. Clearing Drupal cache..."
ddev exec "drush cr"

echo ""
echo "3. Verifying user information..."
ddev exec "drush user:information anna"

echo ""
echo "=== DONE ==="
echo ""
echo "If you still see access denied errors, try:"
echo "  1. Log out and log back in"
echo "  2. Clear your browser cache"
echo "  3. Verify you're accessing from the correct domain:"
echo "     - Vendor dashboard: https://vendor.myeventlane.ddev.site/vendor/dashboard"
echo "     - Admin: https://admin.myeventlane.ddev.site"
echo ""

