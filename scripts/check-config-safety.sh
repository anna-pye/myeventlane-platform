#!/usr/bin/env bash
# CI / pre-deploy check: fail if exported config contains staging hosts,
# QR secrets, or phpinfo references that must not ship to production.
#
# Custom modules may legitimately reference staging hostnames for environment
# detection; the drift risk is in config/sync.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

CONFIG_DIR="config/sync"
FAIL=0

if [ ! -d "$CONFIG_DIR" ]; then
  echo "ERROR: Missing $CONFIG_DIR directory."
  exit 1
fi

grep_config() {
  grep -R --include='*.yml' --include='*.yaml' -n "$@" "$CONFIG_DIR" 2>/dev/null || true
}

check_staging_domain() {
  local domain="$1"
  local matches
  matches="$(grep_config "$domain")"
  if [ -n "$matches" ]; then
    echo "Unsafe staging domain found: $domain"
    echo "$matches"
    FAIL=1
  fi
}

check_staging_domain "staging.myeventlane.com.au"
check_staging_domain "vendor.staging.myeventlane.com.au"
check_staging_domain "admin.staging.myeventlane.com.au"

matches="$(grep_config "qr_secret")"
if [ -n "$matches" ]; then
  echo "QR secret found in config sync."
  echo "$matches"
  FAIL=1
fi

matches="$(grep_config "phpinfo-temp.php")"
if [ -n "$matches" ]; then
  echo "phpinfo-temp.php reference found in config sync."
  echo "$matches"
  FAIL=1
fi

if [ "$FAIL" -ne 0 ]; then
  exit 1
fi

echo "Config safety check passed."
