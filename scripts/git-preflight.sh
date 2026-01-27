#!/usr/bin/env bash
set -e

EXPECTED_DIR="/Users/anna/myeventlane"

echo "🔍 Git preflight check..."

PWD_REAL=$(pwd)
if [ "$PWD_REAL" != "$EXPECTED_DIR" ]; then
  echo "❌ Wrong directory"
  echo "Expected: $EXPECTED_DIR"
  echo "Actual:   $PWD_REAL"
  exit 1
fi

BRANCH=$(git branch --show-current)

if [ "$BRANCH" = "main" ]; then
  echo "❌ You are on 'main'"
  echo "Create or switch to a feature branch first."
  exit 1
fi

echo "✅ Directory: $PWD_REAL"
echo "✅ Branch:    $BRANCH"
echo
git status --short
echo
echo "✔️ Git preflight passed"
