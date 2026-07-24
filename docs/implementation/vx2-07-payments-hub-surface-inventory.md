# VX2-07 — Payments Hub surface inventory

**Epic:** VX2-07 Payments  
**Branch:** `feature/vx2-payments-hub`  
**Date:** 2026-07-24  
**Authority:** Vendor Experience Convergence A7 / G Payments blueprint

## Disposition

| Surface | Path | Disposition | Notes |
| --- | --- | --- | --- |
| Payments Hub | `/vendor/payments` | **KEEP (created)** | Canonical Trust Centre |
| Payouts | `/vendor/payouts` | **KEEP** (deep link) | History + balances under Payments |
| Finance summary | `/vendor/finance` | **REDIRECT** → Payments `#overview` | Product name is Payments |
| BAS / tax | `/vendor/finance/bas` | **KEEP** | Tax section deep link |
| BAS CSV/PDF | export routes | **KEEP** | Tax exports |
| MEL billing | `/vendor/billing/mel-contributions*` | **KEEP** | Billing section when module enabled |
| Refund summary | `/vendor/support/refunds` | **MERGE entry** | Linked from Payments · Refunds; copy softened |
| Event refund requests | `/vendor/events/{id}/refund-requests` | **KEEP** | Event Workspace action |
| Vendor refund form | `/vendor/orders/{order}/refund` | **KEEP** | Order context action |
| Stripe Connect / manage / callbacks | `/stripe/*`, onboard return | **KEEP** | Infra + CTAs from hub |
| Settings Business & payments | `/vendor/settings` | **RENAME** jargon | Store → Account; CTA → Payments |
| Dashboard Stripe chip / quick actions | `/vendor/dashboard` | **KEEP**; CTAs → hub | Health mirrors Payments |
| Event Workspace payments card | Overview | **KEEP**; route fixed | Was broken `myeventlane_vendor.payouts` |
| Manage-event payments placeholder | legacy | **REDIRECT** → hub | Was payouts |

## Terminology

| Avoid | Prefer |
| --- | --- |
| Gateway / Plugin / Commerce / Store | Payments / Stripe / Payment account |
| Finance (product name) | Payments |
| Refund logs | Refund activity |
| Manage Stripe Account | Open Stripe |

## Architecture

- Host: `myeventlane_vendor` (`VendorPaymentsHubController`, `VendorPaymentsHubBuilder`, `VendorPaymentsHealthService`)
- Stripe balances: existing `myeventlane_stripe.vendor_payout` (extended pending + latest payout)
- Refund metrics: optional `myeventlane_escalations_refunds.*`
- Tax: existing `myeventlane_finance` BAS
- Billing: existing `myeventlane_donations` MEL contributions
- No new payment architecture; no Connect / Commerce model changes
