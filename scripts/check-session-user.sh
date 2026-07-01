#!/bin/bash

echo "=== CHECKING SESSION USER ID ==="
echo ""

echo "This will check what user ID is stored in the current session."
echo "Please make sure you're logged in on vendor.myeventlane.ddev.site"
echo ""

echo "Checking session data..."
ddev exec "drush php-eval \"
\\\$session = \\Drupal::service('session');
if (\\\$session->isStarted()) {
  echo 'Session is started' . PHP_EOL;
  echo 'Session ID: ' . \\\$session->getId() . PHP_EOL;
} else {
  echo 'Session is NOT started' . PHP_EOL;
}

\\\$current_user = \\Drupal::currentUser();
echo 'Current user UID: ' . \\\$current_user->id() . PHP_EOL;
echo 'Is anonymous: ' . (\\\$current_user->isAnonymous() ? 'YES' : 'NO') . PHP_EOL;
echo 'Username: ' . \\\$current_user->getAccountName() . PHP_EOL;

if (!\\\$current_user->isAnonymous()) {
  \\\$account = \\Drupal::entityTypeManager()->getStorage('user')->load(\\\$current_user->id());
  if (\\\$account) {
    echo 'Has administrator role: ' . (\\\$account->hasRole('administrator') ? 'YES' : 'NO') . PHP_EOL;
    echo 'Has administer site configuration: ' . (\\\$account->hasPermission('administer site configuration') ? 'YES' : 'NO') . PHP_EOL;
  }
}
\""

echo ""
echo "=== CHECKING RECENT ACCESS LOGS ==="
ddev exec "drush watchdog-show vendor_access --count=5"

echo ""
echo "If current user UID shows 0 (anonymous), the session isn't being recognized."
echo "This could mean:"
echo "  1. Session data mismatch between cookie and server"
echo "  2. Session expired or invalid"
echo "  3. Need to clear session cache"
echo ""







