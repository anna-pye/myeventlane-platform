#!/usr/bin/env bash
# Focused tests for release provenance scripts (no jq, no network).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERIFY="$ROOT/deploy/verify-revision-metadata.sh"
SHOW="$ROOT/deploy/show-release.sh"
FAILS=0
PASSES=0

mel_sha256_file() {
  local file="$1"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$file" | awk '{print $1}'
  else
    shasum -a 256 "$file" | awk '{print $1}'
  fi
}

assert_ok() {
  local name="$1"
  shift
  if "$@" >/tmp/mel-prov-test.out 2>/tmp/mel-prov-test.err; then
    PASSES=$((PASSES + 1))
    echo "PASS: $name"
  else
    FAILS=$((FAILS + 1))
    echo "FAIL: $name (expected success)"
    sed 's/^/  stdout: /' /tmp/mel-prov-test.out || true
    sed 's/^/  stderr: /' /tmp/mel-prov-test.err || true
  fi
}

assert_fail() {
  local name="$1"
  shift
  if "$@" >/tmp/mel-prov-test.out 2>/tmp/mel-prov-test.err; then
    FAILS=$((FAILS + 1))
    echo "FAIL: $name (expected failure)"
    sed 's/^/  stdout: /' /tmp/mel-prov-test.out || true
  else
    PASSES=$((PASSES + 1))
    echo "PASS: $name"
  fi
}

assert_stderr_not_contains() {
  local name="$1"
  local needle="$2"
  if grep -Fq "$needle" /tmp/mel-prov-test.err /tmp/mel-prov-test.out 2>/dev/null; then
    FAILS=$((FAILS + 1))
    echo "FAIL: $name (output unexpectedly contained: $needle)"
  else
    PASSES=$((PASSES + 1))
    echo "PASS: $name"
  fi
}

WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/mel-prov-XXXXXX")"
trap 'rm -rf "$WORKDIR"' EXIT

# Space in path must be handled safely.
SPACE_DIR="$WORKDIR/release with spaces"
mkdir -p "$SPACE_DIR/scripts/deploy"
cp "$ROOT/deploy/remote-deploy.sh" "$SPACE_DIR/scripts/deploy/remote-deploy.sh"
printf 'lock-content-for-test\n' > "$SPACE_DIR/composer.lock"

LOCK_SHA="$(mel_sha256_file "$SPACE_DIR/composer.lock")"
SCRIPT_SHA="$(mel_sha256_file "$SPACE_DIR/scripts/deploy/remote-deploy.sh")"
ARTIFACT_SHA="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"

write_revision() {
  local dest="$1"
  cat > "$dest" <<EOF
artifact_sha=${ARTIFACT_SHA}
branch=main
tag=
workflow=Deploy Staging
workflow_run=1234567890
run_attempt=1
actor=test-actor
repository=anna-pye/myeventlane-platform
build_time_utc=2026-07-13T08:40:00Z
composer_lock_sha256=${LOCK_SHA}
deploy_script_sha256=${SCRIPT_SHA}
release_identifier=1234567890.1
deploy_time_utc=2026-07-13T08:45:12Z
release_dir=20260713084512
EOF
}

write_revision "$SPACE_DIR/REVISION"

echo "=== verify-revision-metadata.sh ==="

assert_ok "valid REVISION passes" \
  "$VERIFY" "$ARTIFACT_SHA" "$LOCK_SHA" "$SCRIPT_SHA" "$SPACE_DIR/REVISION"

assert_fail "missing REVISION fails" \
  "$VERIFY" "$ARTIFACT_SHA" "$LOCK_SHA" "$SCRIPT_SHA" "$SPACE_DIR/missing-REVISION"

MISSING_KEY="$WORKDIR/missing-key.REVISION"
write_revision "$MISSING_KEY"
# Drop deploy_script_sha256
grep -v '^deploy_script_sha256=' "$MISSING_KEY" > "$MISSING_KEY.tmp"
mv "$MISSING_KEY.tmp" "$MISSING_KEY"
assert_fail "missing required deploy_script_sha256 fails" \
  "$VERIFY" "$ARTIFACT_SHA" "$LOCK_SHA" "$SCRIPT_SHA" "$MISSING_KEY"

DUP_KEY="$WORKDIR/dup.REVISION"
write_revision "$DUP_KEY"
printf 'deploy_script_sha256=%s\n' "$SCRIPT_SHA" >> "$DUP_KEY"
assert_fail "duplicate critical key fails" \
  "$VERIFY" "$ARTIFACT_SHA" "$LOCK_SHA" "$SCRIPT_SHA" "$DUP_KEY"

BAD_SHA="$WORKDIR/bad-sha.REVISION"
write_revision "$BAD_SHA"
sed -i.bak 's/^deploy_script_sha256=.*/deploy_script_sha256=not-a-sha/' "$BAD_SHA"
assert_fail "malformed deploy_script_sha256 fails" \
  "$VERIFY" "$ARTIFACT_SHA" "$LOCK_SHA" "$SCRIPT_SHA" "$BAD_SHA"

BAD_LOCK_META="$WORKDIR/bad-lock.REVISION"
write_revision "$BAD_LOCK_META"
sed -i.bak "s/^composer_lock_sha256=.*/composer_lock_sha256=$(printf 'b%.0s' {1..64})/" "$BAD_LOCK_META"
assert_fail "composer_lock_sha256 mismatch fails" \
  "$VERIFY" "$ARTIFACT_SHA" "$LOCK_SHA" "$SCRIPT_SHA" "$BAD_LOCK_META"

BAD_DEPLOY_META="$WORKDIR/bad-deploy.REVISION"
write_revision "$BAD_DEPLOY_META"
sed -i.bak "s/^deploy_script_sha256=.*/deploy_script_sha256=$(printf 'c%.0s' {1..64})/" "$BAD_DEPLOY_META"
assert_fail "deploy_script_sha256 mismatch fails" \
  "$VERIFY" "$ARTIFACT_SHA" "$LOCK_SHA" "$SCRIPT_SHA" "$BAD_DEPLOY_META"

OPTIONAL="$WORKDIR/optional.REVISION"
cat > "$OPTIONAL" <<EOF
artifact_sha=${ARTIFACT_SHA}
composer_lock_sha256=${LOCK_SHA}
deploy_script_sha256=${SCRIPT_SHA}
EOF
assert_ok "optional fields may be absent" \
  "$VERIFY" "$ARTIFACT_SHA" "$LOCK_SHA" "$SCRIPT_SHA" "$OPTIONAL"

# Values must never be evaluated as shell.
EVIL="$WORKDIR/evil.REVISION"
EVIL_MARKER="$WORKDIR/pwned-marker"
rm -f "$EVIL_MARKER"
cat > "$EVIL" <<EOF
artifact_sha=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
composer_lock_sha256=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
deploy_script_sha256=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
actor=\$(touch '$EVIL_MARKER'; echo evil)
EOF
# Mismatch expected (hashes wrong) — but side effect must not run.
assert_fail "evil actor value is not executed (mismatch path)" \
  "$VERIFY" "$ARTIFACT_SHA" "$LOCK_SHA" "$SCRIPT_SHA" "$EVIL"
if [ -e "$EVIL_MARKER" ]; then
  FAILS=$((FAILS + 1))
  echo "FAIL: evil actor value executed shell (marker file created)"
else
  PASSES=$((PASSES + 1))
  echo "PASS: evil actor value produced no shell side effect"
fi

echo "=== show-release.sh ==="

assert_ok "show-release displays metadata" \
  "$SHOW" --path "$SPACE_DIR"

assert_ok "show-release --verify succeeds for consistent release" \
  "$SHOW" --path "$SPACE_DIR" --verify --quiet

assert_fail "show-release --verify fails without REVISION" \
  "$SHOW" --path "$WORKDIR" --verify --quiet

# Composer lock mismatch under show-release --verify
printf 'changed-lock\n' > "$SPACE_DIR/composer.lock"
assert_fail "show-release composer lock mismatch fails" \
  "$SHOW" --path "$SPACE_DIR" --verify --quiet

# Restore lock and break deploy script
printf 'lock-content-for-test\n' > "$SPACE_DIR/composer.lock"
printf 'tampered-script\n' > "$SPACE_DIR/scripts/deploy/remote-deploy.sh"
assert_fail "show-release deploy script mismatch fails" \
  "$SHOW" --path "$SPACE_DIR" --verify --quiet

# Shell injection via REVISION under show-release
EVIL_RELEASE="$WORKDIR/evil release"
EVIL_SHOW_MARKER="$WORKDIR/pwned-show-marker"
rm -f "$EVIL_SHOW_MARKER"
mkdir -p "$EVIL_RELEASE"
cat > "$EVIL_RELEASE/REVISION" <<EOF
artifact_sha=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
composer_lock_sha256=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
deploy_script_sha256=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
actor=\$(touch '$EVIL_SHOW_MARKER'; echo evil)
EOF
assert_ok "show-release parses evil actor as data" \
  "$SHOW" --path "$EVIL_RELEASE"
if [ -e "$EVIL_SHOW_MARKER" ]; then
  FAILS=$((FAILS + 1))
  echo "FAIL: show-release executed actor value (marker file created)"
else
  PASSES=$((PASSES + 1))
  echo "PASS: show-release does not execute actor value"
fi

echo ""
echo "Passed: $PASSES"
echo "Failed: $FAILS"
if [ "$FAILS" -ne 0 ]; then
  exit 1
fi
echo "OK: release provenance tests passed"
