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

echo "== MEL deploy asset validation =="

ASSET_DIR="$RELEASE_PATH/web/themes/custom/myeventlane_theme/dist/assets"
MANIFEST="$RELEASE_PATH/dist-checksums.txt"

# 1. Validate manifest exists
if [ ! -f "$MANIFEST" ]; then
  echo "ERROR: Missing checksum manifest at $MANIFEST"
  exit 1
fi

echo "Manifest found"

# 2. Validate asset directory exists
if [ ! -d "$ASSET_DIR" ]; then
  echo "ERROR: Asset directory missing: $ASSET_DIR"
  exit 1
fi

# 3. Validate exactly ONE main CSS file
shopt -s nullglob

CSS_FILES=("$ASSET_DIR"/main*.css)
CSS_COUNT=${#CSS_FILES[@]}

if [ "$CSS_COUNT" -ne 1 ]; then
  echo "ERROR: Expected exactly 1 main*.css file, found $CSS_COUNT"
  ls -la "$ASSET_DIR"
  exit 1
fi

echo "CSS OK: ${CSS_FILES[0]}"

# 4. Validate JS assets exist
JS_FILES=("$ASSET_DIR"/*.js)
JS_COUNT=${#JS_FILES[@]}

if [ "$JS_COUNT" -eq 0 ]; then
  echo "ERROR: No JS assets found"
  exit 1
fi

echo "JS OK:"
printf '%s\n' "${JS_FILES[@]}"

# 5. Ensure no unexpected file types
INVALID_FILES=$(find "$ASSET_DIR" -type f ! -name "*.css" ! -name "*.js" ! -name "*.map")

if [ -n "$INVALID_FILES" ]; then
  echo "ERROR: Unexpected files in dist/assets:"
  echo "$INVALID_FILES"
  exit 1
fi

# 6. Run checksum verification (repo-root-relative paths)
cd "$RELEASE_PATH"

echo "Running checksum verification..."
shasum -c dist-checksums.txt

echo "Checksum verification passed"

echo "== MEL deploy asset validation complete =="

shopt -u nullglob

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

PREVIOUS_RELEASE=""
if [ -L "$CURRENT_PATH" ]; then
  PREVIOUS_RELEASE="$(readlink -f "$CURRENT_PATH" 2>/dev/null || true)"
fi

# Switch release
ln -sfn "$RELEASE_PATH" "$CURRENT_PATH"

cd "$CURRENT_PATH"

echo "Running post-deploy health check..."
if ! curl -fsS --max-time 30 "$SITE_URI/health" > /dev/null; then
  echo "ERROR: Health check failed for $SITE_URI/health"
  if [ -n "$PREVIOUS_RELEASE" ] && [ -d "$PREVIOUS_RELEASE" ] && [ "$PREVIOUS_RELEASE" != "$RELEASE_PATH" ]; then
    echo "Rolling back current symlink to $PREVIOUS_RELEASE"
    ln -sfn "$PREVIOUS_RELEASE" "$CURRENT_PATH"
  fi
  exit 1
fi
echo "Health check passed"

# Theme assets are built in CI and verified above; do not rebuild on the server.

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

# Retain the five most recent releases (best-effort cleanup).
if compgen -G "$APP_PATH/releases/*" > /dev/null; then
  mapfile -t _mel_old_releases < <(ls -dt "$APP_PATH/releases"/* | tail -n +6)
  if [ "${#_mel_old_releases[@]}" -gt 0 ]; then
    rm -rf "${_mel_old_releases[@]}"
  fi
fi