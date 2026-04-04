#!/usr/bin/env bash
# Switch current symlink to a previous release and rebuild caches.
# Usage: rollback.sh <app_path> <site_uri> [release_id]
# If release_id is omitted, rolls back to the next older release than current.
set -euo pipefail

APP_PATH="${1:?Usage: rollback.sh <app_path> <site_uri> [release_id]}"
SITE_URI="${2:?site_uri required}"
RELEASE_ID="${3:-}"

RELEASES="$APP_PATH/releases"
CURRENT="$APP_PATH/current"

if [[ ! -d "$RELEASES" ]]; then
  echo "[rollback] ERROR: releases directory missing: $RELEASES" >&2
  exit 1
fi

mapfile -t sorted < <(ls -1 "$RELEASES" | sort -r)

if [[ -z "$RELEASE_ID" ]]; then
  if [[ "${#sorted[@]}" -lt 2 ]]; then
    echo "[rollback] ERROR: need at least two releases to roll back" >&2
    ls -la "$RELEASES" >&2 || true
    exit 1
  fi
  current_name=""
  if [[ -L "$CURRENT" ]] || [[ -e "$CURRENT" ]]; then
    current_real="$(readlink -f "$CURRENT" 2>/dev/null || true)"
    if [[ -n "$current_real" ]]; then
      current_name="$(basename "$current_real")"
    fi
  fi
  idx=-1
  if [[ -n "$current_name" ]]; then
    for i in "${!sorted[@]}"; do
      if [[ "${sorted[$i]}" == "$current_name" ]]; then
        idx=$i
        break
      fi
    done
  fi
  if [[ "$idx" -ge 0 ]]; then
    next=$((idx + 1))
    if [[ "$next" -lt "${#sorted[@]}" ]]; then
      RELEASE_ID="${sorted[$next]}"
    else
      echo "[rollback] ERROR: no older release than $current_name" >&2
      exit 1
    fi
  else
    echo "[rollback] WARN: current not matched in releases; using second-newest" >&2
    RELEASE_ID="${sorted[1]}"
  fi
  echo "[rollback] Selected release: $RELEASE_ID"
fi

TARGET="$RELEASES/$RELEASE_ID"
if [[ ! -d "$TARGET" ]]; then
  echo "[rollback] ERROR: release not found: $TARGET" >&2
  echo "[rollback] Available:" >&2
  ls -1 "$RELEASES" >&2 || true
  exit 1
fi

DRUSH="$TARGET/vendor/bin/drush"
if [[ ! -x "$DRUSH" ]]; then
  echo "[rollback] ERROR: drush not found at $DRUSH" >&2
  exit 1
fi

echo "[rollback] Pointing $CURRENT -> $TARGET"
ln -sfn "$TARGET" "${APP_PATH}/.rollback.$$"
mv -Tf "${APP_PATH}/.rollback.$$" "$CURRENT"

echo "[rollback] Drush cache rebuild"
"$DRUSH" cr --uri="$SITE_URI"

echo "[rollback] Done. Active release: $(readlink -f "$CURRENT")"
