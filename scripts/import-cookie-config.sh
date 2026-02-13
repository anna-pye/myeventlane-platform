#!/usr/bin/env bash
# Import cookie consent config when config/sync is sparse or out of sync.
# Run from repo root: ./scripts/import-cookie-config.sh
# Requires: ddev

set -e

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="$REPO_ROOT/config/sync/.cookie-backup"
SYNC_DIR="$REPO_ROOT/config/sync"

cd "$REPO_ROOT"

echo "1. Enabling cookies and myeventlane_privacy modules..."
ddev drush en cookies myeventlane_privacy -y

echo "2. Backing up cookie config files..."
mkdir -p "$BACKUP_DIR"
for f in cookies.config.yml block.block.myeventlane_theme.cookies_ui.yml block.block.myeventlane_radix.cookies_ui.yml block.block.myeventlane_vendor_theme.cookies_ui.yml; do
  [ -f "$SYNC_DIR/$f" ] && cp "$SYNC_DIR/$f" "$BACKUP_DIR/"
done

echo "3. Exporting active config to sync (aligns sync with current site)..."
ddev drush cex -y

echo "4. Restoring cookie config over the export..."
for f in cookies.config.yml block.block.myeventlane_theme.cookies_ui.yml block.block.myeventlane_radix.cookies_ui.yml block.block.myeventlane_vendor_theme.cookies_ui.yml; do
  [ -f "$BACKUP_DIR/$f" ] && cp "$BACKUP_DIR/$f" "$SYNC_DIR/"
done

echo "5. Importing configuration..."
ddev drush cim -y

echo "6. Clearing cache..."
ddev drush cr

echo "Done. Cookie consent config imported."
