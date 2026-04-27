# Task 4 — Stripe Connect onboarding (MEL) — summary

**Branch:** `feature/mel-v2-task-based-completion-audit`  
**Product decision:** Stripe Connect **Express** for vendor onboarding (Stripe-hosted onboarding, vendor responsibility, no custom KYC in MEL).

---

## Account type implemented

- New Connect accounts are created as **Express** via `StripeService::createConnectAccount(..., 'express')` (default parameter is Express).
- `ensureConnectAccountIdForStore()` **reuses** existing `field_stripe_account_id` when set; it only creates an account when the field is empty.
- Method names and docblocks describe Express as the MEL default; `standard` is documented as legacy-only in the account-creation API.

---

## Files changed

| File | Purpose |
|------|---------|
| `web/modules/custom/myeventlane_core/src/Service/StripeService.php` | Express default; `maskAccountId()`; `ensureConnectAccountIdForStore()`; `applyConnectStatusToCommerceStore()`; safe logging (masked account ids in info logs; no full URLs in new code paths). |
| `web/modules/custom/myeventlane_vendor/src/Controller/StripeConnectController.php` | Account link flow, query `account_id` validation, callback, `manage()`; `ApiErrorException` handling with `logConnectApiError()` on manage; no full Account Link / login URLs in logs. |
| `web/modules/custom/myeventlane_vendor/src/EventSubscriber/VendorStoreSubscriber.php` | `ensureStoreForVendor()` + `createAndPersistStoreForVendor()` — store from vendor relationship only (onboarding guard preserved). |
| `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php` | `getStripeConnectStatus()`: **removed** `commerce_store` lookup by `uid`; store only from `field_vendor_store`; `account_id` on connect/resume URLs; `mel_stripe_state` + `primary_action` + `action_url`; dashboard copy and alerts/notifications aligned. |

---

## Cherry-picks from `fix/stripe-connect`

- **No bulk merge** and **no cherry-pick commits** from that branch. Implementation was aligned manually with the same goals (Express, membership-based vendor, store subscriber, safe logging).

---

## How existing connected vendors are protected

- If `field_stripe_account_id` is **non-empty**, `ensureConnectAccountIdForStore()` returns it and **does not** create another Stripe account.
- New-account creation only initializes `field_stripe_*` flags when a **new** account is created (empty id).
- Callback and connect “resume” paths call `getAccountStatus()` and `applyConnectStatusToCommerceStore()` to **refresh** flags from Stripe (authoritative for charges/payouts).

---

## Store resolution

- **No** default platform store and **no** “first store by user uid” fallback on the dashboard.
- Store is resolved via the **vendor** → `field_vendor_store` relationship (and `VendorStoreSubscriber::ensureStoreForVendor()` when a store must be created after onboarding is complete).

---

## Account links

- Each onboarding attempt builds a **new** Account Link via `createAccountLink()` after `ensureConnectAccountIdForStore()`.
- `return_url` → `myeventlane_vendor.stripe_callback` and `refresh_url` → `myeventlane_vendor.stripe_connect`, both with `account_id` (+ optional `destination`) for validation.
- Redirect host must be `https://connect.stripe.com` for onboarding links.
- Logs: **no** full redirect URLs; structured notice with vendor id, store id, **masked** account id.

---

## Logging removed or masked

- `StripeService` info logs for Connect account / Account Link / Login Link / ticket PaymentIntent destination use **masked** account ids where applicable.
- Controllers use masked account ids in structured error/notice messages; `logConnectApiError()` includes vendor id, user id, store id, masked account id, Stripe error type/code, and message (API message only — not raw response bodies).

---

## Verification commands and results (2026-04-27)

| Command | Result |
|---------|--------|
| `composer validate` | `./composer.json is valid` |
| `ddev drush cr` | Success (cache rebuild) |
| `ddev drush route \| grep -Ei "myeventlane_vendor\.stripe"` | Listed `/stripe/connect`, `/stripe/connect/callback`, `/stripe/manage`, onboard refresh/return aliases |
| `grep -R "Stripe redirecting to:\|connect.stripe.com" -n web/modules/custom` | Only host validation in `StripeConnectController` (no full URL logging) |
| `grep -R "createConnectAccount" -n web/modules/custom` | `StripeService` only (Express) |
| `grep -R "type.*standard\|type.*express" -n web/modules/custom` | Connect `type` is `express` in `createConnectAccount`; other matches are unrelated Views “standard” sort ids |

**Not run in this pass (no SCSS/twig changes in Task 4):** `npm run mel:lint` / `npm run mel:build`.

**Optional:** `ddev drush ws --count=100 | grep -Ei "stripe|connect|..."` — run after manual tests in a real environment.

---

## Manual tests still required

1. Vendor with existing `field_stripe_account_id`: Connect does not create a duplicate; flags refresh from Stripe.
2. Vendor with store, no account: Express account created; id saved; redirect to Stripe onboarding; return/callback updates store.
3. Vendor without store (onboarding complete): store created via subscriber; Stripe attaches to that store only.
4. Expired Account Link: hit `refresh_url`; new link generated; no loop.
5. `return_url` with `destination` and `account_id`: relationship validated; status updated.
6. Induce Stripe `ApiErrorException`: user sees generic message; logs are safe.
7. **Paid selling:** ticket/event flows using `assertStripeConnected()` — confirm behaviour matches policy (charges/connected flags). Broader “publish paid event” gating may live outside this task; see follow-up.

---

## Follow-up tasks

1. **Under review / disabled:** `getAccountStatus()` does not expose `requirements` arrays; dashboard states are derived from **stored** fields only. To show “Under review” or “Disabled/rejected” accurately, either extend stored metadata (if product agrees) or a controlled background refresh (watch performance).
2. **Repository secret hygiene:** rotate/remove `_INVALID_config_backup*`, `_myeventlane_audit*` key material (not in Task 4 scope).
3. **Publish path for paid events:** if anything allows publishing a paid-ticket event without `charges_enabled`, add a follow-up ticket; vendor console already uses `assertStripeConnected()` on key routes.

---

## Root cause (brief)

Prior work mixed **Standard/Express** assumptions, **uid-based** store fallbacks, and **unsafe or overly verbose** logging. Task 4 converges on **Express**, **vendor-bound store** resolution, and **safe** operator logs while preserving existing Connect account ids.

---

## Ready to commit?

**Yes, pending your review and the manual checks above.** Working tree: modified custom modules and this summary (new file); commit when satisfied.

---

## STOP

Task 4 implementation and documentation are complete; no further scope unless Anna extends the task.
