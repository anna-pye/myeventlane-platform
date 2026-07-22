# MyEventLane Developer Toolkit v1

Safe, repeatable, self-documenting workflow automation for local MEL development.

These scripts **do not** change Drupal runtime behaviour. They orchestrate Git, worktrees, DDEV, and existing validation helpers under `scripts/`.

## Conventions

| Role | Path | Branch |
|------|------|--------|
| Integration (permanent) | `~/myeventlane` | always `origin/main` |
| Feature work | `~/myeventlane-wt-<slug>` | `feature/mel-<slug>` |

Never do feature work directly in `~/myeventlane`.

## Scripts

| Script | Purpose |
|--------|---------|
| `mel-sync-main.sh` | Sync integration to `origin/main`, validate, `drush cr` |
| `mel-start-feature.sh` | Create branch + worktree + DDEV isolation |
| `mel-finish-feature.sh` | Pre-PR validation gates (never pushes) |
| `mel-review-main.sh` | Sync + daily product review checklist |
| `mel-clean-branches.sh` | Review merged branches; delete only with confirmation |
| `mel-status.sh` | Project health snapshot |
| `mel-common.sh` | Shared library (sourced by the scripts above) |

## Composition (do not duplicate)

The toolkit calls existing project scripts:

- `scripts/check-config-safety.sh`
- `scripts/check-webroot-safety.sh`
- `scripts/validate-push.sh`
- `scripts/mel-phpunit`

Release / deploy remain:

- `scripts/validate-release.sh`
- `scripts/deploy/*`

## Quick start

From any clone that contains this toolkit:

```bash
# Daily: refresh integration for UX review
bash scripts/dev/mel-sync-main.sh

# Start work
bash scripts/dev/mel-start-feature.sh my-short-slug
cd ~/myeventlane-wt-my-short-slug

# Before opening a PR
bash scripts/dev/mel-finish-feature.sh
git push -u origin HEAD   # manual — finish never pushes
```

## Recommended aliases

Document only — do **not** auto-edit `~/.zshrc`:

```bash
alias melmain="bash scripts/dev/mel-sync-main.sh"
alias melfeature="bash scripts/dev/mel-start-feature.sh"
alias melreview="bash scripts/dev/mel-review-main.sh"
alias melpr="bash scripts/dev/mel-finish-feature.sh"
alias melstatus="bash scripts/dev/mel-status.sh"
alias melclean="bash scripts/dev/mel-clean-branches.sh"
```

Run aliases from a checkout that contains `scripts/dev/`, or wrap with an absolute path to the integration (or active worktree) scripts directory.

## Safety rules

- Never discard uncommitted work
- Stop on dirty trees when syncing or creating features
- Never overwrite existing branches or worktrees
- Never delete branches without interactive confirmation
- Never push or deploy from these scripts
- Never run `scripts/dangerous/*` via this toolkit

## Full documentation

See [`docs/DEVELOPMENT_WORKFLOW.md`](../../docs/DEVELOPMENT_WORKFLOW.md).
