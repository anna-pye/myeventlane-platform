#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DEPLOY="$ROOT/scripts/deploy/remote-deploy.sh"

line_number() {
  local pattern="$1"
  grep -nF "$pattern" "$DEPLOY" | head -1 | cut -d: -f1
}

switch_line="$(line_number 'cd "$CURRENT_PATH"')"
rebuild_line="$(line_number 'mel_drush_run "Post-switch: drush cr before updates/config import"')"
updates_line="$(line_number 'if [ "$RUN_UPDB" = "1" ]; then')"
import_line="$(line_number 'if [ "$RUN_CIM" = "1" ]; then')"

if [ -z "$switch_line" ] || [ -z "$rebuild_line" ] || [ -z "$updates_line" ] || [ -z "$import_line" ]; then
  echo "FAIL: unable to locate the release switch, cache rebuild, update, or config import markers" >&2
  exit 1
fi

if [ "$rebuild_line" -le "$switch_line" ]; then
  echo "FAIL: cache rebuild must run after activating the new release" >&2
  exit 1
fi

if [ "$rebuild_line" -ge "$updates_line" ] || [ "$rebuild_line" -ge "$import_line" ]; then
  echo "FAIL: cache rebuild must run before database updates and config import" >&2
  exit 1
fi

echo "OK: post-switch cache rebuild runs before updates and config import"
