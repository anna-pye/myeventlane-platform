# MEL repository scripts

Operational scripts for local development, audits, deployment, and governance. Run from the **repository root** unless noted.

**Dangerous scripts** live in [`dangerous/`](dangerous/). Read the header comment in each file before running. Never run `dangerous/` scripts on staging or production.

### Developer Toolkit (workflow)

Day-to-day Git / worktree / DDEV workflow automation lives in [`dev/`](dev/). See [`dev/README.md`](dev/README.md) and [`docs/DEVELOPMENT_WORKFLOW.md`](../docs/DEVELOPMENT_WORKFLOW.md). These scripts compose the validators below; they do not replace release or deploy gates.

---

## Root scripts (moved from repository root)

### Safe / diagnostic (local DDEV)

| Script | Purpose | Safe environment | Destructive | Required confirmation |
|--------|---------|------------------|-------------|----------------------|
| `mel-phpunit` | Local PHPUnit helper: sets missing `SIMPLETEST_*`, ensures `sites/simpletest/browser_output`, runs `vendor/bin/phpunit` | Local DDEV | No | None |
| `preflight-health-check.sh` | DB, cache, config sync, and filesystem smoke checks | Local DDEV | No | None |
| `validate-push.sh` | Ordinary branch push gate: clean tree, review-branch allowlist (includes chore/docs/test), lightweight safety checks | Local | No | None |
| `validate-release.sh` | Canonical pre-deployment release validation: Git, Drush bootstrap, config status, database updates, MEL tests, and theme builds | Local DDEV / staging / production checkout | No | None |
| `rebuild-scss.sh` | Clears theme caches, reinstalls npm deps, rebuilds theme assets | Local DDEV | No (removes theme `node_modules`/`dist`) | None |
| `myeventlane-audit-collector.sh` | Collects git/composer/drush audit snapshot into `_myeventlane_audit/` | Local | No | None |
| `backup-build-and-db.sh` | Exports DB + optional DDEV snapshot and code tarball to `backups/` | Local DDEV | No | None |
| `create-staging-backup.sh` | Exports and sanitises a staging-ready DB dump | Local DDEV | No | Review sanitisation output before sharing dump |
| `check-mel-hero-variants.mjs` | Enforces locked hero SCSS variant (used by `npm run mel:hero-check`) | CI / local; run via theme npm script | No | None |
| `check-attendee-matching.sh` | Diagnoses attendee ↔ user email matching for local test user | Local DDEV | No | None |
| `check-email-queue.sh` | Inspects messaging queue items | Local DDEV | No | None |
| `check-session-status.sh` | Cookie domain and vendor access diagnostics | Local DDEV | No | None |
| `check-session-user.sh` | Prints current session user from Drush | Local DDEV | No | None |
| `check-ticket-issue.sh` | Diagnoses order receipt template and ticket visibility | Local DDEV | No | None |
| `create-email-template.sh` | Enables order receipt template from module install YAML | Local DDEV | No (writes active config) | None |
| `setup-email-template.sh` | Alternative order receipt template bootstrap via php-eval | Local DDEV | No (writes active config) | None |
| `diagnose-anna-access.sh` | Vendor console access diagnosis for user `anna` | Local DDEV | No | None |
| `ensure-uid1-admin.sh` | Ensures UID 1 has administrator role | Local DDEV | No (modifies roles) | Confirm local-only |
| `fix-anna-access.sh` | Adds administrator role to `anna` and clears cache | Local DDEV | No (modifies roles) | Confirm local-only |
| `fix-email-and-tickets.sh` | Bootstraps receipt template and ticket display config | Local DDEV | No (writes active config) | None |
| `fix-vendor-access-complete.sh` | Cache clear + session/cookie remediation instructions | Local DDEV | No | Follow logout/cookie steps manually |
| `fix-vendor-access-routes.sh` | Clears cache after route access changes | Local DDEV | No | None |
| `setup-event-ct.sh` | Creates taxonomies and event content scaffolding | Local DDEV | Partial (creates config entities) | Review before run on non-empty DB |
| `start-ngrok-tunnels.sh` | Starts ngrok via Docker for local tunneling | Local | No | Requires local ngrok config |
| `test-cookie-domain.sh` | Validates cookie domain settings | Local DDEV | No | None |
| `test-email-and-tickets.sh` | Tests receipt template and My Events query | Local DDEV | No | None |
| `test-phase2.sh` | Phase 2 vendor field/module verification | Local DDEV | No | None |
| `test-vendor-access.sh` | VendorConsoleAccess check for UID 1 | Local DDEV | No | None |
| `verify-access-fix.sh` | Post-fix vendor access verification | Local DDEV | No | None |

---

## Push vs release validation

Ordinary `git push` and deployment readiness are separate gates.

| Gate | Script | When | Branch policy |
|------|--------|------|---------------|
| Push (review) | `scripts/validate-push.sh` | Husky pre-push on every push | Accepts `main`, `release/*`, `feature/*`, `fix/*`, `hotfix/*`, `cursor/*`, plus maintenance `chore/*`, `docs/*`, `test/*` |
| Staging release | `scripts/validate-release.sh staging` | Explicit pre-deploy / packaging | `main`, `release/*`, `feature/*`, `fix/*`, `hotfix/*`, `cursor/*` only |
| Production release | `scripts/validate-release.sh production` | Explicit production validation | `main` or annotated tag (`--force` for exceptions) |

Push validation never claims the branch is ready for staging or production.

## Release validation workflow

The release validator runs before artifact packaging. It validates the repository checkout, not the deployed release artifact, and is not designed to run inside the deployment artifact. Artifact deploys may not contain `.git`, tests, or dev dependencies.

For staging:

```bash
bash scripts/validate-release.sh staging
```

If validation fails, fix the reported issues and rerun the validator. Commit and push only after validation passes, then deploy using the existing deploy process.

For production:

```bash
bash scripts/validate-release.sh production
```

Successful validation writes `build/release-metadata.json` with non-secret release metadata for the validated checkout. Failed validation does not write or update release metadata.

Stripe payment gateway config differences may be environment-specific and must be reviewed, not blindly exported.

---

## Local Git hooks

MEL uses Husky for local Git hooks. Hook installation is managed by the root `package.json` `prepare` script.

Install or refresh hooks after installing npm dependencies:

```bash
npm install
```

If dependencies are already installed and the hooks need to be reinstalled:

```bash
npm run prepare
```

### Why hooks exist

The hooks catch release-blocking issues before they reach the shared branch or deployment workflow. They do not deploy code, import Drupal config, modify Commerce config, rebuild caches, or replace the canonical release validator.

### Pre-commit

The pre-commit hook runs only lightweight checks:

```bash
composer validate
bash scripts/check-config-safety.sh
bash scripts/check-webroot-safety.sh
bash scripts/check-no-raw-card-data.sh
```

It intentionally does not run npm builds, governance tests, PHPUnit, or Drush cache rebuilds.

### Pre-push

The pre-push hook runs the ordinary push validator (not the staging release validator):

```bash
bash scripts/validate-push.sh
```

This checks working-tree cleanliness, the review-branch allowlist (including `chore/*`, `docs/*`, and `test/*`), and the same lightweight safety scripts as pre-commit. It does **not** run Drush, config drift review, governance suites, theme builds, or release-metadata writes, and it does **not** treat the branch as a staging deployment candidate.

Deployment readiness remains an explicit release command:

```bash
bash scripts/validate-release.sh staging
bash scripts/validate-release.sh production
```

If push validation fails, Git rejects the push and prints the validator output unchanged.

### Bypassing hooks

Bypass hooks only when there is a clear reason, such as an emergency WIP commit or a known local tooling issue. Run the skipped checks manually before opening a PR or deploying.

```bash
git commit --no-verify
git push --no-verify
```

---

### Dangerous (`dangerous/`)

| Script | Purpose | Safe environment | Destructive | Required confirmation |
|--------|---------|------------------|-------------|----------------------|
| `dangerous/delete-events.php` | Deletes **all** event nodes | Local DDEV, disposable DB only | **YES** | `ddev export-db`; confirm event count; never on staging/prod |
| `dangerous/wipe-custom-config.sh` | SQL DELETE of `myeventlane_*` config keys | Local DDEV, disposable DB only | **YES** | `ddev export-db`; confirm config wipe intent |
| `dangerous/reset-drupal.sh` | Wipes `web/sites/default/files/sync/*` and re-exports config | Local DDEV only | **YES** | Back up sync dir and DB first |
| `dangerous/setup-event-content-type.sh` | Deletes and recreates `event` content type + fields | Local DDEV, disposable data | **YES** | `ddev export-db`; confirm bundle deletion acceptable |
| `dangerous/reset-admin-password.sh` | Resets/creates `anna` with password `admin` | Local DDEV only | **YES** (credential overwrite) | Confirm local dev reset only |

**Run dangerous PHP scripts:**

```bash
ddev drush php:script scripts/dangerous/delete-events.php
```

**Run dangerous shell scripts:**

```bash
./scripts/dangerous/wipe-custom-config.sh
```

---

## Existing script directories

| Directory | Purpose |
|-----------|---------|
| `audit/` | One-off UI/branding/session audits and screenshot tooling |
| `deploy/` | Staging/production deploy and readiness verification |
| `dev/` | Local-only entity/bootstrap helpers |
| `governance/` | Architecture and template parity audits |

Governance inputs (`mel-*.json`) currently remain at repository root pending owner review — see [`docs/audits/repository-root-cleanup.md`](../docs/audits/repository-root-cleanup.md).

---

## Other notable scripts (pre-existing)

| Script | Purpose | Safe environment | Destructive | Required confirmation |
|--------|---------|------------------|-------------|----------------------|
| `git-preflight.sh` | Blocks commits from wrong directory or `main` branch | Local | No | None |
| `check-config-safety.sh` | Config export safety checks | Local / CI | No | None |
| `check-no-raw-card-data.sh` | Scans for raw card data patterns | Local / CI | No | None |
| `check-webroot-safety.sh` | Webroot placement safety | Local / CI | No | None |
| `import-cookie-config.sh` | Cookie config import helper | Local DDEV | No (writes config) | Preview diff first |
| `deploy/mel-deploy.sh` | MEL deployment automation | Staging/production | **YES** | Follow deploy runbook |
| `deploy/remote-deploy.sh` | Remote server deploy | Staging/production | **YES** | Follow deploy runbook |

---

## Conventions

1. Prefer `ddev drush` for Drupal mutations.
2. Do not add new loose scripts to the repository root — place them here.
3. Any script that deletes entities, wipes config, or overwrites credentials belongs in `dangerous/` with a header warning block.
4. After moving or adding scripts, update this file and `docs/audits/repository-root-cleanup.md`.
