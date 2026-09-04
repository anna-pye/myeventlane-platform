#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DEPLOY="$ROOT/scripts/deploy/remote-deploy.sh"
WORKFLOW="$ROOT/.github/workflows/deploy-staging.yml"
WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/mel-stale-deploy-XXXXXX")"
trap 'rm -rf "$WORKDIR"' EXIT

PASSES=0
FAILS=0

write_revision() {
  local path="$1"
  local run_id="$2"
  printf 'artifact_sha=%040d\nworkflow_run=%s\n' 0 "$run_id" > "$path"
}

run_case() {
  local expected_rc="$1"
  local name="$2"
  local current="$3"
  local candidate="$4"
  local output rc

  set +e
  output="$(
    MEL_TEST_STALE_RELEASE_GUARD=1 \
    MEL_TEST_CURRENT_REVISION="$current" \
    MEL_TEST_CANDIDATE_REVISION="$candidate" \
    bash "$DEPLOY" 2>&1
  )"
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

CURRENT="$WORKDIR/current.REVISION"
OLDER="$WORKDIR/older.REVISION"
SAME="$WORKDIR/same.REVISION"
NEWER="$WORKDIR/newer.REVISION"
MISSING="$WORKDIR/missing.REVISION"
LEGACY="$WORKDIR/legacy.REVISION"
DUPLICATE="$WORKDIR/duplicate.REVISION"

write_revision "$CURRENT" 200
write_revision "$OLDER" 199
write_revision "$SAME" 200
write_revision "$NEWER" 201
printf 'artifact_sha=%040d\n' 0 > "$MISSING"
printf 'artifact_sha=%040d\n' 0 > "$LEGACY"
printf 'artifact_sha=%040d\nworkflow_run=200\nworkflow_run=bad\n' 0 > "$DUPLICATE"

run_case 1 "older workflow is rejected" "$CURRENT" "$OLDER"
run_case 0 "same workflow can be retried" "$CURRENT" "$SAME"
run_case 0 "newer workflow is accepted" "$CURRENT" "$NEWER"
run_case 1 "candidate without workflow provenance fails closed" "$CURRENT" "$MISSING"
run_case 1 "duplicate workflow provenance fails closed" "$CURRENT" "$DUPLICATE"
run_case 0 "legacy current release permits a versioned candidate" "$LEGACY" "$NEWER"
run_case 0 "first deployment permits a versioned candidate" "$WORKDIR/not-present" "$NEWER"

if ! grep -Fq 'gh api "repos/${GITHUB_REPOSITORY}/commits/main" --jq .sha' "$WORKFLOW"; then
  echo "FAIL: staging workflow must compare the queued commit with current main" >&2
  FAILS=$((FAILS + 1))
else
  echo "PASS: staging workflow checks current main before deploy"
  PASSES=$((PASSES + 1))
fi

if ! grep -Fq "needs.freshness.outputs.deploy == 'true'" "$WORKFLOW"; then
  echo "FAIL: deploy job must be gated by the freshness result" >&2
  FAILS=$((FAILS + 1))
else
  echo "PASS: deploy job is gated by the freshness result"
  PASSES=$((PASSES + 1))
fi

echo "Passed: $PASSES"
echo "Failed: $FAILS"
if [ "$FAILS" -ne 0 ]; then
  exit 1
fi

echo "OK: stale deployment guard tests passed"
