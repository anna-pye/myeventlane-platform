# Phase 1 — Dependency & Core Audit

**Audit Date:** 2026-02-20  
**Branch:** audit/staging-2026-Q1  
**Environment:** DDEV local (Drupal 11.3.3, PHP 8.3.23)

---

## 1. Composer Validate

**Result:** VALID (with warnings)

```
./composer.json is valid, but with a few warnings
# General warnings
- require.drupal/gin_login : exact version constraints (2.1.x-dev@dev) should be avoided if the package follows semantic versioning
```

**Classification:** Low — dev constraint best practice

---

## 2. Composer Audit

**Result:** 1 security vulnerability found

| Package | Severity | Advisory | CVE | Title |
|---------|----------|----------|-----|-------|
| firebase/php-jwt | **HIGH** | PKSA-y2cr-5h3j-g3ys | CVE-2025-45769 | php-jwt contains weak encryption |

- **Affected versions:** < 7.0.0
- **Dependency chain:** `sainsburys/guzzle-oauth2-plugin` → `firebase/php-jwt`
- **Note:** guzzle-oauth2-plugin is a transitive dependency of `social_auth_google`
- **URL:** https://github.com/advisories/GHSA-2x45-7fc3-mxwq

**Classification:** **CRITICAL** → Security vulnerability in OAuth/JWT handling. Upgrade path requires guzzle-oauth2-plugin or social_auth_google to use php-jwt ^7.0.

---

## 3. Composer Outdated (Direct)

| Package | Current | Available | Type |
|---------|---------|-----------|------|
| cweagans/composer-patches | 1.7.3 | 2.0.0 | Major |
| drupal/commerce | 3.3.0 | 3.3.2 | Patch/Minor |
| drupal/commerce_stripe | 1.3.0 | 2.2.1 | Major |
| drupal/core-dev | 11.3.1 | 11.3.3 | Patch |
| mglaman/phpstan-drupal | 1.3.9 | 2.0.10 | Major |
| phpstan/phpstan | 1.12.32 | 2.1.39 | Major |
| slevomat/coding-standard | 8.22.1 | 8.27.1 | Patch/Minor |
| squizlabs/php_codesniffer | 3.13.5 | 4.0.1 | Major |
| symfony/config | 7.4.4 | 8.0.4 | Major |
| symfony/var-dumper | 7.4.4 | 8.0.4 | Major |

**Classification:**
- **High:** drupal/commerce 3.3.0 → 3.3.2 (security/compatibility patches)
- **Medium:** slevomat/coding-standard, drupal/core-dev (outdated minor)
- **Low:** Major upgrades (require compatibility testing)

---

## 4. Drush Status

**Result:** OK

- Drupal: 11.3.3
- DB: MySQL, connected
- Site URI: https://myeventlane.ddev.site
- Config sync: sites/default/files/sync
- PHP: 8.3.23
- Drush: 13.7.1.0

**Note:** Config sync uses DDEV default path (`sites/default/files/sync`), not `/config/sync` as assumed in audit spec.

---

## 5. Drush entity:updates

**Result:** Command not available in Drush 13

The `entity:updates` command was removed in Drupal 9+. Schema changes are applied via `drush updatedb`. Documented in `docs/support-architecture.md`: "Entity updates: entity:updates not available; schema updates run via updb."

---

## 6. Drush updatedb:status

**Result:** No pending DB updates

**Warning (HIGH):**
```
myeventlane_commerce: module cannot be updated. It contains an update numbered as 8000 
which is reserved for the earliest installation of a module in Drupal 8.x, before any updates. 
In order to update myeventlane_commerce module, you will need to download a version of the 
module with valid updates.
```

**Classification:** **HIGH** — Update hook `myeventlane_commerce_update_8000()` uses reserved number. For Drupal 11 modules, first update should be `9101` or similar. Blocks future `hook_update_N()` usage in this module.

---

## 7. Drush config:status

**Result:** 6 items with config drift (see Phase 2 report)

---

## 8. Enabled Modules Summary

- **Core:** Standard Drupal 11 core modules
- **Commerce:** commerce 3.3.0, commerce_stripe 1.3
- **Contrib:** conditional_fields (alpha6), inline_entity_form (rc), gin_login (dev)
- **Custom:** ~50+ myeventlane_* modules

---

## Classification Summary

| Severity | Count | Items |
|----------|-------|-------|
| **Critical** | 1 | firebase/php-jwt CVE-2025-45769 |
| **High** | 2 | myeventlane_commerce update_8000; drupal/commerce patch lag |
| **Medium** | 2 | slevomat, core-dev outdated |
| **Low** | 4+ | gin_login constraint; major upgrade lag |
