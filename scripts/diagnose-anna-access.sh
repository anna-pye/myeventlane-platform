#!/bin/bash

# Diagnostic script to check why user "anna" can't access /vendor/dashboard

echo "=== DIAGNOSING ANNA USER ACCESS ==="
echo ""

# Check if user exists
echo "1. Checking if user 'anna' exists..."
ddev exec "drush user:information anna" || echo "  ERROR: User 'anna' not found!"

echo ""
echo "2. Checking user roles..."
ddev exec "drush user:role:list anna"

echo ""
echo "3. Checking user permissions..."
ddev exec "drush php-eval \"
\\\$user = \\Drupal\\user\\Entity\\User::loadByProperties(['name' => 'anna']);
if (empty(\\\$user)) {
  echo 'ERROR: User anna not found!' . PHP_EOL;
  exit;
}
\\\$user = reset(\\\$user);
echo 'UID: ' . \\\$user->id() . PHP_EOL;
echo 'Has administrator role: ' . (\\\$user->hasRole('administrator') ? 'YES' : 'NO') . PHP_EOL;
echo 'Has administer site configuration: ' . (\\\$user->hasPermission('administer site configuration') ? 'YES' : 'NO') . PHP_EOL;
echo 'Has access vendor console: ' . (\\\$user->hasPermission('access vendor console') ? 'YES' : 'NO') . PHP_EOL;
echo 'Is UID 1: ' . (\\\$user->id() === 1 ? 'YES' : 'NO') . PHP_EOL;
\""

echo ""
echo "4. Checking administrator role configuration..."
ddev exec "drush config:get user.role.administrator"

echo ""
echo "=== DIAGNOSIS COMPLETE ==="
echo ""
echo "To fix access issues, run:"
echo "  ddev exec \"drush user:role:add administrator anna\""
echo "  ddev exec \"drush cr\""
echo ""

