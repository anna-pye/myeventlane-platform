#!/bin/bash
set -e

THEME="$(pwd)/web/themes/custom/myeventlane_theme"

echo "Cleaning Vite + Sass build caches…"

rm -rf "$THEME/node_modules"
rm -rf "$THEME/dist"
rm -rf "$THEME/.vite"
rm -rf "$THEME/.sass-cache"

echo "Reinstalling dependencies on macOS host (npm install)…"
npm install --prefix "$THEME"

echo "Rebuilding production assets inside DDEV (npm run build)…"
ddev npm run build --prefix "$THEME"

echo "Rebuild complete."