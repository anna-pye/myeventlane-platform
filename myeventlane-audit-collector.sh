#!/usr/bin/env bash
set -euo pipefail

OUT="_myeventlane_audit"
DOCROOT="web"

mkdir -p "$OUT"

# --- Git metadata ---
{ git rev-parse --verify HEAD 2>/dev/null || true; } > "$OUT/git-commit.txt" || true
{ git branch --show-current 2>/dev/null || true; } > "$OUT/git-branch.txt" || true
git status --porcelain=v1 > "$OUT/git-status.txt" || true
git remote -v > "$OUT/git-remotes.txt" || true

# --- Composer ---
cp -f composer.json "$OUT/" 2>/dev/null || true
cp -f composer.lock "$OUT/" 2>/dev/null || true
{ composer --version; composer check-platform-reqs --no-interaction; } &> "$OUT/composer-platform.txt" || true
composer show -D --format=json > "$OUT/composer-show-dev.json" || true
composer show --format=json > "$OUT/composer-show.json" || true

# --- Detect Drush ---
DRUSH=""
if command -v ddev >/dev/null 2>&1 && ddev --version >/dev/null 2>&1; then
  if ddev exec -s web drush --version >/dev/null 2>&1; then
    DRUSH="ddev drush"
  else
    DRUSH="ddev exec drush"
  fi
elif [ -x "./vendor/bin/drush" ]; then
  DRUSH="./vendor/bin/drush"
elif command -v drush >/dev/null 2>&1; then
  DRUSH="drush"
fi

# --- Drush status and lists ---
if [ -n "$DRUSH" ]; then
  $DRUSH status --format=json > "$OUT/drush-status.json" || true
  $DRUSH pm:list --type=module --status=enabled --no-core --format=json > "$OUT/pm-enabled.json" || true
  $DRUSH pm:list --type=theme  --status=enabled --format=json > "$OUT/themes-enabled.json" || true
  $DRUSH config:get system.site --format=yaml > "$OUT/system.site.yml" || true
  $DRUSH config:get core.extension --format=yaml > "$OUT/core.extension.yml" || true
fi

# --- Config sync ---
SYNC="$DOCROOT/sites/default/config/sync"
if [ -d "$SYNC" ]; then
  mkdir -p "$OUT/config-sync"
  rsync -a --delete "$SYNC/" "$OUT/config-sync/" || cp -R "$SYNC/." "$OUT/config-sync/" || true
fi

# --- Settings and services ---
mkdir -p "$OUT/sites-default"
for f in "$DOCROOT/sites/default"/settings*.php "$DOCROOT/sites/default"/services*.yml; do
  [ -f "$f" ] && cp -f "$f" "$OUT/sites-default/" || true
done

# --- Custom code inventories ---
mkdir -p "$OUT/custom-inventory"
if [ -d "$DOCROOT/modules/custom" ]; then
  find "$DOCROOT/modules/custom" -type f -name "*.info.yml" -print > "$OUT/custom-inventory/custom-modules.txt"
  tar -czf "$OUT/custom-inventory/custom-modules-infos.tgz" -C "$DOCROOT/modules/custom" $(find "$DOCROOT/modules/custom" -type f -name "*.info.yml" -printf '%P\n') 2>/dev/null || true
fi
if [ -d "$DOCROOT/themes/custom" ]; then
  find "$DOCROOT/themes/custom" -type f -name "*.info.yml" -print > "$OUT/custom-inventory/custom-themes.txt"
fi

# --- Commerce inventory (best effort) ---
if [ -n "$DRUSH" ]; then
  $DRUSH pm:list --type=module --status=enabled --no-core --format=list | grep -i '^commerce' > "$OUT/commerce-modules.txt" || true
fi

# --- Frontend ---
mkdir -p "$OUT/frontend"
for f in package.json package-lock.json pnpm-lock.yaml yarn.lock vite.config.*; do
  [ -e "$f" ] && cp -f "$f" "$OUT/frontend/" || true
done

# --- PHP info ---
{ php -v; php -m; } > "$OUT/php-info.txt" 2>&1 || true

# --- DDEV config ---
if [ -d ".ddev" ]; then
  mkdir -p "$OUT/ddev"
  cp -R .ddev/*.yaml "$OUT/ddev/" 2>/dev/null || true
fi

# --- Patches discovery ---
grep -RIn '"patches"' composer.json composer.lock > "$OUT/patches-grep.txt" || true

echo "Done. Collected to $OUT"
