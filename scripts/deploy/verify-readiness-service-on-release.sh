#!/usr/bin/env bash
# Run on the staging host (or locally) to confirm the container can resolve
# myeventlane_surface.state_readiness_helper (defined in myeventlane_core.services.yml).
#
# Usage:
#   bash scripts/deploy/verify-readiness-service-on-release.sh
#   bash scripts/deploy/verify-readiness-service-on-release.sh "$HOME/staging/current"
#
set -euo pipefail

ROOT="${1:-$HOME/staging/current}"
FILE="$ROOT/web/modules/custom/myeventlane_core/myeventlane_core.services.yml"
MIN_SHA="3b6bf8742c9186e16210a71a3f323f1825425d6c"

echo "Release root: $ROOT"
echo "Expected file: $FILE"
echo ""

if [[ ! -d "$ROOT" ]]; then
  echo "ERROR: directory does not exist: $ROOT"
  exit 1
fi

if [[ ! -f "$FILE" ]]; then
  echo "ERROR: myeventlane_core.services.yml not found (wrong ROOT or incomplete deploy)."
  exit 1
fi

echo "--- myeventlane_surface.state_readiness_helper (must appear) ---"
if grep -n 'myeventlane_surface.state_readiness_helper:' "$FILE"; then
  echo "OK: service id is defined in myeventlane_core.services.yml"
else
  echo "FAIL: block myeventlane_surface.state_readiness_helper: missing from $FILE"
  exit 1
fi

echo ""
if [[ -d "$ROOT/.git" ]]; then
  cd "$ROOT"
  HEAD="$(git rev-parse HEAD)"
  echo "GIT_HEAD=$HEAD"
  if git merge-base --is-ancestor "$MIN_SHA" HEAD 2>/dev/null; then
    echo "OK: $MIN_SHA is an ancestor of HEAD"
  else
    echo "WARN: $MIN_SHA is not an ancestor of HEAD (old checkout or forked history)."
  fi
else
  echo "NOTE: no .git under release (normal for CI artifact deploy)."
  echo "      Commit ancestry cannot be checked here; YAML presence above is authoritative."
  echo "      Ensure this tree came from main at or after merge 814228f0 (or commit $MIN_SHA)."
fi

echo ""
echo "verify-readiness-service-on-release: all checks passed"
