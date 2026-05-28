#!/usr/bin/env bash
# Run `drush deploy` on staging/production with the same CLI safeguards as remote-deploy.sh.
#
# Usage (from release root, e.g. ~/staging/current):
#   bash scripts/deploy/mel-deploy.sh
#   SITE_URI=https://staging.myeventlane.com.au bash scripts/deploy/mel-deploy.sh
#
# Do not use bare `drush deploy` on memory-constrained hosts: updatedb's default
# cache flush plus deploy's cache:rebuild can exceed 128M CLI and exit 255 after
# printing "[success] No pending updates." (drush/drush.yml disables updatedb cache-clear).
set -euo pipefail

ROOT="${1:-${PWD}}"
SITE_URI="${SITE_URI:-https://staging.myeventlane.com.au}"
MEL_DRUSH_PHP_MEMORY="${MEL_DRUSH_PHP_MEMORY:-1024M}"

if [[ ! -f "$ROOT/vendor/bin/drush.php" ]]; then
  echo "ERROR: vendor/bin/drush.php not found under $ROOT" >&2
  exit 1
fi

cd "$ROOT"

mel_drush() {
  php \
    -d "memory_limit=${MEL_DRUSH_PHP_MEMORY}" \
    -d opcache.enable_cli=0 \
    vendor/bin/drush.php "$@"
}

echo "Drush deploy (uri=${SITE_URI}, memory_limit=${MEL_DRUSH_PHP_MEMORY})"
php -r 'printf("  CLI memory_limit=%s max_execution_time=%s\n", ini_get("memory_limit"), ini_get("max_execution_time"));'

mel_drush status --uri="$SITE_URI" | grep -E 'Drupal bootstrap|Drupal version|Site URI' || {
  echo "ERROR: Drupal bootstrap failed before deploy." >&2
  exit 1
}

mel_drush deploy -y -v --uri="$SITE_URI"

echo "Deploy complete."
