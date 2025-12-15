#!/bin/bash
# Quick pre-commit check - run this before committing
# Usage: ./pre-commit-check.sh

echo "🔍 Quick check of staged files for secrets..."

# Check staged files
staged=$(git diff --cached --name-only 2>/dev/null)

if [ -z "$staged" ]; then
    echo "ℹ️  No files staged"
    exit 0
fi

# Basic patterns to check
found=false
for file in $staged; do
    if [ ! -f "$file" ]; then
        continue
    fi
    
    # Check for common secret patterns
    if grep -qiE "(password|secret|api[_-]?key|token)\s*[:=]\s*['\"][^'\"]{8,}" "$file" 2>/dev/null; then
        echo "⚠️  Warning: Potential secret pattern in: $file"
        found=true
    fi
    
    # Check for Stripe keys
    if grep -qiE "sk_(live|test)_[a-zA-Z0-9]{24,}" "$file" 2>/dev/null; then
        echo "⚠️  Warning: Stripe key pattern in: $file"
        found=true
    fi
    
    # Check for private keys
    if grep -qiE "-----BEGIN.*PRIVATE KEY-----" "$file" 2>/dev/null; then
        echo "⚠️  Warning: Private key in: $file"
        found=true
    fi
    
    # Warn about common secret files
    if [[ "$file" == *.env* ]] || [[ "$file" == *settings*.php ]] || [[ "$file" == *secret* ]]; then
        echo "⚠️  Warning: Potentially sensitive file staged: $file"
        found=true
    fi
done

if [ "$found" = true ]; then
    echo ""
    echo "❌ Please review the files above before committing!"
    echo "💡 Run './check-secrets.sh' for a full scan"
    exit 1
else
    echo "✅ Quick check passed (run './check-secrets.sh' for full scan)"
    exit 0
fi
