#!/usr/bin/env bash
set -euo pipefail

APP_PATH="${APP_PATH:-$HOME/staging}"
SITE_URI="${SITE_URI:-https://staging.myeventlane.com.au}"
ARTIFACT_PATH="${ARTIFACT_PATH:-/tmp/artifact.tar.gz}"
RUN_UPDB="${RUN_UPDB:-0}"
RUN_CIM="${RUN_CIM:-0}"

TIMESTAMP="$(date +%Y%m%d%H%M%S)"
RELEASE_PATH="$APP_PATH/releases/$TIMESTAMP"
CURRENT_PATH="$APP_PATH/current"
SHARED_PATH="$APP_PATH/shared"
DEFAULT_PATH="$RELEASE_PATH/web/sites/default"

echo "Deploying release: $TIMESTAMP"

mkdir -p "$APP_PATH/releases"
mkdir -p "$SHARED_PATH/files"

if [ ! -f "$ARTIFACT_PATH" ]; then
  echo "Artifact not found: $ARTIFACT_PATH"
  exit 1
fi

mkdir -p "$RELEASE_PATH"
tar -xzf "$ARTIFACT_PATH" -C "$RELEASE_PATH"

if [ ! -d "$RELEASE_PATH/web" ]; then
  echo "Invalid artifact: web/ not found"
  exit 1
fi

# Files (shared)
rm -rf "$DEFAULT_PATH/files"
ln -sfn "$SHARED_PATH/files" "$DEFAULT_PATH/files"
chmod -R 775 "$SHARED_PATH/files"

# Settings (shared if present)
if [ -f "$SHARED_PATH/settings.php" ]; then
  rm -f "$DEFAULT_PATH/settings.php"
  ln -sfn "$SHARED_PATH/settings.php" "$DEFAULT_PATH/settings.php"
fi

if [ -f "$SHARED_PATH/settings.local.php" ]; then
  rm -f "$DEFAULT_PATH/settings.local.php"
  ln -sfn "$SHARED_PATH/settings.local.php" "$DEFAULT_PATH/settings.local.php"
fi

if [ -f "$SHARED_PATH/services.yml" ]; then
  rm -f "$DEFAULT_PATH/services.yml"
  ln -sfn "$SHARED_PATH/services.yml" "$DEFAULT_PATH/services.yml"
fi

cd "$RELEASE_PATH"

if [ ! -x "vendor/bin/drush" ]; then
  echo "Drush not found in artifact"
  exit 1
fi

# Maintenance mode ON
vendor/bin/drush sset system.maintenance_mode 1 --uri="$SITE_URI" || true
vendor/bin/drush cr --uri="$SITE_URI" || true

# Switch release
ln -sfn "$RELEASE_PATH" "$CURRENT_PATH"

cd "$CURRENT_PATH"

# Optional updates (disabled by default)
if [ "$RUN_UPDB" = "1" ]; then
  vendor/bin/drush updb -y --uri="$SITE_URI"
fi

if [ "$RUN_CIM" = "1" ]; then
  vendor/bin/drush cim -y --uri="$SITE_URI"
fi

# Finalise
vendor/bin/drush cr --uri="$SITE_URI"
vendor/bin/drush sset system.maintenance_mode 0 --uri="$SITE_URI"
vendor/bin/drush cr --uri="$SITE_URI"

echo "Release deployed: $RELEASE_PATH"
echo "Current points to: $(readlink -f "$CURRENT_PATH")"