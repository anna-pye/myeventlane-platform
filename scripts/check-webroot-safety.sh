#!/usr/bin/env bash
# CI / pre-deploy check: fail if the public web root contains backup archives,
# database dumps, or phpinfo probes that must never be web-accessible.
#
# Drupal core/contrib ship test fixtures (*.sql, *.tar.gz) under web/core; those
# are excluded here so the check catches accidental operator dumps instead.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ ! -d web ]; then
  echo "ERROR: Missing web directory."
  exit 1
fi

matches="$(
  find web \
    \( \
      -path 'web/core' -o \
      -path 'web/core/*' -o \
      -path 'web/modules/contrib' -o \
      -path 'web/modules/contrib/*' -o \
      -path 'web/profiles/contrib' -o \
      -path 'web/profiles/contrib/*' -o \
      -path 'web/themes/contrib' -o \
      -path 'web/themes/contrib/*' \
    \) -prune -o \
    -type f \( \
      -name '*.sql' -o \
      -name '*.sql.gz' -o \
      -name '*.tar' -o \
      -name '*.tar.gz' -o \
      -name '*.zip' -o \
      -name 'phpinfo*.php' \
    \) -print \
  2>/dev/null || true
)"

if [ -n "$matches" ]; then
  echo "Unsafe files found in web root:"
  echo "$matches"
  exit 1
fi

echo "Webroot safety check passed."
