# Task 13 — Final launch-readiness and deployment audit

**Branch:** `cursor/onboard-storage-fix-128b4`  
**HEAD:** `3ebf4e16` — fix(vendor): align dashboard analytics and attendee export access  
**Upstream:** `origin/cursor/onboard-storage-fix-128b4` (tracking)  
**Date:** 2026-04-29  
**Scope:** Diagnostic audit before merge/deploy. No Stripe configuration changes, no config export, no secrets touched during this run.

---

## A. Git / branch / PR readiness

| Check | Result |
|-------|--------|
| Current branch | `cursor/onboard-storage-fix-128b4` |
| Working tree | **Clean** (`git status --short` empty) |
| Pushed | Yes — upstream `origin/cursor/onboard-storage-fix-128b4` |
| Task 12 on branch | Yes — tip commit is vendor analytics/export parity; history includes Event Studio, theme, docs audits |
| PR diff vs `origin/main` | See below — **narrow**; Tasks 4–11 work appears **already on `main`** relative to this comparison |

**Latest 20 commits (excerpt):** vendor parity (tip), theme cart/checkout polish, event booking CTA, discovery chips, docs (help, vendor dashboard), Event Studio ticket/publish fixes, Stripe gateway lookup docs, onboarding persistence refactors, shared session Stripe retrieval improvements.

**Ignored secrets/settings accidentally committed:** **No** new secrets in *this branch’s diff vs main*. **However**, a targeted scan found **full Stripe test secret keys in other tracked paths** (see Section F and P0). That is a repository hygiene issue predating or outside the branch diff.

---

## B. Diff against `origin/main`

Commands: `git fetch origin`, `git diff --stat origin/main...HEAD`, `git diff --name-only origin/main...HEAD`.

**Stat:** 10 files, +359 / −26 lines.

**Files:**

| Area | Files |
|------|--------|
| **docs/audits** | `mel-vendor-access-parity-launch-readiness.md` |
| **Vendor / checkout / analytics** | `EventVendorAccessChecker.php` (new), `MetricsAggregator.php`, `RsvpStatsService.php`, `AnalyticsDashboardController.php`, `AttendeeExportController.php`, `VendorOrderController.php`, `myeventlane_vendor.services.yml`, `myeventlane_analytics.info.yml`, `myeventlane_checkout_paragraph.info.yml` |

**Unexpected vs broad “Tasks 4–12” narrative:** The PR-shaped delta vs `main` is **only Task 12 vendor access parity + audit doc**. Stripe/theme/Event Studio/help changes from earlier tasks are **not** in this diff — they are presumably **already merged into `main`**.

**Merge:** Not performed (audit only).

---

## C. Build / dependency readiness

| Step | Result |
|------|--------|
| `composer validate` | `./composer.json` **valid** |
| `composer audit` | **No security vulnerability advisories found** |
| `ddev drush status` | Drupal **11.3.8**, bootstrap successful, DB connected, config `config/sync` |
| `ddev drush cr` | **Success** |
| `npm run mel:lint` | **Exit 0** (hero check + stylelint on scoped SCSS) |
| `npm run mel:build` | **Exit 0** (Vite builds for `myeventlane_theme` and `myeventlane_vendor_theme`; npm reported moderate severity advisories in theme deps — informational) |
| `git status` after build | **Clean** — no tracked file changes from build |

**Lockfile drift:** Not indicated by this run; branch diff vs `main` does not include `composer.lock` / root lock changes.

**Config export drift:** Not created; no `cex` run.

---

## D. Drupal runtime readiness

**Drush status summary:** Site URI `https://myeventlane.ddev.site`, PHP 8.3, Drush 13.7, profile `standard`, public files under `sites/default/files`.

**Enabled modules (grep sample):** Core Search/Views; Commerce suite + Commerce Stripe + webhook events; Search API + DB; Paragraphs; Flag; all **myeventlane_*** custom modules relevant to launch (vendor, event_studio, commerce, checkout_flow, checkout_paragraph, cart, help_centre, help_assistant, rsvp, stripe, analytics, etc.) — **present and enabled** in the listing captured during audit.

**Routes:** Grepped `drush route` for vendor dashboard, events, book, cart, checkout, help, assistant, analytics, export, rsvp, my-tickets, stripe, connect — **expected routes registered** (including `/vendor/dashboard`, `/event/{node}/book`, `/cart`, `/checkout`, `/help`, `/help/assistant`, `/vendor/help`, Stripe Connect/OAuth/admin paths, exports, RSVP paths).

**`drush config:status`:** Differences reported (typical local DDEV vs `config/sync`): Stripe gateway entities, Stripe webhook settings, `core.extension`, and Flag “following” **only in DB**. **No export performed** — document for operators; staging should follow normal import procedures.

**Search API:** Indexes `mel_categories`, `mel_content`, `mel_vendors` enabled. **`mel_content`: 100% complete**, 76 / 76 indexed.

**Cron / queues:** Not deeply exercised; watchdog shows occasional “cron already running” warnings (see P2).

---

## E. Watchdog / runtime errors

**Filtered grep** (`ws --count=200` + keyword pattern): Mostly matched benign substrings (e.g. “vendor” in paths). **Not relied on** for severity.

**`drush ws --severity=Error --count=200` (representative issues):**

| Theme | Examples | Classification |
|-------|----------|----------------|
| **Abandoned cart / Pro** | `Order::isEmpty()` undefined on commerce order — abandoned cart scheduler fails on cron | **P1** — recurring cron error, abandoned-cart behaviour at risk |
| **Auth / onboarding** | `OnboardingState::getOwnerId()` return type (`?int` vs string) — customer order onboarding progression failed | **P1** — type bug affecting onboarding flow |
| **Commerce / tickets** | No `mel_ticket_type` maps variation for event 1547; blocking purchase; ticket issuance “no attendee records” for some orders | **P1/P2** — may be test-data or mapping gaps; verify per environment |
| **Stripe Connect UI** | Cannot create Express Dashboard edit link for account (restrictions) | **P1/P2** — account-state / Stripe side; not branch-diff specific |
| **Cron** | Session “headers already sent” during cron | **P1** — noisy cron failures |

**Recent 50 (`ws --count=50`):** Mostly **Notice** — `mel_theme_debug`, `mel_admin_access_debug`, `mel_debug` (BOOST CANDIDATES), `TEMP_DEBUG vendor_parity`, domain projection debug. **P2** noise unless debug logging is disabled for production.

**Warnings sample:** Pro subscription state fallback for store 38; attendee_answer paragraph integrity warnings; **404** warnings for `/event/1540` and `/event/1567` at one timestamp — **path alias / request context** worth confirming in smoke tests (canonical paths may differ).

---

## F. Secrets and privacy scan

**Recursive grep** (patterns per Task 13; exclusions per spec): Thousands of hits in **`web/core` tests** from `password` token — **ignored as core noise**.

**Targeted follow-up:** Search for Stripe secret patterns in non-core YAML:

- **`_INVALID_config_backup_2026-01-02/sync/commerce_payment.commerce_payment_gateway.stripe.yml`** — contains a **full `sk_test_…` secret key** string.
- **`_myeventlane_audit/config-sync/commerce_payment.commerce_payment_gateway.stripe.yml`** — **same class of material**.

Both paths are **tracked by git** (`git ls-files` includes under `_INVALID_config_backup_*` and `_myeventlane_audit/`).

**Verdict:** **P0 — real Stripe secret material in the repository tree.** Redacted prefix for documentation: `sk_test_51…` (full value not repeated here).

**Other `git ls-files` matches:** `docs/SECRETS_PROTECTION_GUIDE.md`, field machine names `field_help_seed_key`, `private/` docs (internal specs), `_myeventlane_audit/sites-default/settings.php` (template-style audit copy — review separately), not evaluated as live production secrets in this pass.

---

## G. Test entities (no PII)

**Nodes (php-eval):**

| NID | Title | Published | Type | Notes |
|-----|-------|-----------|------|--------|
| 1567 | Experience Anna Live | yes | paid | `field_ticket_types` count 2, product **90** |
| 1540 | New RSVP Test | yes | rsvp | tickets count 1 |

**Paid product 90:** Published; **2** variations (**4121** ~49.88 AUD, **4122** ~50.00 AUD), both published.

**RSVP SQL:** `1540` — `confirmed` = **8** rows. `1567` — no rows (expected for paid).

**Last 10 orders (aggregates only):** Mix of `draft` / `completed`; line items reference events **1567**, **1547**, **1538** with variation IDs — useful for smoke routing; **no attendee names/emails** logged in this doc.

---

## H. Manual browser smoke (DDEV / staging)

All items **pending** in this automated audit session (no browser executed here). Track as owner QA before production.

**Public:** `/`, `/events`, category page, `/event/1567`, `/event/1540`, `/event/1567/book`, `/event/1540/book`, `/cart`, checkout one paid ticket, completion/payment step.

**Vendor:** `/vendor/dashboard`, `/vendor/events/1567/edit`, create RSVP draft, create paid draft, publish paid when Stripe ready, blocked when not, attendee export owner/team, analytics owner/team.

**Help:** `/help`, `/help/assistant`, `/vendor/help`, staff playbook routes **admin/staff only**.

**Security:** anonymous vendor route probe, other-vendor deep link, `/my-tickets` isolation.

---

## I. Staging / deployment readiness

| Topic | Notes |
|-------|--------|
| **Deploy workflow** | [`.github/workflows/deploy-staging.yml`](../../.github/workflows/deploy-staging.yml) — deploy on **push to `main`**, uses reusable build artifact, `environment: staging`. |
| **CI** | [`.github/workflows/php-composer.yml`](../../.github/workflows/php-composer.yml), [`.github/workflows/reusable-build.yml`](../../.github/workflows/reusable-build.yml). |
| **Staging settings in repo** | Live secrets belong in env / server — **not** committed; audit copies under `_myeventlane_audit/` / backups must not hold live keys. |
| **Staging Stripe** | Documented in [docs/audits/mel-stripe-staging-env-key-check.md](mel-stripe-staging-env-key-check.md) (gateway IDs, `StripeService` lookup). |
| **Config sync** | `sites/default` → `config/sync` per Drush status (verify server `settings.php` on staging). |
| **Health** | Help docs health route exists (`/admin/myeventlane/docs/health`); full staging smoke should follow Task 13 manual list. |
| **Artifact** | Staging workflow downloads `artifact.tar.gz` from build job — see workflow steps. |

---

## J. Classification

### P0

1. **Stripe secret key material committed** in tracked files under `_INVALID_config_backup_2026-01-02/sync/` and `_myeventlane_audit/config-sync/` (gateway YAML with full `sk_test_…`). **Blocks** responsible merge until removed/scrubbed, `.gitignore` updated if appropriate, and **key rotation** considered if exposure scope warrants it.

### P1

1. **Abandoned cart scheduler:** `Call to undefined method Drupal\commerce_order\Entity\Order::isEmpty()` — recurring cron **Error**.
2. **OnboardingState::getOwnerId()** type mismatch — customer order onboarding progression **Error** (uids 1, 72 observed).
3. **Ticket / commerce mapping** errors for specific events/variations (e.g. 1547 / 2110) — verify data and maps on staging.
4. **Vendor Stripe “Express Dashboard” edit link** errors for some accounts — environment/account configuration.
5. **Cron session / headers** errors — investigate cron bootstrap context.
6. **404** on `/event/1567` and `/event/1540` in one warning pair — confirm canonical URLs vs aliases in QA.
7. **Manual** vendor team access matrix for analytics/export — **not executed** in this session (Tasks 7–12 addressed in code; still verify manually).

### P2

1. High volume of **Notice** logs (`mel_debug`, BOOST candidates, `mel_theme_debug`, `TEMP_DEBUG`).
2. **Projection miss** debug messages for domain events.
3. **Integrity warnings** on attendee_answer paragraphs.
4. **npm audit** moderate advisories inside theme packages (review on upgrade cadence).

---

## K. Launch recommendation

**Verdict: Blocked by P0** (committed Stripe secret material in backup/audit config paths).

**After P0 remediation (narrow Task 13B suggested):**

1. Remove or redact secret-bearing YAML from tracked paths; ensure backups are not committed or contain placeholders only.
2. Rotate the exposed Stripe **test** secret if policy requires (still credential hygiene).
3. Re-run secret grep on `main` candidate.
4. Address **P1** abandoned-cart `Order::isEmpty()` and onboarding type bug before relying on those flows in production.

Then: **Open PR** for the Task 12 vendor parity changes (already clean vs `main`) **and** complete **manual smoke** (Section H) on staging.

---

## L. Commands run (audit session)

```text
git branch --show-current
git status --short
git log -20 --oneline
git remote -v
git rev-parse --abbrev-ref --symbolic-full-name @{u}
git status -sb
git fetch origin
git diff --stat origin/main...HEAD
git diff --name-only origin/main...HEAD
composer validate
composer audit
ddev drush status
ddev drush cr
npm run mel:lint
npm run mel:build
git status --short
ddev drush pm:list --type=module --status=enabled | grep -Ei "myeventlane|commerce|stripe|search|views|flag|paragraph"
ddev drush route | grep -Ei "vendor/dashboard|vendor/events|event/.*/book|checkout|cart|help|assistant|analytics|export|rsvp|my-tickets|stripe|connect"
ddev drush config:status
ddev drush search-api:list
ddev drush search-api:status mel_content
ddev drush ws --count=200 | grep -Ei "emergency|alert|critical|error|..."
ddev drush ws --count=50
ddev drush ws --count=200 --severity=Error
ddev drush ws --count=50 --severity=Warning
grep -R "sk_test_|sk_live_|..." (with exclusions per Task 13)
git ls-files | grep -Ei "settings.php|settings.local.php|\\.env|private|secret|key"
ddev drush php-eval '...' (nodes 1567, 1540; product 1567; orders)
ddev drush sqlq "SELECT ... rsvp_submission ..."
```

---

## M. Files changed by this audit

- **Added:** `docs/audits/mel-final-launch-readiness-audit.md` (this file).

No application code, Stripe config, or config export was modified during Task 13.

---

## Ready to commit/push this audit?

**Yes** — committing **only** this audit document is appropriate and does not resolve **P0** secret exposure elsewhere; recommend fixing or ticketing P0 before relying on repo cleanliness for compliance.
