# MyEventLane v2 — Staging Audit Executive Summary

**Audit Date:** 2026-02-20  
**Branch:** audit/staging-2026-Q1  
**Scope:** Full staging audit (Phases 0–9)

---

## Launch Readiness Status

**NOT LAUNCH SAFE**

Critical and High issues must be remediated before production launch.

---

## Issue Summary

| Severity | Count | Key Items |
|----------|-------|-----------|
| **Critical** | 4 | firebase/php-jwt CVE; Stripe/Google keys in repo; Twig XSS (accessibility fields); Stripe webhook unverified if secret empty |
| **High** | 8 | Config drift (6 items); myeventlane_commerce update_8000; Commerce patch lag; CSS/JS aggregation disabled; anonymous access commerce_order overview |
| **Medium** | 6 | Postmark webhook (simple secret); alpha/RC contrib; anonymous view unpublished paragraphs; checkout \|raw; render-blocking JS |
| **Low** | 5+ | gin_login constraint; major upgrade lag; cart expiration; DB index verification; page cache off (DDEV) |

---

## Top 5 Immediate Risks

1. **Secrets in version control** — Stripe keys and Google Maps API key in `_INVALID_config_backup_2026-01-02/` and `_myeventlane_audit/config-sync/`. Rotate keys and remove or sanitise these paths.
2. **XSS in event accessibility fields** — `node--event--full.html.twig` uses `|raw` on field_accessibility_contact, _entry, _parking, _directions. Vendor-editable content can inject scripts.
3. **firebase/php-jwt CVE-2025-45769** — HIGH severity in OAuth path (social_auth_google). Upgrade path via guzzle-oauth2-plugin or replace dependency.
4. **Stripe webhook verification** — If `webhook_signing_secret` is not set, webhooks are processed without signature verification. Confirm production config.
5. **Config drift** — Commerce order types, user roles, block placement differ from sync. Config import could alter checkout, receipts, and permissions.

---

## Recommended Remediation Order

1. **Immediate:** Remove or sanitise backup/audit folders containing secrets. Rotate Stripe and Google keys.
2. **Immediate:** Fix Twig XSS — replace `|raw` with safe output for field_accessibility_* in node--event--full.html.twig.
3. **Sprint 1:** Upgrade firebase/php-jwt (investigate guzzle-oauth2-plugin / social_auth_google compatibility).
4. **Sprint 1:** Verify Stripe webhook_signing_secret in staging/production. Export and reconcile config drift.
5. **Sprint 2:** Enable CSS/JS aggregation for production. Enable page cache.
6. **Sprint 2:** Fix myeventlane_commerce_update_8000 (rename to 9101 or remove if no-op).
7. **Sprint 3:** Revisit anonymous `access commerce_order overview`. Upgrade drupal/commerce to 3.3.2.

---

## Suggested Sprint Breakdown

| Sprint | Focus | Effort |
|--------|-------|--------|
| **Sprint 1** | Secrets removal, Twig XSS fix, firebase/php-jwt, webhook verification, config export | 3–5 days |
| **Sprint 2** | Performance (aggregation, page cache), update_8000 fix, Commerce patch | 2–3 days |
| **Sprint 3** | Permission review, Postmark webhook hardening, contrib stability | 2–3 days |
| **Sprint 4** | DevOps (config import in CI, backup/rollback docs) | 1–2 days |

---

## Report Index

- [01-dependency-report.md](01-dependency-report.md) — Composer, Drush, packages
- [02-config-drift-report.md](02-config-drift-report.md) — Config sync vs active
- [03-entity-schema-report.md](03-entity-schema-report.md) — Entities, fields, schema
- [04-commerce-security-report.md](04-commerce-security-report.md) — Commerce, payments, webhooks
- [05-permission-matrix.md](05-permission-matrix.md) — Roles, permissions
- [06-performance-report.md](06-performance-report.md) — Caching, aggregation
- [07-theme-security-review.md](07-theme-security-review.md) — Twig, XSS
- [08-humanitix-parity-gap.md](08-humanitix-parity-gap.md) — Feature comparison
- [09-devops-deployment-review.md](09-devops-deployment-review.md) — CI, deployment

---

## Branch Confirmation

- **Branch:** audit/staging-2026-Q1 (created and pushed)
- **Base:** main
- Work performed on audit branch; no changes to main.
