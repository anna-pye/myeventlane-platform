#!/usr/bin/env bash
# mel-review-main.sh — Daily product review checklist (human-focused).
#
# Optionally syncs integration first, then prints a review checklist.
#
# Usage:
#   bash scripts/dev/mel-review-main.sh
#   bash scripts/dev/mel-review-main.sh --skip-sync
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=mel-common.sh
source "${SCRIPT_DIR}/mel-common.sh"

SKIP_SYNC=0
for arg in "$@"; do
  case "${arg}" in
    --skip-sync) SKIP_SYNC=1 ;;
    -h|--help)
      cat <<'EOF'
Usage: mel-review-main.sh [--skip-sync]

Runs mel-sync-main.sh (unless --skip-sync), then prints today's
product review checklist for human UX verification.
EOF
      exit 0
      ;;
    *)
      mel_die "Unknown argument: ${arg}"
      ;;
  esac
done

mel_header "MyEventLane · Daily Review"

if [[ "${SKIP_SYNC}" -eq 0 ]]; then
  mel_section "Sync integration first"
  if ! bash "${SCRIPT_DIR}/mel-sync-main.sh"; then
    mel_error "Sync failed — fix integration before reviewing."
    exit 1
  fi
else
  mel_warn "Skipping sync (--skip-sync)"
fi

ROOT="${MEL_INTEGRATION_ROOT}"
PRIMARY_URL="$(mel_ddev_primary_url "${ROOT}" 2>/dev/null || true)"

echo
printf '%s================================%s\n' "${MEL_CLR_BOLD}${MEL_CLR_CYAN}" "${MEL_CLR_RESET}"
printf '%sToday'\''s Product Review%s\n' "${MEL_CLR_BOLD}" "${MEL_CLR_RESET}"
printf '%s================================%s\n' "${MEL_CLR_BOLD}${MEL_CLR_CYAN}" "${MEL_CLR_RESET}"
cat <<'EOF'
□ Dashboard
□ Create Event
□ Event Workspace
□ Tickets
□ Attendees
□ Orders
□ Payments
□ Analytics
□ Marketing
□ Mobile
□ Accessibility
□ Console errors
□ Performance
================================
EOF

if [[ -n "${PRIMARY_URL}" ]]; then
  mel_info "Open: ${PRIMARY_URL}"
  mel_info "Vendor: $(printf '%s' "${PRIMARY_URL}" | sed -E 's#https://#https://vendor.#')"
  mel_info "Admin:  $(printf '%s' "${PRIMARY_URL}" | sed -E 's#https://#https://admin.#')"
fi
mel_info "Integration: ${ROOT}"
mel_info "Commit: $(mel_current_commit "${ROOT}" 2>/dev/null || echo unknown)"
echo
mel_dim "Check one box at a time. Note regressions before starting new feature work."
mel_usage_footer
