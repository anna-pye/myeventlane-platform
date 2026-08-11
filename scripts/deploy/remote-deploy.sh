#!/usr/bin/env bash
set -euo pipefail

mel_verify_config_status_output() {
  local output="$1"
  local command_rc="$2"
  local allowed_differences="${3:-}"
  local config_names unexpected=""

  if [ "$command_rc" -ne 0 ]; then
    echo "ERROR: config:status command failed with exit code ${command_rc}." >&2
    printf '%s\n' "$output" >&2
    return "$command_rc"
  fi

  # Drush may emit notices before the CSV header. Start parsing only after the
  # exact Name,State header so notices and the header itself never become
  # configuration names. A missing header is unsafe because the output shape
  # cannot be verified.
  if ! config_names="$(printf '%s\n' "$output" | awk -F',' '
    BEGIN { header_seen = 0 }
    {
      gsub(/\r$/, "", $0)
      name = $1
      state = $2
      gsub(/^"|"$/, "", name)
      gsub(/^"|"$/, "", state)
      if (name == "Name" && state == "State") {
        header_seen = 1
        next
      }
      if (header_seen && NF >= 2 && name != "" && state != "") {
        print name
      }
    }
    END { if (!header_seen) exit 2 }
  ')"; then
    echo "ERROR: config:status did not return the expected Name,State CSV header." >&2
    printf '%s\n' "$output" >&2
    return 1
  fi

  while IFS= read -r config_name; do
    [ -n "$config_name" ] || continue
    case ",$allowed_differences," in
      *",$config_name,"*)
        echo "WARNING: Allowing known environment config difference: $config_name" >&2
        ;;
      *)
        unexpected="${unexpected}${config_name}"$'\n'
        ;;
    esac
  done <<< "$config_names"

  if [ -n "$unexpected" ]; then
    echo "ERROR: unexpected config differences remain after cim:" >&2
    printf '%s' "$unexpected" >&2
    printf '%s\n' "$output" >&2
    return 1
  fi
}

# Narrow, side-effect-free entry point used by the regression test. Production
# deploys never set this variable.
if [ "${MEL_TEST_CONFIG_STATUS_OUTPUT:-0}" = "1" ]; then
  test_output="$(cat)"
  mel_verify_config_status_output \
    "$test_output" \
    "${MEL_TEST_CONFIG_STATUS_RC:-0}" \
    "${MEL_TEST_CONFIG_STATUS_ALLOWED:-}"
  exit $?
fi

echo "=================================================="
echo "MEL REMOTE DEPLOY WITH VALIDATION 2026-07-11"
echo "=================================================="

# Optional: APP_ENV=production|prod|staging|stage — drives post-deploy domain env verification.
# Staging/production: MEL_QR_SECRET must be set on the host (never in config/sync).
# Preflight runs mel:qr-secret-status on the new release BEFORE current/ is switched.
# Falls back to SITE_URI containing "staging" vs production *.myeventlane.com.au (no staging).
#
# Multi-domain URLs are NOT written via drush cset. They come from settings.php overrides
# on the host (MEL_PUBLIC_DOMAIN, MEL_VENDOR_DOMAIN, MEL_ADMIN_DOMAIN,
# MEL_FORCE_DOMAIN_REDIRECTS). config/sync keeps empty domain fields only.
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
# Comma-separated config names that are known to be rewritten by the target
# environment immediately after import. Every other difference remains fatal.
CIM_ALLOWED_DIFFERENCES="${CIM_ALLOWED_DIFFERENCES:-}"
# Retain this many non-current release directories after each deploy (Capistrano-style).
MEL_KEEP_RELEASES="${MEL_KEEP_RELEASES:-3}"
# Minimum free space (MB) on APP_PATH filesystem before copying a new release.
MEL_MIN_FREE_DISK_MB="${MEL_MIN_FREE_DISK_MB:-2048}"

# Expected owner for deployed release files. Defaults to the user running this script.
DEPLOY_USER="${DEPLOY_USER:-$(id -un)}"

# cPanel may keep its DocumentRoot at public_html/staging/current/web.
# Keep that "current" path as a symlink to APP_PATH/current so Apache and Drush use the same release.
MEL_ENABLE_WEB_CURRENT_SYMLINK="${MEL_ENABLE_WEB_CURRENT_SYMLINK:-1}"
MEL_WEB_CURRENT_PATH="${MEL_WEB_CURRENT_PATH:-}"
if [ -z "$MEL_WEB_CURRENT_PATH" ]; then
  case "$SITE_URI" in
    *staging*) MEL_WEB_CURRENT_PATH="$HOME/public_html/staging/current" ;;
  esac
fi

# HTTP health check after final Drush steps. Set MEL_HTTP_HEALTHCHECK=0 to skip.
MEL_HTTP_HEALTHCHECK="${MEL_HTTP_HEALTHCHECK:-1}"
MEL_HEALTHCHECK_URL="${MEL_HEALTHCHECK_URL:-$SITE_URI}"

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
  local drush_entry="vendor/bin/drush.php"

  if [ ! -f "$drush_entry" ]; then
    drush_entry="vendor/drush/drush/drush.php"
  fi

  if [ ! -f "$drush_entry" ]; then
    echo "ERROR: Drush PHP entry point not found in $(pwd)." >&2
    echo "Expected vendor/bin/drush.php or vendor/drush/drush/drush.php." >&2
    return 1
  fi

  php \
    -d "memory_limit=${MEL_DRUSH_PHP_MEMORY}" \
    -d opcache.enable_cli=0 \
    "$drush_entry" "$@"
}

mel_drush_log_cli_limits() {
  echo "Drush CLI PHP limits (before deploy Drush steps):"
  php -r 'printf("  memory_limit=%s\n  max_execution_time=%s\n", ini_get("memory_limit"), ini_get("max_execution_time"));'
  echo "  mel_drush uses memory_limit=${MEL_DRUSH_PHP_MEMORY}"
}

# Run a command with streamed and captured stdout/stderr so Drush fatals are reported with deployment context.
mel_drush_run() {
  local label="$1"
  shift
  local command_string=""
  local arg quoted_arg output rc tmp_output

  for arg in "$@"; do
    printf -v quoted_arg '%q' "$arg"
    if [ -n "$command_string" ]; then
      command_string="${command_string} ${quoted_arg}"
    else
      command_string="$quoted_arg"
    fi
  done

  echo "${label}..."
  echo "Working directory: $(pwd)"
  echo "URI: $SITE_URI"
  echo "Command: $command_string"
  tmp_output="$(mktemp)"
  set +e
  "$@" 2>&1 | tee "$tmp_output"
  rc=${PIPESTATUS[0]}
  set -e
  output="$(<"$tmp_output")"
  rm -f "$tmp_output"
  if [ "$rc" -ne 0 ]; then
    echo "==============================" >&2
    echo "DRUSH FAILURE" >&2
    echo "==============================" >&2
    echo "Exit code:" >&2
    echo "$rc" >&2
    echo "Working directory:" >&2
    pwd >&2
    echo "URI:" >&2
    echo "$SITE_URI" >&2
    echo "Command:" >&2
    echo "$command_string" >&2
    echo "----- Begin Drush Output -----" >&2
    printf '%s\n' "$output" >&2
    echo "----- End Drush Output -----" >&2
    echo "==============================" >&2
    return "$rc"
  fi
  return 0
}

mel_drush_maintenance_mode() {
  # state:set (alias sset) requires a bootstrapped site; use integer for 0/1.
  local mode="$1"
  if [ "$mode" = "1" ]; then
    echo "Enabling maintenance mode..."
    echo "Current directory:"
    pwd
    echo "Current release:"
    echo "${MEL_PREVIOUS_CURRENT:-}"
    echo "Target release:"
    echo "$RELEASE_PATH"
  fi
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

# Resolves staging|production|"" for APP_ENV / SITE_URI (used pre- and post-switch).
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

# Requires mel:qr-secret-status (myeventlane_tickets). Never prints the secret value.
# Must run from a release directory that has already passed mel_verify_drush_bootstrap.
mel_verify_qr_signing_secret() {
  echo "Verifying QR signing secret (never prints the secret value)..."
  set +e
  out="$(mel_drush mel:qr-secret-status --uri="$SITE_URI" 2>&1)"
  local rc=$?
  set -e
  echo "$out"
  if [ "$rc" -ne 0 ]; then
    echo "ERROR: QR signing secret check failed." >&2
    echo "Set MEL_QR_SECRET on the PHP-FPM and CLI host environment, or \$settings['myeventlane_qr_secret'] in shared settings.php." >&2
    echo "Never store the signing secret in config/sync." >&2
    echo "Aborting before live symlink switch." >&2
    return 1
  fi
  echo "QR signing secret OK."
  return 0
}


mel_is_abs_path() {
  case "${1:-}" in
    /*) return 0 ;;
    *) return 1 ;;
  esac
}

mel_fail_bad_path() {
  local name="$1"
  local value="${2:-}"
  echo "ERROR: Unsafe ${name}: '${value:-<empty>}'" >&2
  return 1
}

mel_validate_path_not_root() {
  local name="$1"
  local value="${2:-}"

  [ -n "$value" ] || mel_fail_bad_path "$name" "$value"
  mel_is_abs_path "$value" || mel_fail_bad_path "$name" "$value"
  [ "$value" != "/" ] || mel_fail_bad_path "$name" "$value"
}

mel_validate_base_paths() {
  echo "Validating deployment paths..."

  mel_validate_path_not_root APP_PATH "$APP_PATH"
  mel_validate_path_not_root RELEASE_PATH "$RELEASE_PATH"
  mel_validate_path_not_root CURRENT_PATH "$CURRENT_PATH"
  mel_validate_path_not_root SHARED_PATH "$SHARED_PATH"
  mel_validate_path_not_root SHARED_CONFIG_SYNC "$SHARED_CONFIG_SYNC"

  case "$RELEASE_PATH" in
    "$APP_PATH"/releases/*) ;;
    *) echo "ERROR: RELEASE_PATH must be under APP_PATH/releases: $RELEASE_PATH" >&2; exit 1 ;;
  esac

  case "$CURRENT_PATH" in
    "$APP_PATH"/current) ;;
    *) echo "ERROR: CURRENT_PATH must be APP_PATH/current: $CURRENT_PATH" >&2; exit 1 ;;
  esac

  if [ "$MEL_ENABLE_WEB_CURRENT_SYMLINK" = "1" ]; then
    if [ -z "$MEL_WEB_CURRENT_PATH" ]; then
      echo "NOTICE: MEL_WEB_CURRENT_PATH is not set; web current symlink validation will be skipped."
    else
      mel_validate_path_not_root MEL_WEB_CURRENT_PATH "$MEL_WEB_CURRENT_PATH"
      case "$MEL_WEB_CURRENT_PATH" in
        *"/current") ;;
        *) echo "ERROR: MEL_WEB_CURRENT_PATH must end in /current: $MEL_WEB_CURRENT_PATH" >&2; exit 1 ;;
      esac
    fi
  fi

  echo "Deployment paths OK"
}

mel_verify_release_owner() {
  local path="$1"
  local owner="${2:-$DEPLOY_USER}"
  local offender=""

  echo "Verifying release ownership (${owner})..."
  offender="$(find "$path" ! -user "$owner" -print -quit 2>/dev/null || true)"
  if [ -n "$offender" ]; then
    echo "ERROR: Release contains files not owned by ${owner}." >&2
    echo "First offending path: $offender" >&2
    echo "Fix ownership before deploying, for example:" >&2
    echo "  chown -R ${owner}:${owner} $path" >&2
    return 1
  fi
  echo "Release ownership OK"
}

mel_verify_release_composer_layout() {
  local path="$1"
  # Drupal scaffold provides web/autoload.php (required by web/index.php).
  # web/autoload_runtime.php is a Symfony Runtime entrypoint and is NOT part of
  # the Drupal Composer scaffold used by MEL — do not require it.
  local required=(
    "composer.json"
    "composer.lock"
    "vendor/autoload.php"
    "web/index.php"
    "web/autoload.php"
    "web/core"
    "web/modules"
    "web/themes"
    "web/sites/default"
  )
  local item

  echo "Verifying Drupal Composer release layout..."
  for item in "${required[@]}"; do
    if [ ! -e "$path/$item" ]; then
      echo "ERROR: Release is missing required path: $path/$item" >&2
      if [ "$item" = "web/autoload.php" ]; then
        echo "Composer scaffold is incomplete. The build artifact must include web/autoload.php (Drupal scaffold, not Symfony Runtime)." >&2
      fi
      return 1
    fi
  done

  php -l "$path/web/index.php" >/dev/null
  php -l "$path/web/autoload.php" >/dev/null

  echo "Drupal Composer release layout OK"
}

mel_verify_shared_links() {
  echo "Verifying shared runtime links..."

  if [ ! -L "$DEFAULT_PATH/files" ]; then
    echo "ERROR: $DEFAULT_PATH/files must be a symlink to shared files." >&2
    return 1
  fi

  if [ -f "$SHARED_PATH/settings.php" ] && [ ! -L "$DEFAULT_PATH/settings.php" ]; then
    echo "ERROR: $DEFAULT_PATH/settings.php must be a symlink to shared/settings.php." >&2
    return 1
  fi

  if [ -f "$SHARED_PATH/services.yml" ] && [ ! -L "$DEFAULT_PATH/services.yml" ]; then
    echo "ERROR: $DEFAULT_PATH/services.yml must be a symlink to shared/services.yml." >&2
    return 1
  fi

  echo "Shared runtime links OK"
}

mel_update_web_current_symlink() {
  if [ "$MEL_ENABLE_WEB_CURRENT_SYMLINK" != "1" ]; then
    echo "Skipping web current symlink update (MEL_ENABLE_WEB_CURRENT_SYMLINK=0)."
    return 0
  fi

  if [ -z "$MEL_WEB_CURRENT_PATH" ]; then
    echo "Skipping web current symlink update (MEL_WEB_CURRENT_PATH not set)."
    return 0
  fi

  echo "Verifying web current symlink for Apache/cPanel..."
  echo "  Web current path: $MEL_WEB_CURRENT_PATH"
  echo "  Target:           $CURRENT_PATH"

  mkdir -p "$(dirname "$MEL_WEB_CURRENT_PATH")"

  if [ -e "$MEL_WEB_CURRENT_PATH" ] && [ ! -L "$MEL_WEB_CURRENT_PATH" ]; then
    echo "ERROR: $MEL_WEB_CURRENT_PATH exists but is not a symlink." >&2
    echo "Back it up manually, then create a symlink to $CURRENT_PATH." >&2
    return 1
  fi

  ln -sfn "$CURRENT_PATH" "$MEL_WEB_CURRENT_PATH"

  if [ "$(readlink -f "$MEL_WEB_CURRENT_PATH")" != "$(readlink -f "$CURRENT_PATH")" ]; then
    echo "ERROR: $MEL_WEB_CURRENT_PATH does not resolve to $CURRENT_PATH." >&2
    echo "Actual: $(readlink -f "$MEL_WEB_CURRENT_PATH" 2>/dev/null || echo '<missing>')" >&2
    return 1
  fi

  echo "Web current symlink OK"
}

mel_verify_http_health() {
  if [ "$MEL_HTTP_HEALTHCHECK" != "1" ]; then
    echo "Skipping HTTP health check (MEL_HTTP_HEALTHCHECK=0)."
    return 0
  fi

  if ! command -v curl >/dev/null 2>&1; then
    echo "WARNING: curl not found; skipping HTTP health check." >&2
    return 0
  fi

  # Use GET, not HEAD (-I): some Drupal/edge setups mishandle HEAD while GET is healthy.
  echo "Running HTTP health check (GET): $MEL_HEALTHCHECK_URL"
  if ! curl -fsSL --max-time 30 -o /dev/null -w "HTTP %{http_code}\n" "$MEL_HEALTHCHECK_URL" >/tmp/mel_deploy_http_health.$$ 2>&1; then
    echo "ERROR: HTTP health check failed for $MEL_HEALTHCHECK_URL" >&2
    cat /tmp/mel_deploy_http_health.$$ >&2 || true
    rm -f /tmp/mel_deploy_http_health.$$
    return 1
  fi
  rm -f /tmp/mel_deploy_http_health.$$
  echo "HTTP health check OK"
}

mel_revision_upsert_kv() {
  local file="$1"
  local key="$2"
  local value="$3"
  local tmp

  tmp="$(mktemp)"
  if [ -f "$file" ] && grep -q "^${key}=" "$file"; then
    awk -v k="$key" -v v="$value" '
      index($0, k "=") == 1 { print k "=" v; next }
      { print }
    ' "$file" > "$tmp"
  else
    if [ -f "$file" ]; then
      cat "$file" > "$tmp"
    else
      : > "$tmp"
    fi
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
  fi
  mv "$tmp" "$file"
}

mel_write_revision_metadata() {
  local dst="$1/REVISION"
  local deploy_time="${MEL_DEPLOY_TIME_UTC:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}"
  local release_dir
  release_dir="$(basename "$1")"

  # Prefer KEY=VALUE provenance already baked into the artifact. Only stamp
  # deploy-time fields — do not rewrite activation, rollback, or symlink logic.
  if [ -f "$dst" ] && grep -q '^artifact_sha=' "$dst"; then
    mel_revision_upsert_kv "$dst" "deploy_time_utc" "$deploy_time"
    mel_revision_upsert_kv "$dst" "release_dir" "$release_dir"
    if [ -n "${MEL_RELEASE_IDENTIFIER:-}" ]; then
      mel_revision_upsert_kv "$dst" "release_identifier" "$MEL_RELEASE_IDENTIFIER"
    fi
  elif [ -n "${MEL_REVISION:-}" ]; then
    if printf '%s' "$MEL_REVISION" | grep -q '='; then
      printf '%s\n' "$MEL_REVISION" > "$dst"
    else
      {
        printf 'artifact_sha=%s\n' "$MEL_REVISION"
        printf 'deploy_time_utc=%s\n' "$deploy_time"
        printf 'release_dir=%s\n' "$release_dir"
        if [ -n "${MEL_RELEASE_IDENTIFIER:-}" ]; then
          printf 'release_identifier=%s\n' "$MEL_RELEASE_IDENTIFIER"
        fi
      } > "$dst"
    fi
  elif [ -n "${GITHUB_SHA:-}" ]; then
    {
      printf 'artifact_sha=%s\n' "$GITHUB_SHA"
      printf 'deploy_time_utc=%s\n' "$deploy_time"
      printf 'release_dir=%s\n' "$release_dir"
    } > "$dst"
  elif [ -f "$dst" ]; then
    :
  else
    printf 'artifact_sha=unknown\n' > "$dst"
  fi

  echo "Release revision metadata:"
  cat "$dst"
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
  if [ "${MEL_CURRENT_SWITCHED:-0}" = "1" ] && [ -n "${MEL_PREVIOUS_CURRENT:-}" ] && [ -d "$MEL_PREVIOUS_CURRENT" ]; then
    echo "  Rolling back current symlink to: $MEL_PREVIOUS_CURRENT" >&2
    ln -sfn "$MEL_PREVIOUS_CURRENT" "$CURRENT_PATH" || true
    if [ "${MEL_ENABLE_WEB_CURRENT_SYMLINK:-1}" = "1" ] && [ -n "${MEL_WEB_CURRENT_PATH:-}" ]; then
      ln -sfn "$CURRENT_PATH" "$MEL_WEB_CURRENT_PATH" || true
    fi
  fi
  if [ -n "${RELEASE_PATH:-}" ] && [ -d "$RELEASE_PATH" ]; then
    echo "  Removing failed partial release: $RELEASE_PATH" >&2
    mel_remove_release_dir "$RELEASE_PATH"
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

mel_validate_base_paths

mkdir -p "$APP_PATH/releases"
mkdir -p "$SHARED_PATH/files"
mkdir -p "$SHARED_PATH/files/page-visuals"

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
echo
echo "========== ARTIFACT BEFORE COPY =========="
echo "Artifact path:"
echo "$ARTIFACT_PATH"
echo
echo "Release path:"
echo "$RELEASE_PATH"
echo
echo "Artifact top level:"
ls -la "$ARTIFACT_PATH" || true
echo
echo "Artifact tree:"
find "$ARTIFACT_PATH" -maxdepth 2 | sort || true
echo "=========================================="
echo
if ! cp -a "$ARTIFACT_PATH"/. "$RELEASE_PATH"/; then
  echo "ERROR: Failed to copy artifact to $RELEASE_PATH (often 'No space left on device')." >&2
  mel_report_disk_usage >&2
  exit 1
fi

mel_write_revision_metadata "$RELEASE_PATH"
mel_verify_release_owner "$RELEASE_PATH" "$DEPLOY_USER"

echo
echo "========== RELEASE AFTER COPY =========="
echo
echo "Top level:"
ls -la "$RELEASE_PATH" || true
echo
echo "Tree:"
find "$RELEASE_PATH" -maxdepth 2 | sort || true
echo "========================================"
echo

mel_verify_release_composer_layout "$RELEASE_PATH"

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

# shared/settings.php lives outside the release and typically requires
# __DIR__ . '/settings.mel_shared_session.php'. Sync the tracked fragment from
# each release so domain/Stripe/session overrides stay current without hand-editing shared/.
MEL_SHARED_SESSION_SRC="$RELEASE_PATH/web/sites/default/settings.mel_shared_session.php"
MEL_SHARED_SESSION_DST="$SHARED_PATH/settings.mel_shared_session.php"
if [ -f "$MEL_SHARED_SESSION_SRC" ]; then
  cp "$MEL_SHARED_SESSION_SRC" "$MEL_SHARED_SESSION_DST"
  echo "Synced settings.mel_shared_session.php to $MEL_SHARED_SESSION_DST"
elif [ ! -f "$MEL_SHARED_SESSION_DST" ]; then
  echo "ERROR: settings.mel_shared_session.php missing in release and not present in shared/." >&2
  echo "Ensure web/sites/default/settings.mel_shared_session.php is in the artifact." >&2
  exit 1
fi

if [ -f "$SHARED_PATH/settings.php" ] && ! grep -qE 'settings\.mel_(shared_session|domains)|myeventlane_core\.domain_settings' "$SHARED_PATH/settings.php"; then
  echo "ERROR: $SHARED_PATH/settings.php must load MEL domain overrides." >&2
  echo "Add (uses active release for mel_shared_session, shared/ for mel_domains):" >&2
  echo '  $mel_shared_session = $app_root . '"'"'/'"'"' . $site_path . '"'"'/settings.mel_shared_session.php'"'"';' >&2
  echo '  if (is_readable($mel_shared_session)) { require $mel_shared_session; }' >&2
  echo '  $mel_domains = __DIR__ . '"'"'/settings.mel_domains.php'"'"';' >&2
  echo '  if (is_readable($mel_domains)) { require $mel_domains; }' >&2
  exit 1
fi

mel_write_shared_domain_settings() {
  local mode="$1"
  local dst="$SHARED_PATH/settings.mel_domains.php"
  local pub vendor admin

  case "$mode" in
    staging)
      pub='https://staging.myeventlane.com.au'
      vendor='https://vendor.staging.myeventlane.com.au'
      admin='https://admin.staging.myeventlane.com.au'
      ;;
    production)
      pub='https://myeventlane.com.au'
      vendor='https://vendor.myeventlane.com.au'
      admin='https://admin.myeventlane.com.au'
      ;;
    *)
      return 0
      ;;
  esac

  cat > "$dst" <<PHP
<?php

declare(strict_types=1);

/**
 * @file
 * Host domain URLs for ${mode} — written by scripts/deploy/remote-deploy.sh.
 *
 * Do not commit this file; it lives in ~/staging/shared/ (or production shared/).
 * Env vars MEL_* override when set (same names as settings.mel_shared_session.php).
 */

\$melGetEnv = static function (string \$name): string {
  \$v = getenv(\$name);
  if (is_string(\$v) && \$v !== '') {
    return \$v;
  }
  if (isset(\$_ENV[\$name]) && is_string(\$_ENV[\$name]) && \$_ENV[\$name] !== '') {
    return \$_ENV[\$name];
  }
  if (isset(\$_SERVER[\$name]) && is_string(\$_SERVER[\$name]) && \$_SERVER[\$name] !== '') {
    return \$_SERVER[\$name];
  }
  return '';
};

\$config['myeventlane_core.domain_settings']['public_domain'] =
  \$melGetEnv('MEL_PUBLIC_DOMAIN') ?: '${pub}';
\$config['myeventlane_core.domain_settings']['vendor_domain'] =
  \$melGetEnv('MEL_VENDOR_DOMAIN') ?: '${vendor}';
\$config['myeventlane_core.domain_settings']['admin_domain'] =
  \$melGetEnv('MEL_ADMIN_DOMAIN') ?: '${admin}';
\$config['myeventlane_core.domain_settings']['force_redirects'] =
  \$melGetEnv('MEL_FORCE_DOMAIN_REDIRECTS') !== '0';
PHP

  echo "Wrote domain overrides to $dst (${mode})"
}

# Do not symlink settings.local.php: it is DDEV-only in this project and must
# never override staging/production trusted hosts or domains from shared/.
if [ -f "$SHARED_PATH/settings.local.php" ]; then
  echo "WARNING: $SHARED_PATH/settings.local.php exists but is never symlinked (DDEV-only). Remove it from shared to avoid operator confusion." >&2
fi

if [ -f "$SHARED_PATH/services.yml" ]; then
  rm -f "$DEFAULT_PATH/services.yml"
  ln -sfn "$SHARED_PATH/services.yml" "$DEFAULT_PATH/services.yml"
fi

mel_verify_shared_links

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

# Pre-activation QR secret gate (staging/production only).
# Desired order (this check): Composer artifact ready → bootstrap → QR secret verify → … → activate.
# Note: updb/cim remain post-switch in the established MEL pipeline; this PR does not relocate them.
MEL_DEPLOY_MODE="$(mel_resolve_deploy_mode)"
if [ "$MEL_DEPLOY_MODE" = "production" ] || [ "$MEL_DEPLOY_MODE" = "staging" ]; then
  mel_verify_qr_signing_secret
else
  echo "NOTICE: Skipping pre-activation QR secret verification (set APP_ENV=production|staging, or SITE_URI with staging vs myeventlane.com.au)." >&2
fi

# Capture previous live release before switching (for rollback).
if [ -e "$CURRENT_PATH" ]; then
  MEL_PREVIOUS_CURRENT="$(readlink -f "$CURRENT_PATH" 2>/dev/null || true)"
fi

echo
echo "========== BEFORE MAINTENANCE =========="
echo "Current release:"
echo "${MEL_PREVIOUS_CURRENT:-<none>}"
echo
echo "New release:"
echo "$RELEASE_PATH"
echo
echo "Current release top level:"
ls -la "${MEL_PREVIOUS_CURRENT:-$RELEASE_PATH}" || true
echo
echo "Current release tree:"
find "${MEL_PREVIOUS_CURRENT:-$RELEASE_PATH}" -maxdepth 2 | sort || true
echo
echo "New release top level:"
ls -la "$RELEASE_PATH" || true
echo
echo "New release tree:"
find "$RELEASE_PATH" -maxdepth 2 | sort || true
echo
echo "========================================"
echo

# ---- MAINTENANCE MODE ----
# Prefer the live (previous) release so maintenance covers traffic before the symlink switch.
# If that Drush bootstrap fails, retry from the copied new release rather than aborting.
MEL_MM_ENABLED_ATTEMPTED=1
if [ -n "${MEL_PREVIOUS_CURRENT:-}" ] && [ -f "${MEL_PREVIOUS_CURRENT}/vendor/bin/drush.php" ]; then
  set +e
  ( cd "$MEL_PREVIOUS_CURRENT" && mel_drush_maintenance_mode 1 )
  mel_mm_previous_rc=$?
  set -e
  if [ "$mel_mm_previous_rc" -ne 0 ]; then
    echo "WARNING: Enabling maintenance mode from previous release failed (exit ${mel_mm_previous_rc}); retrying from new release." >&2
    ( cd "$RELEASE_PATH" && mel_drush_maintenance_mode 1 )
  fi
else
  ( cd "$RELEASE_PATH" && mel_drush_maintenance_mode 1 )
fi
# No pre-switch cache rebuild: drush cr on the unreleased tree peaks memory during container
# rebuild; staging CLI defaults are often 128M. Post-switch finalize runs drush cr with mel_drush.

# ---- SWITCH RELEASE (ATOMIC) ----
ln -sfn "$RELEASE_PATH" "$CURRENT_PATH"
MEL_CURRENT_SWITCHED=1
mel_update_web_current_symlink

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

  # Deploy safety: active storage must match sync after import, apart from an
  # explicit target-environment allow-list supplied by the deployment workflow.
  echo "Verifying config:status after import..."
  set +e
  CST_OUT="$(mel_drush cst --fields=name,state --format=csv --uri="$SITE_URI" 2>&1)"
  CST_RC=$?
  set -e
  mel_verify_config_status_output "$CST_OUT" "$CST_RC" "$CIM_ALLOWED_DIFFERENCES"
fi

# ---- DOMAIN CONFIGURATION (environment; not active config / cset) ----
# Effective domain_settings are supplied by settings.php $config overrides from the
# host environment. Shared ~/staging/shared/settings.php must include the override
# block from web/sites/default/settings.php in the repo.
#
# Set on each host (PHP-FPM Environment=, systemd, or platform secret store):
#   MEL_PUBLIC_DOMAIN=https://staging.myeventlane.com.au
#   MEL_VENDOR_DOMAIN=https://vendor.staging.myeventlane.com.au
#   MEL_ADMIN_DOMAIN=https://admin.staging.myeventlane.com.au
#   MEL_FORCE_DOMAIN_REDIRECTS=1   — required on staging (VendorDomainSubscriber redirects)
#
# Production example:
#   MEL_PUBLIC_DOMAIN=https://myeventlane.com.au
#   MEL_VENDOR_DOMAIN=https://vendor.myeventlane.com.au
#   MEL_ADMIN_DOMAIN=https://admin.myeventlane.com.au
#   MEL_FORCE_DOMAIN_REDIRECTS=1   — when apex/vendor/admin redirects must be enforced
#
# Do not drush cset domain URLs here; overrides supersede active storage and cset
# would reintroduce environment-specific values into the database.
mel_domain_effective() {
  local key="$1"
  mel_drush php:eval "
\$c = \\Drupal::config('myeventlane_core.domain_settings');
\$k = '${key}';
if (\$k === 'force_redirects') {
  echo \$c->get('force_redirects') ? '1' : '0';
} else {
  echo (string) \$c->get(\$k);
}
" --uri="$SITE_URI"
}

mel_verify_domain_environment() {
  local mode="$1"
  local pub vendor admin failures=0

  case "$mode" in
    production)
      pub='https://myeventlane.com.au'
      vendor='https://vendor.myeventlane.com.au'
      admin='https://admin.myeventlane.com.au'
      ;;
    staging)
      pub='https://staging.myeventlane.com.au'
      vendor='https://vendor.staging.myeventlane.com.au'
      admin='https://admin.staging.myeventlane.com.au'
      ;;
    *)
      return 0
      ;;
  esac

  echo "Verifying effective domain_settings from host environment (${mode})..."

  local actual
  for pair in "public_domain:$pub" "vendor_domain:$vendor" "admin_domain:$admin"; do
    local key="${pair%%:*}"
    local expected="${pair#*:}"
    set +e
    actual="$(mel_domain_effective "$key")"
    local rc=$?
    set -e
    if [ "$rc" -ne 0 ] || [ "$actual" != "$expected" ]; then
      echo "ERROR: effective myeventlane_core.domain_settings.${key} is '${actual:-<empty>}', expected '${expected}'." >&2
      failures=$((failures + 1))
    fi
  done

  set +e
  actual="$(mel_domain_effective force_redirects)"
  local force_rc=$?
  set -e
  if [ "$force_rc" -ne 0 ] || [ "$actual" != "1" ]; then
    echo "ERROR: effective force_redirects is '${actual:-<empty>}' (expected 1)." >&2
    echo "Set MEL_FORCE_DOMAIN_REDIRECTS=1 in the PHP-FPM / host environment." >&2
    failures=$((failures + 1))
  fi

  if [ "$failures" -gt 0 ]; then
    echo "ERROR: Domain environment misconfigured (${failures} check(s) failed)." >&2
    echo "Configure MEL_PUBLIC_DOMAIN, MEL_VENDOR_DOMAIN, MEL_ADMIN_DOMAIN, and MEL_FORCE_DOMAIN_REDIRECTS on the host." >&2
    return 1
  fi

  echo "Domain environment OK (${mode})."
  return 0
}

MEL_DEPLOY_MODE="$(mel_resolve_deploy_mode)"

if [ "$MEL_DEPLOY_MODE" = "production" ] || [ "$MEL_DEPLOY_MODE" = "staging" ]; then
  mel_write_shared_domain_settings "$MEL_DEPLOY_MODE"
  mel_verify_domain_environment "$MEL_DEPLOY_MODE"
else
  echo "NOTICE: Skipping domain environment verification (set APP_ENV=production|staging, or SITE_URI with staging vs myeventlane.com.au)." >&2
fi

# ---- FINALISE ----
# These drush invocations are strict (no "|| true"): failures must surface in CI/SSH logs.
mel_drush_run "Finalize: drush cr (post-domain verification)" \
  mel_drush cr --uri="$SITE_URI"

mel_drush_run "Finalize: drush state:set system.maintenance_mode 0" \
  mel_drush_maintenance_mode 0

mel_drush_run "Finalize: drush cr (after maintenance off)" \
  mel_drush cr --uri="$SITE_URI"

mel_verify_http_health

DEPLOY_SUCCEEDED=1

echo "Release deployed: $RELEASE_PATH"
echo "Current points to: $(readlink -f "$CURRENT_PATH")"
