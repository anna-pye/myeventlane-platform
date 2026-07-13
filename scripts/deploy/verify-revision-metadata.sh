#!/usr/bin/env bash
# Verify a deployed release REVISION provenance file.
#
# Usage:
#   verify-revision-metadata.sh \
#     <expected_artifact_sha> \
#     <expected_composer_lock_sha256> \
#     <expected_deploy_script_sha256> \
#     [REVISION_FILE]
#
# REVISION_FILE defaults to stdin when omitted.
# Exit 0 on match; exit 1 on any mismatch, missing required field,
# malformed value, or duplicate critical key.
#
# REVISION is parsed as data only — never sourced or evaluated.
set -euo pipefail

EXPECTED_SHA="${1:?expected artifact SHA required}"
EXPECTED_LOCK="${2:?expected composer.lock SHA-256 required}"
EXPECTED_DEPLOY_SCRIPT="${3:?expected deploy script SHA-256 required}"
REVISION_FILE="${4:-/dev/stdin}"

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

# Count exact KEY= lines. Never eval/source REVISION.
mel_key_count() {
  local key="$1"
  printf '%s\n' "$content" | grep -c "^${key}=" || true
}

mel_kv_unique() {
  local key="$1"
  local count
  count="$(mel_key_count "$key")"
  if [ "$count" -gt 1 ]; then
    echo "ERROR: duplicate key in REVISION: ${key}" >&2
    exit 1
  fi
  if [ "$count" -eq 0 ]; then
    printf '%s' ""
    return 0
  fi
  # Data-only extract: strip KEY= prefix from the matching line.
  printf '%s\n' "$content" | sed -n "s/^${key}=//p" | head -1
}

mel_require_hex() {
  local label="$1"
  local value="$2"
  local len="$3"
  if [ -z "$value" ]; then
    echo "ERROR: ${label} missing from REVISION" >&2
    exit 1
  fi
  if ! printf '%s' "$value" | grep -Eq "^[0-9a-f]{${len}}$"; then
    echo "ERROR: ${label} is malformed (expected ${len} lowercase hex chars)" >&2
    echo "  got: ${value}" >&2
    exit 1
  fi
}

# Reject any line that is neither blank, comment, KEY=VALUE, nor legacy plain SHA.
mel_validate_syntax() {
  local line key
  local saw_kv=0
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      ''|'#'*) continue ;;
    esac
    case "$line" in
      *=*)
        key="${line%%=*}"
        if ! printf '%s' "$key" | grep -Eq '^[a-z][a-z0-9_]*$'; then
          echo "ERROR: malformed REVISION key: ${key}" >&2
          exit 1
        fi
        saw_kv=1
        ;;
      *)
        if [ "$saw_kv" -eq 0 ] && printf '%s' "$line" | grep -Eq '^[0-9a-f]{40}$'; then
          # Legacy plain-SHA REVISION (pre KEY=VALUE provenance).
          continue
        fi
        echo "ERROR: malformed REVISION line (expected KEY=VALUE): ${line}" >&2
        exit 1
        ;;
    esac
  done <<EOF
$content
EOF
}

mel_validate_syntax

got_sha=""
if [ "$(mel_key_count artifact_sha)" -gt 0 ]; then
  got_sha="$(mel_kv_unique artifact_sha)"
else
  # Legacy plain-SHA REVISION (pre KEY=VALUE provenance).
  got_sha="$(printf '%s\n' "$content" | head -1 | tr -d '[:space:]')"
fi
mel_require_hex "artifact_sha" "$got_sha" 40

if [ "$got_sha" != "$EXPECTED_SHA" ]; then
  echo "ERROR: artifact_sha mismatch" >&2
  echo "  expected: $EXPECTED_SHA" >&2
  echo "  got:      $got_sha" >&2
  exit 1
fi
echo "OK: artifact_sha matches ${EXPECTED_SHA}"

got_lock="$(mel_kv_unique composer_lock_sha256)"
mel_require_hex "composer_lock_sha256" "$got_lock" 64

if [ "$got_lock" != "$EXPECTED_LOCK" ]; then
  echo "ERROR: composer_lock_sha256 mismatch" >&2
  echo "  expected: $EXPECTED_LOCK" >&2
  echo "  got:      $got_lock" >&2
  exit 1
fi
echo "OK: composer_lock_sha256 matches repository lockfile"

got_deploy="$(mel_kv_unique deploy_script_sha256)"
mel_require_hex "deploy_script_sha256" "$got_deploy" 64

if [ "$got_deploy" != "$EXPECTED_DEPLOY_SCRIPT" ]; then
  echo "ERROR: deploy_script_sha256 mismatch" >&2
  echo "  expected: $EXPECTED_DEPLOY_SCRIPT" >&2
  echo "  got:      $got_deploy" >&2
  echo "  expected source: repository scripts/deploy/remote-deploy.sh at the deployed commit" >&2
  exit 1
fi
echo "OK: deploy_script_sha256 matches repository remote-deploy.sh"

echo "OK: release provenance verified"
