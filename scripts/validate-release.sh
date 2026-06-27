#!/usr/bin/env bash
# Canonical MEL pre-deployment validation.
#
# This script validates the current checkout only. It does not deploy, import
# config, run database updates, or mutate Drupal state.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

SITE_URI="${SITE_URI:-}"
MEL_DRUSH_PHP_MEMORY="${MEL_DRUSH_PHP_MEMORY:-1024M}"
TARGET="staging"
VALIDATOR_VERSION="1.1.0"
FORCE=0
declare -a DRUSH_CMD=()
declare -a DRUSH_URI_ARGS=()
declare -a REASONS=()

usage() {
  cat <<'EOF'
Usage:
  scripts/validate-release.sh staging
  scripts/validate-release.sh production
  scripts/validate-release.sh production --force

Targets:
  staging     Accepts main, release/*, feature/*, fix/*, hotfix/*, cursor/*.
  production  Requires main or an annotated tag. Non-main branches require --force.
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    staging|production)
      TARGET="$1"
      ;;
    --force)
      FORCE=1
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "ERROR: Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
  shift
done

BRANCH_STATUS="PASS"
DRUPAL_STATUS="NOT RUN"
CONFIG_STATUS="NOT RUN"
DATABASE_STATUS="NOT RUN"
GOVERNANCE_STATUS="NOT RUN"
TESTS_STATUS="NOT RUN"
BUILD_STATUS="NOT RUN"
METADATA_STATUS="NOT WRITTEN"
REMOTE_TRACKING_BRANCH=""
REMOTE_AHEAD=""
REMOTE_BEHIND=""
DRUPAL_VERSION=""
PHP_VERSION=""
DRUSH_VERSION=""
DETECTED_SITE_URI=""

print_rule() {
  echo "----------------------------------------"
}

print_summary_row() {
  local label="$1"
  local status="$2"
  local width=22
  local dot_count

  dot_count=$((width - ${#label}))
  if [ "$dot_count" -lt 1 ]; then
    dot_count=1
  fi

  printf '%s ' "$label"
  printf '%*s' "$dot_count" '' | tr ' ' '.'
  printf ' %s\n' "$status"
}

target_label() {
  case "$TARGET" in
    staging)
      echo "STAGING"
      ;;
    production)
      echo "PRODUCTION"
      ;;
    *)
      echo "$TARGET"
      ;;
  esac
}

add_reason() {
  REASONS+=("$1")
}

mask_sensitive() {
  sed -E \
    -e 's/sk_(live|test)_[A-Za-z0-9_]+/[MASKED_STRIPE_SECRET]/g' \
    -e 's/rk_(live|test)_[A-Za-z0-9_]+/[MASKED_STRIPE_RESTRICTED_KEY]/g' \
    -e 's/pk_(live|test)_[A-Za-z0-9_]+/[MASKED_STRIPE_PUBLISHABLE_KEY]/g' \
    -e 's/whsec_[A-Za-z0-9_]+/[MASKED_STRIPE_WEBHOOK_SECRET]/g' \
    -e 's/((secret|SECRET|api[_-]?key|API[_-]?KEY|token|TOKEN)[^:=]*[=:][[:space:]]*)[^[:space:]]+/\1[MASKED]/g'
}

run_and_report() {
  local label="$1"
  shift

  echo ""
  echo "== ${label} =="

  local tmp rc
  tmp="$(mktemp)"
  "$@" >"$tmp" 2>&1
  rc=$?
  mask_sensitive <"$tmp"
  rm -f "$tmp"

  if [ "$rc" -ne 0 ]; then
    add_reason "${label} failed."
    return "$rc"
  fi

  return 0
}

configure_drush() {
  DRUSH_URI_ARGS=()
  if [ -n "$SITE_URI" ]; then
    DRUSH_URI_ARGS=(--uri="$SITE_URI")
  fi

  if command -v ddev >/dev/null 2>&1 && [ -d ".ddev" ]; then
    DRUSH_CMD=(ddev drush)
    return 0
  fi

  if [ -f "vendor/bin/drush.php" ]; then
    DRUSH_CMD=(php -d "memory_limit=${MEL_DRUSH_PHP_MEMORY}" -d opcache.enable_cli=0 vendor/bin/drush.php)
    return 0
  fi

  if [ -x "vendor/bin/drush" ]; then
    DRUSH_CMD=(vendor/bin/drush)
    return 0
  fi

  return 1
}

drush_capture() {
  if [ "${#DRUSH_URI_ARGS[@]}" -gt 0 ]; then
    "${DRUSH_CMD[@]}" "$@" "${DRUSH_URI_ARGS[@]}"
  else
    "${DRUSH_CMD[@]}" "$@"
  fi
}

is_staging_branch() {
  case "$1" in
    main|release/*|feature/*|fix/*|hotfix/*|cursor/*)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

annotated_tag_at_head() {
  local tag
  tag="$(git describe --exact-match --tags HEAD 2>/dev/null || true)"
  if [ -z "$tag" ]; then
    return 1
  fi

  if [ "$(git cat-file -t "refs/tags/${tag}" 2>/dev/null || true)" = "tag" ]; then
    printf '%s\n' "$tag"
    return 0
  fi

  return 1
}

upstream_summary() {
  if [ -z "$REMOTE_TRACKING_BRANCH" ]; then
    echo "No upstream configured"
    return 0
  fi

  if [ -z "$REMOTE_AHEAD" ] || [ -z "$REMOTE_BEHIND" ]; then
    echo "${REMOTE_TRACKING_BRANCH}: unable to compare"
    return 0
  fi

  echo "${REMOTE_TRACKING_BRANCH}: ahead ${REMOTE_AHEAD}, behind ${REMOTE_BEHIND}"
}

resolve_upstream_metadata() {
  local counts

  REMOTE_TRACKING_BRANCH="$(git rev-parse --abbrev-ref --symbolic-full-name '@{upstream}' 2>/dev/null || true)"
  REMOTE_AHEAD=""
  REMOTE_BEHIND=""

  if [ -z "$REMOTE_TRACKING_BRANCH" ]; then
    return 0
  fi

  counts="$(git rev-list --left-right --count "${REMOTE_TRACKING_BRANCH}...HEAD" 2>/dev/null || true)"
  if [ -z "$counts" ]; then
    return 0
  fi

  REMOTE_BEHIND="${counts%%[[:space:]]*}"
  REMOTE_AHEAD="${counts##*[[:space:]]}"
}

print_git_cleanliness() {
  local modified untracked
  modified="$(git status --porcelain 2>/dev/null | grep -Ev '^\?\?' || true)"
  untracked="$(git status --porcelain 2>/dev/null | grep -E '^\?\?' || true)"

  echo ""
  echo "Modified files:"
  if [ -n "$modified" ]; then
    printf '%s\n' "$modified"
  else
    echo "(none)"
  fi

  echo ""
  echo "Untracked files:"
  if [ -n "$untracked" ]; then
    printf '%s\n' "$untracked"
  else
    echo "(none)"
  fi
}

evaluate_target_policy() {
  local annotated_tag=""

  case "$TARGET" in
    staging)
      if [ "$CURRENT_BRANCH" = "DETACHED" ]; then
        BRANCH_STATUS="FAIL"
        add_reason "Staging validation rejects detached HEAD."
      elif ! is_staging_branch "$CURRENT_BRANCH"; then
        BRANCH_STATUS="FAIL"
        add_reason "Staging validation rejects branch '${CURRENT_BRANCH}'. Allowed: main, release/*, feature/*, fix/*, hotfix/*, cursor/*."
      fi
      ;;
    production)
      if [ "$CURRENT_BRANCH" = "main" ]; then
        return 0
      fi

      annotated_tag="$(annotated_tag_at_head || true)"
      if [ -n "$annotated_tag" ]; then
        BRANCH_STATUS="PASS (annotated tag ${annotated_tag})"
        return 0
      fi

      if [ "$FORCE" = "1" ] && [ "$CURRENT_BRANCH" != "DETACHED" ]; then
        BRANCH_STATUS="PASS (forced)"
        return 0
      fi

      BRANCH_STATUS="FAIL"
      if [ "$CURRENT_BRANCH" = "DETACHED" ]; then
        add_reason "Production validation requires main or an annotated tag; detached HEAD is not an annotated tag."
      else
        add_reason "Production validation requires main or an annotated tag. Branch '${CURRENT_BRANCH}' is rejected without --force."
      fi
      ;;
  esac
}

print_not_ready_and_exit() {
  echo ""
  print_rule
  print_summary_row "Git" "$BRANCH_STATUS"
  print_summary_row "Drupal" "$DRUPAL_STATUS"
  print_summary_row "Config" "$CONFIG_STATUS"
  print_summary_row "Database" "$DATABASE_STATUS"
  print_summary_row "Governance" "$GOVERNANCE_STATUS"
  print_summary_row "Tests" "$TESTS_STATUS"
  print_summary_row "Build" "$BUILD_STATUS"
  print_summary_row "Metadata" "$METADATA_STATUS"
  print_rule
  echo ""
  echo "NOT READY FOR $(target_label)"
  echo ""
  echo "Reasons:"
  for reason in "${REASONS[@]}"; do
    echo "- ${reason}"
  done
  print_rule
  exit 1
}

metadata_status() {
  case "$1" in
    PASS*)
      echo "pass"
      ;;
    WARN*)
      echo "warn"
      ;;
    *)
      echo "fail"
      ;;
  esac
}

drush_status_value() {
  local label="$1"
  local status_output="$2"

  printf '%s\n' "$status_output" | awk -F ':' -v label="$label" '
    {
      key = $1
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", key)
      if (tolower(key) == tolower(label)) {
        sub(/^[^:]*:[[:space:]]*/, "", $0)
        gsub(/^[[:space:]]+|[[:space:]]+$/, "", $0)
        print $0
        exit
      }
    }
  '
}

write_release_metadata() {
  local metadata_file="build/release-metadata.json"
  local tmp validated_at_utc

  validated_at_utc="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
  tmp="$(mktemp)"

  mkdir -p build || {
    rm -f "$tmp"
    return 1
  }

  if ! php -r '
$nullableString = static fn (string $value): ?string => $value !== "" ? $value : null;
$nullableInt = static fn (string $value): ?int => $value !== "" ? (int) $value : null;
$metadata = [
    "validator_version" => $argv[1],
    "target" => $argv[2],
    "branch" => $argv[3],
    "commit" => $argv[4],
    "commit_message" => $argv[5],
    "remote_tracking_branch" => $nullableString($argv[6]),
    "ahead" => $nullableInt($argv[7]),
    "behind" => $nullableInt($argv[8]),
    "validated_at_utc" => $argv[9],
    "drupal_version" => $nullableString($argv[10]),
    "php_version" => $nullableString($argv[11]),
    "drush_version" => $nullableString($argv[12]),
    "site_uri" => $nullableString($argv[13]),
    "drupal_status" => $argv[14],
    "database_status" => $argv[15],
    "config_status" => $argv[16],
    "governance_status" => $argv[17],
    "tests_status" => $argv[18],
    "build_status" => $argv[19],
];
$json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "Failed to encode release metadata JSON.\n");
    exit(1);
}
echo $json, PHP_EOL;
' \
    "$VALIDATOR_VERSION" \
    "$TARGET" \
    "$CURRENT_BRANCH" \
    "$CURRENT_SHA" \
    "$CURRENT_MESSAGE" \
    "$REMOTE_TRACKING_BRANCH" \
    "$REMOTE_AHEAD" \
    "$REMOTE_BEHIND" \
    "$validated_at_utc" \
    "$DRUPAL_VERSION" \
    "$PHP_VERSION" \
    "$DRUSH_VERSION" \
    "$DETECTED_SITE_URI" \
    "$(metadata_status "$DRUPAL_STATUS")" \
    "$(metadata_status "$DATABASE_STATUS")" \
    "$(metadata_status "$CONFIG_STATUS")" \
    "$(metadata_status "$GOVERNANCE_STATUS")" \
    "$(metadata_status "$TESTS_STATUS")" \
    "$(metadata_status "$BUILD_STATUS")" >"$tmp"; then
    rm -f "$tmp"
    return 1
  fi

  mv "$tmp" "$metadata_file" || {
    rm -f "$tmp"
    return 1
  }

  METADATA_STATUS="WRITTEN"
  echo ""
  echo "Release metadata written: ${metadata_file}"
}

print_rule
echo "MEL Release Validation"
print_rule
echo ""

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  BRANCH_STATUS="FAIL"
  add_reason "Git metadata is unavailable; release validation requires a repository checkout so branch, tag, commit, and cleanliness can be verified."
  print_not_ready_and_exit
fi

CURRENT_BRANCH="$(git branch --show-current 2>/dev/null || true)"
if [ -z "$CURRENT_BRANCH" ]; then
  CURRENT_BRANCH="DETACHED"
fi
CURRENT_SHA="$(git rev-parse HEAD 2>/dev/null || true)"
CURRENT_SHORT_SHA="$(git rev-parse --short HEAD 2>/dev/null || true)"
CURRENT_MESSAGE="$(git log -1 --pretty=%s 2>/dev/null || true)"
resolve_upstream_metadata
UPSTREAM_STATUS="$(upstream_summary)"

echo "Target:          ${TARGET}"
echo "Force:           ${FORCE}"
echo "Current branch:  ${CURRENT_BRANCH}"
echo "Current commit:  ${CURRENT_SHA}"
echo "Commit message:  ${CURRENT_MESSAGE}"
echo "Remote status:   ${UPSTREAM_STATUS}"

evaluate_target_policy

GIT_STATUS="$(git status --porcelain 2>/dev/null || true)"
if [ -n "$GIT_STATUS" ]; then
  print_git_cleanliness
  BRANCH_STATUS="FAIL"
  add_reason "Working tree is not clean."
  print_not_ready_and_exit
fi

if ! configure_drush; then
  DRUPAL_STATUS="FAIL"
  add_reason "Drush is unavailable; expected DDEV Drush locally or vendor/bin/drush.php on a release host."
else
  echo ""
  echo "Drush command: ${DRUSH_CMD[*]}${SITE_URI:+ --uri=${SITE_URI}}"

  echo ""
  echo "== Drupal Bootstrap =="
  DRUPAL_OUT="$(drush_capture status 2>&1)"
  DRUPAL_RC=$?
  printf '%s\n' "$DRUPAL_OUT" | mask_sensitive
  DRUPAL_VERSION="$(drush_status_value "Drupal version" "$DRUPAL_OUT")"
  PHP_VERSION="$(drush_status_value "PHP version" "$DRUPAL_OUT")"
  DRUSH_VERSION="$(drush_status_value "Drush version" "$DRUPAL_OUT")"
  DETECTED_SITE_URI="$(drush_status_value "Site URI" "$DRUPAL_OUT")"
  if [ -z "$DETECTED_SITE_URI" ]; then
    DETECTED_SITE_URI="$SITE_URI"
  fi
  if [ "$DRUPAL_RC" -eq 0 ] && printf '%s\n' "$DRUPAL_OUT" | grep -qE 'Drupal bootstrap[[:space:]]*:[[:space:]]*Successful'; then
    DRUPAL_STATUS="PASS"
  else
    DRUPAL_STATUS="FAIL"
    add_reason "Drupal did not bootstrap successfully."
  fi

  echo ""
  echo "== Config Status =="
  CONFIG_OUT="$(drush_capture config:status 2>&1)"
  CONFIG_RC=$?
  printf '%s\n' "$CONFIG_OUT" | mask_sensitive

  if [ "$CONFIG_RC" -ne 0 ] && ! printf '%s\n' "$CONFIG_OUT" | grep -Eiq 'No differences|configuration differences|Only in|Different|Missing|New'; then
    CONFIG_STATUS="FAIL"
    add_reason "drush config:status failed."
  elif printf '%s\n' "$CONFIG_OUT" | grep -Eiq 'No differences'; then
    CONFIG_STATUS="PASS"
  else
    ENV_CONFIG="$(printf '%s\n' "$CONFIG_OUT" | grep -E 'myeventlane_core\.domain_settings' || true)"
    STRIPE_CONFIG="$(printf '%s\n' "$CONFIG_OUT" | grep -Ei 'commerce_payment\.commerce_payment_gateway|commerce_stripe|stripe' || true)"
    UNEXPECTED_CONFIG="$(printf '%s\n' "$CONFIG_OUT" \
      | grep -Eiv 'myeventlane_core\.domain_settings|commerce_payment\.commerce_payment_gateway|commerce_stripe|stripe|configuration differences|^$|^[[:space:]-]+$|^[[:space:]]*Name[[:space:]]|^[[:space:]]*Collection[[:space:]]' \
      || true)"

    if [ -n "$ENV_CONFIG" ]; then
      echo ""
      echo "Expected environment differences:"
      printf '%s\n' "$ENV_CONFIG" | mask_sensitive
    fi

    if [ -n "$STRIPE_CONFIG" ]; then
      echo ""
      echo "Stripe/payment gateway differences (review separately; not automatically treated as errors):"
      printf '%s\n' "$STRIPE_CONFIG" | mask_sensitive
    fi

    if [ -n "$UNEXPECTED_CONFIG" ]; then
      echo ""
      echo "Unexpected configuration drift:"
      printf '%s\n' "$UNEXPECTED_CONFIG" | mask_sensitive
      CONFIG_STATUS="FAIL"
      add_reason "Unexpected configuration drift detected."
    elif [ -n "$STRIPE_CONFIG" ]; then
      CONFIG_STATUS="WARN"
      echo ""
      echo "Config:"
      echo "WARN"
      echo ""
      echo "Reason:"
      echo "Environment-specific Stripe payment gateway overrides detected."
      echo "Review only."
      echo "Do not export automatically."
    elif [ -n "$ENV_CONFIG" ]; then
      CONFIG_STATUS="PASS (Environment overrides detected)"
    else
      CONFIG_STATUS="PASS"
    fi
  fi

  echo ""
  echo "== Database Update Status =="
  UPDB_OUT="$(drush_capture updatedb:status 2>&1)"
  UPDB_RC=$?
  printf '%s\n' "$UPDB_OUT" | mask_sensitive
  if [ "$UPDB_RC" -ne 0 ]; then
    DATABASE_STATUS="FAIL"
    add_reason "drush updatedb:status failed."
  elif printf '%s\n' "$UPDB_OUT" | grep -Eiq 'No pending updates|No database updates required|No updates required'; then
    DATABASE_STATUS="PASS"
  else
    DATABASE_STATUS="FAIL"
    add_reason "Pending database updates detected."
  fi
fi

TEST_FAILURES=0
run_and_report "Composer validate" composer validate || TEST_FAILURES=$((TEST_FAILURES + 1))
run_and_report "Config safety check" bash scripts/check-config-safety.sh || TEST_FAILURES=$((TEST_FAILURES + 1))
run_and_report "Webroot safety check" bash scripts/check-webroot-safety.sh || TEST_FAILURES=$((TEST_FAILURES + 1))
run_and_report "Raw card data safety check" bash scripts/check-no-raw-card-data.sh || TEST_FAILURES=$((TEST_FAILURES + 1))

if [ "$TEST_FAILURES" -eq 0 ]; then
  TESTS_STATUS="PASS"
else
  TESTS_STATUS="FAIL"
fi

GOVERNANCE_FAILURES=0
run_and_report "Governance audit" composer run-script governance:audit || GOVERNANCE_FAILURES=$((GOVERNANCE_FAILURES + 1))
run_and_report "Governance tests" composer run-script governance:test || GOVERNANCE_FAILURES=$((GOVERNANCE_FAILURES + 1))

if [ "$GOVERNANCE_FAILURES" -eq 0 ]; then
  GOVERNANCE_STATUS="PASS"
else
  GOVERNANCE_STATUS="FAIL"
fi

BUILD_FAILURES=0
run_and_report "MEL lint" npm run mel:lint || BUILD_FAILURES=$((BUILD_FAILURES + 1))
run_and_report "MEL build" npm run mel:build || BUILD_FAILURES=$((BUILD_FAILURES + 1))

if [ "$BUILD_FAILURES" -eq 0 ]; then
  BUILD_STATUS="PASS"
else
  BUILD_STATUS="FAIL"
fi

if ! write_release_metadata; then
  add_reason "Release metadata could not be written."
fi

echo ""
print_rule
if [ "${#REASONS[@]}" -eq 0 ]; then
  print_summary_row "Git" "$BRANCH_STATUS"
  print_summary_row "Drupal" "$DRUPAL_STATUS"
  print_summary_row "Config" "$CONFIG_STATUS"
  print_summary_row "Database" "$DATABASE_STATUS"
  print_summary_row "Governance" "$GOVERNANCE_STATUS"
  print_summary_row "Tests" "$TESTS_STATUS"
  print_summary_row "Build" "$BUILD_STATUS"
  print_summary_row "Metadata" "$METADATA_STATUS"
  print_rule
  echo ""
  echo "READY FOR $(target_label)"
  echo ""
  echo "Metadata:"
  echo "build/release-metadata.json"
else
  print_summary_row "Git" "$BRANCH_STATUS"
  print_summary_row "Drupal" "$DRUPAL_STATUS"
  print_summary_row "Config" "$CONFIG_STATUS"
  print_summary_row "Database" "$DATABASE_STATUS"
  print_summary_row "Governance" "$GOVERNANCE_STATUS"
  print_summary_row "Tests" "$TESTS_STATUS"
  print_summary_row "Build" "$BUILD_STATUS"
  print_summary_row "Metadata" "$METADATA_STATUS"
  print_rule
  echo ""
  echo "NOT READY FOR $(target_label)"
fi

if [ "${#REASONS[@]}" -gt 0 ]; then
  echo ""
  echo "Reasons:"
  for reason in "${REASONS[@]}"; do
    echo "- ${reason}"
  done
fi
print_rule

if [ "${#REASONS[@]}" -gt 0 ]; then
  exit 1
fi

exit 0
