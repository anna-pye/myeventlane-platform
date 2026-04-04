#!/usr/bin/env bash
# Curl smoke checks. Exits non-zero on any non-2xx response.
# Usage: smoke-test.sh <base_url> [strict]
# strict=1 adds extra paths for production.
set -euo pipefail

BASE_URL="${1:?Usage: smoke-test.sh <base_url> [strict 0|1]}"
STRICT="${2:-0}"

# Trim trailing slash
BASE_URL="${BASE_URL%/}"

paths=(
  "/"
  "/user/login"
  "/events"
)

if [[ "$STRICT" == "1" ]]; then
  paths+=(
    "/robots.txt"
  )
fi

fail=0
for path in "${paths[@]}"; do
  url="${BASE_URL}${path}"
  code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 30 --retry 2 --retry-delay 1 "$url" || echo "000")"
  if [[ "$code" =~ ^2[0-9][0-9]$ ]]; then
    echo "[smoke OK] $url -> HTTP $code"
  else
    echo "[smoke FAIL] $url -> HTTP $code" >&2
    fail=1
  fi
done

if [[ "$fail" -ne 0 ]]; then
  echo "[smoke-test] One or more checks failed." >&2
  exit 1
fi

echo "[smoke-test] All checks passed."
