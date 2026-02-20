# Phase 9 — DevOps & Deployment Review

**Audit Date:** 2026-02-20  
**Branch:** audit/staging-2026-Q1

---

## 1. GitHub CI Workflow

**File:** .github/workflows/php-composer.yml

**Triggers:** push + pull_request on `main`

**Steps:**
- Checkout
- Set up PHP 8.3
- composer validate
- Cache vendor
- composer install --prefer-dist --no-progress
- drupal-check (continue-on-error: true)

**Gaps:**
- No config import
- No database updates
- No deployment to staging
- No security audit (composer audit)
- No PHPUnit / PHPStan (drupal-check is optional)
- No DDEV-specific steps

**Classification:** Medium — CI validates install only. No config/schema/deploy automation.

---

## 2. Composer Install Flags

- **CI:** `composer install --prefer-dist --no-progress`
- **Production:** Typically `composer install --no-dev --optimize-autoloader --no-interaction`
- **Recommendation:** Document production flags; ensure --no-dev in prod.

---

## 3. Config Import Automation

- **Status:** Not present in CI.
- **Config sync path:** sites/default/files/sync (DDEV). Standard path for staging may differ.
- **Risk:** Manual config import; drift between envs (see Phase 2).

**Classification:** High — Config import not automated. Staging may diverge.

---

## 4. Database Backup Policy

- **Status:** Not documented in repo.
- **.gitignore:** *.sql, *.sql.gz, backup*, backups/ — backup artifacts excluded.
- **Recommendation:** Document backup schedule, retention, restoration procedure.

---

## 5. Rollback Plan

- **Status:** Not documented in repo.
- **Recommendation:** Document rollback steps (config revert, DB restore, tag revert).

---

## 6. Environment Variable Management

- **.env:** In .gitignore. .env.example allowed.
- **Secrets:** docs/SECRETS_PROTECTION_GUIDE.md exists. Settings.php and config overrides recommended for keys.
- **Issue:** Stripe/Google keys found in committed backup folders (see Phase 4). .env itself not committed.

---

## 7. .env Not Committed

- **Status:** .env and .env.* are in .gitignore. ✓
- **Backup folders:** _INVALID_config_backup_2026-01-02, _myeventlane_audit contain secrets. These should be removed or stripped.

---

## 8. DDEV

- **Usage:** Local development. settings.ddev.php managed by DDEV.
- **Config sync:** Set to sites/default/files/sync.
- **Recommendation:** Consider config sync outside docroot for production (e.g. ../config/sync).

---

## Summary

| Item | Status | Severity |
|------|--------|----------|
| GitHub CI | Basic (validate, install) | Medium |
| Config import automation | None | High |
| Database backup policy | Not documented | Medium |
| Rollback plan | Not documented | Medium |
| .env not committed | OK | — |
| Secrets in repo (backup folders) | Present | **Critical** |
