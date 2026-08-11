#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DEPLOY="$ROOT/scripts/deploy/remote-deploy.sh"
PASSES=0
FAILS=0

run_case() {
  local expected_rc="$1"
  local name="$2"
  local input="$3"
  local status_rc="${4:-0}"
  local allowed="${5:-}"
  local output rc

  set +e
  output="$(printf '%s' "$input" | \
    MEL_TEST_CONFIG_STATUS_OUTPUT=1 \
    MEL_TEST_CONFIG_STATUS_RC="$status_rc" \
    MEL_TEST_CONFIG_STATUS_ALLOWED="$allowed" \
    bash "$DEPLOY" 2>&1)"
  rc=$?
  set -e

  if [ "$rc" -eq "$expected_rc" ]; then
    PASSES=$((PASSES + 1))
    echo "PASS: $name"
  else
    FAILS=$((FAILS + 1))
    echo "FAIL: $name (expected rc $expected_rc, got $rc)"
    printf '%s\n' "$output" | sed 's/^/  /'
  fi
}

run_case 0 \
  "notice and CSV header are ignored when configuration is clean" \
  $' [notice] No differences between DB and sync directory.\nName,State\n'

run_case 1 \
  "genuine unexpected configuration difference fails" \
  $'Name,State\ngin.settings,Different\n'

run_case 0 \
  "explicitly allowed configuration difference passes" \
  $'Name,State\ngin.settings,Different\n' \
  0 \
  'gin.settings'

run_case 7 \
  "config status command failure is preserved" \
  $'Drush bootstrap failed\n' \
  7

run_case 1 \
  "missing CSV header fails closed" \
  $' [notice] No differences between DB and sync directory.\n'

echo "Passed: $PASSES"
echo "Failed: $FAILS"
if [ "$FAILS" -ne 0 ]; then
  exit 1
fi

echo "OK: deploy config-status parser tests passed"
