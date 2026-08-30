#!/usr/bin/env bash
# Safely plan or apply Drupal core/contributed package updates for MEL in local DDEV.
#
# This script never deploys, imports configuration, changes Composer constraints,
# or runs on main. Apply mode requires a clean feature/fix branch and creates a
# local database backup before changing packages or running database updates.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${ROOT}"

MODE="plan"
SCOPE="all"
SCOPE_WAS_SET=0
ASSUME_YES=0
REVIEW_REQUIRED=0
BACKUP_ROOT=""
TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/mel-drupal-update.XXXXXX")"
declare -a REQUESTED_PACKAGES=()
declare -a UPDATE_PACKAGES=()

cleanup() {
  rm -rf "${TMP_ROOT}"
}

on_error() {
  local rc=$?
  trap - ERR
  echo >&2
  echo "ERROR: Drupal update workflow stopped (exit ${rc})." >&2
  if [[ -n "${BACKUP_ROOT}" ]]; then
    echo "Local recovery material: ${BACKUP_ROOT}" >&2
    echo "Do not deploy this partial result. Inspect the Composer/Drush error first." >&2
  fi
  exit "${rc}"
}

trap cleanup EXIT
trap on_error ERR

usage() {
  cat <<'EOF'
MEL Drupal dependency updater (local DDEV only)

Usage:
  bash scripts/maintenance/mel-update-drupal.sh --plan --scope core
  bash scripts/maintenance/mel-update-drupal.sh --apply --scope core
  bash scripts/maintenance/mel-update-drupal.sh --plan --scope contrib
  bash scripts/maintenance/mel-update-drupal.sh --apply --scope contrib
  bash scripts/maintenance/mel-update-drupal.sh --plan --package drupal/token
  bash scripts/maintenance/mel-update-drupal.sh --apply --package drupal/token
  bash scripts/maintenance/mel-update-drupal.sh --apply --scope all

Modes:
  --plan              Preview only (default). Does not change Composer files or Drupal.
  --apply             Update dependencies, run database updates, and rebuild caches.

Scopes:
  --scope core        Drupal core packages only.
  --scope contrib     Directly required Drupal modules/themes, excluding core.
  --scope all         Core plus directly required Drupal modules/themes.
  --package NAME      Update one installed drupal/* package. Repeat as needed.

Options:
  --yes               Skip the apply confirmation (still requires clean non-main branch).
  -h, --help          Show this help.

Apply mode deliberately refuses main, detached HEAD, and dirty working trees.
Major-version upgrades are out of scope because this script does not alter constraints.
EOF
}

die() {
  echo "ERROR: $*" >&2
  exit 1
}

warn() {
  echo "WARNING: $*" >&2
}

run_heading() {
  echo
  echo "== $* =="
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --plan)
      MODE="plan"
      ;;
    --apply)
      MODE="apply"
      ;;
    --scope)
      [[ $# -ge 2 ]] || die "--scope requires core, contrib, or all."
      [[ ${#REQUESTED_PACKAGES[@]} -eq 0 ]] || die "Do not combine --scope and --package."
      SCOPE="$2"
      SCOPE_WAS_SET=1
      shift
      ;;
    --package)
      [[ $# -ge 2 ]] || die "--package requires a package such as drupal/token."
      [[ "${SCOPE_WAS_SET}" -eq 0 ]] || die "Do not combine --scope and --package."
      [[ "$2" == drupal/* ]] || die "--package accepts only drupal/* packages."
      SCOPE="packages"
      REQUESTED_PACKAGES+=("$2")
      shift
      ;;
    --yes)
      ASSUME_YES=1
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      die "Unknown argument: $1 (use --help)."
      ;;
  esac
  shift
done

case "${SCOPE}" in
  core|contrib|all|packages) ;;
  *) die "Unknown scope '${SCOPE}'. Use core, contrib, or all." ;;
esac

if [[ "${MODE}" == "apply" && "${SCOPE}" != "packages" && "${SCOPE_WAS_SET}" -eq 0 ]]; then
  die "Apply mode requires an explicit --scope. Preview first, then choose core, contrib, or all."
fi

command -v git >/dev/null 2>&1 || die "Git is required."
command -v ddev >/dev/null 2>&1 || die "DDEV is required."
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "Run this from a MEL Git checkout."
[[ "$(git rev-parse --show-toplevel)" == "${ROOT}" ]] || die "Script path does not match the Git repository root."
[[ -f composer.json && -f composer.lock && -d .ddev ]] || die "This is not a complete MEL Composer/DDEV checkout."

CURRENT_BRANCH="$(git branch --show-current)"
CURRENT_COMMIT="$(git rev-parse --short HEAD)"

if [[ "${MODE}" == "apply" ]]; then
  [[ -n "${CURRENT_BRANCH}" ]] || die "Apply mode refuses detached HEAD."
  [[ "${CURRENT_BRANCH}" != "main" ]] || die "Apply mode refuses main. Create a dedicated update branch/worktree."
  [[ -z "$(git status --porcelain)" ]] || {
    git status --short >&2
    die "Apply mode requires a clean working tree. Commit, stash, or move existing work first."
  }
fi

run_heading "Environment"
echo "Mode:   ${MODE}"
echo "Scope:  ${SCOPE}"
echo "Branch: ${CURRENT_BRANCH:-detached HEAD}"
echo "Commit: ${CURRENT_COMMIT}"

echo "Starting the local DDEV project if needed..."
ddev start

collect_direct_drupal_packages() {
  local wanted="$1"
  local package
  while IFS= read -r package; do
    [[ -n "${package}" ]] || continue
    case "${wanted}:${package}" in
      core:drupal/core|core:drupal/core-*)
        UPDATE_PACKAGES+=("${package}")
        ;;
      contrib:drupal/core|contrib:drupal/core-*)
        ;;
      contrib:drupal/*|all:drupal/*)
        UPDATE_PACKAGES+=("${package}")
        ;;
    esac
  done < <(ddev composer show --locked --direct --name-only "drupal/*")
}

case "${SCOPE}" in
  core)
    collect_direct_drupal_packages core
    ;;
  contrib)
    collect_direct_drupal_packages contrib
    ;;
  all)
    collect_direct_drupal_packages all
    ;;
  packages)
    for package in "${REQUESTED_PACKAGES[@]}"; do
      ddev composer show --locked "${package}" >/dev/null
      UPDATE_PACKAGES+=("${package}")
    done
    ;;
esac

[[ ${#UPDATE_PACKAGES[@]} -gt 0 ]] || die "No installed packages matched scope '${SCOPE}'."

run_heading "Packages selected"
printf '%s\n' "${UPDATE_PACKAGES[@]}"

PATCHED_SELECTION=0
CORE_SELECTION=0
PAYMENT_SELECTION=0
EVENT_STUDIO_SELECTION=0
for package in "${UPDATE_PACKAGES[@]}"; do
  case "${package}" in
    drupal/core|drupal/core-*|drupal/image_widget_crop|drupal/commerce_stripe)
      PATCHED_SELECTION=1
      ;;
  esac
  case "${package}" in
    drupal/core|drupal/core-*)
      CORE_SELECTION=1
      ;;
  esac
  case "${package}" in
    drupal/commerce|drupal/commerce_*|drupal/commerce-stripe)
      PAYMENT_SELECTION=1
      ;;
  esac
  [[ "${package}" == "drupal/conditional_fields" ]] && EVENT_STUDIO_SELECTION=1
done

if [[ "${PATCHED_SELECTION}" -eq 1 ]]; then
  warn "This selection includes a MEL-patched package. Confirm every patch still applies and is still required."
fi
if [[ "${CORE_SELECTION}" -eq 1 ]] && ! ddev composer show --locked drupal/core-recommended >/dev/null 2>&1; then
  warn "MEL uses drupal/core directly, not drupal/core-recommended. Expect broader transitive dependency movement and review it closely."
fi
if [[ "${PAYMENT_SELECTION}" -eq 1 ]]; then
  warn "This selection touches Commerce/payment code. Staging payment, checkout, refund, and subscription acceptance may be required."
fi
if [[ "${EVENT_STUDIO_SELECTION}" -eq 1 ]]; then
  warn "Conditional Fields participates in Event Studio forms. Smoke-test create/edit, media, ticket, and publish flows."
fi

run_heading "Preflight"
ddev composer validate --strict --no-check-publish
ddev drush status

UPDB_BEFORE="${TMP_ROOT}/updatedb-before.txt"
ddev drush updatedb:status >"${UPDB_BEFORE}"
cat "${UPDB_BEFORE}"
if ! grep -Eiq 'No pending updates|No database updates required|No updates required' "${UPDB_BEFORE}"; then
  die "Pending database updates already exist. Resolve them before changing dependencies."
fi

CONFIG_BEFORE="${TMP_ROOT}/config-before.txt"
ddev drush config:status >"${CONFIG_BEFORE}"
cat "${CONFIG_BEFORE}"

run_heading "Current direct Drupal packages"
ddev composer show --locked --direct "drupal/*"

run_heading "Available direct Drupal updates"
if ! ddev composer outdated --direct "drupal/*"; then
  warn "Composer could not complete the outdated-package report."
fi

run_heading "Current production dependency audit"
if ! ddev composer audit --locked --no-dev; then
  warn "The current lock file has an audit failure. The planned update must resolve it before database updates run."
fi

declare -a UPDATE_FLAGS=(--with-all-dependencies --no-interaction --no-progress)
if ddev composer update --help | grep -q -- '--minimal-changes'; then
  UPDATE_FLAGS+=(--minimal-changes)
fi

if [[ "${MODE}" == "plan" ]]; then
  run_heading "Composer dry run"
  ddev composer update "${UPDATE_PACKAGES[@]}" "${UPDATE_FLAGS[@]}" --dry-run
  echo
  echo "PLAN COMPLETE: nothing was updated. Review the proposed versions and release notes."
  echo "Apply only from a clean update branch:"
  if [[ "${SCOPE}" == "packages" ]]; then
    printf '  bash scripts/maintenance/mel-update-drupal.sh --apply'
    for package in "${REQUESTED_PACKAGES[@]}"; do
      printf ' --package %q' "${package}"
    done
    printf '\n'
  else
    echo "  bash scripts/maintenance/mel-update-drupal.sh --apply --scope ${SCOPE}"
  fi
  exit 0
fi

echo
echo "Apply will now:"
echo "- export the local DDEV database"
echo "- update the selected Composer packages within existing constraints"
echo "- block on Composer validation/security audit failures"
echo "- run Drupal database updates and rebuild caches"
echo "- compare configuration drift, without importing or exporting config"
echo

if [[ "${ASSUME_YES}" -ne 1 ]]; then
  [[ -t 0 ]] || die "Interactive confirmation is required. Re-run with --yes only after reviewing the plan."
  read -r -p "Type UPDATE to continue: " CONFIRMATION
  [[ "${CONFIRMATION}" == "UPDATE" ]] || die "Cancelled."
fi

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_ROOT="backups/drupal-update-${TIMESTAMP}"
mkdir -p "${BACKUP_ROOT}"
cp composer.json composer.lock "${BACKUP_ROOT}/"
cp "${CONFIG_BEFORE}" "${BACKUP_ROOT}/config-before.txt"
ddev composer show --locked --direct "drupal/*" >"${BACKUP_ROOT}/drupal-packages-before.txt"

run_heading "Local database backup"
ddev export-db --file="${BACKUP_ROOT}/database.sql.gz"
echo "Backup: ${BACKUP_ROOT}/database.sql.gz"

run_heading "Composer update"
ddev composer update "${UPDATE_PACKAGES[@]}" "${UPDATE_FLAGS[@]}"

run_heading "Composer gates"
ddev composer validate --strict --no-check-publish
ddev composer audit --locked --no-dev
ddev composer install --dry-run --no-dev --no-interaction --no-progress

run_heading "Drupal database and cache"
ddev drush updatedb -y
ddev drush cache:rebuild
ddev drush updatedb:status

CONFIG_AFTER="${TMP_ROOT}/config-after.txt"
ddev drush config:status >"${CONFIG_AFTER}"
cat "${CONFIG_AFTER}"
cp "${CONFIG_AFTER}" "${BACKUP_ROOT}/config-after.txt"

if ! cmp -s "${CONFIG_BEFORE}" "${CONFIG_AFTER}"; then
  REVIEW_REQUIRED=1
  warn "Configuration status changed during the update. Review this diff; do not blindly run config import/export."
  diff -u "${CONFIG_BEFORE}" "${CONFIG_AFTER}" || true
fi

run_heading "Repository safety checks"
bash scripts/check-config-safety.sh
bash scripts/check-webroot-safety.sh
bash scripts/check-no-raw-card-data.sh
git diff --check

run_heading "Changed Drupal packages"
ddev composer show --locked --direct "drupal/*" >"${BACKUP_ROOT}/drupal-packages-after.txt"
diff -u "${BACKUP_ROOT}/drupal-packages-before.txt" "${BACKUP_ROOT}/drupal-packages-after.txt" || true

run_heading "Git review"
git status --short
git diff --stat
git diff -- composer.json

echo
echo "UPDATE APPLIED LOCALLY. It is not approved, committed, deployed, or production-ready."
echo "Backup: ${BACKUP_ROOT}"
echo
echo "Next:"
echo "1. Review composer.json, composer.lock, patch output, release notes, and this package diff."
echo "2. Run targeted automated tests and manual journeys for every affected MEL area."
echo "3. Commit only the reviewed update files."
echo "4. From the clean committed branch, run: bash scripts/validate-release.sh staging"
echo "5. Open a PR, pass CI, deploy that exact commit to staging, and complete acceptance there."
echo "6. Deploy production only through MEL's release workflow after explicit approval."

if [[ "${REVIEW_REQUIRED}" -eq 1 ]]; then
  echo
  echo "REVIEW REQUIRED: configuration status changed. The dependency update remains applied locally."
  exit 2
fi

