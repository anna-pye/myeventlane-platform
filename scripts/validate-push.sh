#!/usr/bin/env bash
# MEL ordinary branch push validation.
#
# Purpose:
# - Gate local `git push` with cleanliness and lightweight safety checks.
# - Allow legitimate review/maintenance branches to be pushed for PR review.
#
# This script is NOT a staging or production release validator.
# It does not claim the checkout is ready to deploy.
# Strict deployment allowlists remain in scripts/validate-release.sh.
#
# Usage:
#   bash scripts/validate-push.sh
#   bash scripts/validate-push.sh --check-branch chore/example
#   bash scripts/validate-push.sh --help
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

CHECK_BRANCH_ONLY=0
BRANCH_OVERRIDE=""
declare -a REASONS=()

usage() {
  cat <<'EOF'
Usage:
  scripts/validate-push.sh
  scripts/validate-push.sh --check-branch <name>

Push validation (ordinary review pushes):
  - Requires a clean working tree
  - Accepts: main, release/*, feature/*, fix/*, hotfix/*, cursor/*,
              chore/*, docs/*, test/*
  - Runs lightweight safety checks (composer validate + config/webroot/card checks)
  - Does not run Drush, config drift review, governance suites, or theme builds
  - Does not write release metadata
  - Does not treat the branch as a staging deployment candidate

Deployment validation remains:
  bash scripts/validate-release.sh staging
  bash scripts/validate-release.sh production

--check-branch <name>
  Non-destructive allowlist check only (no cleanliness or quality gates).
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --check-branch)
      if [ -z "${2:-}" ]; then
        echo "ERROR: --check-branch requires a branch name." >&2
        usage >&2
        exit 2
      fi
      CHECK_BRANCH_ONLY=1
      BRANCH_OVERRIDE="$2"
      shift
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

add_reason() {
  REASONS+=("$1")
}

print_rule() {
  echo "----------------------------------------"
}

# Review / maintenance branches may be pushed for PR review.
# Staging/production deploy allowlists are intentionally stricter and live in
# scripts/validate-release.sh — do not merge those policies here.
#
# Prefix rationale:
#   main, release/*, feature/*, fix/*, hotfix/*, cursor/*
#     Existing MEL collaboration / agent prefixes already accepted for review.
#   chore/*
#     Documented in CLAUDE.md / AGENTS.md; used for tooling and maintenance
#     (e.g. local PHPUnit helper, CI hygiene). Not a deploy candidate by itself.
#   docs/*
#     Documentation / help-content branches already used on origin.
#   test/*
#     Isolated test-harness or fixture review branches; not deployment targets.
is_push_branch() {
  case "$1" in
    main|release/*|feature/*|fix/*|hotfix/*|cursor/*|chore/*|docs/*|test/*)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
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

print_failure_and_exit() {
  echo ""
  print_rule
  echo "PUSH VALIDATION FAILED"
  echo ""
  echo "Reasons:"
  for reason in "${REASONS[@]}"; do
    echo "- ${reason}"
  done
  echo ""
  echo "Note: This gate validates ordinary branch pushes for review."
  echo "It does not certify staging or production deployment readiness."
  echo "For deployment candidates run: bash scripts/validate-release.sh staging|production"
  print_rule
  exit 1
}

print_rule
echo "MEL Push Validation"
print_rule
echo ""
echo "Mode: ordinary branch push (not a staging/production deploy check)"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  add_reason "Git metadata is unavailable; push validation requires a repository checkout."
  print_failure_and_exit
fi

if [ -n "$BRANCH_OVERRIDE" ]; then
  CURRENT_BRANCH="$BRANCH_OVERRIDE"
else
  CURRENT_BRANCH="$(git branch --show-current 2>/dev/null || true)"
  if [ -z "$CURRENT_BRANCH" ]; then
    CURRENT_BRANCH="DETACHED"
  fi
fi

echo "Current branch: ${CURRENT_BRANCH}"

if [ "$CURRENT_BRANCH" = "DETACHED" ]; then
  add_reason "Push validation rejects detached HEAD. Check out a named review branch."
elif ! is_push_branch "$CURRENT_BRANCH"; then
  add_reason "Push validation rejects branch '${CURRENT_BRANCH}'. Allowed: main, release/*, feature/*, fix/*, hotfix/*, cursor/*, chore/*, docs/*, test/*."
fi

if [ "$CHECK_BRANCH_ONLY" = "1" ]; then
  if [ "${#REASONS[@]}" -gt 0 ]; then
    print_failure_and_exit
  fi
  echo ""
  print_rule
  echo "PUSH BRANCH POLICY: PASS"
  print_rule
  exit 0
fi

if [ "${#REASONS[@]}" -gt 0 ]; then
  print_failure_and_exit
fi

GIT_STATUS="$(git status --porcelain 2>/dev/null || true)"
if [ -n "$GIT_STATUS" ]; then
  print_git_cleanliness
  add_reason "Working tree is not clean. Commit, stash, relocate, or ignore local artefacts before pushing."
  print_failure_and_exit
fi

FAILURES=0
run_and_report "Composer validate" composer validate || FAILURES=$((FAILURES + 1))
run_and_report "Config safety check" bash scripts/check-config-safety.sh || FAILURES=$((FAILURES + 1))
run_and_report "Webroot safety check" bash scripts/check-webroot-safety.sh || FAILURES=$((FAILURES + 1))
run_and_report "Raw card data safety check" bash scripts/check-no-raw-card-data.sh || FAILURES=$((FAILURES + 1))

if [ "$FAILURES" -ne 0 ] || [ "${#REASONS[@]}" -gt 0 ]; then
  print_failure_and_exit
fi

echo ""
print_rule
echo "PUSH VALIDATION PASSED"
echo ""
echo "Ordinary review push checks succeeded."
echo "Staging/production deployment still requires:"
echo "  bash scripts/validate-release.sh staging|production"
print_rule
exit 0
