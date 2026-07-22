#!/usr/bin/env bash
# mel-start-feature.sh — Create a feature branch + worktree workspace.
#
# Usage:
#   bash scripts/dev/mel-start-feature.sh vx2-tickets
#   bash scripts/dev/mel-start-feature.sh feature/mel-vx2-tickets
#
# Creates:
#   branch:  feature/mel-<slug>
#   worktree: ~/myeventlane-wt-<slug>
#
# Does not overwrite existing branches or worktrees.
# Does not work inside ~/myeventlane for feature coding.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=mel-common.sh
source "${SCRIPT_DIR}/mel-common.sh"

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" || -z "${1:-}" ]]; then
  cat <<'EOF'
Usage: mel-start-feature.sh <slug>

Examples:
  mel-start-feature.sh vx2-tickets
  mel-start-feature.sh apple-wallet-poster

Creates branch feature/mel-<slug> and worktree ~/myeventlane-wt-<slug>
from a clean, up-to-date integration main.
EOF
  [[ -z "${1:-}" ]] && exit 1
  exit 0
fi

SLUG="$(mel_normalize_feature_slug "$1")"
BRANCH="$(mel_branch_name_from_slug "${SLUG}")"
WORKTREE="$(mel_worktree_path_from_slug "${SLUG}")"
INTEGRATION="${MEL_INTEGRATION_ROOT}"

mel_header "MyEventLane · Start Feature"
mel_info "Slug:     ${SLUG}"
mel_info "Branch:   ${BRANCH}"
mel_info "Worktree: ${WORKTREE}"

mel_section "Integration preflight"
mel_require_integration_root
mel_require_git_repo "${INTEGRATION}"
mel_require_clean_tree "${INTEGRATION}" "Integration working tree"

CURRENT="$(mel_current_branch "${INTEGRATION}")"
if [[ "${CURRENT}" != "main" ]]; then
  mel_error "Integration is not on main (currently: ${CURRENT})."
  mel_info "Run: bash scripts/dev/mel-sync-main.sh"
  mel_info "Or switch ~/myeventlane to main after parking other work."
  exit 1
fi
mel_success "Integration on main"

mel_step "Fetching origin…"
mel_git "${INTEGRATION}" fetch origin --prune
mel_success "Fetch complete"

# Prefer origin/main as the base tip.
BASE_REF="origin/main"
if ! mel_git "${INTEGRATION}" rev-parse --verify "${BASE_REF}" >/dev/null 2>&1; then
  BASE_REF="main"
fi

# Refuse collisions.
if mel_git "${INTEGRATION}" show-ref --verify --quiet "refs/heads/${BRANCH}"; then
  mel_error "Branch already exists: ${BRANCH}"
  mel_info "Choose a new slug, or resume: cd ${WORKTREE} 2>/dev/null || git -C ${INTEGRATION} worktree list"
  exit 1
fi
if mel_git "${INTEGRATION}" ls-remote --exit-code --heads origin "${BRANCH}" >/dev/null 2>&1; then
  mel_error "Remote branch already exists: origin/${BRANCH}"
  mel_info "Fetch/checkout that branch instead of creating a duplicate."
  exit 1
fi
if [[ -e "${WORKTREE}" ]]; then
  mel_error "Worktree path already exists: ${WORKTREE}"
  mel_info "Remove or rename it only after confirming it is safe."
  exit 1
fi

mel_section "Create branch + worktree"
mel_step "Creating ${BRANCH} from ${BASE_REF}…"
mel_git "${INTEGRATION}" branch "${BRANCH}" "${BASE_REF}"
mel_success "Branch created"

mel_step "Adding worktree at ${WORKTREE}…"
mel_git "${INTEGRATION}" worktree add "${WORKTREE}" "${BRANCH}"
mel_success "Worktree ready"

if [[ -d "${WORKTREE}/.ddev" ]]; then
  mel_section "DDEV worktree isolation"
  mel_write_worktree_ddev_local "${WORKTREE}" "${SLUG}"
  mel_ensure_ddev_running "${WORKTREE}"
else
  mel_warn "No .ddev directory in worktree — skip DDEV start."
fi

PRIMARY_URL="$(mel_ddev_primary_url "${WORKTREE}" 2>/dev/null || true)"
VENDOR_HINT=""
if [[ -n "${PRIMARY_URL}" ]]; then
  VENDOR_HINT="$(printf '%s' "${PRIMARY_URL}" | sed -E 's#https://#https://vendor.#')"
fi

mel_box \
  "Feature workspace ready" \
  "Branch:   ${BRANCH}" \
  "Path:     ${WORKTREE}" \
  "Primary:  ${PRIMARY_URL:-"(ddev describe inside worktree)"}" \
  "Vendor:   ${VENDOR_HINT:-"(see additional_hostnames)"}"

echo "Next steps:"
mel_info "cd ${WORKTREE}"
mel_info "Hack, commit, then: bash scripts/dev/mel-finish-feature.sh"
mel_info "Open a PR when finish-feature is green (push is manual)."
mel_usage_footer
