# MyEventLane Platform — Git & Repository Rules (Development Reference)

**Purpose:**
Prevent Git worktree conflicts, accidental branch locking, and detached worktrees.
Ensure all work happens in the canonical repository only.

⸻

## Canonical repository (mandatory)

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

## Absolute restrictions (do not violate)

- Do not create or use Git worktrees
- Do not lock or check out `main` outside the canonical path
- Do not create detached HEAD states
- Do not recreate main worktrees implicitly or explicitly

**If any instruction would require the above, STOP and ASK.**

⸻

## Allowed workflow (only this)

1. All commands MUST run from the project root: `/Users/anna/myeventlane`

2. **Feature work:**
   - Always create or use a `feature/*` branch
   - Never commit directly to `main`

3. **Merging:**
   - Use GitHub Pull Requests to merge into `main`
   - Never attempt to locally force-merge into `main`

4. **If main is unavailable:**
   - Assume a worktree conflict
   - Do not attempt recovery
   - Ask for confirmation before proceeding

⸻

## Safety checks (run first)

Before suggesting Git commands, verify:

```bash
pwd
git status
git branch
```

**If `pwd` is not the project root, STOP.**

⸻

## Cleanup rules

If cleaning up Git state:

- You may suggest: `git worktree list`
- You may suggest removal only after confirmation
- You must explain consequences before destructive commands
- You must never auto-remove worktrees

⸻

## Communication rules

- Be explicit
- Prefer GitHub PRs over local merges
- If uncertain, ASK instead of guessing
- Treat repository safety as higher priority than speed

⸻

## End goal

The repository must always satisfy:

- One canonical working directory
- No accidental worktrees
- Clean, auditable Git history
- Zero risk of lost work

⸻

## Final instruction

**If any instruction conflicts with the above rules:**

**STOP AND ASK FOR CONFIRMATION**

⸻

## Related documentation (development reference only)

- `docs/ONBOARDING_ANALYSIS.md` — Onboarding flow documentation
