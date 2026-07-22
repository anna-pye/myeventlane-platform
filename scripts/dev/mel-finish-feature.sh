#!/usr/bin/env bash
# mel-finish-feature.sh — Prepare a feature worktree for PR.
#
# Runs validation gates. Stops on failure. Never pushes automatically.
#
# Usage (from a feature worktree):
#   bash scripts/dev/mel-finish-feature.sh
#
# Skip long gates when needed:
#   MEL_SKIP_PHPUNIT=1 MEL_SKIP_PHPCS=1 bash scripts/dev/mel-finish-feature.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=mel-common.sh
source "${SCRIPT_DIR}/mel-common.sh"

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  cat <<'EOF'
Usage: mel-finish-feature.sh

Run from a feature worktree (~/myeventlane-wt-*).

Gates:
  composer validate, PHPCS, PHPUnit, drush cr,
  config status, webroot safety, push validation,
  git status, diff summary, commit list

Never pushes. Never merges.
EOF
  exit 0
fi

ROOT="$(pwd -P)"
mel_header "MyEventLane · Finish Feature"

mel_section "Workspace checks"
mel_require_git_repo "${ROOT}"

# Soft guidance if someone runs this in integration.
RESOLVED_INTEGRATION="$(cd "${MEL_INTEGRATION_ROOT}" 2>/dev/null && pwd -P || true)"
if [[ -n "${RESOLVED_INTEGRATION}" && "${ROOT}" == "${RESOLVED_INTEGRATION}" ]]; then
  mel_error "You are in the integration checkout (${ROOT})."
  mel_info "Finish features from a ~/myeventlane-wt-* worktree."
  exit 1
fi

BRANCH="$(mel_current_branch "${ROOT}")"
if [[ -z "${BRANCH}" ]]; then
  mel_die "Detached HEAD — checkout a feature branch first."
fi
case "${BRANCH}" in
  main)
    mel_error "Refusing to finish on main."
    mel_info "Feature work belongs on feature/* (or fix/*) branches."
    exit 1
    ;;
esac
mel_success "Branch: ${BRANCH}"
mel_info "Commit: $(mel_current_commit "${ROOT}")"
mel_info "Tracking: $(mel_ahead_behind "${ROOT}")"

FAILURES=0
fail() {
  FAILURES=$((FAILURES + 1))
}

mel_section "Validation gates"

if ! mel_run_step "Composer validate" mel_composer_validate "${ROOT}"; then
  fail
fi

if [[ "${MEL_SKIP_PHPCS:-0}" == "1" ]]; then
  mel_warn "Skipping PHPCS (MEL_SKIP_PHPCS=1)"
else
  mel_git "${ROOT}" fetch origin main --quiet 2>/dev/null || true
  if ! mel_run_step "PHPCS" mel_phpcs_changed_or_default "${ROOT}" "origin/main"; then
    fail
  fi
fi

if [[ "${MEL_SKIP_PHPUNIT:-0}" == "1" ]]; then
  mel_warn "Skipping PHPUnit (MEL_SKIP_PHPUNIT=1)"
else
  PHPUNIT_TARGET="${MEL_PHPUNIT_TARGET:-}"
  if [[ -z "${PHPUNIT_TARGET}" ]]; then
    CHANGED_TESTS="$(mel_git "${ROOT}" diff --name-only --diff-filter=ACMR origin/main...HEAD -- 'web/modules/custom/*/tests/**/*.php' 2>/dev/null | head -20 || true)"
    if [[ -n "${CHANGED_TESTS}" ]]; then
      FIRST="$(printf '%s\n' "${CHANGED_TESTS}" | head -1)"
      PHPUNIT_TARGET="$(printf '%s' "${FIRST}" | sed -E 's#(web/modules/custom/[^/]+/tests/src/[^/]+).*#\1#')"
    else
      PHPUNIT_TARGET="web/modules/custom/myeventlane_api/tests/src/Unit"
    fi
  fi
  mel_info "PHPUnit target: ${PHPUNIT_TARGET}"
  if ! mel_run_step "PHPUnit" mel_phpunit_smoke "${ROOT}" "${PHPUNIT_TARGET}"; then
    fail
  fi
fi

if [[ "${MEL_SKIP_DRUSH:-0}" == "1" ]]; then
  mel_warn "Skipping Drush gates (MEL_SKIP_DRUSH=1)"
else
  mel_ensure_ddev_running "${ROOT}"
  if ! mel_run_step "drush cr" mel_drush_cr "${ROOT}"; then
    fail
  fi
  mel_step "Config status"
  if mel_drush_config_status "${ROOT}"; then
    mel_success "Config status completed (review any drift above)"
  else
    mel_error "Config status failed"
    fail
  fi
fi

if ! mel_run_step "Webroot safety" mel_check_webroot_safety "${ROOT}"; then
  fail
fi
if ! mel_run_step "Config safety" mel_check_config_safety "${ROOT}"; then
  fail
fi

mel_section "Push validation (compose scripts/validate-push.sh)"
if ! mel_working_tree_clean "${ROOT}"; then
  mel_warn "Working tree is dirty — push validation will fail until you commit."
  mel_git "${ROOT}" status --short | head -30
  fail
else
  if ! mel_run_step "validate-push.sh" mel_validate_push "${ROOT}"; then
    fail
  fi
fi

mel_section "Git summary"
echo
mel_git "${ROOT}" status
echo
mel_step "Diffstat vs origin/main"
mel_git "${ROOT}" fetch origin main --quiet 2>/dev/null || true
mel_git "${ROOT}" diff --stat origin/main...HEAD 2>/dev/null || mel_git "${ROOT}" diff --stat main...HEAD || true
echo
mel_step "Commits not in origin/main"
mel_git "${ROOT}" log --oneline origin/main..HEAD 2>/dev/null || mel_git "${ROOT}" log --oneline main..HEAD || true
echo

if [[ "${FAILURES}" -gt 0 ]]; then
  mel_box \
    "Finish feature: BLOCKED" \
    "Failures: ${FAILURES}" \
    "Fix the gates above, then re-run." \
    "Never push a red finish-feature."
  exit 1
fi

mel_box \
  "Finish feature: READY FOR PR" \
  "Branch: ${BRANCH}" \
  "Push when ready (manual):" \
  "  git push -u origin ${BRANCH}" \
  "Then open a PR against main." \
  "This script does not push."

mel_usage_footer
