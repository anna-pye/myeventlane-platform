#!/bin/bash

echo "=== MYEVENTLANE V2 PRE-FLIGHT HEALTH CHECK ==="

# 1. DB CONNECTION
echo "1. DB CONNECTION"
if ddev drush sql:query "SELECT 1;" >/dev/null 2>&1; then
  echo "[OK] Database connection"
else
  echo "[FAIL] Database connection"
fi

# 2. CACHE
echo "2. CACHE"
ddev drush cr >/dev/null 2>&1
if [ $? -eq 0 ]; then
  echo "[OK] Cache rebuild"
else
  echo "[FAIL] Cache rebuild"
fi

# 3. CONFIG SYNC DIRECTORY
echo "3. CONFIG DIRECTORY"
SYNC_DIR="web/sites/default/files/sync"
if [ -d "$SYNC_DIR" ]; then
  echo "[OK] Config sync directory exists"
else
  echo "[FAIL] Missing config sync directory: $SYNC_DIR"
fi

# 4. CONFIG STATUS
echo "4. CONFIG STATUS"
ddev drush config:status || echo "[FAIL] Config status unavailable"

# 5. FILESYSTEM PERMISSIONS
echo "5. FILE SYSTEM PERMISSIONS"
ddev drush php:eval "
\$private = \Drupal::service('file_system')->realpath('private://');
\$public = \Drupal::service('file_system')->realpath('public://');
echo \"Public:  \$public\n\";
echo \"Private: \$private\n\";
if (!is_writable(\$public)) { echo \"[FAIL] public:// not writable\n\"; }
else { echo \"[OK] public:// writable\n\"; }
if (!is_writable(\$private)) { echo \"[FAIL] private:// not writable\n\"; }
else { echo \"[OK] private:// writable\n\"; }
" 2>/dev/null

# 6. ENTITY SCHEMA UPDATES
echo "6. ENTITY SCHEMA CHECK"
if ddev drush update:entities -n >/dev/null 2>&1; then
  echo "[OK] No pending entity schema updates"
else
  echo "[NOTICE] Pending updates (or command unavailable)"
  ddev drush update:entities -n 2>/dev/null
fi

# 7. MODULE STATUS
echo "7. MODULE STATUS"
ddev drush pm:list --status=enabled --type=module

echo "=== PRE-FLIGHT COMPLETE ==="