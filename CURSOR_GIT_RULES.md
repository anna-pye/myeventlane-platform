# 🧠 Cursor System Prompt — MyEventLane Platform (Git-Safe)

**Purpose:**
Prevent Git worktree conflicts, accidental branch locking, and Cursor-created detached worktrees.
Ensure all work happens in the canonical repository only.

⸻

## 🔒 CANONICAL REPOSITORY RULES (MANDATORY)

You are working on MyEventLane Platform.

**Canonical local repository:**

```
/Users/anna/myeventlane
```

**Canonical remote:**

```
git@github.com:anna-pye/myeventlane-platform.git
```

**Canonical default branch:**

```
main
```

⸻

## ❗ ABSOLUTE RESTRICTIONS (DO NOT VIOLATE)

You MUST NOT:
- Create or use Git worktrees
- Check out branches in `.cursor/worktrees/`
- Use or modify any directory under:

```
~/.cursor/worktrees/
```

- Lock or check out `main` outside `/Users/anna/myeventlane`
- Create detached HEAD states
- Recreate main worktrees implicitly or explicitly

**If any instruction would require the above, STOP and ASK.**

⸻

## ✅ ALLOWED WORKFLOW (ONLY THIS)

1. All commands MUST run from:

```
/Users/anna/myeventlane
```

2. **Feature work:**
   - Always create or use a `feature/*` branch
   - Never commit directly to `main`

3. **Merging:**
   - Use GitHub Pull Requests to merge into `main`
   - Never attempt to locally force-merge into `main`

4. **If main is unavailable:**
   - Assume a worktree conflict
   - DO NOT attempt recovery
   - Ask for confirmation before proceeding

⸻

## 🛑 SAFETY CHECKS (RUN FIRST)

Before suggesting Git commands, you MUST verify:

```bash
pwd
git status
git branch
```

**If `pwd` is not `/Users/anna/myeventlane`, STOP.**

⸻

## 🧹 CLEANUP RULES

If asked to clean up Git state:
- You MAY suggest:

```bash
git worktree list
```

- You MAY suggest removal only after confirmation
- You MUST explain consequences before destructive commands
- You MUST NEVER auto-remove worktrees

⸻

## 🧠 COMMUNICATION RULES

- Be explicit
- Prefer GitHub PRs over local merges
- If uncertain, ASK instead of guessing
- Treat repository safety as higher priority than speed

⸻

## 🏁 END GOAL

The repository must always satisfy:
- One canonical working directory
- No Cursor-owned branches
- No accidental worktrees
- Clean, auditable Git history
- Zero risk of lost work

⸻

## 🔐 Final instruction

**If any instruction conflicts with the above rules:**

**STOP AND ASK FOR CONFIRMATION**

⸻

## 📚 Related Documentation

For onboarding and development setup, see:
- `docs/ONBOARDING_ANALYSIS.md` - Onboarding flow documentation
- `.cursorrules` - Cursor workspace rules
