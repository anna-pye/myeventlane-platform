# Task 5E — Event Studio publish blocker after ticket refs (field_product_target payload)

**Date:** 2026-04-28

## Context

After **`field_ticket_types`** reconciliation ([commit c13637cd](https://github.com/myeventlane/myeventlane/commit/c13637cd)) and **paid readiness JS** ([75b41e94](https://github.com/myeventlane/myeventlane/commit/75b41e94)), event **1567** still showed tickets and product **90** in the database, but publish from Event Studio could still fail or regress product linkage on save.

## Event 1567 state (CLI snapshot)

- **`field_event_type`**: paid  
- **`field_ticket_types`**: 88, 89  
- **`field_product_target`**: 90 (Commerce ticket product, published)  
- **`PaidPublishStripeGate`** for owner **uid 1**: **ALLOW** (admin bypass)  

Stripe charge-ready **server** gate was not blocking uid 1.

## Root cause

**Publish is blocked or product linkage is cleared because `EventStudioMelPayloadService::buildFromFormState()` did not carry forward `field_product_target` from the saved node when the submitted `mel[field_product_target]` value was empty**, while it already did so for `field_ticket_types`.

The ticket product field lives under **`#states`** (visible when event type is paid). On full-form submit the autocomplete can be **missing from POST or empty** even though the node already references product **90**.  
`EventStudioSaveService::applyTicketPayload()` then received **`field_product_target` ⇒ empty**, cleared the node reference, and failed paid validation (“Paid events need a ticket product”) or blocked publish.

This is independent of:

- **`PaidPublishStripeGate`** (unchanged; vendor testing still gated correctly).  
- **`mel_publish_blocked`** in Twig preprocess (onboarding Stripe messaging — separate UX).

## Fix

**File:** [`web/modules/custom/myeventlane_event_studio/src/Service/EventStudioMelPayloadService.php`](../../web/modules/custom/myeventlane_event_studio/src/Service/EventStudioMelPayloadService.php)

For **`$nid > 0`**, after merging **`field_ticket_types`** from the loaded node: if **`field_product_target`** from the form is missing/zero **and** the node already has a non-empty **`field_product_target`**, set **`payload['field_product_target']`** to the existing **`target_id`**.

Does **not**:

- Create tickets, products, or variations.  
- Bypass Stripe onboarding or the publish gate.  
- Hide Twig warnings globally.

## Verification

```bash
composer validate
ddev drush cr
php -l web/modules/custom/myeventlane_event_studio/src/Service/EventStudioMelPayloadService.php
```

**Manual (browser)**

1. `/vendor/events/1567/edit` — ticket cards visible; product linked.  
2. Toggle publish / save — event saves without clearing **`field_product_target`**.  
3. Vendor user without charge-ready Stripe — paid publish still blocked server-side with Stripe message.  
4. RSVP/external flows unchanged.

## Browser result

*(Record after QA.)*

## Follow-ups

- Optional: align **`mel_publish_blocked`** preprocess flag with **`PaidPublishStripeGate`** Commerce-store criteria so the Stripe banner matches server behaviour for vendors with **`stripe_connected`** in onboarding but not charge-ready on the store (product polish only).

## Ready to commit?

Yes, after one successful publish/save smoke test on a paid event with product linked only on the node (simulate empty product field in POST).
