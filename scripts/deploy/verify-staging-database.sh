#!/usr/bin/env bash
# Run on the staging host (SSH) to diagnose deploy failures:
#   SQLSTATE[HY000] [2002] Connection refused
#
# Usage:
#   bash scripts/deploy/verify-staging-database.sh
#   SITE_URI=https://staging.myeventlane.com.au bash scripts/deploy/verify-staging-database.sh /home/mel/staging/current
set -euo pipefail

ROOT="${1:-${HOME}/staging/current}"
SITE_URI="${SITE_URI:-https://staging.myeventlane.com.au}"

if [[ ! -d "$ROOT" ]]; then
  echo "ERROR: release directory not found: $ROOT"
  exit 1
fi

if [[ ! -x "$ROOT/vendor/bin/drush" ]]; then
  echo "ERROR: drush not found under $ROOT"
  exit 1
fi

cd "$ROOT"

echo "Release: $(readlink -f .)"
echo "SITE_URI: $SITE_URI"
echo ""

echo "=== Drush status ==="
vendor/bin/drush status --uri="$SITE_URI" || true
echo ""

echo "=== SQL connectivity (SELECT 1) ==="
set +e
SQL_OUT="$(vendor/bin/drush sql:query "SELECT 1" --uri="$SITE_URI" 2>&1)"
SQL_RC=$?
set -e
printf '%s\n' "$SQL_OUT"
if [[ "$SQL_RC" -ne 0 ]]; then
  echo ""
  echo "FAIL: database not reachable from CLI."
  echo "If the public site loads but this fails, check whether settings.php uses"
  echo "host 127.0.0.1 (TCP) vs localhost (socket) and whether MariaDB listens on TCP."
  echo ""
  echo "Suggested host checks:"
  command -v systemctl >/dev/null 2>&1 && systemctl is-active mariadb 2>/dev/null || systemctl is-active mysql 2>/dev/null || true
  exit "$SQL_RC"
fi

echo "OK: database reachable"
