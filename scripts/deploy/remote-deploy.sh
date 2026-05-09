#!/usr/bin/env bash
set -euo pipefail

# Optional: APP_ENV=production|prod|staging|stage — drives post-deploy domain cset.
# Falls back to SITE_URI containing "staging" vs production *.myeventlane.com.au (no staging).
#
# On failure after maintenance is enabled and/or current/ is switched, EXIT cleanup rolls
# back current/ to the previous release (if known) and turns maintenance off so staging
# does not stay wedged offline.

APP_PATH="${APP_PATH:-$HOME/staging}"
# Shared config sync directory on the server (must match $settings['config_sync_directory'] in
# shared settings.php). Default: /home/mel/staging/config/sync when APP_PATH is ~/staging.
SHARED_CONFIG_SYNC="${SHARED_CONFIG_SYNC:-$APP_PATH/config/sync}"
SITE_URI="${SITE_URI:-https://staging.myeventlane.com.au}"
ARTIFACT_PATH="${ARTIFACT_PATH:-}"
RUN_UPDB="${RUN_UPDB:-0}"
RUN_CIM="${RUN_CIM:-0}"

TIMESTAMP="$(date +%Y%m%d%H%M%S)"
RELEASE_PATH="$APP_PATH/releases/$TIMESTAMP"
CURRENT_PATH="$APP_PATH/current"
SHARED_PATH="$APP_PATH/shared"
DEFAULT_PATH="$RELEASE_PATH/web/sites/default"

DEPLOY_SUCCEEDED=0
MEL_CURRENT_SWITCHED=0
MEL_MM_ENABLED_ATTEMPTED=0
MEL_PREVIOUS_CURRENT=""

mel_deploy_cleanup() {
  if [ "${DEPLOY_SUCCEEDED:-0}" = "1" ]; then
    return 0
  fi
  echo "" >&2
  echo "DEPLOY FAILED — cleanup: restoring previous release (if any) and clearing maintenance mode..." >&2
  if [ "${MEL_CURRENT_SWITCHED:-0}" = "1" ] && [ -n "${MEL_PREVIOUS_CURRENT:-}" ] && [ -d "$MEL_PREVIOUS_CURRENT" ]; then
    echo "  Rolling back current symlink to: $MEL_PREVIOUS_CURRENT" >&2
    ln -sfn "$MEL_PREVIOUS_CURRENT" "$CURRENT_PATH" || true
  fi
  local drush_cwd=""
  drush_cwd="$(readlink -f "$CURRENT_PATH" 2>/dev/null || true)"
  if [ -n "$drush_cwd" ] && [ -x "$drush_cwd/vendor/bin/drush" ]; then
    if [ "${MEL_MM_ENABLED_ATTEMPTED:-0}" = "1" ]; then
      ( cd "$drush_cwd" && vendor/bin/drush sset system.maintenance_mode 0 --uri="$SITE_URI" 2>/dev/null || true )
    fi
    ( cd "$drush_cwd" && vendor/bin/drush cr --uri="$SITE_URI" 2>/dev/null || true )
  fi
}
trap mel_deploy_cleanup EXIT

echo "Deploying release: $TIMESTAMP"

mkdir -p "$APP_PATH/releases"
mkdir -p "$SHARED_PATH/files"

# ---- CRITICAL FIX: validate directory, not file ----
if [ -z "$ARTIFACT_PATH" ] || [ ! -d "$ARTIFACT_PATH" ]; then
  echo "Artifact directory not found"
  exit 1
fi

mkdir -p "$RELEASE_PATH"

echo "Copying artifact contents..."
cp -a "$ARTIFACT_PATH"/. "$RELEASE_PATH"/

# ---- SAFETY CHECK (prevents bad deploys) ----
[ -f "$RELEASE_PATH/web/index.php" ] || {
  echo "Invalid artifact structure"
  exit 1
}

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
CSS_FILES=$(ls "$ASSET_DIR"/main*.css 2>/dev/null || true)
CSS_COUNT=$(echo "$CSS_FILES" | wc -w | tr -d ' ')

if [ "$CSS_COUNT" -ne 1 ]; then
  echo "ERROR: Expected exactly 1 main*.css file, found $CSS_COUNT"
  ls -la "$ASSET_DIR"
  exit 1
fi

echo "CSS OK: $CSS_FILES"

# 4. Validate JS assets exist
JS_FILES=$(ls "$ASSET_DIR"/*.js 2>/dev/null || true)

if [ -z "$JS_FILES" ]; then
  echo "ERROR: No JS assets found"
  exit 1
fi

echo "JS OK:"
echo "$JS_FILES"

# 5. Ensure no unexpected file types
INVALID_FILES=$(find "$ASSET_DIR" -type f ! -name "*.css" ! -name "*.js" ! -name "*.map")

if [ -n "$INVALID_FILES" ]; then
  echo "ERROR: Unexpected files in dist/assets:"
  echo "$INVALID_FILES"
  exit 1
fi

# 6. Checksum verification (sha1sum: coreutils; staging hosts often omit Perl's shasum)
cd "$RELEASE_PATH"

if ! command -v sha1sum >/dev/null 2>&1; then
  echo "ERROR: sha1sum not found — install coreutils (GNU coreutils busybox)."
  exit 1
fi

echo "Running checksum verification..."
sha1sum -c dist-checksums.txt

echo "Checksum verification passed"
echo "== MEL deploy asset validation complete =="

# Vendor console theme (myeventlane_vendor_theme): dist/ is gitignored and must
# be produced in CI. Missing files mean vendor.* pages load without global CSS/JS.
VENDOR_DIST="$RELEASE_PATH/web/themes/custom/myeventlane_vendor_theme/dist"
if [ ! -f "$VENDOR_DIST/main.css" ] || [ ! -f "$VENDOR_DIST/main.js" ]; then
  echo "ERROR: Vendor theme dist missing (expected $VENDOR_DIST/main.css and main.js)."
  echo "The deploy artifact must be built with the reusable-build workflow (vendor theme npm run build)."
  ls -la "$VENDOR_DIST" 2>/dev/null || echo "(dist directory missing)"
  exit 1
fi
echo "Vendor theme dist OK"

# ---- SHARED FILES ----
rm -rf "$DEFAULT_PATH/files"
ln -sfn "$SHARED_PATH/files" "$DEFAULT_PATH/files"
# Non-fatal: trees with root-owned upload files often make chmod -R fail under deploy user.
if ! chmod -R 775 "$SHARED_PATH/files" 2>/dev/null; then
  echo "WARNING: chmod -R 775 on $SHARED_PATH/files did not complete (non-fatal); check ownership for web user." >&2
fi

if [ -f "$SHARED_PATH/settings.php" ]; then
  rm -f "$DEFAULT_PATH/settings.php"
  ln -sfn "$SHARED_PATH/settings.php" "$DEFAULT_PATH/settings.php"
  if [ ! -f "$DEFAULT_PATH/settings.php" ]; then
    echo "ERROR: settings.php missing after deploy"
    exit 1
  fi
fi

# Do not symlink settings.local.php: it is DDEV-only in this project and must
# never override staging/production trusted hosts or domains from shared/.
if [ -f "$SHARED_PATH/settings.local.php" ]; then
  echo "WARNING: $SHARED_PATH/settings.local.php exists but is never symlinked (DDEV-only). Remove it from shared to avoid operator confusion." >&2
fi

if [ -f "$SHARED_PATH/services.yml" ]; then
  rm -f "$DEFAULT_PATH/services.yml"
  ln -sfn "$SHARED_PATH/services.yml" "$DEFAULT_PATH/services.yml"
fi

# CI packages exclude web/sites/*/settings.php; Drush steps need $databases['default'].
# Staging must provide ~/staging/shared/settings.php (symlinked above). Skip only for custom flows.
if [ "${SKIP_DB_SETTINGS_CHECK:-0}" != "1" ] && [ ! -f "$DEFAULT_PATH/settings.php" ]; then
  echo "ERROR: $DEFAULT_PATH/settings.php is missing. The build artifact does not include settings.php."
  echo "Create $SHARED_PATH/settings.php defining \$databases['default']['default'], then redeploy."
  exit 1
fi

cd "$RELEASE_PATH"

# ---- DRUSH CHECK ----
if [ ! -x "vendor/bin/drush" ]; then
  echo "Drush not found in artifact"
  exit 1
fi

# ---- MAINTENANCE MODE ----
MEL_MM_ENABLED_ATTEMPTED=1
vendor/bin/drush sset system.maintenance_mode 1 --uri="$SITE_URI" || true
vendor/bin/drush cr --uri="$SITE_URI" || true

# Capture previous live release before switching (for rollback).
if [ -e "$CURRENT_PATH" ]; then
  MEL_PREVIOUS_CURRENT="$(readlink -f "$CURRENT_PATH" 2>/dev/null || true)"
fi

# ---- SWITCH RELEASE (ATOMIC) ----
ln -sfn "$RELEASE_PATH" "$CURRENT_PATH"
MEL_CURRENT_SWITCHED=1

cd "$CURRENT_PATH"

# Fail fast after switching current: use full status output (not only --field) so stderr is visible if Drush warns.
echo "Verifying Drupal bootstrap (Drush against new release)..."
DRUSH_STATUS_OUT="$(vendor/bin/drush status --uri="$SITE_URI" 2>&1)" || true
if ! echo "$DRUSH_STATUS_OUT" | grep -qE 'Drupal bootstrap\s*:\s*Successful'; then
  echo "ERROR: Drupal did not bootstrap after release switch." >&2
  echo "$DRUSH_STATUS_OUT" >&2
  exit 1
fi

# ---- CONFIG SYNC: mirror artifact → shared sync directory (single source of truth per release) ----
# Staging uses a shared path (e.g. /home/mel/staging/config/sync) referenced by settings.php.
# Without this step, an incomplete or stale sync dir causes cim to report "no changes" while
# active config drifts; missing files can delete config on import.
RELEASE_CONFIG_SYNC="$RELEASE_PATH/config/sync"
if [ ! -d "$RELEASE_CONFIG_SYNC" ]; then
  echo "ERROR: Missing $RELEASE_CONFIG_SYNC in artifact — cannot deploy config."
  exit 1
fi

mkdir -p "$SHARED_CONFIG_SYNC"
echo "Syncing config from release to shared directory (rsync --delete)..."
echo "  Source: $RELEASE_CONFIG_SYNC/"
echo "  Dest:   $SHARED_CONFIG_SYNC/"
rsync -av --delete "$RELEASE_CONFIG_SYNC/" "$SHARED_CONFIG_SYNC/"

# ---- CONFIG SYNC SANITY (before cim): validate what Drupal will import ----
CONFIG_SYNC_DIR="$SHARED_CONFIG_SYNC"
if [ -d "$CONFIG_SYNC_DIR" ]; then
  if grep -rE 'ddev\.site' "$CONFIG_SYNC_DIR" --include='*.yml' --include='*.yaml' 2>/dev/null | grep -q .; then
    echo "ERROR: DDEV hostname found in config/sync — fix export before deploy." >&2
    grep -rE 'ddev\.site' "$CONFIG_SYNC_DIR" --include='*.yml' --include='*.yaml' >&2 || true
    exit 1
  fi
else
  echo "WARNING: config/sync missing at $CONFIG_SYNC_DIR (skipping ddev grep)." >&2
fi

# ---- OPTIONAL UPDATES ----
if [ "$RUN_UPDB" = "1" ]; then
  vendor/bin/drush updb -y --uri="$SITE_URI"
fi

if [ "$RUN_CIM" = "1" ]; then
  vendor/bin/drush cim -y --uri="$SITE_URI"

  # Deploy safety: active storage must match sync after import (see Drush docs: grep "No differences").
  echo "Verifying config:status after import..."
  CST_OUT="$(vendor/bin/drush cst --uri="$SITE_URI" 2>&1)" || true
  if echo "$CST_OUT" | grep -qi 'configuration differences'; then
    echo "ERROR: config:status reports configuration differences — deployment aborted." >&2
    echo "$CST_OUT" >&2
    exit 1
  fi
  if ! echo "$CST_OUT" | grep -q 'No differences'; then
    echo "ERROR: config:status must report no differences between DB and sync after cim." >&2
    echo "$CST_OUT" >&2
    exit 1
  fi
fi

# ---- DOMAIN ENFORCEMENT (after cim; runs every deploy) ----
# Prevents production from keeping staging hosts from sync; idempotent on staging.
mel_resolve_deploy_mode() {
  local app_raw="${APP_ENV:-}"
  local app_lc
  app_lc=$(printf '%s' "$app_raw" | tr '[:upper:]' '[:lower:]')
  case "$app_lc" in
    production|prod) echo production; return ;;
    staging|stage) echo staging; return ;;
  esac
  case "${SITE_URI:-}" in
    *staging*) echo staging; return ;;
  esac
  case "${SITE_URI:-}" in
    *myeventlane.com.au*)
      case "${SITE_URI:-}" in
        *staging*) echo staging; return ;;
        *) echo production; return ;;
      esac
      ;;
  esac
  echo ""
}

MEL_DEPLOY_MODE="$(mel_resolve_deploy_mode)"

if [ "$MEL_DEPLOY_MODE" = "production" ]; then
  echo "Applying production domain settings (APP_ENV/SITE_URI → production)..."
  vendor/bin/drush cset myeventlane_core.domain_settings public_domain 'https://myeventlane.com.au' -y --uri="$SITE_URI"
  vendor/bin/drush cset myeventlane_core.domain_settings vendor_domain 'https://vendor.myeventlane.com.au' -y --uri="$SITE_URI"
  vendor/bin/drush cset myeventlane_core.domain_settings admin_domain 'https://admin.myeventlane.com.au' -y --uri="$SITE_URI"
elif [ "$MEL_DEPLOY_MODE" = "staging" ]; then
  echo "Applying staging domain settings (APP_ENV/SITE_URI → staging)..."
  vendor/bin/drush cset myeventlane_core.domain_settings public_domain 'https://staging.myeventlane.com.au' -y --uri="$SITE_URI"
  vendor/bin/drush cset myeventlane_core.domain_settings vendor_domain 'https://vendor.staging.myeventlane.com.au' -y --uri="$SITE_URI"
  vendor/bin/drush cset myeventlane_core.domain_settings admin_domain 'https://admin.staging.myeventlane.com.au' -y --uri="$SITE_URI"
else
  echo "NOTICE: Skipping automatic domain cset (set APP_ENV=production|staging, or SITE_URI with staging vs myeventlane.com.au)." >&2
fi

# ---- FINALISE ----
# These drush invocations are strict (no "|| true"): failures must surface in CI/SSH logs.
echo "Finalize: drush cr (post-domain cset)..."
set +e
_mel_drush_cr_out="$(vendor/bin/drush cr --uri="$SITE_URI" 2>&1)"
_mel_drush_cr_rc=$?
set -e
printf '%s\n' "$_mel_drush_cr_out"
if [ "$_mel_drush_cr_rc" -ne 0 ]; then
  echo "ERROR: drush cr failed during finalize (exit $_mel_drush_cr_rc)." >&2
  exit "$_mel_drush_cr_rc"
fi

echo "Finalize: drush sset system.maintenance_mode 0..."
set +e
_mel_drush_mm_out="$(vendor/bin/drush sset system.maintenance_mode 0 --uri="$SITE_URI" 2>&1)"
_mel_drush_mm_rc=$?
set -e
printf '%s\n' "$_mel_drush_mm_out"
if [ "$_mel_drush_mm_rc" -ne 0 ]; then
  echo "ERROR: drush sset system.maintenance_mode 0 failed during finalize (exit $_mel_drush_mm_rc)." >&2
  exit "$_mel_drush_mm_rc"
fi

echo "Finalize: drush cr (after maintenance off)..."
set +e
_mel_drush_cr2_out="$(vendor/bin/drush cr --uri="$SITE_URI" 2>&1)"
_mel_drush_cr2_rc=$?
set -e
printf '%s\n' "$_mel_drush_cr2_out"
if [ "$_mel_drush_cr2_rc" -ne 0 ]; then
  echo "ERROR: second drush cr failed during finalize (exit $_mel_drush_cr2_rc)." >&2
  exit "$_mel_drush_cr2_rc"
fi

DEPLOY_SUCCEEDED=1

echo "Release deployed: $RELEASE_PATH"
echo "Current points to: $(readlink -f "$CURRENT_PATH")"
