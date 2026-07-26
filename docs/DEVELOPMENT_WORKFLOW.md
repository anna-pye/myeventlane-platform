# MyEventLane Development Workflow

**Status:** Active repository workflow

**Authority:** [PDR-001](product-decisions/PDR-001-governance-baseline-authority.md) · [CONTRIBUTING.md](../CONTRIBUTING.md)

This document is the standard local development workflow for MyEventLane (MEL).

Tooling lives in [`scripts/dev/`](../scripts/dev/). It is **developer-only**: it does not change Drupal runtime behaviour and does not deploy.

---

## Roles of checkouts

### Integration — `~/myeventlane`

- Permanent local integration environment
- Must track **`origin/main`**
- Used for UX review and day-to-day product verification
- **No feature development** in this tree

### Feature worktrees — `~/myeventlane-wt-*`

- One worktree per feature (or fix)
- Branch naming: `feature/mel-<slug>` (fixes may use `fix/mel-<slug>` when created manually)
- Isolated DDEV project via gitignored `.ddev/config.local.yaml`

---

## Integration workflow

```bash
bash scripts/dev/mel-sync-main.sh
# optional smoke:
bash scripts/dev/mel-sync-main.sh --smoke
```

What it does:

1. Verifies `~/myeventlane` is a Git repo
2. Requires a **clean** working tree (never discards work)
3. Requires branch **`main`**
4. `git fetch` + fast-forward `pull` of `origin/main`
5. Ensures DDEV is running
6. Composer validate
7. Config safety + webroot safety (existing scripts)
8. `drush cr`
9. Prints an integration-ready summary

If the tree is dirty or not on `main`, stop and fix that first.

---

## Feature workflow

```bash
# From any machine path that can invoke the toolkit scripts:
bash ~/myeventlane/scripts/dev/mel-start-feature.sh vx2-tickets

cd ~/myeventlane-wt-vx2-tickets
# develop… commit…

bash scripts/dev/mel-finish-feature.sh
git push -u origin HEAD
# open PR against main
```

`mel-start-feature.sh`:

- Verifies integration is clean and on `main`
- Creates `feature/mel-<slug>` from `origin/main`
- Adds worktree `~/myeventlane-wt-<slug>`
- Writes DDEV `config.local.yaml` (unique name + ports)
- Starts DDEV
- Refuses to overwrite existing branches or paths

`mel-finish-feature.sh`:

- Composer validate, PHPCS, PHPUnit, `drush cr`, config status
- Config + webroot safety
- Composes `scripts/validate-push.sh`
- Prints git status, diffstat, and commits
- **Stops on failure**
- **Never pushes**

Skip long gates only when you understand the risk:

```bash
MEL_SKIP_PHPUNIT=1 MEL_SKIP_PHPCS=1 bash scripts/dev/mel-finish-feature.sh
```

---

## Merge workflow

1. Finish feature (green gates)
2. Push branch manually
3. Open PR → `main`
4. Pass CI / review
5. Merge via GitHub (no force-push to `main`)
6. Sync integration: `mel-sync-main.sh`
7. Optionally clean merged locals: `mel-clean-branches.sh`

Do not merge from these scripts. Do not deploy from these scripts.

---

## Daily review

```bash
bash scripts/dev/mel-review-main.sh
# or, if already synced:
bash scripts/dev/mel-review-main.sh --skip-sync
```

Human checklist (printed by the script):

- Dashboard, Create Event, Event Workspace, Tickets, Attendees
- Orders, Payments, Analytics, Marketing
- Mobile, Accessibility, Console errors, Performance

---

## Release workflow

Toolkit scripts are **not** release validators.

| Gate | Command |
|------|---------|
| Ordinary push | `bash scripts/validate-push.sh` (Husky pre-push) |
| Staging release | `bash scripts/validate-release.sh staging` |
| Production release | `bash scripts/validate-release.sh production` |
| Deploy | `scripts/deploy/*` (explicit, production-aware) |

See [`scripts/README.md`](../scripts/README.md).

---

## Branch naming

Suggested prefixes (aligned with push allowlist):

```text
feature/mel-short-description
fix/mel-short-description
hotfix/mel-short-description
chore/mel-short-description
docs/mel-short-description
```

`mel-start-feature.sh` creates `feature/mel-<slug>` by default.

---

## Worktree usage

```text
~/myeventlane                    → integration (main)
~/myeventlane-wt-vx2-tickets     → feature/mel-vx2-tickets
~/myeventlane-wt-apple-wallet-…  → feature work
```

List worktrees:

```bash
git -C ~/myeventlane worktree list
```

Remove a worktree only after the branch is merged or abandoned:

```bash
git -C ~/myeventlane worktree remove ~/myeventlane-wt-vx2-tickets
```

Each worktree should use a unique DDEV project name (toolkit writes `.ddev/config.local.yaml`).

---

## Safety rules

1. Never discard uncommitted work (`reset --hard`, `clean -fd` are out of scope)
2. Stop on dirty trees for sync / start-feature
3. Never overwrite existing branches or worktree paths
4. Never delete branches without confirmation (`mel-clean-branches.sh`)
5. Never auto-push or auto-deploy
6. Never weaken access control or commit secrets
7. Prefer composition of existing `scripts/*` validators

---

## DDEV conventions

| Checkout | Project name | Notes |
|----------|--------------|-------|
| `~/myeventlane` | `myeventlane` | Fixed ports in `.ddev/config.yaml` |
| `~/myeventlane-wt-*` | `myeventlane-wt-<slug>` | `config.local.yaml` overrides name + ports |

Typical URLs (integration):

- https://myeventlane.ddev.site
- https://vendor.myeventlane.ddev.site
- https://admin.myeventlane.ddev.site

Worktree URLs follow the local project name from `ddev describe`.

---

## Status and housekeeping

```bash
bash scripts/dev/mel-status.sh
bash scripts/dev/mel-clean-branches.sh
bash scripts/dev/mel-clean-branches.sh --remote-prune-preview
```

---

## Recommended aliases

Add to `~/.zshrc` yourself (toolkit does **not** modify shell config):

```bash
alias melmain="bash scripts/dev/mel-sync-main.sh"
alias melfeature="bash scripts/dev/mel-start-feature.sh"
alias melreview="bash scripts/dev/mel-review-main.sh"
alias melpr="bash scripts/dev/mel-finish-feature.sh"
alias melstatus="bash scripts/dev/mel-status.sh"
alias melclean="bash scripts/dev/mel-clean-branches.sh"
```

Tip: define aliases with absolute paths if you jump between worktrees often:

```bash
alias melmain="bash $HOME/myeventlane/scripts/dev/mel-sync-main.sh"
alias melfeature="bash $HOME/myeventlane/scripts/dev/mel-start-feature.sh"
```

After merging the toolkit to `main`, sync integration so those paths stay current.

---

## Environment overrides

| Variable | Meaning |
|----------|---------|
| `MEL_INTEGRATION_ROOT` | Override integration path (default `~/myeventlane`) |
| `MEL_WORKTREE_PARENT` | Parent dir for worktrees (default `$HOME`) |
| `MEL_BRANCH_PREFIX` | Default `feature/mel-` |
| `MEL_SKIP_PHPUNIT` / `MEL_SKIP_PHPCS` / `MEL_SKIP_DRUSH` | Skip finish gates |
| `MEL_PHPUNIT_TARGET` | Override PHPUnit suite path(s) for finish (space-separated) |
| `NO_COLOR=1` | Disable colour |

---

## Related docs

- [`scripts/dev/README.md`](../scripts/dev/README.md) — toolkit index
- [`scripts/README.md`](../scripts/README.md) — all repository scripts
- [`docs/GIT_PUSH_WORKFLOW.md`](GIT_PUSH_WORKFLOW.md) — push basics
