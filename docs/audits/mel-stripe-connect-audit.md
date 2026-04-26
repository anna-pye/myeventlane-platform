# MEL — Full Stripe Connect audit

**Date:** 2026-04-27  
**Branch under audit:** `feature/mel-v2-task-based-completion-audit` (HEAD `bd2e5209` at run time)  
**Read-only task:** this file only; no code, config, DB updates, or merges.  
**Comparison branch (evidence only, not merged):** `fix/stripe-connect`

---

## 1. Current Stripe package / config

### Composer (output of `composer show | grep -Ei "stripe|commerce"`)

- `drupal/commerce` 3.3.5 (and commerce_order, commerce_payment, commerce_store, commerce_price, etc.)  
- `drupal/commerce_stripe` 2.2.1  
- `drupal/commerce_paypal` 2.1.2 (non-Stripe)  
- `stripe/stripe-php` 15.10.0  

### Drupal modules (enabled)

- **Commerce + Commerce Stripe** (contrib) for Payment Element / gateway stack.  
- **Custom:** `myeventlane_core` (Stripe service), `myeventlane_commerce` (Connect gateway plugin + payment service), `myeventlane_vendor` (onboarding UI/controllers), `myeventlane_admin_dashboard` (payout webhooks), `myeventlane_webhooks` (MEL’s **outgoing** webhooks to subscribers — not Stripe’s inbound API), `myeventlane_pro` (subscription webhook), etc.

### Where platform keys are read (code)

- `Drupal\myeventlane_core\Service\StripeService::getPlatformSecretKey()` loads **`commerce_payment_gateway` entities** `mel_stripe` (preferred) then `stripe`, then optional `myeventlane_core.stripe_settings: platform_secret_key`.  
- `config/sync/commerce_payment.commerce_payment_gateway.stripe.yml` has **`publishable_key: ''` and `secret_key: ''`** in sync (no secrets committed in active sync for that file).  
- A gateway definition file exists for `mel_stripe_cc` (manual / test) in sync — do not confuse with live Connect.  
- The custom plugin class **`StripeConnect`** lives under `myeventlane_commerce`; an **export** in `config/sync` for `plugin: stripe_connect` was **not found** in this audit pass — the **live checkout gateway** configuration may be **DB-only, overridden, or under a name not in sync**. That is a **documentation / ops** gap (P2), not proof of misconfiguration.

### Secret scan (grep for key patterns, excluding `vendor` / `node_modules` / `.git`)

- **Hits included:**  
  - `_INVALID_config_backup_2026-01-02/.../commerce_payment.commerce_payment_gateway.stripe.yml` — contains **`pk_test_…` and `sk_test_…` literals**.  
  - `_myeventlane_audit/config-sync/.../commerce_payment.commerce_payment_gateway.stripe.yml` — same.  
  - Documentation references in `SECRETS_PROTECTION_GUIDE.md` / `docs/…` (pattern examples, not live keys in body beyond descriptions).  
- **Full values** are not reproduced here. **Classification:** if those backup paths are tracked in the repository, that is a **P0** hygiene issue (test keys in tree); treat as **rotate** if the keys were ever real and reuse is possible.  
- **Active** `config/sync` gateway for `stripe` uses **empty** keys in YAML as checked above.

---

## 2. Current Stripe **account** model (this branch, from code)

### Account creation (onboarding)

- `StripeConnectController::connect()` (current branch) calls  
  `StripeService::createConnectAccount($userEmail, 'AU', '**standard**')` when no `field_stripe_account_id` on the store — see `web/modules/custom/myeventlane_vendor/src/Controller/StripeConnectController.php` (~line 230).  
- `StripeService::createConnectAccount()` uses Stripe API: `'type' => $type` (default `standard` in docblock), with **`capabilities`**: `card_payments` and `transfers` both **requested: true** — `web/modules/custom/myeventlane_core/src/Service/StripeService.php`.  
- **AccountLink** is created with `'type' => 'account_onboarding'`. No OAuth `authorize_url` path was found in MEL for Connect; flow is **Account Links** to hosted onboarding.

### `fix/stripe-connect` divergence (evidence, not merged)

- `git log feature/mel-v2-task-based-completion-audit..fix/stripe-connect` shows three commits; **`StripeService` on that branch** adds `getOrCreateAccount()` which calls `createConnectAccount($email, 'AU', '**express**')` (Express, not Standard).  
- **Conclusion for product:** this audit branch and `fix/stripe-connect` are **not aligned** on **account type (Standard vs Express)**. Resolving that is a **P1** product/compliance decision, not a guess.

---

## 3. Current **charge** model (from code; no guesswork beyond this)

### Primary pattern: **destination charges** on the **platform** account

- `StripeService::createPaymentIntentForTicketSale()` builds a `PaymentIntent` with:  
  - `application_fee_amount`  
  - `transfer_data['destination']` = vendor Connect account ID `acct_…`  
- `StripeConnectPaymentService::getConnectPaymentIntentParams()` merges into Commerce PaymentIntents:  
  - `application_fee_amount` (from ticket revenue only)  
  - `transfer_data` with **`destination`** and explicit **`amount`** = ticket revenue in cents; donations excluded from that amount per comments.  
- The **custom** Commerce gateway `Drupal\myeventlane_commerce\Plugin\Commerce\PaymentGateway\StripeConnect` **extends** `commerce_stripe` `StripePaymentElement` and overrides `createPaymentIntent()` to inject those params.

### Merchant of record (from design comments only)

- `StripeConnectPaymentService` file-level doc and `getConnectPaymentIntentParams` comments state: total charged includes tickets + donations; **application fee and transfer math** are described as platform fee on tickets + **donations stay with platform** — **vendor receives ticket net of fee** via `transfer_data`.  
- Exact Stripe MoR for Standard vs Connect fee liability is a **Stripe account + settings** question; the code **does** implement **application fees + transfer to connected account** (not “direct charge on connected only” in this path).

### `on_behalf_of` / `Stripe-Account` header in custom PI helper

- The reviewed `createPaymentIntentForTicketSale` snippet **does not** set `on_behalf_of` in the array shown. Whether `commerce_stripe` adds options elsewhere was **not** fully traced in this task.  
- **Grep** across `web/modules/custom` + `config/sync` for those strings is part of the required audit list; MEL’s **explicit** Connect ticket logic uses **`application_fee_amount` + `transfer_data`**.

### Checkout gating of “not ready” vendor

- `StripeConnectPaymentService::validateOrderForConnect()` requires a **store** and **`field_stripe_account_id` on the store** for paid line items, or returns invalid with a user-oriented message.  
- `StripeConnectValidationSubscriber` on **checkout completion** only **logs a warning** if validation fails — it does **not** block completion. **P1:** risk that misconfiguration is caught **late**; rely on **earlier** validation in checkout/payment (Commerce + gateway) in normal operation.  
- Vendor booking forms (`RsvpBookingForm::isVendorStripeConnected`) use **`field_stripe_charges_enabled`**, then `field_stripe_connected` on the store.

### Commerce Stripe (contrib) routes (Drush `route` sample)

- Includes `/admin/commerce/.../connect` OAuth, `/stripe-connect/oauth/return/...`, Express Checkout, etc. MEL’s primary vendor path is **custom** (`/stripe/connect`).

**Required `ddev drush route | grep`:** exit **141** when piped/limited (not a Drush error); a prefix of routes was captured, including `myeventlane_admin_dashboard.stripe_payout_webhook: /stripe/webhook/payout` and MEL vendor routes.

---

## 4. Vendor / store / account relationship

### Storage (config entity fields in sync)

- `commerce_store` bundle `online` has: `field_stripe_account_id`, `field_stripe_connected`, `field_stripe_charges_enabled`, `field_stripe_payouts_enabled`, `field_stripe_onboard_url`, `field_stripe_dashboard_url` (and related storage YAML under `config/sync/field.…`).  
- `myeventlane_vendor` entity: vendor links to a single store via `field_vendor_store` (as used in `StripeConnectController::getStoreForConnect()`).

### Onboarding code behaviour (this branch)

- Resolves **store** via vendor’s `field_vendor_store`, else “first store by uid” query, else **default store** (`getCurrentUserStore()` can return default store) — see `StripeConnectController`.  
- New Connect account ID is written to **store** and optionally **vendor** `field_stripe_account_id`.  
- **Overwriting** risk: recreating a new account for the same store when `accountId` is empty could orphan old `acct_` in Stripe; no merge analysis performed here. **P2** to review with Stripe Dashboard ops.

### Drush `php-eval` (read-only) — store `field_stripe_connected` snapshot (local DDEV)

Command ran successfully. Output was one line per store id with `0` or `1` (boolean stored). A few stores showed `1`; most `0` — **environment-specific**, not a pass/fail.

---

## 5. Onboarding flow (this branch, sequence)

1. **Vendor** hits **`GET /stripe/connect`** → route `myeventlane_vendor.stripe_connect` → `StripeConnectController::connect()`.  
2. **Anonymous** → access denied.  
3. **Store** resolved; if **none** → error message, redirect to **vendor dashboard**.  
4. If **existing `field_stripe_account_id`:** call **`getAccountStatus`**, update store flags; if **`charges_enabled`**, early exit “already connected” to dashboard.  
5. If **no account id:** `createConnectAccount(…, '**standard**')` → save `acct_` on store + vendor fields → **`createAccountLink`**.  
6. **Return/refresh URLs** from `buildAccountLinkUrls` → `myeventlane_vendor.stripe_onboard_return` and `myeventlane_vendor.stripe_onboard_refresh` (see `StripeConnectController`).  
7. **Redirect** to `https://connect.stripe.com/...` (validated prefix).  
8. **Callback** `StripeConnectController::callback` → reads account id from **store**, refreshes `getAccountStatus`, updates store/vendor, messages, **redirect to destination** if query param, else **dashboard**.  
9. **Manage** (`::manage`) → `createLoginLinkIfEligible` path (uses eligibility including `details_submitted`, `charges_enabled`).

**`fix/stripe-connect` (diff only):** reworks store resolution through `VendorStoreSubscriber::ensureStoreForVendor`, **removes** default-store fallback, changes **return/refresh** to pass **`account_id` in query** and different routes, uses **`getOrCreateAccount`**, **Express** type, and adds **`ApiErrorException`** user messaging and logging; **connect method signature** becomes `connect(Request $request)`.

### Watchdog evidence (onboarding / loop)

- `ddev drush ws` filtered output showed many repeated lines in the same second: **STRIPE CONNECT HIT**, **Created AccountLink for account acct_…**, and **`mel_debug` logging “Stripe redirecting to: @url”** with a **connect.stripe.com** URL.  
- **P1 (logging safety):** **Full AccountLink URLs** (or long redirect URLs) are written to `mel_debug` / `myeventlane_core` at **notice/info** in ways that can expose **sensitive, single-use** onboarding URLs in logs. **P2 (ops):** repeated hits suggest **retry** or **double navigation**; confirm monitoring.

**Required `drush route | grep`:** see section 1 (exit 141 when truncated).

---

## 6. The “managing **losses** / responsibilities” error

### In-repo string search

- Grep in `web/modules/custom` for that phrase: **no matches** (error is not MEL’s own string). It is consistent with a **Stripe API** or **Stripe-hosted** onboarding error when the **Connect platform** or **account type** (e.g. **Standard** + platform liability) does not match the platform’s **Connect profile** or **loss liability** configuration.

### Likely locus (inference limited to public Stripe behaviour + MEL’s Standard path)

- On this branch, **account type is `standard`**. If the **Stripe Dashboard / Connect** settings for the platform are incomplete or conflict with **Standard** + requested capabilities, Stripe may reject **account** creation or **AccountLink** creation with a **platform-level** or **onboarding** error. MEL’s **`fix/stripe-connect`** branch adds a **user-visible** message (on `ApiErrorException`) that references the **platform Connect profile** needing **review** — that points to the **same class of failure** (operator-facing), but **does not by itself** change the **root** Stripe **account type** in `createConnectAccount` on **this** branch; the **separate** diff moves creation toward **Express** in `getOrCreateAccount`.

**Conclusion for Task 3:** document **P1: operator must** inspect **Stripe Connect settings**, **Connect application**, and **account type (Standard vs Express)** with Stripe. Do **not** treat this file as a Stripe support diagnosis.

**Required watchdog grep:** no line contained the literal “losses” in the last 100 entries shown; the sample was dominated by connect redirects and “Created AccountLink”.

---

## 7. Account readiness checks (code)

`StripeService::getAccountStatus()` maps:

- `details_submitted` + `charges_enabled` + `payouts_enabled` all true → `status` **complete**; else if charges or payouts false → **restricted**; else **pending** (simplified; see method).  
- Returns `charges_enabled`, `payouts_enabled`, `details_submitted` booleans.  
- **Not** used in the audited snippet: `requirements.currently_due`, `past_due`, `disabled_reason`, or **capability** objects beyond the **initial** `createConnectAccount` capability request.  
- **P2:** for **payouts held** with **charges live**, gating on **`charges_enabled` alone** in places may be **incomplete** (depends on product — some flows intentionally ignore payouts, see in-code comment on onboard controller).

`validateAccountDashboardEligibility()` **does** require `details_submitted` and `charges_enabled` (and not deleted) before **LoginLink**.

**Paid sales blocking:** see §3 (validation service + form checks). **P1** remains: completion subscriber is **log-only**.

---

## 8. Webhooks (summary)

| Area | Findings (from code grep + sample files) |
|------|------------------------------------------|
| **MEL payout** | `web/modules/custom/myeventlane_admin_dashboard/src/Controller/StripeWebhookController.php` — **`Webhook::constructEvent($payload, $sigHeader, $webhookSecret)`**; secret from **config** key `…->get('stripe_webhook_secret')` (schema documents `whsec_` pattern). Fails closed if **empty** (rejects with 500 in snippet). **Idempotence** for `transfer.paid` via ledger/transfer id checks in same file (partial read / grep). |
| **MEL Pro subscription** | `ProSubscriptionWebhookController` — `constructEvent` (same pattern). |
| **Commerce Stripe (contrib)** | Inbound payment webhooks are **Commerce Stripe** / queue; **not** re-audited line-by-line in this task. |
| **myeventlane_webhooks** | **Outbound** HMAC to subscribers; **not** Stripe inbound signature verification. |

**Logging safety:** `StripeService::safeLog` uses `SensitiveDataScrubber` for service logs; `StripeConnectController` still uses direct `\Drupal::logger('mel_debug')->notice('Stripe redirecting to: @url',…)` for **full URL** (see §5).

### Required `grep` for webhook / PaymentIntent (high level)

- `constructEvent` only in `myeventlane_admin_dashboard` and `myeventlane_pro` in the `grep` head for custom code.  
- `application_fee_amount`, `transfer_data`, `PaymentIntent` in `StripeService` and `StripeConnectPaymentService` as above.

---

## 9. Vendor UX (condensed)

- **Dashboard / vendor console** uses `VendorDashboardController::getStripeConnectStatus()` and CTA/JS `stripe-connect-cta.js` (file names only in grep).  
- **assertStripeConnected()** in `VendorConsoleBaseController` checks **`field_stripe_connected` or `field_stripe_charges_enabled`**, and redirects to **`/stripe/connect?destination=…`**. **Admins and uid 1** bypass.  
- **“One primary action”** for connection is the **connect route**; exact Twig/theme review was **out of scope** for this doc.

---

## 10. Admin UX (condensed)

- **Routes** exist for **payouts**, **vendors list**, and **staged financial tools** under `myeventlane_admin_dashboard` (e.g. `/admin/myeventlane/payouts`, …).  
- This audit **does not** confirm each field visible on the admin **vendor** edit form; **no bank/KYC** fields should be added in MEL; **P2** to verify only **safe** Stripe ids/status are shown in UI.

---

## 11. Risk summary

### P0 (launch / security)

1. **Test Stripe secret/publishable keys** present in **repository copy paths** (`_INVALID_config_backup*`, `_myeventlane_audit*`) — treat as **committed secret material** until removed or gitignored; **rotate** if ever valid.  
2. **(Conditional P0)** If production ever relied on the same test keys, **fraud and leakage** — **out of code scope**; ops must confirm.  
3. **No** evidence in this pass that **vendor A** can read **vendor B** Stripe fields via **MEL** code (would need targeted access review) — **not** listed as P0.

### P1 (important)

1. **Branch drift:** `fix/stripe-connect` changes **Connect account type** to **Express** in new helper vs **Standard** in current `connect()` — **product alignment** required before any merge.  
2. **Onboarding / API errors:** `fix/stripe-connect` adds **ApiErrorException** handling and user messaging; **this branch** has **generic** catch in `::connect` (and does **not** list the **“platform profile”** string).  
3. **Checkout completion** only **logs** failed Connect validation — **safety** depends on **earlier** steps.  
4. **Watchdog** may contain **sensitive** redirect URLs; **scrub** or downgrade log level.  
5. **“Managing losses”** — **operator** must reconcile **Connect app**, **account type**, and **Stripe** dashboard with Stripe docs; MEL has **no** local string match.

### P2 (polish / ops)

1. **Gateway export:** `plugin: stripe_connect` **not in** `config/sync` grep; document **where** the active gateway is stored in each env.  
2. **Status fields** do not map **all** Stripe **requirements** arrays in `getAccountStatus` return.  
3. **Onboarding** retry noise in logs.

### P3 (later)

- npm / Stripe-PHP version drift — routine dependency hygiene.

**Required `git` / shell commands:** see **Appendix** (all were run; **failed** only where noted: `ddev drush route` with pipe exit 141, not a business failure).

---

## Current Stripe model (concise)

- **Connect** with **Account** creation ( **`standard` on this branch** ), **Account Links** to **Stripe-hosted** onboarding, and **Connect destination-style** `PaymentIntents` with **`application_fee_amount`** and **`transfer_data[destination]`** to the **connected account id** stored on the **Commerce store**. **Platform** Stripe secret comes from **Commerce payment gateway** (or `myeventlane_core` settings) via **`StripeService`**. **Commerce Stripe** and **MEL** custom gateway work together; exact **active** gateway id in each env may be **outside** sync. **Payout** reconciliation uses a **dedicated** `StripeWebhookController` with **signature verification** when the secret is configured.

---

## Vendor responsibility alignment (concise)

- Code and service comments state **tickets** pay out to vendor **net of application fee**; **donations** and **certain** line types are modelled as **platform**-retained. Vendors’ **obligations** (tax, disputes, **their** Dashboard) are **in line with** Stripe’s **Connect** model, but **Standard vs Express** and **liability** must match **product** and **Stripe** setup — **diverging branches** (Express vs Standard) need **resolution** before claiming alignment.

---

## Confirmed risks (short list)

- **Test keys in backup/audit tree paths** in the repo.  
- **Log exposure** of full **onboarding** redirect URLs.  
- **Standard vs Express** + **`fix/stripe-connect`** not merged — **inconsistent** Connect behaviour until unified.  
- **Checkout** completion Connect validation is **log-only** at the last subscriber.

---

## Recommended next task

**TASK 4 — Fix Stripe onboarding / account-creation path only (single coherent implementation, including merge or supersede of `fix/stripe-connect` with explicit product sign-off on account type, store resolution, and logging safety)** — *without* in this turn changing unrelated checkout or global charge model unless Task 4 scope is written to include them.

---

## Appendix: commands run and outcomes

| Command | Outcome |
|---------|---------|
| `git status --short` | **empty** (clean) |
| `git branch --show-current` | `feature/mel-v2-task-based-completion-audit` |
| `git log -1 --oneline` | `bd2e5209 docs(audit): add MEL v2 current build audit` |
| `composer show \| grep -Ei "stripe\|commerce"` | See §1 |
| `ddev drush route \| grep -Ei "stripe\|connect\|payout\|vendor"` | **Exit 141** (broken pipe to pager); first lines included Commerce Stripe + MEL admin payout webhook, vendor routes |
| `ddev drush ws --count=100 \| grep -Ei "stripe\|…"` | Matched many Stripe/AccountLink/redirect lines; no “losses” in sample |
| `grep -R "sk_test\|…" -n . --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git` | See §1; **backup/audit yml** hits |
| `ddev drush php-eval '…'` (stores / `field_stripe_connected`) | **OK**; per-store 0/1 output |
| `git log --oneline feature/…audit..fix/stripe-connect` | 3 commits listed |
| `git diff feature/…audit..fix/stripe-connect -- …StripeConnectController.php` | Large diff: Express, `getOrCreateAccount`, URLs, `ApiErrorException`, etc. |
| `git diff feature/…audit..fix/stripe-connect -- myeventlane_stripe` | **empty** (no change under that path) |
| `git diff` … `StripeService.php` (manual) | **Non-empty**; adds `getOrCreateAccount`, Express, etc. |
| Grep groups from prompt (`StripeConnect`, `Account::create`, `PaymentIntent`, `webhook`, etc.) | Summarized in report; full outputs not pasted |

### Failed / noisy commands

- **None** in the “command not found / exception” sense for Drush, except **`drush route` exit 141** when used with a **pipe** (expected).  
- **`grep` for `myeventlane_stripe` diff** returned **no** diff; actual Stripe service changes on `fix/stripe-connect` are under **`myeventlane_core`**, not **`myeventlane_stripe`**.

---

**END — Task 3 (audit file only).**
