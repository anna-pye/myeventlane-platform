# Staging deploy: Drush CLI memory limit

## Problem

Staging deploys run Drush during `scripts/deploy/remote-deploy.sh` (maintenance mode, cache rebuilds, optional `updb`/`cim`, domain `cset`, finalize). On constrained hosts, `drush cr` and related steps can exhaust PHP CLI memory and exit with errors such as “Drush command terminated abnormally” after a successful bootstrap.

## Audit (2026-05)

| Layer | Finding |
|-------|---------|
| **CI** | `.github/workflows/deploy-staging.yml` SSHs to the host and runs `bash scripts/deploy/remote-deploy.sh` with `APP_ENV=staging`, `SITE_URI`, `ARTIFACT_PATH`. No PHP memory settings in the workflow. |
| **Deploy script** | All Drush usage is in `scripts/deploy/remote-deploy.sh`. Rollback/cleanup uses the same script (`mel_deploy_cleanup` trap). |
| **Composer** | No deploy-time Drush wrappers in root `composer.json` scripts. |
| **Drush 13** | `vendor/bin/drush` is a shell launcher → `drush.php`. **`DRUSH_PHP_OPTIONS` is not read by Drush 13** (no references in `vendor/drush`). A prior `export DRUSH_PHP_OPTIONS=...` in the deploy script did not apply PHP flags. |
| **Web PHP** | Unchanged. This fix only affects CLI invocations from the deploy script. |

Drush 13 supports deploy-time CLI flags via **`--php-options`**, which maps to `runtime.php.options` and is applied to Drush subprocesses (e.g. `updatedb`).

## Fix

1. **`mel_drush()`** — single wrapper used for every deploy Drush call:

   ```bash
   vendor/bin/drush --php-options="-d memory_limit=${MEL_DRUSH_PHP_MEMORY_LIMIT} ${MEL_DRUSH_PHP_EXTRA}" "$@"
   ```

2. **Defaults**
   - `MEL_DRUSH_PHP_MEMORY_LIMIT` → `-1` (unlimited CLI memory for deploy)
   - Falls back to `PHP_MEMORY_LIMIT` if set
   - `MEL_DRUSH_PHP_EXTRA` → `-d opcache.enable_cli=0` (avoids stale class issues during container rebuild)

3. **Rollback / cleanup** — unchanged behaviour: failed deploy still rolls back `current/` and runs `mel_drush` maintenance off + `cr` via the same wrapper.

## Overrides (staging host or CI)

```bash
# Cap memory instead of unlimited (example)
MEL_DRUSH_PHP_MEMORY_LIMIT=1G bash scripts/deploy/remote-deploy.sh

# Or use the generic alias
PHP_MEMORY_LIMIT=512M bash scripts/deploy/remote-deploy.sh
```

Do **not** change PHP-FPM / Apache `memory_limit` for this issue.

## Local dry-run (no deploy)

From a release directory with `vendor/bin/drush` and valid `settings.php`:

```bash
export SITE_URI=https://staging.myeventlane.com.au
MEL_DRUSH_PHP_MEMORY_LIMIT=-1
source /path/to/scripts/deploy/remote-deploy.sh  # defines mel_drush only if sourced carefully — prefer:

mel_drush() {
  vendor/bin/drush --php-options="-d memory_limit=-1 -d opcache.enable_cli=0" "$@"
}
mel_drush status --uri="$SITE_URI"
mel_drush php:eval 'echo ini_get("memory_limit");'
```

Expect `memory_limit` to reflect the override (often `-1`).

## Residual risk

- Unlimited CLI memory can allow a runaway Drush process to use more RAM on a small VPS; override with `MEL_DRUSH_PHP_MEMORY_LIMIT=1G` if needed.
- Does not fix non-memory Drush failures (DB down, bad config import, etc.).
