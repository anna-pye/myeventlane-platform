# Git Push Workflow - Quick Reference

## Standard Push Workflow

### 1. Check what you've changed
```bash
git status
```
Shows all modified, staged, and untracked files.

### 2. Stage your changes
```bash
# Stage all changes
git add .

# Or stage specific files
git add path/to/file.php

# Or stage specific patterns
git add *.php
```

### 3. **CHECK FOR SECRETS** (important!)
```bash
# Quick check of staged files
./pre-commit-check.sh

# OR full repository scan
./check-secrets.sh
```

### 4. Review your changes
```bash
# See what's staged
git diff --cached

# Or see all changes (staged + unstaged)
git diff
```

### 5. Commit your changes
```bash
git commit -m "Your commit message describing the changes"
```

### 6. Push to remote
```bash
# Push current branch to remote
git push

# Push to specific remote and branch
git push origin main

# Push to a different branch
git push origin your-branch-name

# First time pushing a new branch? Use:
git push -u origin branch-name
```

## Complete Example

```bash
# 1. See what changed
git status

# 2. Stage files
git add .

# 3. Check for secrets
./pre-commit-check.sh

# 4. Review changes (optional but recommended)
git diff --cached | less

# 5. Commit
git commit -m "Add feature X"

# 6. Push
git push
```

## Common Scenarios

### Pushing to a specific remote
```bash
# List remotes
git remote -v

# Push to specific remote
git push origin main
```

### Pushing a new branch
```bash
# Create and switch to new branch
git checkout -b new-feature

# Make changes, then...
git add .
git commit -m "New feature"

# First push (sets upstream)
git push -u origin new-feature

# Subsequent pushes
git push
```

### Force push (use with caution!)
```bash
# Only use if you know what you're doing
# Usually needed after rebasing/amending
git push --force

# Safer: force with lease (fails if remote changed)
git push --force-with-lease
```

### Push all branches
```bash
git push --all origin
```

## Troubleshooting

### "No upstream branch" error
```bash
# Set upstream branch
git push -u origin branch-name
```

### "Updates were rejected" error
```bash
# Pull remote changes first
git pull

# Resolve conflicts if any, then:
git push
```

### Push rejected (non-fast-forward)
```bash
# Someone else pushed changes. Pull first:
git pull --rebase

# Or merge:
git pull

# Then push:
git push
```

## Safety Reminders

✅ **Always run `./pre-commit-check.sh` before committing**
✅ **Review `git diff --cached` to see what you're committing**
✅ **Never commit secrets or sensitive data**
✅ **Write clear commit messages**

❌ Don't force push to shared branches (main/master)
❌ Don't push without reviewing changes
❌ Don't skip the secret check!
