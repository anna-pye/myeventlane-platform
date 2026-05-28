# Staging deploy: Drush CLI memory limit

## Problem

Staging deploys run Drush during `scripts/deploy/remote-deploy.sh` (maintenance mode, cache rebuilds, optional `updb`/`cim`, domain `cset`, finalize). On constrained hosts, `drush cr` and related steps can exhaust PHP CLI memory (often 128M on the server) and exit with errors such as “Drush command terminated abnormally” after a successful bootstrap.

## Audit (2026-05)

| Layer | Finding |
|-------|---------|
| **CI** | `.github/workflows/deploy-staging.yml` SSHs to the host and runs `bash scripts/deploy/remote-deploy.sh` with `APP_ENV=staging`, `SITE_URI`, `ARTIFACT_PATH`. No PHP memory settings in the workflow. |
| **Deploy script** | All Drush usage is in `scripts/deploy/remote-deploy.sh`. Rollback/cleanup uses the same script (`mel_deploy_cleanup` trap). |
| **Composer** | No deploy-time Drush wrappers in root `composer.json` scripts. |
| **Drush 13** | `vendor/bin/drush` is a shell launcher → `drush.php`. **`DRUSH_PHP_OPTIONS` is not honored** by that launcher. Prior `export DRUSH_PHP_OPTIONS=...` in the deploy script had no effect. |
| **Web PHP** | Unchanged. This fix only affects CLI invocations from the deploy script. |

## Fix

1. **`mel_drush()`** — every deploy Drush call goes through PHP with explicit `-d` flags on `vendor/bin/drush.php`:

   ```bash
   php \
     -d "memory_limit=${MEL_DRUSH_PHP_MEMORY}" \
     -d opcache.enable_cli=0 \
     vendor/bin/drush.php "$@"
   ```

2. **Default** — `MEL_DRUSH_PHP_MEMORY=1024M` (raised from ineffective 512M via `DRUSH_PHP_OPTIONS`).

3. **Pre-switch `drush cr` removed** — cache rebuild on the unreleased tree before symlink switch was a major memory peak; finalize still runs `mel_drush cr` after switch.

4. **Maintenance on previous release** — when `current/` exists, maintenance mode is enabled from the live release directory (lighter bootstrap) before switching symlink.

5. **`mel_drush_run`** — streams stdout/stderr (no capture) so PHP fatals appear in CI/SSH logs.

6. **Rollback / cleanup** — unchanged: failed deploy rolls back `current/` and runs `mel_drush` maintenance off + `cr` via the same wrapper.

7. **`mel_drush_log_cli_limits`** — logs host CLI `memory_limit` / `max_execution_time` before Drush steps.

## Overrides (staging host or CI)

```bash
MEL_DRUSH_PHP_MEMORY=-1 bash scripts/deploy/remote-deploy.sh
# or
MEL_DRUSH_PHP_MEMORY=2G bash scripts/deploy/remote-deploy.sh
```

Do **not** change PHP-FPM / Apache `memory_limit` for this issue.

## Local dry-run (no deploy)

From project root with database available:

```bash
MEL_DRUSH_PHP_MEMORY=1024M
php -d "memory_limit=${MEL_DRUSH_PHP_MEMORY}" -d opcache.enable_cli=0 \
  vendor/bin/drush.php status --uri=https://myeventlane.ddev.site
php -d "memory_limit=${MEL_DRUSH_PHP_MEMORY}" vendor/bin/drush.php php:eval 'echo ini_get("memory_limit");' --uri=...
```

(DDEV may still cap `memory_limit` in the container; staging bare-metal CLI uses the `-d` values.)

## `drush deploy` exit 255 after “No pending updates”

Symptom on staging:

```text
The command ".../vendor/bin/drush updatedb --uri=default" failed.
Exit Code: 255
Error Output: [success] No pending updates.
```

**Cause:** `drush deploy` runs `updatedb` as a subprocess. With default options, `updatedb` calls `drupal_flush_all_caches()` even when there are no hook updates. On hosts with low CLI `memory_limit` (often 128M), that flush can fatal **after** the success line, so SiteProcess reports exit 255. `drush deploy` then runs `cache:rebuild` anyway, so the `updatedb` flush is redundant.

**Repo fixes:**

| Change | Purpose |
|--------|---------|
| [`drush/drush.yml`](../drush/drush.yml) | `updatedb` option `cache-clear: false` (deploy still runs `cache:rebuild`) |
| [`scripts/deploy/mel-deploy.sh`](../scripts/deploy/mel-deploy.sh) | Manual deploy wrapper: `php -d memory_limit=1024M vendor/bin/drush.php deploy` + `SITE_URI` |
| [`drush/sites/default.site.yml`](../drush/sites/default.site.yml) | Relative `root: ../web` (was a stale absolute path) |

**On staging (after pulling the branch):**

```bash
cd ~/staging/current
SITE_URI=https://staging.myeventlane.com.au bash scripts/deploy/mel-deploy.sh
```

Avoid bare `~/bin/drush512 deploy` without `-l` / `SITE_URI` (Drush defaults to `--uri=default`).

## Residual risk

- `1024M` may still be insufficient for very large config imports; set `MEL_DRUSH_PHP_MEMORY=-1` if needed.
- Removing pre-switch `drush cr` means caches are only rebuilt post-switch (intended trade-off for memory).
- Manual `drush updb` no longer clears caches automatically; run `drush cr` afterward if needed.
- Does not fix non-memory Drush failures (DB down, bad `cim`, etc.).
