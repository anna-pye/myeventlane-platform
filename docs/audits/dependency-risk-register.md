# Dependency Risk Register

**Repository:** `/Users/anna/myeventlane`  
**Audit date:** 2026-06-22  
**Scope:** Non-stable production Composer dependencies flagged in `composer.json`  
**Action taken:** Audit only — no package upgrades, removals, or subscription logic changes.

---

## Summary

| Package | Constraint | Installed | Latest available | Stable release |
|---------|------------|-----------|------------------|----------------|
| `drupal/conditional_fields` | `^4.0@alpha` | 4.0.0-alpha6 | 4.0.0-alpha6 | **None** |
| `drupal/commerce_recurring` | `^1.0@RC` | 1.0.0-rc3 | 1.0.0-rc3 | **None** |

Both packages are **already at the newest tagged release** Composer can resolve under the current constraints. No stable-compatible upgrade path exists today. **Do not remove either package.**

---

## Validation (2026-06-22)

```bash
composer validate --check-lock
# ./composer.json is valid

composer outdated drupal/conditional_fields
# Installed: 4.0.0-alpha6 | Latest: 4.0.0-alpha6

composer outdated drupal/commerce_recurring
# Installed: 1.0.0-rc3 | Latest: 1.0.0-rc3
```

**Upgrade decision:** No automatic upgrade performed. Stable releases are unavailable; constraints remain unchanged.

---

## drupal/conditional_fields

**Current constraint:** `^4.0@alpha` (`composer.json`)

**Installed version:** 4.0.0-alpha6 (2024-12-11)

**Risk:** **Medium** — Alpha stability; **not covered** by Drupal security advisories (`composer.lock` reports `security-coverage.status: not-covered`). API and behaviour may change before 4.0.0 stable. Module hooks run on entity forms site-wide when enabled, which can interact with AJAX-heavy forms (e.g. Event Studio branding).

**Used by:**

- Enabled in `config/sync/core.extension.yml`.
- Contrib module hooks (`conditional_fields_element_after_build`, `conditional_fields_form_after_build`) participate in Event Studio form builds — see `docs/audits/event-studio-branding-serialization-audit.md`.
- No conditional-field rule configuration found in `config/sync` or the active config store (SQL search for `conditional_fields` returned no entity-form rules). The module is enabled and its form alters are active; explicit show/hide rules may be added later or live only in non-exported config.

**Why MEL uses it:**

Provides conditional show/hide/require behaviour on content entity fields without custom `#states` logic — intended for Event Studio and other complex entity forms where field visibility depends on other field values.

**Affected product area:**

- Event Studio (vendor event create/edit flows)
- Content modelling / admin entity forms (any bundle with conditional field rules)

**Owner:** Platform / Event Studio

**Mitigation:**

- Keep `@alpha` constraint explicit; do not relax to `4.x-dev`.
- Pin to latest alpha via `composer update drupal/conditional_fields` only after reviewing release notes and smoke-testing Event Studio forms (image upload, crop, media library AJAX).
- Before adding new conditional rules, export config (`drush cex`) so rules are reviewable in `config/sync`.
- Track [drupal.org project releases](https://www.drupal.org/project/conditional_fields/releases) and meta issue [#2830988 — 4.0.0 release roadmap](https://www.drupal.org/project/conditional_fields/issues/2830988).

**Upgrade path:**

1. When **4.0.0 stable** (or newer stable 4.x) ships on Drupal.org, change constraint to `^4.0` (drop `@alpha`).
2. Run `composer update drupal/conditional_fields --with-dependencies` in a feature branch.
3. Verify Composer resolves cleanly; run Event Studio form smoke tests (branding, tickets, publish).
4. Export and review config diff before merge.

**Next review:** 2026-09-22 (quarterly), or immediately when a stable 4.x tag is published.

---

## drupal/commerce_recurring

**Current constraint:** `^1.0@RC` (`composer.json`)

**Installed version:** 1.0.0-rc3 (2024-09-03)

**Risk:** **High** — RC stability; **not covered** by Drupal security advisories. Core dependency for paid subscription billing. Recurring order generation, billing schedules, and subscription state transitions are payment-adjacent; regressions affect revenue and entitlements.

**Used by:**

- Hard dependency: `web/modules/custom/myeventlane_pro` (`myeventlane_pro.info.yml`).
- Downstream custom modules: `myeventlane_growth`, `myeventlane_automation`, `myeventlane_launch`, `myeventlane_admin_dashboard`.
- Custom services importing `SubscriptionInterface` and recurring events: `ProSubscriptionSubscriber`, `ProSubscriptionLifecycleScheduler`, `ProEntitlementReconciler`, `ProBoostProvisioner`, `GrowthSubscriptionSubscriber`, and related Pro billing/reporting services.
- Exported Commerce config: subscription entity displays, recurring order types, billing schedule fields, Advanced Queue worker (`commerce_recurring`), admin/customer subscription Views.

**Why MEL uses it:**

Drupal Commerce’s official recurring billing framework. Powers **MEL Pro** subscription products, recurring Stripe charges, subscription lifecycle (active/canceled/past_due), and entitlement reconciliation for Pro features and boosts.

**Affected product area:**

- MEL Pro subscriptions (purchase, renewal, cancellation)
- Vendor Pro billing dashboard and subscription health
- Pro entitlements, auto-boost, and growth automation tied to subscription state
- Commerce recurring orders and payment method storage

**Owner:** Commerce / `myeventlane_pro`

**Mitigation:**

- Keep `@RC` constraint explicit; do not downgrade to beta or switch to `1.x-dev` in production.
- Monitor [commerce_recurring releases](https://www.drupal.org/project/commerce_recurring/releases) and issue queue; RC3 is the latest tag (no newer RC as of audit date).
- Subscription changes require kernel test coverage (`ProSubscriptionHardeningKernelTest`, `ProAutoBoostAndCommsKernelTest`) and manual billing smoke tests — **do not change subscription logic** as part of dependency reviews.
- Treat `composer update drupal/commerce_recurring` as a **payment release**: staging Stripe test mode, full subscribe → renew → cancel path.

**Upgrade path:**

1. When **1.0.0 stable** ships, change constraint to `^1.0` (drop `@RC`).
2. Run `composer update drupal/commerce_recurring --with-dependencies` in a feature branch; confirm compatibility with `drupal/commerce ^3.3`.
3. Run Pro subscription kernel tests and staging billing smoke tests.
4. Review Advanced Queue / cron behaviour for recurring order generation.
5. Export config if entity/display definitions change.

**Next review:** 2026-07-22 (monthly), or immediately when a stable 1.0 tag is published.

---

## References

- [Conditional Fields — Drupal.org](https://www.drupal.org/project/conditional_fields)
- [Commerce Recurring Framework — Drupal.org](https://www.drupal.org/project/commerce_recurring)
- Prior audit: `docs/audit/staging-2026-Q1/01-dependency-report.md`
- Prior parity note: `docs/audit/staging-2026-Q1/08-humanitix-parity-gap.md`
