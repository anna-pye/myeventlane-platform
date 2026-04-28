# Task 5B — Server-side Stripe charge-ready gate (Event Studio paid publish)

**Date:** 2026-04-28

## Root cause

Task 5 found that Event Studio relied on **Twig/preprocess warnings** (`mel_publish_stripe_gate` / `mel_publish_blocked`) for paid events without a **canonical save-layer check**. Vendors could theoretically publish paid or hybrid (`both`) events while the Commerce store was not Stripe charge-ready if they bypassed UI hints.

## Guard location

| Layer | Behaviour |
|-------|-----------|
| **`PaidPublishStripeGate`** (`myeventlane_vendor`) | Single policy service: resolves vendor membership → Commerce store → validates `field_stripe_account_id` (non-empty `acct_*`) and `field_stripe_charges_enabled === TRUE`. No Stripe API calls. **Fail closed** if expected fields are missing on the store. |
| **`EventStudioSaveService::save()`** | Before any node mutation, when `!$draft`, intended publish (`status`), and `field_event_type` ∈ `{paid, both}`, calls the gate; returns form errors instead of saving. |
| **`EventStudioSaveService::setNodePublishedState()`** | Bulk/vendor list publish path: same rule when `$published === TRUE`; throws `InvalidArgumentException` with the user message so callers can handle it. |
| **`VendorEventsBulkActionsForm`** | Catches `InvalidArgumentException` around bulk publish and surfaces the message (no white screen). |

### Exact publish-intent rule (`save()`)

- **Draft/autosave** (`$draft === TRUE`): gate **never** runs (vendors can keep editing unpaid Stripe setup).
- **Non-draft** (`$draft === FALSE`): gate runs only if the save would **publish**:
  - **New node:** `(bool) ($payload['status'] ?? FALSE)` — default unpublished; matches Event Studio “Published” checkbox default for new events.
  - **Existing node:** `(bool) ($payload['status'] ?? TRUE)` — preserves prior behaviour when status omitted from payload.
- **Event types:** `paid` and `both` only. `rsvp` and `external` unchanged.

### Admin bypass

Matches **`VendorConsoleBaseController::assertStripeConnected`**: user ID `1` or permission `administer site configuration` skips the gate.

### Logging

`logger.channel.myeventlane_vendor` receives **notice** lines with: `reason`, `uid`, `vendor_id`, `store_id`, `event_nid` — **no** secrets or Stripe keys.

### User-facing message

> Connect Stripe before publishing paid tickets. Stripe must be ready to accept charges before this event can go live.

## Files changed

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_vendor/src/Service/PaidPublishStripeGate.php` | **New** — gate service |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml` | Register `myeventlane_vendor.paid_publish_stripe_gate` |
| `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.services.yml` | Inject gate into `myeventlane_event_studio.save` |
| `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php` | Early validation in `save()`; validation in `setNodePublishedState()` |
| `web/modules/custom/myeventlane_vendor/src/Form/VendorEventsBulkActionsForm.php` | `try/catch` on bulk publish |

## Direct route `/vendor/events/create`

**No route/controller change** in this task: vendors can still open Event Studio and **save unpublished** paid events (status unchecked / draft-capable flows). Only **publish intent** is blocked without charge-ready Stripe.

**Follow-up (optional):** align `/vendor/events/add` redirect behaviour with `/vendor/events/create` for analytics/onboarding only — **not** required for server-side publish safety after this change.

## Manual tests required (staging)

| Case | Expected |
|------|----------|
| **A** Stripe charge-ready vendor publishes paid | Success |
| **B** Vendor without charge-ready Stripe saves paid with **Published** unchecked | Success (draft/unpublished) |
| **B2** Same vendor tries to publish paid (checkbox / publish step) | Blocked + message |
| **C** RSVP-only publish | No Stripe gate |
| **D** External-only publish | No Stripe gate |
| **E** Admin / user 1 | Bypass (same as onboarding policy) |
| **F** Bulk publish paid events from vendor list without Stripe | Error message per blocked row / attempt |

## Verification commands / results

Run locally after pull:

```bash
composer validate
ddev drush cr
php -l web/modules/custom/myeventlane_vendor/src/Service/PaidPublishStripeGate.php
php -l web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php
php -l web/modules/custom/myeventlane_vendor/src/Form/VendorEventsBulkActionsForm.php
```

Watchdog spot-check:

```bash
ddev drush ws --count=80 | grep -Ei "event_studio|stripe|charges|publish|error|exception" || true
```

*(Record actual command output in CI or staging when validating.)*

## Remaining follow-ups

- Optional: extend the same gate to **other** publish entrypoints (e.g. API or CLI) if they bypass `EventStudioSaveService`.
- Task **6** intentionally **not** started here.

## Ready to commit?

Yes, after `composer validate`, `ddev drush cr`, `php -l` clean, and staging manual checks **A–F** documented above.
