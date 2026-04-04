#!/usr/bin/env bash
# Run on the target server (e.g. via SSH) after the artifact tarball is uploaded.
# Required env: APP_PATH, DEPLOY_ARCHIVE, SITE_URI
# Optional: RELEASE_ID (default: timestamp), RUN_UPDATEDB (0|1), RUN_CIM (0|1)
set -euo pipefail

APP_PATH="${APP_PATH:?Set APP_PATH (e.g. /home/mel/staging)}"
DEPLOY_ARCHIVE="${DEPLOY_ARCHIVE:?Set DEPLOY_ARCHIVE to uploaded .tar.gz path}"
SITE_URI="${SITE_URI:?Set SITE_URI for Drush}"

RELEASE_ID="${RELEASE_ID:-$(date +%Y%m%d%H%M%S)}"
RUN_UPDATEDB="${RUN_UPDATEDB:-0}"
RUN_CIM="${RUN_CIM:-0}"

SHARED="$APP_PATH/shared"
RELEASES="$APP_PATH/releases"
NEW_RELEASE="$RELEASES/$RELEASE_ID"
TMP_CURRENT="${APP_PATH}/.current-switch.$$"

log() { echo "[remote-deploy] $*"; }

mkdir -p "$RELEASES" "$SHARED/web/sites/default"

if [[ ! -f "$DEPLOY_ARCHIVE" ]]; then
  log "ERROR: archive not found: $DEPLOY_ARCHIVE"
  exit 1
fi

drush_bin() {
  local root="$1"
  echo "$root/vendor/bin/drush"
}

maintenance() {
  local root="$1"
  local on="$2"
  local drush
  drush="$(drush_bin "$root")"
  if [[ ! -x "$drush" ]]; then
    log "WARN: drush not executable at $drush — skip maintenance toggle"
    return 0
  fi
  if [[ "$on" == "1" ]]; then
    log "Maintenance mode ON ($root)"
    "$drush" state:set system.maintenance_mode 1 --input-format=integer -y --uri="$SITE_URI" || true
  else
    log "Maintenance mode OFF ($root)"
    "$drush" state:set system.maintenance_mode 0 --input-format=integer -y --uri="$SITE_URI" || true
  fi
}

CURRENT_LINK="$APP_PATH/current"
OLD_ROOT=""
if [[ -L "$CURRENT_LINK" ]] || [[ -d "$CURRENT_LINK" ]]; then
  OLD_ROOT="$(readlink -f "$CURRENT_LINK" 2>/dev/null || true)"
fi

MAINT_ON_OLD=0
restore_maintenance_on_exit() {
  local ec=$?
  if [[ "$MAINT_ON_OLD" == "1" ]] && [[ -n "$OLD_ROOT" ]] && [[ -d "$OLD_ROOT" ]]; then
    log "Deploy failed or interrupted; turning maintenance OFF on previous release"
    maintenance "$OLD_ROOT" 0 || true
  fi
  exit "$ec"
}
trap restore_maintenance_on_exit EXIT

if [[ -n "$OLD_ROOT" && -d "$OLD_ROOT" ]]; then
  maintenance "$OLD_ROOT" 1
  MAINT_ON_OLD=1
else
  log "No existing current release; skipping maintenance ON"
fi

log "Extracting $DEPLOY_ARCHIVE -> $NEW_RELEASE"
mkdir -p "$NEW_RELEASE"
tar -xzf "$DEPLOY_ARCHIVE" -C "$NEW_RELEASE"

# Shared persistent dirs (never from artifact)
mkdir -p "$SHARED/web/sites/default/files"
chmod 775 "$SHARED/web/sites/default/files" 2>/dev/null || true

if [[ ! -f "$SHARED/web/sites/default/settings.php" ]]; then
  log "ERROR: Shared settings.php missing at $SHARED/web/sites/default/settings.php"
  log "Create it on the server once; it is never deployed from git."
  exit 1
fi

log "Linking shared files + settings into release"
rm -rf "$NEW_RELEASE/web/sites/default/files"
ln -sfn "$SHARED/web/sites/default/files" "$NEW_RELEASE/web/sites/default/files"
ln -sfn "$SHARED/web/sites/default/settings.php" "$NEW_RELEASE/web/sites/default/settings.php"

chmod 775 "$NEW_RELEASE/web/sites/default/files" 2>/dev/null || true

log "Preparing symlink switch"
ln -sfn "$NEW_RELEASE" "$TMP_CURRENT"
mv -Tf "$TMP_CURRENT" "$CURRENT_LINK"

NEW_ROOT="$(readlink -f "$CURRENT_LINK")"
DRUSH="$(drush_bin "$NEW_ROOT")"

log "Drush cache rebuild"
"$DRUSH" cr --uri="$SITE_URI"

if [[ "$RUN_UPDATEDB" == "1" ]]; then
  log "Running database updates (explicit flag)"
  "$DRUSH" updb -y --uri="$SITE_URI"
fi

if [[ "$RUN_CIM" == "1" ]]; then
  log "Running config import (explicit flag)"
  "$DRUSH" cim -y --uri="$SITE_URI"
fi

trap - EXIT
MAINT_ON_OLD=0
maintenance "$NEW_ROOT" 0

log "Deploy complete: $NEW_ROOT"
rm -f "$DEPLOY_ARCHIVE"
log "Removed archive $DEPLOY_ARCHIVE"
