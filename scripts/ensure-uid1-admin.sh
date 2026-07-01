#!/bin/bash

# Script to ensure UID 1 is super admin with administrator role

echo "=== Ensuring UID 1 is super administrator ==="
echo ""

# Check current UID 1 status
echo "Checking current User ID 1..."
ddev exec "drush sql:query \"SELECT uid, name, mail FROM users_field_data WHERE uid=1\""

echo ""
echo "Ensuring UID 1 has administrator role..."

# Add administrator role to UID 1 (safe to run even if already has it)
ddev exec "drush sql:query \"INSERT IGNORE INTO user__roles (entity_id, roles_target_id, bundle, deleted, langcode, delta) VALUES (1, 'administrator', 'user', 0, 'en', 0)\""

# Also ensure via Drush command
ddev exec "drush user:role:add administrator 1" || echo "Role may already be assigned"

echo ""
echo "Verifying User ID 1 setup..."
ddev exec "drush user:information 1"

echo ""
echo "Clearing cache..."
ddev exec "drush cr"

echo ""
echo "=== DONE ==="
echo "User ID 1 is now confirmed as super administrator"







