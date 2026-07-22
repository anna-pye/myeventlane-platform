#!/usr/bin/env bash
# MyEventLane Developer Toolkit — shared library
#
# Sourced by scripts/dev/mel-*.sh. Do not execute directly.
# Safe for local development only. Never deploys. Never discards work.

# shellcheck disable=SC2034

if [[ -n "${MEL_COMMON_LOADED:-}" ]]; then
  return 0 2>/dev/null || exit 0
fi
MEL_COMMON_LOADED=1

set -euo pipefail

###############################################################################
# Paths and conventions
###############################################################################

MEL_TOOLKIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MEL_REPO_ROOT="$(cd "${MEL_TOOLKIT_DIR}/../.." && pwd)"

# Permanent integration checkout (always origin/main).
MEL_INTEGRATION_ROOT="${MEL_INTEGRATION_ROOT:-${HOME}/myeventlane}"

# Feature worktrees live beside integration: ~/myeventlane-wt-<slug>
MEL_WORKTREE_PREFIX="${MEL_WORKTREE_PREFIX:-myeventlane-wt-}"
MEL_WORKTREE_PARENT="${MEL_WORKTREE_PARENT:-${HOME}}"

MEL_BRANCH_PREFIX="${MEL_BRANCH_PREFIX:-feature/mel-}"

# Optional skip flags for long gates (finish-feature).
# MEL_SKIP_PHPUNIT=1  MEL_SKIP_PHPCS=1  MEL_SKIP_DRUSH=1

###############################################################################
# Colour / UX
###############################################################################

if [[ -t 1 ]] && [[ "${NO_COLOR:-}" != "1" ]] && [[ "${TERM:-}" != "dumb" ]]; then
  MEL_CLR_RESET=$'\033[0m'
  MEL_CLR_BOLD=$'\033[1m'
  MEL_CLR_DIM=$'\033[2m'
  MEL_CLR_RED=$'\033[31m'
  MEL_CLR_GREEN=$'\033[32m'
  MEL_CLR_YELLOW=$'\033[33m'
  MEL_CLR_BLUE=$'\033[34m'
  MEL_CLR_CYAN=$'\033[36m'
else
  MEL_CLR_RESET=''
  MEL_CLR_BOLD=''
  MEL_CLR_DIM=''
  MEL_CLR_RED=''
  MEL_CLR_GREEN=''
  MEL_CLR_YELLOW=''
  MEL_CLR_BLUE=''
  MEL_CLR_CYAN=''
fi

mel_log() {
  printf '%s\n' "$*"
}

mel_dim() {
  printf '%s%s%s\n' "${MEL_CLR_DIM}" "$*" "${MEL_CLR_RESET}"
}

mel_header() {
  local title="$1"
  echo
  printf '%s%s====================================%s\n' "${MEL_CLR_BOLD}${MEL_CLR_CYAN}" "" "${MEL_CLR_RESET}"
  printf '%s%s%s\n' "${MEL_CLR_BOLD}${MEL_CLR_CYAN}" "${title}" "${MEL_CLR_RESET}"
  printf '%s%s====================================%s\n' "${MEL_CLR_BOLD}${MEL_CLR_CYAN}" "" "${MEL_CLR_RESET}"
}

mel_section() {
  echo
  printf '%s▸ %s%s\n' "${MEL_CLR_BOLD}${MEL_CLR_BLUE}" "$*" "${MEL_CLR_RESET}"
}

mel_step() {
  printf '  %s→%s %s\n' "${MEL_CLR_CYAN}" "${MEL_CLR_RESET}" "$*"
}

mel_success() {
  printf '  %s✓%s %s\n' "${MEL_CLR_GREEN}" "${MEL_CLR_RESET}" "$*"
}

mel_warn() {
  printf '  %s!%s %s\n' "${MEL_CLR_YELLOW}" "${MEL_CLR_RESET}" "$*" >&2
}

mel_error() {
  printf '  %s✗%s %s\n' "${MEL_CLR_RED}" "${MEL_CLR_RESET}" "$*" >&2
}

mel_info() {
  printf '  %s•%s %s\n' "${MEL_CLR_DIM}" "${MEL_CLR_RESET}" "$*"
}

mel_box() {
  local line
  echo
  printf '%s====================================%s\n' "${MEL_CLR_BOLD}" "${MEL_CLR_RESET}"
  for line in "$@"; do
    printf '%s\n' "${line}"
  done
  printf '%s====================================%s\n' "${MEL_CLR_BOLD}" "${MEL_CLR_RESET}"
  echo
}

mel_die() {
  mel_error "$*"
  exit 1
}

mel_confirm() {
  local prompt="${1:-Continue?}"
  local reply
  if [[ ! -t 0 ]]; then
    mel_error "Confirmation required but stdin is not a terminal."
    mel_info "Refusing to proceed without an interactive yes."
    return 1
  fi
  printf '  %s?%s %s [y/N] ' "${MEL_CLR_YELLOW}" "${MEL_CLR_RESET}" "${prompt}"
  read -r reply
  case "${reply}" in
    y|Y|yes|YES) return 0 ;;
    *)
      mel_warn "Cancelled."
      return 1
      ;;
  esac
}

###############################################################################
# Repository helpers
###############################################################################

mel_require_cmd() {
  local cmd="$1"
  local hint="${2:-}"
  if ! command -v "${cmd}" >/dev/null 2>&1; then
    mel_error "Missing required command: ${cmd}"
    [[ -n "${hint}" ]] && mel_info "${hint}"
    return 1
  fi
  return 0
}

mel_is_git_repo() {
  local dir="${1:-.}"
  git -C "${dir}" rev-parse --is-inside-work-tree >/dev/null 2>&1
}

mel_require_git_repo() {
  local dir="${1:-.}"
  if [[ ! -d "${dir}" ]]; then
    mel_die "Directory not found: ${dir}"
  fi
  if ! mel_is_git_repo "${dir}"; then
    mel_error "Not a Git repository: ${dir}"
    mel_info "Expected a MyEventLane checkout (integration or worktree)."
    exit 1
  fi
}

mel_git() {
  local dir="$1"
  shift
  git -C "${dir}" "$@"
}

mel_current_branch() {
  local dir="${1:-.}"
  mel_git "${dir}" branch --show-current
}

mel_current_commit() {
  local dir="${1:-.}"
  mel_git "${dir}" rev-parse --short HEAD
}

mel_current_commit_full() {
  local dir="${1:-.}"
  mel_git "${dir}" rev-parse HEAD
}

mel_working_tree_clean() {
  local dir="${1:-.}"
  [[ -z "$(mel_git "${dir}" status --porcelain 2>/dev/null)" ]]
}

mel_require_clean_tree() {
  local dir="${1:-.}"
  local label="${2:-working tree}"
  if ! mel_working_tree_clean "${dir}"; then
    mel_error "${label} is dirty. Refusing to continue."
    mel_info "Never discarding work. Commit, stash, or move changes first."
    echo
    mel_git "${dir}" status --short | head -40
    exit 1
  fi
}

mel_require_branch() {
  local dir="$1"
  local expected="$2"
  local actual
  actual="$(mel_current_branch "${dir}")"
  if [[ "${actual}" != "${expected}" ]]; then
    mel_error "Wrong branch in ${dir}"
    mel_info "Expected: ${expected}"
    mel_info "Actual:   ${actual:-"(detached HEAD)"}"
    exit 1
  fi
}

mel_require_integration_root() {
  mel_require_git_repo "${MEL_INTEGRATION_ROOT}"
}

mel_ahead_behind() {
  local dir="${1:-.}"
  local upstream
  if ! upstream="$(mel_git "${dir}" rev-parse --abbrev-ref '@{upstream}' 2>/dev/null)"; then
    echo "no upstream"
    return 0
  fi
  local counts ahead behind
  counts="$(mel_git "${dir}" rev-list --left-right --count "HEAD...@{upstream}" 2>/dev/null || echo "0	0")"
  ahead="$(printf '%s' "${counts}" | awk '{print $1}')"
  behind="$(printf '%s' "${counts}" | awk '{print $2}')"
  echo "ahead ${ahead} / behind ${behind} (${upstream})"
}

mel_normalize_feature_slug() {
  local raw="$1"
  raw="$(printf '%s' "${raw}" | tr '[:upper:]' '[:lower:]')"
  raw="${raw#feature/mel-}"
  raw="${raw#feature/}"
  raw="${raw#fix/mel-}"
  raw="${raw#fix/}"
  raw="${raw#mel-}"
  raw="$(printf '%s' "${raw}" | sed -E 's/[^a-z0-9_-]+/-/g; s/^-+//; s/-+$//; s/-+/-/g')"
  if [[ -z "${raw}" ]]; then
    mel_die "Feature slug is empty after normalisation."
  fi
  printf '%s' "${raw}"
}

mel_branch_name_from_slug() {
  local slug="$1"
  printf '%s%s' "${MEL_BRANCH_PREFIX}" "${slug}"
}

mel_worktree_path_from_slug() {
  local slug="$1"
  printf '%s/%s%s' "${MEL_WORKTREE_PARENT}" "${MEL_WORKTREE_PREFIX}" "${slug}"
}

###############################################################################
# DDEV helpers
###############################################################################

mel_ddev_available() {
  command -v ddev >/dev/null 2>&1
}

mel_ddev_in_dir() {
  local dir="$1"
  shift
  (cd "${dir}" && ddev "$@")
}

mel_ddev_status() {
  local dir="${1:-.}"
  if ! mel_ddev_available; then
    echo "ddev not installed"
    return 1
  fi
  if [[ ! -d "${dir}/.ddev" ]]; then
    echo "no .ddev directory"
    return 1
  fi
  local status
  status="$(mel_ddev_in_dir "${dir}" describe -j 2>/dev/null | python3 -c '
import json,sys
try:
  data=json.load(sys.stdin)
  raw=data.get("raw") or data
  status=(raw.get("status") or raw.get("Status") or "unknown")
  name=raw.get("name") or raw.get("Name") or ""
  print(f"{status}" + (f" ({name})" if name else ""))
except Exception:
  print("unknown")
' 2>/dev/null || true)"
  if [[ -z "${status}" ]]; then
    if mel_ddev_in_dir "${dir}" describe >/dev/null 2>&1; then
      echo "running"
    else
      echo "stopped or unavailable"
      return 1
    fi
  else
    printf '%s\n' "${status}"
  fi
}

mel_require_ddev() {
  local dir="${1:-.}"
  if ! mel_ddev_available; then
    mel_error "DDEV is not installed or not on PATH."
    mel_info "Install: https://ddev.readthedocs.io/en/stable/users/install/"
    mel_info "Then retry from a MyEventLane checkout with a .ddev directory."
    exit 1
  fi
  if [[ ! -d "${dir}/.ddev" ]]; then
    mel_die "No .ddev directory in ${dir}"
  fi
}

mel_ensure_ddev_running() {
  local dir="$1"
  mel_require_ddev "${dir}"
  local status
  status="$(mel_ddev_status "${dir}" 2>/dev/null || true)"
  if printf '%s' "${status}" | grep -qiE 'running|ok'; then
    mel_success "DDEV is running (${status})"
    return 0
  fi
  mel_step "Starting DDEV in ${dir}…"
  if ! mel_ddev_in_dir "${dir}" start; then
    mel_error "Failed to start DDEV."
    mel_info "Try: cd ${dir} && ddev describe"
    exit 1
  fi
  mel_success "DDEV started"
}

mel_ddev_primary_url() {
  local dir="$1"
  mel_ddev_in_dir "${dir}" describe -j 2>/dev/null | python3 -c '
import json,sys
try:
  data=json.load(sys.stdin)
  raw=data.get("raw") or data
  url=raw.get("primary_url") or raw.get("httpsurl") or ""
  if not url:
    urls=raw.get("urls") or []
    https=[u for u in urls if isinstance(u,str) and u.startswith("https://")]
    url=https[0] if https else (urls[0] if urls else "")
  print(url)
except Exception:
  print("")
' 2>/dev/null || true
}

# Reserved host ports for the integration project and known wallet worktree.
MEL_DDEV_PORT_MIN="${MEL_DDEV_PORT_MIN:-59200}"
MEL_DDEV_PORT_MAX="${MEL_DDEV_PORT_MAX:-59899}"

mel_port_is_listening() {
  local port="$1"
  if command -v lsof >/dev/null 2>&1; then
    lsof -nP -iTCP:"${port}" -sTCP:LISTEN >/dev/null 2>&1 && return 0
  fi
  # Fallback probe (may false-positive on firewalls that accept then reset).
  (echo >/dev/tcp/127.0.0.1/"${port}") >/dev/null 2>&1 && return 0
  return 1
}

mel_collect_claimed_ddev_ports() {
  # Prints claimed host ports (one per line): reserved + existing worktree configs.
  local skip_cfg="${1:-}"
  local p cfg
  for p in 59000 59001 59002 59100 59101 59102; do
    printf '%s\n' "${p}"
  done
  local parent="${MEL_WORKTREE_PARENT}"
  local prefix="${MEL_WORKTREE_PREFIX}"
  for cfg in "${parent}/${prefix}"*/.ddev/config.local.yaml; do
    [[ -f "${cfg}" ]] || continue
    if [[ -n "${skip_cfg}" && -f "${skip_cfg}" ]]; then
      # Same file → ignore (re-allocating for this worktree).
      if [[ "${cfg}" -ef "${skip_cfg}" ]]; then
        continue
      fi
    elif [[ -n "${skip_cfg}" && "${cfg}" == "${skip_cfg}" ]]; then
      continue
    fi
    sed -nE 's/^[[:space:]]*host_(webserver|https|db)_port:[[:space:]]*"?([0-9]+)"?.*/\2/p' "${cfg}" 2>/dev/null || true
  done
}

mel_port_is_claimed() {
  local port="$1"
  local claimed="$2"
  printf '%s\n' "${claimed}" | grep -qx "${port}"
}

mel_allocate_worktree_ports() {
  # Pick a free host-port trio in [MEL_DDEV_PORT_MIN, MEL_DDEV_PORT_MAX].
  # Hash only chooses the scan start — we walk until ports are free and unclaimed
  # by other myeventlane-wt-* config.local.yaml files (avoids slug hash collisions).
  local slug="$1"
  local skip_cfg="${2:-}"
  local hash start candidate web_port https_port db_port
  local claimed span slots i index
  local min="${MEL_DDEV_PORT_MIN}"
  local max="${MEL_DDEV_PORT_MAX}"

  # Keep trios aligned to the historical 59200 base.
  while (( (min - 59200) % 3 != 0 )); do
    min=$((min + 1))
  done
  span=$((max - min + 1))
  if (( span < 3 )); then
    mel_die "DDEV port range too small (${min}-${max})."
  fi
  slots=$((span / 3))
  hash="$(printf '%s' "${slug}" | cksum | awk '{print $1}')"
  start=$((hash % slots))
  claimed="$(mel_collect_claimed_ddev_ports "${skip_cfg}")"

  i=0
  while (( i < slots )); do
    index=$(( (start + i) % slots ))
    candidate=$((min + index * 3))
    web_port="${candidate}"
    https_port=$((candidate + 1))
    db_port=$((candidate + 2))
    if (( db_port > max )); then
      i=$((i + 1))
      continue
    fi
    if mel_port_is_claimed "${web_port}" "${claimed}" \
      || mel_port_is_claimed "${https_port}" "${claimed}" \
      || mel_port_is_claimed "${db_port}" "${claimed}"; then
      i=$((i + 1))
      continue
    fi
    if mel_port_is_listening "${web_port}" \
      || mel_port_is_listening "${https_port}" \
      || mel_port_is_listening "${db_port}"; then
      i=$((i + 1))
      continue
    fi
    printf '%s %s %s' "${web_port}" "${https_port}" "${db_port}"
    return 0
  done

  mel_die "No free DDEV host ports in ${min}-${max}. Stop unused worktrees or raise MEL_DDEV_PORT_MAX."
}

mel_write_worktree_ddev_local() {
  local worktree="$1"
  local slug="$2"
  local project_name="myeventlane-wt-${slug}"
  # DDEV project names: lowercase alphanumeric + hyphen.
  project_name="$(printf '%s' "${project_name}" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9-]+/-/g; s/-+/-/g; s/^-|-$//g')"
  local local_cfg="${worktree}/.ddev/config.local.yaml"
  local web_port https_port db_port
  read -r web_port https_port db_port <<EOF
$(mel_allocate_worktree_ports "${slug}" "${local_cfg}")
EOF
  cat >"${local_cfg}" <<EOF
# Worktree-only DDEV overrides (gitignored).
# Generated by mel-start-feature.sh — keeps this checkout from colliding
# with ~/myeventlane (project name myeventlane).
override_config: true
name: ${project_name}
additional_hostnames:
  - admin.${project_name}
  - vendor.${project_name}
host_webserver_port: "${web_port}"
host_https_port: "${https_port}"
host_db_port: "${db_port}"
EOF
  mel_success "Wrote ${local_cfg}"
  mel_info "DDEV project: ${project_name}"
  mel_info "Ports: http ${web_port} / https ${https_port} / db ${db_port}"
}

###############################################################################
# Validation wrappers (compose existing scripts — do not duplicate logic)
###############################################################################

mel_run_step() {
  local label="$1"
  shift
  mel_step "${label}"
  if "$@"; then
    mel_success "${label}"
    return 0
  fi
  mel_error "${label} failed"
  return 1
}

mel_composer_validate() {
  local dir="${1:-.}"
  (
    cd "${dir}"
    # Match scripts/validate-push.sh — no --strict (warnings should not block).
    if mel_ddev_available && [[ -d .ddev ]]; then
      if ddev exec composer validate --no-check-publish 2>/dev/null; then
        return 0
      fi
      ddev composer validate --no-check-publish
    elif command -v composer >/dev/null 2>&1; then
      composer validate --no-check-publish
    else
      mel_error "Neither ddev nor composer is available for composer validate."
      return 1
    fi
  )
}

mel_check_config_safety() {
  local dir="${1:-.}"
  bash "${dir}/scripts/check-config-safety.sh"
}

mel_check_webroot_safety() {
  local dir="${1:-.}"
  bash "${dir}/scripts/check-webroot-safety.sh"
}

mel_validate_push() {
  local dir="${1:-.}"
  bash "${dir}/scripts/validate-push.sh"
}

mel_drush_cr() {
  local dir="${1:-.}"
  mel_require_ddev "${dir}"
  mel_ddev_in_dir "${dir}" drush cr
}

mel_drush_config_status() {
  local dir="${1:-.}"
  mel_require_ddev "${dir}"
  mel_ddev_in_dir "${dir}" drush config:status
}

mel_phpcs_changed_or_default() {
  local dir="${1:-.}"
  local base_ref="${2:-origin/main}"
  (
    cd "${dir}"
    local files=""
    local line
    local count=0
    while IFS= read -r line; do
      if [[ -n "${line}" && -f "${line}" ]]; then
        files="${files} ${line}"
        count=$((count + 1))
      fi
    done <<EOF
$(git diff --name-only --diff-filter=ACMR "${base_ref}"...HEAD -- '*.php' '*.module' '*.inc' '*.install' '*.theme' 2>/dev/null || true)
EOF

    if [[ "${count}" -eq 0 ]]; then
      mel_info "No changed PHP files vs ${base_ref}; running phpcs.xml defaults."
      if mel_ddev_available && [[ -d .ddev ]]; then
        ddev exec vendor/bin/phpcs --standard=phpcs.xml
      else
        ./vendor/bin/phpcs --standard=phpcs.xml
      fi
      return $?
    fi

    mel_info "PHPCS on ${count} changed PHP file(s) vs ${base_ref}"
    # shellcheck disable=SC2086
    if mel_ddev_available && [[ -d .ddev ]]; then
      ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice ${files}
    else
      ./vendor/bin/phpcs --standard=Drupal,DrupalPractice ${files}
    fi
  )
}

mel_phpunit_smoke() {
  # Usage: mel_phpunit_smoke <repo_dir> [suite_path ...]
  # Multiple suite paths are passed through to mel-phpunit / PHPUnit.
  local dir="${1:-.}"
  shift || true
  if [[ "$#" -eq 0 ]]; then
    set -- "web/modules/custom/myeventlane_api/tests/src/Unit"
  fi
  mel_require_ddev "${dir}"
  mel_ddev_in_dir "${dir}" exec bash scripts/mel-phpunit "$@"
}

mel_php_version() {
  local dir="${1:-.}"
  if mel_ddev_available && [[ -d "${dir}/.ddev" ]]; then
    mel_ddev_in_dir "${dir}" exec php -r 'echo PHP_VERSION;' 2>/dev/null || echo "unavailable"
  elif command -v php >/dev/null 2>&1; then
    php -r 'echo PHP_VERSION;'
  else
    echo "unavailable"
  fi
}

mel_drupal_version() {
  local dir="${1:-.}"
  if mel_ddev_available && [[ -d "${dir}/.ddev" ]]; then
    mel_ddev_in_dir "${dir}" drush status --fields=drupal-version --format=string 2>/dev/null || echo "unavailable"
  else
    echo "unavailable (DDEV required)"
  fi
}

mel_usage_footer() {
  mel_dim "MyEventLane Developer Toolkit v1 · scripts/dev/"
}
