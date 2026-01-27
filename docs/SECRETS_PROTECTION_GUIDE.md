# Protecting Secrets in Git - Quick Guide

This guide helps ensure you don't accidentally commit API keys, passwords, or other sensitive information to your git repository.

## Current Protections

Your `.gitignore` file already excludes:
- ✅ Drupal settings files (`settings.php`, `services.yml`)
- ✅ Database dumps (`.sql` files)
- ✅ Private files directories
- ✅ Vendor directories

## Before Pushing to Git

### 1. **Manual Check (Recommended)**
Always review what you're committing:
```bash
# See what files are staged
git status

# Preview the actual changes
git diff --cached

# Or for a specific file
git diff --cached path/to/file
```

### 2. **Run the Secret Scanner**
Use the provided script to check for potential secrets:
```bash
./check-secrets.sh
```

This will check:
- Staged files
- Recent commits
- All tracked files

### 3. **Pre-Push Hook (Optional)**
The pre-push hook (`.git/hooks/pre-push-check-secrets.sh`) will automatically check before pushing. To enable it:
```bash
# Make it executable (already done)
chmod +x .git/hooks/pre-push-check-secrets.sh

# Link it as a pre-push hook
ln -s pre-push-check-secrets.sh .git/hooks/pre-push
```

## Where Secrets Should Go (Drupal Best Practices)

### ✅ **DO Store Secrets In:**
1. **`web/sites/*/settings.php` or `settings.local.php`**
   ```php
   $settings['twilio_account_sid'] = getenv('TWILIO_SID');
   $settings['twilio_auth_token'] = getenv('TWILIO_TOKEN');
   ```

2. **Environment Variables** (via `.env` file - excluded by .gitignore)
   ```bash
   export TWILIO_SID="your_sid_here"
   export TWILIO_TOKEN="your_token_here"
   ```

3. **Drupal Config (for site-specific, non-sensitive settings)**
   - Use config forms that store values in the database
   - These are NOT committed (stored in DB or `settings.php`)

### ❌ **DON'T Store Secrets In:**
1. ❌ Version controlled config files (`config/sync/*.yml`)
2. ❌ Source code files
3. ❌ Composer or package.json files
4. ❌ Any file tracked by git

## What to Do If You've Already Committed Secrets

1. **IMMEDIATELY rotate/revoke the exposed credentials**
2. Remove from git history:
   ```bash
   # Remove from last commit (if not pushed)
   git reset --soft HEAD~1
   # Remove secrets, then commit again
   
   # If already pushed, use git-filter-repo (advanced)
   # Or contact your team/security lead
   ```
3. Update `.gitignore` to prevent future commits
4. Check all branches for the secret

## Enhanced Protection Tools

For production projects, consider:

### git-secrets (AWS)
```bash
# Install
brew install git-secrets  # macOS
# or
git clone https://github.com/awslabs/git-secrets.git
cd git-secrets && sudo make install

# Setup
git secrets --install
git secrets --register-aws  # Adds AWS key patterns
```

### gitleaks
```bash
# Install
brew install gitleaks  # macOS

# Scan repository
gitleaks detect --source . --verbose
```

### GitHub Secret Scanning
- If using GitHub, enable secret scanning in repository settings
- Automatically scans commits for known secret patterns

## Common Secret Patterns to Watch For

- Stripe: `sk_live_*`, `pk_live_*`, `sk_test_*`, `pk_test_*`
- AWS: `AKIA...`, `aws_access_key_id`, `aws_secret_access_key`
- GitHub: `ghp_*`, `gho_*`, `ghu_*`
- Twilio: `AC[a-z0-9]{32}`
- Private keys: `-----BEGIN PRIVATE KEY-----`
- Passwords: Any `password = ...` or `password: ...` patterns

## Quick Checklist Before Push

- [ ] Run `git status` - review all files
- [ ] Run `git diff --cached` - review all changes
- [ ] Run `./check-secrets.sh` - automated check
- [ ] Verify no `.env` files are staged
- [ ] Verify no `settings.php` files are staged
- [ ] Verify no API keys or tokens in code
- [ ] Verify credentials are in environment variables or settings.php only

## Your Current Setup

✅ `.gitignore` configured to exclude common secret files
✅ `check-secrets.sh` script available for manual checking
✅ Pre-push hook available (optional)

Remember: **When in doubt, don't commit!** It's always safer to ask or double-check than to expose credentials.
