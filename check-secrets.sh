#!/bin/bash
# Script to check your repository for potential secrets
# Run this manually before pushing: ./check-secrets.sh

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🔍 Scanning repository for potential secrets...${NC}\n"

# Patterns to look for
declare -a patterns=(
    # API Keys
    "sk_live_[a-zA-Z0-9]{24,}"
    "sk_test_[a-zA-Z0-9]{24,}"
    "pk_live_[a-zA-Z0-9]{24,}"
    "pk_test_[a-zA-Z0-9]{24,}"
    "AKIA[0-9A-Z]{16}"
    "AIza[0-9A-Z_-]{35}"
    
    # Tokens
    "xox[baprs]-[0-9]{12}-[0-9]{12}-[0-9]{12}-[a-zA-Z0-9]{32}"
    "ghp_[a-zA-Z0-9]{36}"
    "gho_[a-zA-Z0-9]{36}"
    "ghu_[a-zA-Z0-9]{36}"
    "ghs_[a-zA-Z0-9]{36}"
    "ghr_[a-zA-Z0-9]{36}"
    
    # Passwords in plain text
    "password\s*[:=]\s*['\"][^'\"]{8,}['\"]"
    "password\s*=\s*[^\s]{8,}"
    
    # Private keys
    "-----BEGIN (RSA|DSA|EC|OPENSSH) PRIVATE KEY-----"
    
    # Twilio/Authy
    "AC[a-z0-9]{32}"
    
    # AWS
    "aws_access_key_id\s*=\s*[A-Z0-9]{20}"
    "aws_secret_access_key\s*=\s*[A-Za-z0-9/+=]{40}"
)

found_issues=false

# Check staged files
echo -e "${YELLOW}📝 Checking staged files...${NC}"
staged_files=$(git diff --cached --name-only 2>/dev/null)
if [ -n "$staged_files" ]; then
    for file in $staged_files; do
        if [ -f "$file" ]; then
            for pattern in "${patterns[@]}"; do
                if grep -qE "$pattern" "$file" 2>/dev/null; then
                    echo -e "${RED}⚠️  Potential secret in STAGED file: $file${NC}"
                    grep -nE "$pattern" "$file" 2>/dev/null | head -3 | sed 's/^/   /'
                    found_issues=true
                fi
            done
        fi
    done
fi

# Check recent commits (last 5)
echo -e "\n${YELLOW}📜 Checking last 5 commits...${NC}"
for commit in $(git log --oneline -5 --format="%H"); do
    changed_files=$(git diff-tree --no-commit-id --name-only -r "$commit" 2>/dev/null)
    for file in $changed_files; do
        if [ -f "$file" ]; then
            file_content=$(git show "$commit:$file" 2>/dev/null)
            if [ -n "$file_content" ]; then
                for pattern in "${patterns[@]}"; do
                    if echo "$file_content" | grep -qE "$pattern"; then
                        commit_msg=$(git log -1 --format="%s" "$commit")
                        echo -e "${RED}⚠️  Potential secret in commit: $commit ($commit_msg)${NC}"
                        echo -e "   File: $file"
                        echo "$file_content" | grep -nE "$pattern" | head -3 | sed 's/^/   /'
                        found_issues=true
                    fi
                done
            fi
        fi
    done
done

# Check working directory (excluding git-ignored files)
echo -e "\n${YELLOW}📂 Checking tracked files in working directory...${NC}"
tracked_files=$(git ls-files 2>/dev/null)
for file in $tracked_files; do
    if [ -f "$file" ] && [[ ! "$file" =~ ^(vendor|node_modules|web/core|web/modules/contrib|web/themes/contrib) ]]; then
        for pattern in "${patterns[@]}"; do
            if grep -qE "$pattern" "$file" 2>/dev/null; then
                echo -e "${RED}⚠️  Potential secret in tracked file: $file${NC}"
                grep -nE "$pattern" "$file" 2>/dev/null | head -3 | sed 's/^/   /'
                found_issues=true
            fi
        done
    fi
done

echo ""
if [ "$found_issues" = true ]; then
    echo -e "${RED}❌ Potential secrets found! Review the files above.${NC}"
    echo -e "${YELLOW}💡 Recommendations:${NC}"
    echo "   1. Remove secrets from tracked files"
    echo "   2. Add files to .gitignore if they contain secrets"
    echo "   3. Store secrets in environment variables or settings.php"
    echo "   4. If secrets were already committed, rotate them immediately"
    echo "   5. Consider using git-secrets (https://github.com/awslabs/git-secrets)"
    exit 1
else
    echo -e "${GREEN}✅ No obvious secrets detected${NC}"
    echo -e "${YELLOW}💡 Note: This is a basic check. Always review your code manually.${NC}"
    exit 0
fi
