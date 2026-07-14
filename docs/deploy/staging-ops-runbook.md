# Staging deployment operations runbook

Operator reference for the **Git-driven staging deployment** model after the deployment recovery programme.

**Scope:** staging only (`APP_PATH=$HOME/staging`, `https://staging.myeventlane.com.au`).

**Out of scope:** production HOLD at `/home/mel/sites/myeventlane_hold` — read-only; never modify, deploy, restart, or retarget.

This document does **not** change deployment architecture. It describes the layout and procedures already implemented by:

- [`.github/workflows/deploy-staging.yml`](../../.github/workflows/deploy-staging.yml)
- [`.github/workflows/reusable-build.yml`](../../.github/workflows/reusable-build.yml)
- [`scripts/deploy/remote-deploy.sh`](../../scripts/deploy/remote-deploy.sh)

Related:

- [STAGING_DEPLOY_GIT.md](../STAGING_DEPLOY_GIT.md) — how pushes/merges to `main` trigger deploy
- [release-provenance.md](./release-provenance.md) — `REVISION` format, `show-release.sh`, post-deploy verification
- [recovery-artefacts-disposition.md](./recovery-artefacts-disposition.md) — leftover recovery files and disposition

## Release layout

Capistrano-style tree under `$HOME/staging` (`APP_PATH`):

| Path | Role |
|------|------|
| `~/staging/releases/<YYYYMMDDHHMMSS>/` | Immutable release directory from the GitHub Actions artifact |
| `~/staging/current` | Symlink to the active release directory |
| `~/staging/shared/` | Shared runtime (private files, host settings, etc.) |
| `~/staging/config/sync` | Shared config sync directory (must match `$settings['config_sync_directory']`) |
| `~/staging/releases/<ts>/REVISION` | Release provenance (also at `~/staging/current/REVISION`) |

Release retention: `MEL_KEEP_RELEASES` (default `3` non-current releases) in `remote-deploy.sh`.

## Shared runtime

Shared data lives outside the release tree so symlink switches do not lose uploads or host settings:

- Private files: typically `~/staging/shared/private` (Drush `Files, Private` on staging)
- Host `settings.php` / secrets: under the shared sites path wired by deploy (not committed)
- Config sync: `~/staging/config/sync` (shared; not overwritten by each release tree alone)

Do not hand-edit files inside an active `releases/<ts>/` tree to “fix” a deploy. Redeploy from GitHub or use rollback.

## DocumentRoot and public_html symlinks

cPanel DocumentRoot for staging is under `~/public_html/staging/`. Deploy keeps Apache and Drush on the same release via:

| Path | Expected state |
|------|----------------|
| `~/public_html/staging/current` | Symlink → `~/staging/current` |
| `~/public_html/staging/web` | Symlink → `~/staging/current/web` |
| `~/staging/current` | Symlink → `~/staging/releases/<active>/` |

Controlled by `MEL_ENABLE_WEB_CURRENT_SYMLINK` / `MEL_WEB_CURRENT_PATH` in `remote-deploy.sh` (staging defaults to `$HOME/public_html/staging/current`).

## Current symlink

Activation is switching `~/staging/current` (and the public_html mirrors) to the new release directory. On failure after maintenance or switch, `remote-deploy.sh` EXIT cleanup rolls `current` back to the previous release when known and turns maintenance off.

## REVISION and provenance

Every release ships a top-level `REVISION` KEY=VALUE file. Build writes build-time fields; remote deploy stamps `deploy_time_utc` and `release_dir` only.

See [release-provenance.md](./release-provenance.md) for the full field table and failure behaviour.

## Operator inspection: `show-release.sh`

On the staging host (read-only inspection):

```bash
scripts/deploy/show-release.sh --path /home/mel/staging/current
scripts/deploy/show-release.sh --path /home/mel/staging/current --verify
```

`--verify` must report Composer lock and deploy script as **verified**, and exit **0**. Never source `REVISION` as shell.

## Rollback

### Automatic (deploy failure)

`remote-deploy.sh` traps EXIT: if deploy fails after maintenance and/or `current` switch, it restores the previous `current` when known and disables maintenance mode.

### Operator (staging broken after a successful deploy)

1. List recent releases: `ls -1dt ~/staging/releases/*/ | head`
2. Point `~/staging/current` at a known-good release directory (same pattern deploy uses — prefer a controlled redo of the symlink, then rebuild caches).
3. Confirm `~/public_html/staging/current` and `web` still resolve to `~/staging/current` / `~/staging/current/web`.
4. `cd ~/staging/current && php -d memory_limit=1024M vendor/bin/drush.php cr --uri=https://staging.myeventlane.com.au`
5. Prefer fixing forward with a new GitHub merge to `main` when the bad release came from bad code.

Do **not** restore the Stage-3 empty DocumentRoot directories (`current.real-empty-*` / `web.real-empty-*`) except as an explicit emergency documented in [recovery-artefacts-disposition.md](./recovery-artefacts-disposition.md).

Do **not** run `/home/mel/staging/remote-deploy.631023e16.sh` — that is a recovery-era backup, not the live deploy path.

## Health checks

Deploy performs an HTTP health check against `MEL_HEALTHCHECK_URL` (default `SITE_URI`) unless `MEL_HTTP_HEALTHCHECK=0`.

Operator smoke after deploy:

```bash
curl -sI https://staging.myeventlane.com.au/ | head -20
# Expect: HTTP 200, X-Generator: Drupal 11, X-Robots-Tag: noindex...
# Not: 500, directory listing, unexpected redirects to production HOLD
```

Drush:

```bash
cd ~/staging/current
php -d memory_limit=1024M vendor/bin/drush.php status --uri=https://staging.myeventlane.com.au
```

Expect: `Drupal bootstrap : Successful`, database connected.

## Recovery process (summary)

1. **Do not** touch production HOLD.
2. Prefer **redeploy from GitHub** (`main` merge) over server-side patches.
3. Use **automatic rollback** on failed deploy; use **operator rollback** only if a successful deploy left staging unhealthy.
4. Verify with `show-release.sh --verify` and HTTP/Drush health checks.
5. Treat leftover recovery files per [recovery-artefacts-disposition.md](./recovery-artefacts-disposition.md) — review before delete; never `rm -rf` without confirmation.

## Operator workflow

1. Develop on a feature branch; do not merge until ready to deploy staging.
2. Open PR → merge to `main` (triggers Build + Deploy Staging).
3. Watch GitHub Actions: Build artifact → remote deploy → Verify release → Verify deployed REVISION provenance.
4. On green: optionally SSH and run `show-release.sh --verify`.
5. On red: read the first failed step log; fix in Git; redeploy. Do not hand-patch the live release tree.

## Deployment verification checklist

- [ ] Pre-activation: `mel:qr-secret-status` PASS (enforced by `remote-deploy.sh` before `current/` symlink switch on staging/production)

- [ ] Actions **Build** and **Deploy to staging** succeeded for the expected `github.sha`
- [ ] `~/staging/current/REVISION` `artifact_sha` equals that commit
- [ ] `composer_lock_sha256` and `deploy_script_sha256` match the repository at that commit
- [ ] `show-release.sh --path ~/staging/current --verify` exits 0
- [ ] HTTP homepage returns 200 with Drupal headers
- [ ] `drush status` bootstrap successful
- [ ] Production HOLD path untouched
- [ ] No ad-hoc server overlay of `remote-deploy.sh` on the activation path

## Source of truth

| Concern | Source of truth |
|---------|-----------------|
| Deploy scripts & workflows | GitHub repository `main` |
| What is live on staging | `~/staging/current` + `REVISION` |
| Host secrets / DB | Server shared settings (not Git) |
| Production | HOLD — out of staging deploy scope |