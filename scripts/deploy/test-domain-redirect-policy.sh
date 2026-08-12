#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DEPLOY="$ROOT/scripts/deploy/remote-deploy.sh"
STAGING_WORKFLOW="$ROOT/.github/workflows/deploy-staging.yml"

assert_contains() {
  local file="$1"
  local expected="$2"
  local description="$3"

  if ! grep -Fq -- "$expected" "$file"; then
    echo "FAIL: $description" >&2
    echo "Expected to find: $expected" >&2
    exit 1
  fi
  echo "PASS: $description"
}

assert_contains \
  "$STAGING_WORKFLOW" \
  "MEL_FORCE_DOMAIN_REDIRECTS=0" \
  "staging workflow explicitly disables forced cross-domain redirects"

assert_contains \
  "$DEPLOY" \
  '\$melGetEnv('\''MEL_FORCE_DOMAIN_REDIRECTS'\'') === '\''1'\'';' \
  "generated shared settings fail closed unless redirects are explicitly enabled"

assert_contains \
  "$DEPLOY" \
  "expected_force='0'" \
  "staging deployment verification requires redirects to remain disabled"

assert_contains \
  "$DEPLOY" \
  "expected_force='1'" \
  "production deployment verification retains its existing redirect requirement"

echo "OK: deploy domain redirect policy tests passed"
