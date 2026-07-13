#!/usr/bin/env bash
# Verify a deployed release REVISION provenance file.
#
# Usage:
#   verify-revision-metadata.sh <expected_artifact_sha> <expected_composer_lock_sha256> [REVISION_FILE]
#
# REVISION_FILE defaults to stdin when omitted.
# Exit 0 on match; exit 1 on any mismatch or missing required field.
set -euo pipefail

EXPECTED_SHA="${1:?expected artifact SHA required}"
EXPECTED_LOCK="${2:?expected composer.lock SHA-256 required}"
REVISION_FILE="${3:-/dev/stdin}"

if [ ! -r "$REVISION_FILE" ] && [ "$REVISION_FILE" != "/dev/stdin" ]; then
  echo "ERROR: cannot read REVISION file: $REVISION_FILE" >&2
  exit 1
fi

content="$(cat "$REVISION_FILE")"
if [ -z "$content" ]; then
  echo "ERROR: REVISION is empty" >&2
  exit 1
fi

echo "===== REVISION UNDER TEST ====="
printf '%s\n' "$content"
echo "==============================="

mel_kv() {
  local key="$1"
  printf '%s\n' "$content" | sed -n "s/^${key}=//p" | head -1
}

got_sha=""
if printf '%s\n' "$content" | grep -q '^artifact_sha='; then
  got_sha="$(mel_kv artifact_sha)"
else
  # Legacy plain-SHA REVISION (pre KEY=VALUE provenance).
  got_sha="$(printf '%s\n' "$content" | head -1 | tr -d '[:space:]')"
fi

if [ -z "$got_sha" ]; then
  echo "ERROR: could not parse artifact_sha from REVISION" >&2
  exit 1
fi

if [ "$got_sha" != "$EXPECTED_SHA" ]; then
  echo "ERROR: artifact_sha mismatch" >&2
  echo "  expected: $EXPECTED_SHA" >&2
  echo "  got:      $got_sha" >&2
  exit 1
fi
echo "OK: artifact_sha matches ${EXPECTED_SHA}"

got_lock="$(mel_kv composer_lock_sha256)"
if [ -z "$got_lock" ]; then
  echo "ERROR: composer_lock_sha256 missing from REVISION" >&2
  exit 1
fi

if [ "$got_lock" != "$EXPECTED_LOCK" ]; then
  echo "ERROR: composer_lock_sha256 mismatch" >&2
  echo "  expected: $EXPECTED_LOCK" >&2
  echo "  got:      $got_lock" >&2
  exit 1
fi
echo "OK: composer_lock_sha256 matches repository lockfile"

echo "OK: release provenance verified"
