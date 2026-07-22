#!/usr/bin/env bash
# mel-sync-main.sh — Synchronise the permanent integration environment.
#
# Target: ~/myeventlane (always origin/main)
# Never discards work. Stops on a dirty tree.
#
# Usage:
#   bash scripts/dev/mel-sync-main.sh
#   bash scripts/dev/mel-sync-main.sh --smoke
#   MEL_INTEGRATION_ROOT=/path/to/integration bash scripts/dev/mel-sync-main.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=mel-common.sh
source "${SCRIPT_DIR}/mel-common.sh"

SMOKE=0
for arg in "$@"; do
  case "${arg}" in
    --smoke) SMOKE=1 ;;
    -h|--help)
      cat <<'EOF'
Usage: mel-sync-main.sh [--smoke]

Synchronise ~/myeventlane to origin/main.

Steps:
  verify repo → clean tree → on main → fetch → pull
  ensure DDEV → composer validate → config safety → webroot safety
  drush cr → optional PHPUnit smoke

Never discards local work. Never force-resets.
EOF
      exit 0
      ;;
    *)
      mel_die "Unknown argument: ${arg} (try --help)"
      ;;
  esac
done

mel_header "MyEventLane · Sync Integration"

ROOT="${MEL_INTEGRATION_ROOT}"
mel_section "Repository"
mel_step "Integration root: ${ROOT}"
mel_require_integration_root
mel_require_git_repo "${ROOT}"
mel_success "Git repository found"

mel_section "Safety gates"
mel_require_clean_tree "${ROOT}" "Integration working tree"
mel_success "Working tree clean"

mel_require_branch "${ROOT}" "main"
mel_success "On main"

mel_section "Update from origin"
mel_step "Fetching origin…"
mel_git "${ROOT}" fetch origin --prune
mel_success "Fetch complete"

mel_step "Pulling origin/main…"
if ! mel_git "${ROOT}" pull --ff-only origin main; then
  mel_error "Fast-forward pull failed."
  mel_info "Integration main may have diverged. Inspect manually — never force-reset."
  mel_info "cd ${ROOT} && git status && git log --oneline --decorate -5"
  exit 1
fi
mel_success "main is up to date with origin/main"

BRANCH="$(mel_current_branch "${ROOT}")"
COMMIT="$(mel_current_commit "${ROOT}")"
COMMIT_MSG="$(mel_git "${ROOT}" log -1 --pretty=format:'%s')"

# Start DDEV before composer validate (prefers ddev exec/composer when .ddev exists).
# Matches mel-finish-feature.sh — a stopped project must not fail validation prematurely.
mel_section "DDEV"
if [[ -d "${ROOT}/.ddev" ]] && mel_ddev_available; then
  mel_ensure_ddev_running "${ROOT}"
fi
DDEV_STATUS="$(mel_ddev_status "${ROOT}" 2>/dev/null || echo "unknown")"

VALIDATION="ok"
mel_section "Validation"
if ! mel_run_step "Composer validate" mel_composer_validate "${ROOT}"; then
  VALIDATION="failed"
fi
if ! mel_run_step "Config safety" mel_check_config_safety "${ROOT}"; then
  VALIDATION="failed"
fi
if ! mel_run_step "Webroot safety" mel_check_webroot_safety "${ROOT}"; then
  VALIDATION="failed"
fi

if [[ "${VALIDATION}" != "ok" ]]; then
  mel_error "Validation failed. Fix issues before UX review."
  exit 1
fi

mel_step "drush cr…"
if ! mel_drush_cr "${ROOT}"; then
  mel_error "drush cr failed"
  exit 1
fi
mel_success "Caches rebuilt"

if [[ "${SMOKE}" -eq 1 ]]; then
  mel_section "Optional PHPUnit smoke"
  if ! mel_run_step "PHPUnit smoke" mel_phpunit_smoke "${ROOT}"; then
    mel_error "Smoke tests failed"
    exit 1
  fi
fi

PRIMARY_URL="$(mel_ddev_primary_url "${ROOT}")"

mel_box \
  "MyEventLane Integration Ready" \
  "Current branch: ${BRANCH}" \
  "Commit:         ${COMMIT} — ${COMMIT_MSG}" \
  "DDEV status:    ${DDEV_STATUS}" \
  "Validation:     ${VALIDATION}" \
  "Primary URL:    ${PRIMARY_URL:-"(see ddev describe)"}" \
  "Ready for UX review"

mel_info "Feature work: use mel-start-feature.sh (not this checkout)."
mel_usage_footer
