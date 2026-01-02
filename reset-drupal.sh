#!/bin/bash

set -e

echo "=== RESETTING DRUPAL CONFIG SAFELY ==="

# 1. Rebuild caches
ddev drush cr

# 2. Disable ALL non-core modules (except twig_tweak which theme needs)
echo "Disabling contrib modules..."
ddev drush pm:uninstall twig_tweak -y || true

# 3. Remove all config files (safe, since DB is master)
echo "Wiping sync directory..."
rm -rf web/sites/default/files/sync/*
mkdir -p web/sites/default/files/sync

# 4. Export clean config from the DB
echo "Exporting fresh clean config..."
ddev drush cex -y

# 5. Reinstall twig_tweak
echo "Reinstalling twig_tweak..."
ddev composer require drupal/twig_tweak
ddev drush en twig_tweak -y

# 6. Re-enable your theme
echo "Re-enabling myeventlane_theme..."
ddev drush theme:enable myeventlane_theme

echo "=== DONE: YOU ARE NOW ON A CLEAN BASELINE ==="