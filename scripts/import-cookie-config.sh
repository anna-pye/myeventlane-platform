#!/usr/bin/env bash
# Import Klaro cookie consent config when config/sync is sparse or out of sync.
# Run from repo root: ./scripts/import-cookie-config.sh
# Requires: ddev, Klaro module installed (see docs/KLARO_MIGRATION.md)

set -e

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SYNC_DIR="$REPO_ROOT/config/sync"

cd "$REPO_ROOT"

echo "1. Enabling Klaro and myeventlane_privacy modules..."
ddev drush en klaro myeventlane_privacy -y

echo "2. Exporting active config to sync (aligns sync with current site)..."
ddev drush cex -y

echo "3. Importing configuration..."
ddev drush cim -y

echo "4. Clearing cache..."
ddev drush cr

echo "Done. Configure Klaro services at /admin/config/user-interface/klaro/services"
