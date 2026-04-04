#!/usr/bin/env bash
# Build a production deploy artifact (tar.gz) from the repository root.
# Run from repo root. Does not modify tracked files.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

OUTPUT_NAME="${1:-artifact.tar.gz}"
STAGE_DIR="${BUILD_STAGE_DIR:-$(mktemp -d -t mel-build-XXXXXX)}"
cleanup() {
  if [[ "${KEEP_BUILD_STAGE:-}" != "1" ]]; then
    rm -rf "$STAGE_DIR"
  fi
}
trap cleanup EXIT

echo "==> Staging build in $STAGE_DIR"
rsync -a \
  --exclude='.git/' \
  --exclude='.ddev/' \
  --exclude='node_modules/' \
  --exclude='web/sites/default/files/' \
  --exclude='**/node_modules/' \
  --exclude='.github/' \
  --exclude='_myeventlane_audit/' \
  ./ "$STAGE_DIR/"

cd "$STAGE_DIR"

echo "==> Composer install (no dev)"
composer install --no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader

if [[ -f package.json ]]; then
  echo "==> npm ci && npm run build (root)"
  npm ci
  npm run build
fi

echo "==> Remove dev-only paths from stage"
rm -rf .git .ddev node_modules .github _myeventlane_audit 2>/dev/null || true
find . -type d -name 'node_modules' -prune -exec rm -rf {} + 2>/dev/null || true
# Drop PHPUnit / test trees from custom code (artifact hygiene)
if [[ -d web/modules/custom || -d web/themes/custom ]]; then
  find web/modules/custom web/themes/custom -depth -type d -name 'tests' -exec rm -rf {} + 2>/dev/null || true
fi

echo "==> Create archive $ROOT/$OUTPUT_NAME"
tar -czf "$ROOT/$OUTPUT_NAME" -C "$STAGE_DIR" .

echo "==> Done: $ROOT/$OUTPUT_NAME ($(du -h "$ROOT/$OUTPUT_NAME" | cut -f1))"
