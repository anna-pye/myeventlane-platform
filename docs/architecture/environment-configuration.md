# Environment configuration

**Date:** 12 July 2026  
**Scope:** Production-safe `config/sync` defaults, local DDEV overrides, and the settings include chain.

## Production-safe defaults (committed in `config/sync`)

| Config object | Key | Production value |
|---------------|-----|------------------|
| `system.logging` | `error_level` | `hide` |
| `system.performance` | `cache.page.max_age` | `900` |
| `system.performance` | `css.preprocess` | `true` |
| `system.performance` | `css.gzip` | `true` |
| `system.performance` | `js.preprocess` | `true` |
| `system.performance` | `js.gzip` | `true` |

These are the Drupal 11 schema keys confirmed via `config.typed` (`error_level` choices: `hide`, `some`, `all`, `verbose`).

## Local DDEV overrides

Loaded only when `IS_DDEV_PROJECT=true` (see settings include order below).

| Source | Overrides |
|--------|-----------|
| `web/sites/default/settings.ddev.php` (DDEV-generated, gitignored) | DB credentials, hash salt, Mailpit, `system.logging:error_level=verbose`, trusted hosts `.*` |
| `web/sites/default/settings.local.php` (gitignored via `*.local.php`) | Trusted hosts for `*.ddev.site`, domain settings for DDEV, reverse proxy, **verbose logging**, **CSS/JS preprocess off**, **page max_age 0** |

Do not export active DDEV values back into `config/sync`. After local config changes, review `drush config:export --diff` and discard performance/logging drift.

## Settings include order

### Local (DDEV)

1. `web/sites/default/settings.php` (gitignored; deployed copy lives in host `shared/` on staging/production)
2. Sets `config_sync_directory` → repo `config/sync`
3. Optional `MEL_REQUIRE_VERIFY_MAIL` override
4. **If** `IS_DDEV_PROJECT=true` and file exists → `settings.ddev.php`
5. **If** readable → `settings.mel_shared_session.php` (tracked; session YAML, Stripe/Postmark/QR env merges, DDEV domain defaults)
6. **If** readable → `settings.mel_domains.php` (gitignored; host-specific domains on staging/production shared)
7. Optional ngrok reverse-proxy block
8. `MEL_OPENAI_API_KEY` → `$settings['myeventlane_ai']`
9. Domain settings from `MEL_*_DOMAIN` env (DDEV defaults when unset)
10. Optional `STRIPE_PK` / `STRIPE_SK` gateway overrides (non-empty only)
11. **If** `IS_DDEV_PROJECT=true` and file exists → `settings.local.php`
12. Staging AU session YAML re-append (host contains `staging.myeventlane.com.au`)
13. Guard: throw if domain settings reference `ddev.site` when not on DDEV
14. Auth JWT / OAuth secrets from env

### Staging / production

Same chain **except**:

- `settings.ddev.php` is **not** loaded (`IS_DDEV_PROJECT` unset)
- `settings.local.php` is **not** loaded (explicit DDEV guard in `settings.php`; deploy script also refuses to symlink it)
- `settings.php` is a symlink from release `sites/default/` → `~/…/shared/settings.php`
- Each deploy syncs tracked `settings.mel_shared_session.php` into shared
- Session cookie YAML selected by host in `settings.mel_shared_session.php`:
  - `mel.session.staging-au.yml` / `mel.session.staging-com.yml`
  - `mel.session.production-au.yml` / `mel.session.production.yml`
- Domains and secrets come from environment variables and optional `settings.mel_domains.php` in shared

## Tracked vs outside Git

| Path | Git |
|------|-----|
| `config/sync/**` | Tracked |
| `web/sites/default/settings.mel_shared_session.php` | Tracked |
| `web/sites/default/mel.session.*.yml` | Tracked |
| `web/sites/default/services.yml` | Tracked |
| `web/sites/default/settings.php` | **Not tracked** (gitignore `*settings*.php`; force-add only with explicit approval) |
| `web/sites/default/settings.ddev.php` | **Not tracked** |
| `web/sites/default/settings.local.php` | **Not tracked** |
| `web/sites/default/settings.mel_domains.php` | **Not tracked** |
| `.ddev/config.local.yaml` / host env for secrets | **Not tracked** |

## Safety properties

- Local cannot load production session YAML while `IS_DDEV_PROJECT=true`.
- Staging/production cannot load `settings.local.php`.
- Missing optional local files do not fatal (`file_exists` / `is_readable` guards).
- Missing required shared session fragment fails deploy verification in `scripts/deploy/remote-deploy.sh`.
- Secrets must not live in tracked PHP/YAML; use `MEL_STRIPE_*`, `STRIPE_*`, `MEL_POSTMARK_*`, `MEL_QR_SECRET`, `MEL_AUTH_*`, `MEL_OPENAI_API_KEY`.
