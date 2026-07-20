# Payment Launch Remediation — Pre-Commit Audit

**Branch:** `feature/payment-launch-remediation`  
**HEAD:** `54b7a8e0a` — `fix(checkout): restore Commerce Stripe Payment Element compatibility`  
**Audit date:** 20 July 2026  
**Mode:** Read-only working-tree audit (no stage/commit/restore performed for this audit)  
**This file:** deliverable only; created after inspection

---

## Verdict

**Yes — the intended payment remediation changes in the working tree can be isolated into a clean commit.**

There are **no dirty wallet, checkout template, or SCSS files** in the working tree.  
There are **no secret literals** in the unstaged diffs or untracked remediation/docs files scanned for this audit.

**Caveats (do not ignore):**

1. Branch tip is **identical** to `fix/mel-stripe-payment-element-compat` / `origin/fix/mel-stripe-payment-element-compat`. A PR of this branch tip against `main` would also ship prior wallet + checkout history already on that tip — not only the uncommitted remediation.
2. **Active DDEV config currently diverges badly** from tracked `config/sync` (see § Active vs sync). Do **not** run `drush cex` from this environment into the remediation commit.
3. Local remediation backups under `backups/` are gitignored and currently **empty** (not commit candidates).

---

## Working-tree inventory (complete)

### Modified (unstaged)

| Path |
| --- |
| `config/sync/commerce_payment.commerce_payment_gateway.mel_stripe_cc.yml` |
| `config/sync/commerce_payment.commerce_payment_gateway.stripe_pe_recurring.yml` |
| `web/modules/custom/myeventlane_admin_dashboard/myeventlane_admin_dashboard.info.yml` |
| `web/modules/custom/myeventlane_admin_dashboard/myeventlane_admin_dashboard.services.yml` |
| `web/modules/custom/myeventlane_admin_dashboard/src/Service/PlatformMetricsService.php` |
| `web/modules/custom/myeventlane_commerce/myeventlane_commerce.services.yml` |
| `web/modules/custom/myeventlane_commerce/src/Service/OrderItemClassifier.php` |

### Untracked

| Path |
| --- |
| `web/modules/custom/myeventlane_commerce/src/EventSubscriber/FilterPaymentGatewaysSubscriber.php` |
| `web/modules/custom/myeventlane_commerce/tests/src/Unit/FilterPaymentGatewaysSubscriberTest.php` |
| `web/modules/custom/myeventlane_commerce/tests/src/Unit/OrderItemClassifierPayoutLedgerTest.php` |
| `docs/adr/ADR-002-payment-runtime.md` |
| `docs/adr/ADR-003-stripe-connect-strategy.md` |
| `docs/architecture/payment-component-lifecycle.md` |
| `docs/architecture/payment-critical-findings.md` |
| `docs/architecture/payment-gateway-runtime.md` |
| `docs/architecture/payment-ledger-review.md` |
| `docs/architecture/payment-runtime-map.md` |
| `docs/architecture/payment-runtime-matrix.md` |
| `docs/architecture/payment-sequence-diagrams.md` |
| `docs/architecture/payment-technical-debt.md` |
| `docs/architecture/wallet-payment-boundary.md` |
| `docs/launch/payment-executive-summary.md` |
| `docs/launch/payment-launch-risk-register.md` |
| `docs/release/payment-launch-remediation-plan.md` |
| `docs/release/payment-launch-remediation-report.md` |
| `docs/release/payment-launch-remediation-precommit-audit.md` *(this file)* |

### Staged

None.

---

## Classification

### 1. Payment launch remediation (code + config)

**Include in the payment remediation commit:**

| Path | Role |
| --- | --- |
| `config/sync/commerce_payment.commerce_payment_gateway.mel_stripe_cc.yml` | Admin-only manual gateway conditions |
| `config/sync/commerce_payment.commerce_payment_gateway.stripe_pe_recurring.yml` | Restrict PE to Pro variation + AUD |
| `web/modules/custom/myeventlane_commerce/src/Service/OrderItemClassifier.php` | Ledger eligibility + recurring helper |
| `web/modules/custom/myeventlane_commerce/src/EventSubscriber/FilterPaymentGatewaysSubscriber.php` | Gateway matrix filter |
| `web/modules/custom/myeventlane_commerce/myeventlane_commerce.services.yml` | Register filter subscriber |
| `web/modules/custom/myeventlane_commerce/tests/src/Unit/FilterPaymentGatewaysSubscriberTest.php` | Unit tests |
| `web/modules/custom/myeventlane_commerce/tests/src/Unit/OrderItemClassifierPayoutLedgerTest.php` | Unit tests |
| `web/modules/custom/myeventlane_admin_dashboard/src/Service/PlatformMetricsService.php` | Ledger insert allowlist |
| `web/modules/custom/myeventlane_admin_dashboard/myeventlane_admin_dashboard.services.yml` | DI for classifier/logger |
| `web/modules/custom/myeventlane_admin_dashboard/myeventlane_admin_dashboard.info.yml` | Depend on `myeventlane_commerce` |

**Not modified / not to invent for this commit:**

- `config/sync/commerce_payment.commerce_payment_gateway.stripe.yml` — **unchanged** in working tree (still Card Element plugin + empty keys in sync).

### 2. Wallet work

| Finding | Detail |
| --- | --- |
| Working-tree wallet code/assets | **None dirty** |
| Wallet templates/services/JS | **No changes** vs `HEAD` |
| Docs only | `docs/architecture/wallet-payment-boundary.md` is payment-audit documentation (boundary), not wallet product code |

**Conclusion:** No wallet implementation files belong in (or contaminate) the remediation commit.

### 3. Checkout work

| Finding | Detail |
| --- | --- |
| Working-tree checkout templates / SCSS | **None dirty** |
| Checkout modules | **No working-tree changes** |
| Branch history | `HEAD` *is* the PE checkout compatibility fix commit (`54b7a8e0a`). That commit is already on the branch tip / shared with `fix/mel-stripe-payment-element-compat`, but it is **not** an uncommitted working-tree change |

**Conclusion:** Uncommitted tree has no checkout UX/template/SCSS edits. PR base selection still matters (see recommended split).

### 4. Documentation

All untracked under `docs/adr/`, `docs/architecture/payment-*.md`, `docs/architecture/wallet-payment-boundary.md`, `docs/launch/payment-*.md`, `docs/release/payment-launch-remediation-*.md`.

**Recommendation:** Prefer a **second commit** (or separate docs PR) so code review stays focused. Acceptable to include with code if product wants one remediation PR.

### 5. Local-only or temporary files

| Path | Status | Action |
| --- | --- | --- |
| `backups/` | gitignored (`.gitignore:81`); directory present, **empty** now | Exclude |
| `.ddev/` generated/runtime | ignored | Exclude |
| `web/sites/default/settings.php`, `settings.ddev.php`, etc. | ignored | Exclude |
| `web/core/`, `web/modules/contrib/` | ignored | Exclude |
| `.codex/` | **Absent** | N/A |
| `.tmp-reconciliation-backups/` | **Absent** | N/A |

### 6. Unrelated or unsafe changes

| Item | Verdict |
| --- | --- |
| `web/update.php` | Tracked; **not dirty** |
| `web/sites/development.services.yml` | Tracked; **not dirty** |
| Active DDEV gateway mutation (`access_token`, plugin drift, missing `mel_stripe_cc`) | **Unsafe to export**; not present as tracked file changes |
| Branch tip containing prior wallet/checkout commits vs `main` | Historical coupling — isolate remediation files for commit, and choose PR base carefully |

### 7. Secrets / configuration risks

| Check | Result |
| --- | --- |
| Secret literals in unstaged `git diff` | **None** (`sk_*` / `pk_*` / `whsec_*` / non-empty `access_token:` values) |
| Secret literals in untracked remediation/docs files (pattern scan) | **None** |
| Tracked sync gateway YAML keys | Empty strings only (`publishable_key: ''`, `secret_key: ''`, PE `webhook_signing_secret: ''`) |
| Active DDEV `stripe` / `stripe_pe_recurring` | **Non-empty** publishable key + `access_token` lengths observed (107); `authentication_method=stripe_connect`; **not** in git |
| Active `mel_stripe_cc` entity | **MISSING** in DDEV entity storage (sync still has it) |
| Active `stripe` plugin id | **`stripe_payment_element`** in DDEV — differs from sync plugin `stripe` |
| Risk of `drush cex` now | **High** — would risk exporting wrong plugin/auth shape and/or secret material into sync |

**Do not commit** any active-config dump, private settings, or gateway YAML with non-empty Stripe keys.

---

## Exact intended files for the payment remediation commit

```text
config/sync/commerce_payment.commerce_payment_gateway.mel_stripe_cc.yml
config/sync/commerce_payment.commerce_payment_gateway.stripe_pe_recurring.yml
web/modules/custom/myeventlane_admin_dashboard/myeventlane_admin_dashboard.info.yml
web/modules/custom/myeventlane_admin_dashboard/myeventlane_admin_dashboard.services.yml
web/modules/custom/myeventlane_admin_dashboard/src/Service/PlatformMetricsService.php
web/modules/custom/myeventlane_commerce/myeventlane_commerce.services.yml
web/modules/custom/myeventlane_commerce/src/Service/OrderItemClassifier.php
web/modules/custom/myeventlane_commerce/src/EventSubscriber/FilterPaymentGatewaysSubscriber.php
web/modules/custom/myeventlane_commerce/tests/src/Unit/FilterPaymentGatewaysSubscriberTest.php
web/modules/custom/myeventlane_commerce/tests/src/Unit/OrderItemClassifierPayoutLedgerTest.php
```

Optional same-PR docs commit (or follow-up):

```text
docs/adr/ADR-002-payment-runtime.md
docs/adr/ADR-003-stripe-connect-strategy.md
docs/architecture/payment-component-lifecycle.md
docs/architecture/payment-critical-findings.md
docs/architecture/payment-gateway-runtime.md
docs/architecture/payment-ledger-review.md
docs/architecture/payment-runtime-map.md
docs/architecture/payment-runtime-matrix.md
docs/architecture/payment-sequence-diagrams.md
docs/architecture/payment-technical-debt.md
docs/architecture/wallet-payment-boundary.md
docs/launch/payment-executive-summary.md
docs/launch/payment-launch-risk-register.md
docs/release/payment-launch-remediation-plan.md
docs/release/payment-launch-remediation-report.md
docs/release/payment-launch-remediation-precommit-audit.md
```

---

## Exact files that must be excluded

```text
# Never stage from this tree for remediation
backups/**
.ddev/**
web/sites/default/settings.php
web/sites/default/settings.ddev.php
web/sites/default/files/**
web/core/**
web/modules/contrib/**

# Not dirty, but must remain untouched / not "fixed" into this commit
web/update.php
web/sites/development.services.yml
web/modules/custom/myeventlane_wallet/**
web/themes/custom/**/checkout*
web/themes/custom/**/*.scss   # no dirty SCSS in tree; do not add unrelated theme work

# Do not export/overwrite into commit from active DDEV
# (especially) config/sync/commerce_payment.commerce_payment_gateway.stripe.yml
# if generated by cex while access_token / wrong plugin are active
```

Also exclude any future files matching `.codex/` or `.tmp-reconciliation-backups/` if they appear.

---

## Config export required?

| Question | Answer |
| --- | --- |
| Is a fresh `drush cex` required before commit? | **No — do not cex from current DDEV** |
| Are the intended gateway condition changes already in tracked sync YAML? | **Yes** (working-tree edits to `mel_stripe_cc` + `stripe_pe_recurring`) |
| Should `stripe.yml` sync be updated in this commit? | **No** unless separately decided; it is unmodified and must stay secret-free |

**Deploy path:** commit sync YAML as edited → on target env `drush cim` for those two gateways → **separately** ensure active `stripe` uses platform API keys (empty `access_token`) without exporting secrets.

---

## Active config vs tracked sync config

| Gateway | Sync (working tree) | Active DDEV (entity load, 20 Jul 2026) | Match? |
| --- | --- | --- | --- |
| `stripe` | plugin `stripe`; AUD; empty keys; no access_token fields in YAML | plugin **`stripe_payment_element`**; AUD; pk/access_token non-empty; `auth=stripe_connect` | **No** |
| `stripe_pe_recurring` | PE; AUD **and** Pro variation; `conditionOperator: AND`; empty keys | PE; AUD **and** Pro variation; `AND`; pk/access_token non-empty | Conditions **yes**; credentials/auth **no** (active has secrets) |
| `mel_stripe_cc` | manual; admin role condition; `status: true` | **Entity MISSING** | **No** |

**Implication:** Remediation code/tests can be committed from the working tree safely. Runtime verification on this DDEV instance is **not trustworthy** until active gateways are repaired to match Option A (`stripe` Card Element entity present, `mel_stripe_cc` present, empty `access_token`). That repair is an **environment** action, not a sync-secret commit.

---

## Secret exposure findings

1. **Tracked remediation diffs:** no Stripe secret material.  
2. **Untracked docs/code for this work:** no live key literals in pattern scan.  
3. **Active config only:** Stripe credentials present in DB/config storage (expected for local); lengths observed, values not recorded here.  
4. **Historical docs elsewhere in repo** mention `sk_test_…` patterns in audits; out of scope for this working-tree commit, not introduced by these dirty files.  
5. **Highest near-term risk:** accidental `cex` or copying active gateway YAML into `config/sync`.

---

## Recommended commit split

### Option A (preferred)

1. **Commit 1 — payment remediation code/config/tests**  
   Exact file list in § “Exact intended files for the payment remediation commit”.
2. **Commit 2 — payment architecture / launch / release docs**  
   Exact docs list above (including this precommit audit).

### Option B

Single commit with code + docs (acceptable if one remediation PR is required).

### Branch / PR hygiene (separate from working-tree isolation)

Because `feature/payment-launch-remediation` currently points at the same commit as `fix/mel-stripe-payment-element-compat`:

- For a **payment-only PR onto `main`**, create/reset the branch from `main` (or current production base) and apply only the intended files, **or** rebase/cherry-pick carefully so checkout/wallet history is not re-opened.
- Do **not** assume “commit the dirty files on this tip” yields a payment-only PR against `main`.

---

## Rollback-safe next commands

Read-only / safe inspection (no mutation):

```bash
git status -sb
git diff --stat
git diff -- config/sync/commerce_payment.commerce_payment_gateway.*.yml
git ls-files --others --exclude-standard
```

Stage **only** the intended remediation files (when ready to commit — not run by this audit):

```bash
git add \
  config/sync/commerce_payment.commerce_payment_gateway.mel_stripe_cc.yml \
  config/sync/commerce_payment.commerce_payment_gateway.stripe_pe_recurring.yml \
  web/modules/custom/myeventlane_admin_dashboard/myeventlane_admin_dashboard.info.yml \
  web/modules/custom/myeventlane_admin_dashboard/myeventlane_admin_dashboard.services.yml \
  web/modules/custom/myeventlane_admin_dashboard/src/Service/PlatformMetricsService.php \
  web/modules/custom/myeventlane_commerce/myeventlane_commerce.services.yml \
  web/modules/custom/myeventlane_commerce/src/Service/OrderItemClassifier.php \
  web/modules/custom/myeventlane_commerce/src/EventSubscriber/FilterPaymentGatewaysSubscriber.php \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/FilterPaymentGatewaysSubscriberTest.php \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/OrderItemClassifierPayoutLedgerTest.php

git status
git diff --cached --stat
```

**Avoid until env is known-safe:**

```bash
# DO NOT run for this commit from current DDEV:
# ddev drush cex
# ddev drush config:export
```

Unstage without discarding work:

```bash
git restore --staged -- <paths>
```

Discard is **not** recommended here; keep working tree intact until commit intent is confirmed.

---

## Summary answers

| Question | Answer |
| --- | --- |
| Can payment remediation be isolated into a clean commit? | **Yes** (working tree) |
| Wallet work in dirty tree? | **No** |
| Checkout templates/SCSS in dirty tree? | **No** |
| Config export required? | **No** — commit existing sync edits; **do not cex** now |
| Active differs from sync? | **Yes — materially** (plugin/auth/secrets; `mel_stripe_cc` missing) |
| Secrets in tracked dirty/untracked remediation files? | **No** |
| `.codex/` / `.tmp-reconciliation-backups/` | **Absent** |
| `web/update.php` / `development.services.yml` | Tracked, **clean** |
