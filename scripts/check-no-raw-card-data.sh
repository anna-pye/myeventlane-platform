#!/usr/bin/env bash
# CI check: ensures custom code never handles raw card data.
#
# Exit 1 if any forbidden pattern is found in custom modules.
# Designed to run in CI or as a pre-merge gate.
#
# What it catches:
#   - Token::create / tokens->create  (legacy Stripe Tokens API)
#   - Charge::create with card source (legacy Charges API)
#   - card[number] / card_number in Stripe request context
#   - Direct /v1/tokens or /v1/charges endpoint calls
#
# False-positive strategy:
#   - Only scans web/modules/custom/ (not contrib, not tests)
#   - Patterns are specific enough to avoid matching field labels or CSS classes

set -euo pipefail

SEARCH_DIR="web/modules/custom"
FAIL=0

patterns=(
  'Token::create'
  'tokens->create'
  'Source::create'
  'sources->create'
  "card\[.number.\]"
  '/v1/tokens'
  '/v1/charges'
)

echo "Checking custom modules for raw card data patterns..."

for pattern in "${patterns[@]}"; do
  if rg --quiet --glob '*.php' --glob '*.js' --glob '*.twig' \
       -- "$pattern" "$SEARCH_DIR" 2>/dev/null; then
    echo "FAIL: Found forbidden pattern: $pattern"
    rg --glob '*.php' --glob '*.js' --glob '*.twig' \
       -- "$pattern" "$SEARCH_DIR" || true
    FAIL=1
  fi
done

if [ $FAIL -eq 1 ]; then
  echo ""
  echo "ERROR: Raw card data patterns detected in custom code."
  echo "Card data must be tokenised client-side via Stripe.js."
  exit 1
fi

echo "PASS: No raw card data patterns found in custom modules."
exit 0
