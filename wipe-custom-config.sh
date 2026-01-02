#!/bin/bash
# File: wipe-custom-config.sh
# Usage: run inside your DDEV container or host with drush access.

# 1. Delete config entries by name pattern (custom module prefixes)
#    Add or adjust patterns ('myeventlane_vendor', other custom module prefixes)
ddev drush sqlq "DELETE FROM config WHERE name LIKE 'myeventlane_vendor.%';"
ddev drush sqlq "DELETE FROM config WHERE name LIKE 'myeventlane_%.%';"
#   You can add additional patterns for other custom modules

# 2. Optionally — remove lingering field configs referencing deleted bundles/types
#    This tries to catch any 'field.field.*' or 'field.storage.*' entries referencing custom modules
ddev drush sqlq "DELETE FROM config WHERE name LIKE 'field.field.myeventlane_vendor.%';"
ddev drush sqlq "DELETE FROM config WHERE name LIKE 'field.storage.myeventlane_vendor.%';"

# 3. Clear cache & rebuild
ddev drush cr

# 4. Confirm no config remains for those modules
echo "Remaining custom-module config entries:"
ddev drush cst | grep -E 'myeventlane_vendor|myeventlane_'

# 5. Optionally — re-enable module if you want fresh install
# ddev drush en myeventlane_vendor -y
# ddev drush cr