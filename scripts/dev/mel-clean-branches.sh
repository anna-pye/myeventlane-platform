#!/usr/bin/env bash
# mel-clean-branches.sh — Review merged local branches; delete only with confirmation.
#
# Never deletes automatically. Never force-deletes.
#
# Usage:
#   bash scripts/dev/mel-clean-branches.sh
#   bash scripts/dev/mel-clean-branches.sh --remote-prune-preview
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=mel-common.sh
source "${SCRIPT_DIR}/mel-common.sh"

REMOTE_PREVIEW=0
for arg in "$@"; do
  case "${arg}" in
    --remote-prune-preview) REMOTE_PREVIEW=1 ;;
    -h|--help)
      cat <<'EOF'
Usage: mel-clean-branches.sh [--remote-prune-preview]

Lists merged / not-merged local branches relative to origin/main.
Optionally previews remote prune candidates.
Asks before deleting any local branch. Never auto-deletes.
EOF
      exit 0
      ;;
    *)
      mel_die "Unknown argument: ${arg}"
      ;;
  esac
done

# Prefer integration repo (shared refs), else current repo.
ROOT="${MEL_INTEGRATION_ROOT}"
if ! mel_is_git_repo "${ROOT}"; then
  ROOT="$(pwd -P)"
  mel_require_git_repo "${ROOT}"
  mel_warn "Integration root unavailable; using current repo: ${ROOT}"
fi

mel_header "MyEventLane · Clean Branches"
mel_info "Repository: ${ROOT}"

mel_section "Refresh refs"
mel_git "${ROOT}" fetch origin --prune
mel_success "Fetched origin"

BASE="origin/main"
if ! mel_git "${ROOT}" rev-parse --verify "${BASE}" >/dev/null 2>&1; then
  BASE="main"
fi

CURRENT="$(mel_current_branch "${ROOT}")"

# Use for-each-ref to avoid worktree markers (+/*) and stray tokens.
MERGED_LIST="$(mel_git "${ROOT}" for-each-ref --format='%(refname:short)' --merged="${BASE}" refs/heads/ | grep -vE '^(main|master)$' || true)"
NOT_MERGED_LIST="$(mel_git "${ROOT}" for-each-ref --format='%(refname:short)' --no-merged="${BASE}" refs/heads/ | grep -vE '^(main|master)$' || true)"

# Safe-to-delete candidates: merged, not current, not checked out in a worktree.
SAFE_LIST=""
SAFE_COUNT=0
while IFS= read -r b; do
  [[ -z "${b}" ]] && continue
  [[ "${b}" == "${CURRENT}" ]] && continue
  if mel_git "${ROOT}" worktree list | grep -q "\[${b}\]"; then
    continue
  fi
  SAFE_LIST="${SAFE_LIST}${b}"$'\n'
  SAFE_COUNT=$((SAFE_COUNT + 1))
done <<EOF
${MERGED_LIST}
EOF

mel_section "Merged into ${BASE}"
if [[ -z "$(printf '%s' "${MERGED_LIST}" | tr -d '[:space:]')" ]]; then
  mel_info "(none)"
else
  while IFS= read -r b; do
    [[ -z "${b}" ]] && continue
    printf '  %s✓%s %s\n' "${MEL_CLR_GREEN}" "${MEL_CLR_RESET}" "${b}"
  done <<EOF
${MERGED_LIST}
EOF
fi

mel_section "Not merged"
if [[ -z "$(printf '%s' "${NOT_MERGED_LIST}" | tr -d '[:space:]')" ]]; then
  mel_info "(none)"
else
  while IFS= read -r b; do
    [[ -z "${b}" ]] && continue
    printf '  %s•%s %s\n' "${MEL_CLR_YELLOW}" "${MEL_CLR_RESET}" "${b}"
  done <<EOF
${NOT_MERGED_LIST}
EOF
fi

mel_section "Safe to delete (merged, not current, no worktree)"
if [[ "${SAFE_COUNT}" -eq 0 ]]; then
  mel_info "(none)"
else
  while IFS= read -r b; do
    [[ -z "${b}" ]] && continue
    printf '  %s○%s %s\n' "${MEL_CLR_CYAN}" "${MEL_CLR_RESET}" "${b}"
  done <<EOF
${SAFE_LIST}
EOF
fi

if [[ "${REMOTE_PREVIEW}" -eq 1 ]]; then
  mel_section "Remote prune preview (no changes)"
  mel_git "${ROOT}" remote prune origin --dry-run || true
fi

if [[ "${SAFE_COUNT}" -eq 0 ]]; then
  mel_success "Nothing safe to delete."
  mel_usage_footer
  exit 0
fi

echo
if ! mel_confirm "Delete all ${SAFE_COUNT} safe local branch(es) listed above?"; then
  mel_info "No branches deleted."
  mel_usage_footer
  exit 0
fi

while IFS= read -r b; do
  [[ -z "${b}" ]] && continue
  if mel_confirm "Delete local branch ${b}?"; then
    if mel_git "${ROOT}" branch -d "${b}"; then
      mel_success "Deleted ${b}"
    else
      mel_warn "Could not delete ${b} (still refused — never force)."
    fi
  fi
done <<EOF
${SAFE_LIST}
EOF

mel_usage_footer
