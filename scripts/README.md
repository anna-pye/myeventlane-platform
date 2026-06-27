# MEL repository scripts

Operational scripts for local development, audits, deployment, and governance. Run from the **repository root** unless noted.

**Dangerous scripts** live in [`dangerous/`](dangerous/). Read the header comment in each file before running. Never run `dangerous/` scripts on staging or production.

---

## Root scripts (moved from repository root)

### Safe / diagnostic (local DDEV)

| Script | Purpose | Safe environment | Destructive | Required confirmation |
|--------|---------|------------------|-------------|----------------------|
| `preflight-health-check.sh` | DB, cache, config sync, and filesystem smoke checks | Local DDEV | No | None |
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
