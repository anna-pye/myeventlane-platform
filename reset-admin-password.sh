#!/bin/bash

set -e

echo "=== RESETTING ADMIN PASSWORD FOR ALL DOMAINS ==="
echo ""
echo "This script will:"
echo "  1. Start DDEV (you may need to enter your password for sudo)"
echo "  2. Create/reset user 'Anna' with password 'admin'"
echo "  3. Assign administrator role (full access to all domains)"
echo ""

# Start DDEV
echo "Starting DDEV..."
ddev start

# Wait a moment for services to be ready
sleep 2

# Check if user exists, if not create it
echo ""
echo "Checking if user 'Anna' exists..."
USER_EXISTS=$(ddev exec "drush user:information anna" 2>/dev/null | grep -c "anna" || echo "0")

if [ "$USER_EXISTS" -eq "0" ]; then
  echo "User 'Anna' does not exist. Creating..."
  ddev exec "drush user:create anna --mail=anna@myeventlane.local --password=admin"
  echo "User created."
else
  echo "User 'Anna' exists. Resetting password..."
  ddev exec "drush user:password anna admin"
  echo "Password reset."
fi

# Ensure user has administrator role
echo ""
echo "Assigning administrator role..."
ddev exec "drush user:role:add administrator anna"

# Clear cache to ensure permissions are updated
echo ""
echo "Clearing Drupal cache..."
ddev exec "drush cr"

# Verify the setup
echo ""
echo "Verifying user setup..."
ddev exec "drush user:information anna"

# Verify permissions
echo ""
echo "Verifying user roles..."
ddev exec "drush user:role:list anna"

echo ""
echo "=== DONE ==="
echo ""
echo "Admin user 'Anna' is now set up with:"
echo "  Username: anna"
echo "  Password: admin"
echo "  Role: Administrator (full access to all domains)"
echo ""
echo "You can now log in at:"
echo "  - https://myeventlane.ddev.site/user/login"
echo "  - https://admin.myeventlane.ddev.site/user/login"
echo "  - https://vendor.myeventlane.ddev.site/user/login"
echo ""

