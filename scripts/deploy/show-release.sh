#!/usr/bin/env bash
# Display and optionally verify MyEventLane release provenance.
#
# Usage:
#   show-release.sh [--path PATH] [--verify] [--quiet] [--format text] [--help]
#   show-release.sh [PATH]
#
# Default path is the current working directory when it contains REVISION,
# otherwise deployed-release metadata is reported as unavailable (repository
# Git metadata may still be shown). Never sources REVISION as shell.
set -euo pipefail

FORMAT="text"
VERIFY=0
QUIET=0
RELEASE_PATH=""
SHOW_HELP=0

mel_usage() {
  cat <<'EOF'
Usage:
  show-release.sh [--path PATH] [--verify] [--quiet] [--format text] [--help]
  show-release.sh [PATH]

Inspect MyEventLane release provenance (REVISION). Never modifies files.

Options:
  --path PATH     Release directory containing REVISION (default: cwd if present)
  --verify        Verify local release consistency against REVISION checksums
  --quiet         Suppress normal output; exit status only
  --format text   Output format (text is the only supported format)
  --help          Show this help

Exit codes:
  0  success (display and/or verification passed)
  1  verification failed or fatal error
  2  usage error
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --path)
      if [ $# -lt 2 ]; then
        echo "ERROR: --path requires an argument" >&2
        exit 2
      fi
      RELEASE_PATH="$2"
      shift 2
      ;;
    --verify)
      VERIFY=1
      shift
      ;;
    --quiet)
      QUIET=1
      shift
      ;;
    --format)
      if [ $# -lt 2 ]; then
        echo "ERROR: --format requires an argument" >&2
        exit 2
      fi
      FORMAT="$2"
      shift 2
      ;;
    --help|-h)
      SHOW_HELP=1
      shift
      ;;
    --)
      shift
      break
      ;;
    -*)
      echo "ERROR: unknown option: $1" >&2
      mel_usage >&2
      exit 2
      ;;
    *)
      if [ -n "$RELEASE_PATH" ]; then
        echo "ERROR: multiple release paths specified" >&2
        exit 2
      fi
      RELEASE_PATH="$1"
      shift
      ;;
  esac
done

if [ "$SHOW_HELP" -eq 1 ]; then
  mel_usage
  exit 0
fi

if [ "$FORMAT" != "text" ]; then
  echo "ERROR: unsupported --format '${FORMAT}' (only 'text' is supported)" >&2
  exit 2
fi

mel_out() {
  if [ "$QUIET" -eq 0 ]; then
    printf '%s\n' "$*"
  fi
}

mel_sha256_file() {
  local file="$1"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$file" | awk '{print $1}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$file" | awk '{print $1}'
  else
    echo "ERROR: neither sha256sum nor shasum is available" >&2
    return 1
  fi
}

mel_is_hex() {
  local value="$1"
  local len="$2"
  printf '%s' "$value" | grep -Eq "^[0-9a-f]{${len}}$"
}

# Resolve release path default.
if [ -z "$RELEASE_PATH" ]; then
  if [ -f "./REVISION" ]; then
    RELEASE_PATH="$(pwd -P)"
  else
    RELEASE_PATH=""
  fi
fi

REVISION_FILE=""
HAS_DEPLOYED_REVISION=0
if [ -n "$RELEASE_PATH" ]; then
  if [ ! -d "$RELEASE_PATH" ]; then
    echo "ERROR: release path is not a directory: ${RELEASE_PATH}" >&2
    exit 1
  fi
  REVISION_FILE="${RELEASE_PATH%/}/REVISION"
  if [ -f "$REVISION_FILE" ]; then
    HAS_DEPLOYED_REVISION=1
  fi
fi

# Repository Git context (never pretend a checkout is a deployed release).
REPO_ROOT=""
REPO_SHA="unavailable"
REPO_BRANCH="unavailable"
if REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_SHA="$(git -C "$REPO_ROOT" rev-parse HEAD 2>/dev/null || echo unavailable)"
  REPO_BRANCH="$(git -C "$REPO_ROOT" branch --show-current 2>/dev/null || echo unavailable)"
fi

CONTENT=""
PARSE_STATUS="unavailable"
ARTIFACT_SHA=""
BRANCH=""
TAG=""
WORKFLOW=""
WORKFLOW_RUN=""
RUN_ATTEMPT=""
ACTOR=""
REPOSITORY=""
BUILD_TIME_UTC=""
COMPOSER_LOCK_SHA256=""
DEPLOY_SCRIPT_SHA256=""
RELEASE_IDENTIFIER=""
DEPLOY_TIME_UTC=""
RELEASE_DIR=""

mel_key_count() {
  local key="$1"
  printf '%s\n' "$CONTENT" | grep -c "^${key}=" || true
}

mel_kv() {
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
  printf '%s\n' "$CONTENT" | sed -n "s/^${key}=//p" | head -1
}

mel_parse_revision_file() {
  local line key
  local saw_kv=0
  CONTENT="$(cat "$REVISION_FILE")"
  if [ -z "$CONTENT" ]; then
    echo "ERROR: REVISION is empty: ${REVISION_FILE}" >&2
    exit 1
  fi

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
        if [ "$saw_kv" -eq 0 ] && mel_is_hex "$line" 40; then
          continue
        fi
        echo "ERROR: malformed REVISION line (expected KEY=VALUE): ${line}" >&2
        exit 1
        ;;
    esac
  done <<EOF
$CONTENT
EOF

  if [ "$(mel_key_count artifact_sha)" -gt 0 ]; then
    ARTIFACT_SHA="$(mel_kv artifact_sha)"
  else
    ARTIFACT_SHA="$(printf '%s\n' "$CONTENT" | head -1 | tr -d '[:space:]')"
  fi

  BRANCH="$(mel_kv branch)"
  TAG="$(mel_kv tag)"
  WORKFLOW="$(mel_kv workflow)"
  WORKFLOW_RUN="$(mel_kv workflow_run)"
  RUN_ATTEMPT="$(mel_kv run_attempt)"
  ACTOR="$(mel_kv actor)"
  REPOSITORY="$(mel_kv repository)"
  BUILD_TIME_UTC="$(mel_kv build_time_utc)"
  COMPOSER_LOCK_SHA256="$(mel_kv composer_lock_sha256)"
  DEPLOY_SCRIPT_SHA256="$(mel_kv deploy_script_sha256)"
  RELEASE_IDENTIFIER="$(mel_kv release_identifier)"
  DEPLOY_TIME_UTC="$(mel_kv deploy_time_utc)"
  RELEASE_DIR="$(mel_kv release_dir)"
  PARSE_STATUS="valid"
}

field_or_unavailable() {
  local value="$1"
  if [ -z "$value" ]; then
    printf '%s' "unavailable"
  else
    printf '%s' "$value"
  fi
}

branch_or_tag() {
  if [ -n "$TAG" ]; then
    printf '%s' "$TAG"
  elif [ -n "$BRANCH" ]; then
    printf '%s' "$BRANCH"
  else
    printf '%s' "unavailable"
  fi
}

VERIFY_REVISION="unavailable"
VERIFY_COMPOSER="unavailable"
VERIFY_DEPLOY="unavailable"
VERIFY_ARTIFACT_FORMAT="unavailable"
VERIFY_GIT="not applicable"
VERIFY_DRUPAL="not checked"

mel_verify_local() {
  local lock_path script_path got

  if [ "$HAS_DEPLOYED_REVISION" -ne 1 ]; then
    echo "ERROR: cannot --verify without a deployed REVISION file" >&2
    exit 1
  fi

  VERIFY_REVISION="$PARSE_STATUS"
  if [ "$PARSE_STATUS" != "valid" ]; then
    echo "ERROR: REVISION format is not valid" >&2
    exit 1
  fi

  if [ -z "$ARTIFACT_SHA" ]; then
    VERIFY_ARTIFACT_FORMAT="mismatch"
    echo "ERROR: artifact_sha missing" >&2
    exit 1
  fi
  if mel_is_hex "$ARTIFACT_SHA" 40; then
    VERIFY_ARTIFACT_FORMAT="verified"
  else
    VERIFY_ARTIFACT_FORMAT="mismatch"
    echo "ERROR: artifact_sha is malformed (expected 40 lowercase hex chars)" >&2
    echo "  got: ${ARTIFACT_SHA}" >&2
    exit 1
  fi

  lock_path="${RELEASE_PATH%/}/composer.lock"
  if [ -z "$COMPOSER_LOCK_SHA256" ]; then
    VERIFY_COMPOSER="unavailable"
    echo "ERROR: composer_lock_sha256 missing from REVISION" >&2
    exit 1
  fi
  if ! mel_is_hex "$COMPOSER_LOCK_SHA256" 64; then
    VERIFY_COMPOSER="mismatch"
    echo "ERROR: composer_lock_sha256 is malformed" >&2
    exit 1
  fi
  if [ ! -f "$lock_path" ]; then
    VERIFY_COMPOSER="unavailable"
    echo "ERROR: composer.lock not found at ${lock_path}" >&2
    exit 1
  fi
  got="$(mel_sha256_file "$lock_path")"
  if [ "$got" != "$COMPOSER_LOCK_SHA256" ]; then
    VERIFY_COMPOSER="mismatch"
    echo "ERROR: composer.lock SHA-256 mismatch" >&2
    echo "  expected: $COMPOSER_LOCK_SHA256" >&2
    echo "  got:      $got" >&2
    exit 1
  fi
  VERIFY_COMPOSER="verified"

  script_path="${RELEASE_PATH%/}/scripts/deploy/remote-deploy.sh"
  if [ -z "$DEPLOY_SCRIPT_SHA256" ]; then
    VERIFY_DEPLOY="unavailable"
    echo "ERROR: deploy_script_sha256 missing from REVISION" >&2
    exit 1
  fi
  if ! mel_is_hex "$DEPLOY_SCRIPT_SHA256" 64; then
    VERIFY_DEPLOY="mismatch"
    echo "ERROR: deploy_script_sha256 is malformed" >&2
    exit 1
  fi
  if [ ! -f "$script_path" ]; then
    VERIFY_DEPLOY="unavailable"
    echo "ERROR: remote-deploy.sh not found at ${script_path}" >&2
    exit 1
  fi
  got="$(mel_sha256_file "$script_path")"
  if [ "$got" != "$DEPLOY_SCRIPT_SHA256" ]; then
    VERIFY_DEPLOY="mismatch"
    echo "ERROR: deploy script SHA-256 mismatch" >&2
    echo "  expected: $DEPLOY_SCRIPT_SHA256" >&2
    echo "  got:      $got" >&2
    echo "  checked:  ${script_path}" >&2
    exit 1
  fi
  VERIFY_DEPLOY="verified"
}

if [ "$HAS_DEPLOYED_REVISION" -eq 1 ]; then
  mel_parse_revision_file
else
  PARSE_STATUS="unavailable"
fi

if [ "$VERIFY" -eq 1 ]; then
  mel_verify_local
fi

# Display
mel_out "MyEventLane Release"
mel_out ""
if [ -n "$RELEASE_PATH" ]; then
  mel_out "Release path:              ${RELEASE_PATH}"
else
  mel_out "Release path:              unavailable"
fi

if [ "$HAS_DEPLOYED_REVISION" -eq 1 ]; then
  mel_out "Release identifier:        $(field_or_unavailable "$RELEASE_IDENTIFIER")"
  mel_out "Git artifact SHA:          $(field_or_unavailable "$ARTIFACT_SHA")"
  mel_out "Branch or tag:             $(branch_or_tag)"
  mel_out "Repository:                $(field_or_unavailable "$REPOSITORY")"
  mel_out "Workflow:                  $(field_or_unavailable "$WORKFLOW")"
  mel_out "Workflow run:              $(field_or_unavailable "$WORKFLOW_RUN")"
  mel_out "Run attempt:               $(field_or_unavailable "$RUN_ATTEMPT")"
  mel_out "Actor:                     $(field_or_unavailable "$ACTOR")"
  mel_out "Build time UTC:            $(field_or_unavailable "$BUILD_TIME_UTC")"
  mel_out "Deploy time UTC:           $(field_or_unavailable "$DEPLOY_TIME_UTC")"
  mel_out "Composer lock SHA-256:     $(field_or_unavailable "$COMPOSER_LOCK_SHA256")"
  mel_out "Deploy script SHA-256:     $(field_or_unavailable "$DEPLOY_SCRIPT_SHA256")"
  mel_out "Release directory:         $(field_or_unavailable "$RELEASE_DIR")"
else
  mel_out "Deployed-release metadata: unavailable"
  mel_out "(This checkout is not a deployed release with REVISION.)"
  if [ -n "$REPO_ROOT" ]; then
    mel_out "Repository root:           ${REPO_ROOT}"
    mel_out "Repository HEAD:           ${REPO_SHA}"
    mel_out "Repository branch:         ${REPO_BRANCH}"
  fi
fi

mel_out ""
mel_out "Verification"
mel_out ""
if [ "$VERIFY" -eq 1 ]; then
  mel_out "REVISION format:           ${VERIFY_REVISION}"
  mel_out "artifact_sha format:       ${VERIFY_ARTIFACT_FORMAT}"
  mel_out "Composer lock:             ${VERIFY_COMPOSER}"
  mel_out "Deploy script:             ${VERIFY_DEPLOY}"
else
  if [ "$HAS_DEPLOYED_REVISION" -eq 1 ]; then
    mel_out "REVISION format:           ${PARSE_STATUS}"
    mel_out "Composer lock:             not checked (pass --verify)"
    mel_out "Deploy script:             not checked (pass --verify)"
  else
    mel_out "REVISION format:           unavailable"
    mel_out "Composer lock:             unavailable"
    mel_out "Deploy script:             unavailable"
  fi
fi
mel_out "Git checkout:              ${VERIFY_GIT}"
mel_out "Drupal bootstrap:          ${VERIFY_DRUPAL}"

exit 0
