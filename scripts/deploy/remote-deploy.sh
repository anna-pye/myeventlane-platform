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
# Retain this many non-current release directories after each deploy (Capistrano-style).
MEL_KEEP_RELEASES="${MEL_KEEP_RELEASES:-3}"
# Minimum free space (MB) on APP_PATH filesystem before copying a new release.
MEL_MIN_FREE_DISK_MB="${MEL_MIN_FREE_DISK_MB:-2048}"

TIMESTAMP="$(date +%Y%m%d%H%M%S)"
RELEASE_PATH="$APP_PATH/releases/$TIMESTAMP"
CURRENT_PATH="$APP_PATH/current"
SHARED_PATH="$APP_PATH/shared"
DEFAULT_PATH="$RELEASE_PATH/web/sites/default"

DEPLOY_SUCCEEDED=0
MEL_CURRENT_SWITCHED=0
MEL_MM_ENABLED_ATTEMPTED=0
MEL_PREVIOUS_CURRENT=""

# Drush 12's vendor/bin/drush launcher does NOT honor DRUSH_PHP_OPTIONS — invoke drush.php via php.
MEL_DRUSH_PHP_MEMORY="${MEL_DRUSH_PHP_MEMORY:-1024M}"

mel_drush() {
  php \
    -d "memory_limit=${MEL_DRUSH_PHP_MEMORY}" \
    -d opcache.enable_cli=0 \
    vendor/bin/drush.php "$@"
}

mel_drush_log_cli_limits() {
  echo "Drush CLI PHP limits (before deploy Drush steps):"
  php -r 'printf("  memory_limit=%s\n  max_execution_time=%s\n", ini_get("memory_limit"), ini_get("max_execution_time"));'
  echo "  mel_drush uses memory_limit=${MEL_DRUSH_PHP_MEMORY}"
}

# Run a command with streamed stdout/stderr so PHP fatals appear in CI logs (not swallowed).
mel_drush_run() {
  local label="$1"
  shift
  echo "${label}..."
  set +e
  "$@"
  local rc=$?
  set -e
  if [ "$rc" -ne 0 ]; then
    echo "ERROR: ${label} failed (exit ${rc})." >&2
    return "$rc"
  fi
  return 0
}

mel_drush_maintenance_mode() {
  # state:set (alias sset) requires a bootstrapped site; use integer for 0/1.
  local mode="$1"
  mel_drush_run "Set system.maintenance_mode=${mode}" \
    mel_drush state:set system.maintenance_mode "$mode" \
      --input-format=integer \
      --uri="$SITE_URI"
}

mel_db_connection_hint() {
  cat >&2 <<'EOF'
Database connection failed before deploy could continue.

On the staging host, check:
  1. MariaDB/MySQL is running:
       sudo systemctl status mariadb || sudo systemctl status mysql
       sudo systemctl start mariadb
  2. Credentials in ~/staging/shared/settings.php match the live database.
  3. Host vs socket: if host is 127.0.0.1 but MySQL only listens on a Unix socket,
     set 'host' => 'localhost' in $databases['default']['default'] (or enable TCP bind).
  4. Manual test from the release directory:
       cd ~/staging/current && php -d memory_limit=1024M vendor/bin/drush.php sql:query "SELECT 1" --uri="$SITE_URI"
EOF
}

mel_verify_drush_bootstrap() {
  local label="${1:-Preflight}"
  echo "${label}: verifying Drupal bootstrap (Drush)..."
  local out
  set +e
  out="$(mel_drush status --uri="$SITE_URI" 2>&1)"
  local rc=$?
  set -e
  if [ "$rc" -ne 0 ] || ! echo "$out" | grep -qE 'Drupal bootstrap\s*:\s*Successful'; then
    echo "ERROR: Drupal did not bootstrap (${label})." >&2
    printf '%s\n' "$out" >&2
    mel_db_connection_hint
    return 1
  fi
  echo "${label}: Drupal bootstrap OK"
  return 0
}

mel_disk_available_mb() {
  df -Pm "$1" | awk 'NR==2 {print $4}'
}

mel_report_disk_usage() {
  echo "Filesystem usage for $APP_PATH:"
  df -h "$APP_PATH" || true
  if [ -d "$APP_PATH/releases" ]; then
    echo "Release directories (newest first):"
    ls -1dt "$APP_PATH/releases"/*/ 2>/dev/null | head -10 || true
  fi
}

mel_check_disk_space() {
  local avail_mb min_mb="${MEL_MIN_FREE_DISK_MB}"
  avail_mb="$(mel_disk_available_mb "$APP_PATH")"
  echo "Disk space: ${avail_mb}MB available on $(df -Pm "$APP_PATH" | awk 'NR==2 {print $1" ("$6")"}') (minimum ${min_mb}MB required for deploy)."
  if [ "$avail_mb" -lt "$min_mb" ]; then
    echo "ERROR: Insufficient disk space for deploy (No space left on device)." >&2
    mel_report_disk_usage >&2
    echo "Free space on the staging host (remove old releases, logs, or backups) and redeploy." >&2
    return 1
  fi
}

# Strip sites/default symlinks (shared files, settings, etc.) before rm so we never
# touch live shared paths and so web-server-owned targets are not involved.
mel_prepare_release_for_removal() {
  local release_dir="$1"
  local default_dir="$release_dir/web/sites/default"
  local entry known

  [ -d "$default_dir" ] || return 0

  for known in files settings.php services.yml config; do
    if [ -L "$default_dir/$known" ]; then
      rm -f "$default_dir/$known" || true
    fi
  done

  while IFS= read -r entry; do
    [ -n "$entry" ] || continue
    rm -f "$entry" || true
  done < <(find "$default_dir" -maxdepth 1 -type l 2>/dev/null || true)

  chmod -R u+w "$release_dir" 2>/dev/null || true
}

# Best-effort release removal. Pruning must not abort deploy: old trees often
# contain www-data-owned files under sites/default from prior live releases.
mel_remove_release_dir() {
  local release_dir="$1"
  local name
  name="$(basename "$release_dir")"

  mel_prepare_release_for_removal "$release_dir"

  set +e
  rm -rf "$release_dir" 2>/dev/null
  local rc=$?
  set -e

  if [ "$rc" -eq 0 ] && [ ! -e "$release_dir" ]; then
    return 0
  fi

  echo "  WARNING: could not fully remove release $name (permission denied on some paths)." >&2
  echo "  On the staging host, remove leftovers manually or fix ownership under:" >&2
  echo "    $release_dir/web/sites/default" >&2
  return 0
}

mel_prune_old_releases() {
  local keep="${MEL_KEEP_RELEASES}"
  local releases_dir="$APP_PATH/releases"
  local protected="" dir name candidates=() count i prune_failed=0

  [ -d "$releases_dir" ] || return 0

  if [ -e "$CURRENT_PATH" ]; then
    protected="$(readlink -f "$CURRENT_PATH" 2>/dev/null || true)"
  fi

  echo "Pruning old releases (retain ${keep} non-current; current is never removed)..."

  while IFS= read -r dir; do
    [ -n "$dir" ] || continue
    [ -d "$dir" ] || continue
    if [ -n "$protected" ] && [ "$dir" = "$protected" ]; then
      name="$(basename "$dir")"
      echo "  Keeping (current): $name"
      continue
    fi
    candidates+=("$dir")
  done < <(find "$releases_dir" -mindepth 1 -maxdepth 1 -type d | sort -r)

  count="${#candidates[@]}"
  if [ "$count" -le "$keep" ]; then
    echo "  ${count} release(s) eligible for pruning; nothing to remove (limit ${keep})."
    return 0
  fi

  for ((i=keep; i<count; i++)); do
    name="$(basename "${candidates[$i]}")"
    echo "  Removing old release: $name"
    if [ -e "${candidates[$i]}" ]; then
      mel_remove_release_dir "${candidates[$i]}"
      if [ -e "${candidates[$i]}" ]; then
        prune_failed=1
      fi
    fi
  done

  if [ "$prune_failed" = "1" ]; then
    echo "  NOTICE: Some old releases could not be pruned (non-fatal). Disk preflight will still run." >&2
  fi
}

mel_deploy_cleanup() {
  if [ "${DEPLOY_SUCCEEDED:-0}" = "1" ]; then
    return 0
  fi
  echo "" >&2
  echo "DEPLOY FAILED — cleanup: restoring previous release (if any) and clearing maintenance mode..." >&2
  if [ -n "${RELEASE_PATH:-}" ] && [ -d "$RELEASE_PATH" ]; then
    echo "  Removing failed partial release: $RELEASE_PATH" >&2
    mel_remove_release_dir "$RELEASE_PATH"
  fi
  if [ "${MEL_CURRENT_SWITCHED:-0}" = "1" ] && [ -n "${MEL_PREVIOUS_CURRENT:-}" ] && [ -d "$MEL_PREVIOUS_CURRENT" ]; then
    echo "  Rolling back current symlink to: $MEL_PREVIOUS_CURRENT" >&2
    ln -sfn "$MEL_PREVIOUS_CURRENT" "$CURRENT_PATH" || true
  fi
  local drush_cwd=""
  drush_cwd="$(readlink -f "$CURRENT_PATH" 2>/dev/null || true)"
  if [ -n "$drush_cwd" ] && [ -f "$drush_cwd/vendor/bin/drush.php" ]; then
    if [ "${MEL_MM_ENABLED_ATTEMPTED:-0}" = "1" ]; then
      ( cd "$drush_cwd" && mel_drush_maintenance_mode 0 2>/dev/null || true )
    fi
    ( cd "$drush_cwd" && mel_drush cr --uri="$SITE_URI" 2>/dev/null || true )
  fi
}
trap mel_deploy_cleanup EXIT

echo "Deploying release: $TIMESTAMP"

mkdir -p "$APP_PATH/releases"
mkdir -p "$SHARED_PATH/files"

mel_report_disk_usage
mel_prune_old_releases
mel_check_disk_space

# ---- CRITICAL FIX: validate directory, not file ----
if [ -z "$ARTIFACT_PATH" ] || [ ! -d "$ARTIFACT_PATH" ]; then
  echo "Artifact directory not found"
  exit 1
fi

mkdir -p "$RELEASE_PATH"

echo "Copying artifact contents..."
if ! cp -a "$ARTIFACT_PATH"/. "$RELEASE_PATH"/; then
  echo "ERROR: Failed to copy artifact to $RELEASE_PATH (often 'No space left on device')." >&2
  mel_report_disk_usage >&2
  exit 1
fi

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
if [ ! -f "vendor/bin/drush.php" ]; then
  echo "Drush not found in artifact (expected vendor/bin/drush.php)"
  exit 1
fi

mel_drush_log_cli_limits

# Fail fast before switching current/: new code + shared settings must bootstrap and reach MySQL.
mel_verify_drush_bootstrap "Preflight (new release, before symlink switch)"

# Capture previous live release before switching (for rollback).
if [ -e "$CURRENT_PATH" ]; then
  MEL_PREVIOUS_CURRENT="$(readlink -f "$CURRENT_PATH" 2>/dev/null || true)"
fi

# ---- MAINTENANCE MODE ----
# Prefer the live release for maintenance toggles (lighter, already warmed).
MEL_MM_ENABLED_ATTEMPTED=1
if [ -n "${MEL_PREVIOUS_CURRENT:-}" ] && [ -f "${MEL_PREVIOUS_CURRENT}/vendor/bin/drush.php" ]; then
  ( cd "$MEL_PREVIOUS_CURRENT" && mel_drush_maintenance_mode 1 )
else
  mel_drush_maintenance_mode 1
fi
# No pre-switch cache rebuild: drush cr on the unreleased tree peaks memory during container
# rebuild; staging CLI defaults are often 128M. Post-switch finalize runs drush cr with mel_drush.

# ---- SWITCH RELEASE (ATOMIC) ----
ln -sfn "$RELEASE_PATH" "$CURRENT_PATH"
MEL_CURRENT_SWITCHED=1

cd "$CURRENT_PATH"

mel_verify_drush_bootstrap "Post-switch (current release)"

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
  mel_drush updb -y --uri="$SITE_URI"
fi

if [ "$RUN_CIM" = "1" ]; then
  mel_drush cim -y --uri="$SITE_URI"

  # Deploy safety: active storage must match sync after import (see Drush docs: grep "No differences").
  echo "Verifying config:status after import..."
  CST_OUT="$(mel_drush cst --uri="$SITE_URI" 2>&1)" || true
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
  mel_drush cset myeventlane_core.domain_settings public_domain 'https://myeventlane.com.au' -y --uri="$SITE_URI"
  mel_drush cset myeventlane_core.domain_settings vendor_domain 'https://vendor.myeventlane.com.au' -y --uri="$SITE_URI"
  mel_drush cset myeventlane_core.domain_settings admin_domain 'https://admin.myeventlane.com.au' -y --uri="$SITE_URI"
elif [ "$MEL_DEPLOY_MODE" = "staging" ]; then
  echo "Applying staging domain settings (APP_ENV/SITE_URI → staging)..."
  mel_drush cset myeventlane_core.domain_settings public_domain 'https://staging.myeventlane.com.au' -y --uri="$SITE_URI"
  mel_drush cset myeventlane_core.domain_settings vendor_domain 'https://vendor.staging.myeventlane.com.au' -y --uri="$SITE_URI"
  mel_drush cset myeventlane_core.domain_settings admin_domain 'https://admin.staging.myeventlane.com.au' -y --uri="$SITE_URI"
else
  echo "NOTICE: Skipping automatic domain cset (set APP_ENV=production|staging, or SITE_URI with staging vs myeventlane.com.au)." >&2
fi

# ---- FINALISE ----
# These drush invocations are strict (no "|| true"): failures must surface in CI/SSH logs.
mel_drush_run "Finalize: drush cr (post-domain cset)" \
  mel_drush cr --uri="$SITE_URI"

mel_drush_run "Finalize: drush state:set system.maintenance_mode 0" \
  mel_drush_maintenance_mode 0

mel_drush_run "Finalize: drush cr (after maintenance off)" \
  mel_drush cr --uri="$SITE_URI"

DEPLOY_SUCCEEDED=1

echo "Release deployed: $RELEASE_PATH"
echo "Current points to: $(readlink -f "$CURRENT_PATH")"
