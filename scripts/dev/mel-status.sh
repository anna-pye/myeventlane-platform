#!/usr/bin/env bash
# mel-status.sh — Project health snapshot for the current checkout.
#
# Usage:
#   bash scripts/dev/mel-status.sh
#   bash scripts/dev/mel-status.sh /path/to/checkout
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=mel-common.sh
source "${SCRIPT_DIR}/mel-common.sh"

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  cat <<'EOF'
Usage: mel-status.sh [checkout-path]

Shows repository, branch, git, DDEV, PHP/Drupal, composer,
config drift hint, and a short validation summary.
EOF
  exit 0
fi

ROOT="$(cd "${1:-.}" && pwd -P)"
mel_header "MyEventLane · Status"
mel_require_git_repo "${ROOT}"

BRANCH="$(mel_current_branch "${ROOT}")"
COMMIT="$(mel_current_commit "${ROOT}")"
COMMIT_FULL="$(mel_current_commit_full "${ROOT}")"
AB="$(mel_ahead_behind "${ROOT}")"

mel_section "Repository"
mel_info "Path:    ${ROOT}"
mel_info "Branch:  ${BRANCH:-"(detached)"}"
mel_info "Commit:  ${COMMIT} (${COMMIT_FULL})"
mel_info "Track:   ${AB}"

IS_INTEGRATION="no"
RESOLVED_INTEGRATION="$(cd "${MEL_INTEGRATION_ROOT}" 2>/dev/null && pwd -P || true)"
if [[ -n "${RESOLVED_INTEGRATION}" && "${ROOT}" == "${RESOLVED_INTEGRATION}" ]]; then
  IS_INTEGRATION="yes"
fi
mel_info "Integration checkout: ${IS_INTEGRATION}"

mel_section "Git status"
if mel_working_tree_clean "${ROOT}"; then
  mel_success "Working tree clean"
else
  mel_warn "Working tree has local changes"
  mel_git "${ROOT}" status --short | head -40
fi

mel_section "DDEV"
if mel_ddev_available; then
  DDEV_STATUS="$(mel_ddev_status "${ROOT}" 2>/dev/null || echo "stopped or unavailable")"
  mel_info "Status: ${DDEV_STATUS}"
  PRIMARY="$(mel_ddev_primary_url "${ROOT}" 2>/dev/null || true)"
  [[ -n "${PRIMARY}" ]] && mel_info "URL:    ${PRIMARY}"
else
  mel_warn "ddev not installed"
  DDEV_STATUS="missing"
fi

mel_section "Runtime"
PHP_VER="$(mel_php_version "${ROOT}")"
mel_info "PHP:     ${PHP_VER}"
if printf '%s' "${DDEV_STATUS}" | grep -qiE 'running|ok'; then
  DRUPAL_VER="$(mel_drupal_version "${ROOT}")"
  mel_info "Drupal:  ${DRUPAL_VER}"
else
  mel_info "Drupal:  (start DDEV to query)"
fi

mel_section "Composer"
if [[ -f "${ROOT}/composer.json" ]]; then
  if mel_composer_validate "${ROOT}" >/dev/null 2>&1; then
    mel_success "composer validate OK"
    COMPOSER_STATUS="ok"
  else
    mel_warn "composer validate reported issues"
    COMPOSER_STATUS="issues"
  fi
else
  mel_warn "composer.json missing"
  COMPOSER_STATUS="missing"
fi

mel_section "Outstanding config"
if printf '%s' "${DDEV_STATUS}" | grep -qiE 'running|ok'; then
  if CFG_OUT="$(mel_ddev_in_dir "${ROOT}" drush config:status 2>/dev/null)"; then
    if [[ -z "$(printf '%s' "${CFG_OUT}" | tr -d '[:space:]')" ]]; then
      mel_success "No config status output (likely in sync)"
      CONFIG_STATUS="ok"
    else
      printf '%s\n' "${CFG_OUT}" | head -25
      CONFIG_STATUS="review"
    fi
  else
    mel_warn "Could not read config:status"
    CONFIG_STATUS="unavailable"
  fi
else
  mel_info "Skipped (DDEV not running)"
  CONFIG_STATUS="skipped"
fi

mel_section "Outstanding PR branch"
case "${BRANCH}" in
  main|"")
    mel_info "On main — no feature PR branch active here."
    ;;
  *)
    mel_info "Active branch: ${BRANCH}"
    mel_info "Commits ahead of origin/main:"
    mel_git "${ROOT}" fetch origin main --quiet 2>/dev/null || true
    mel_git "${ROOT}" log --oneline origin/main..HEAD 2>/dev/null | head -15 || mel_info "(unable to compare)"
    ;;
esac

mel_section "Validation summary"
LIGHT=0
if [[ "${COMPOSER_STATUS}" == "ok" ]]; then
  mel_success "Composer"
else
  mel_warn "Composer: ${COMPOSER_STATUS}"
  LIGHT=1
fi
if bash "${ROOT}/scripts/check-config-safety.sh" >/dev/null 2>&1; then
  mel_success "Config safety"
else
  mel_warn "Config safety"
  LIGHT=1
fi
if bash "${ROOT}/scripts/check-webroot-safety.sh" >/dev/null 2>&1; then
  mel_success "Webroot safety"
else
  mel_warn "Webroot safety"
  LIGHT=1
fi

VALIDATE_LABEL="ok"
if [[ "${LIGHT}" -ne 0 ]]; then
  VALIDATE_LABEL="review"
fi

mel_box \
  "Health snapshot" \
  "Repo:     ${ROOT}" \
  "Branch:   ${BRANCH:-detached}" \
  "Commit:   ${COMMIT}" \
  "DDEV:     ${DDEV_STATUS}" \
  "Config:   ${CONFIG_STATUS}" \
  "Validate: ${VALIDATE_LABEL}"

mel_usage_footer
