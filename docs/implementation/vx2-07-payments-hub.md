# VX2-07 — Payments Hub implementation notes

**Epic:** VX2-07 Payments  
**Branch:** `feature/vx2-payments-hub`  
**Date:** 2026-07-24

## What shipped

- Organiser **Payments Hub** at `/vendor/payments` (Trust Centre)
- Sections: Payment health, Overview, Stripe connection, Payouts, Refunds, Tax, Billing (if applicable), Support
- Shell nav **Payments** points at the hub (was `/vendor/payouts`)
- `/vendor/finance` redirects to Payments overview
- Payouts deep page improved with balances, last payout, empty-state copy
- Settings payment copy: Account name/email; Open Payments CTA
- Event Workspace “View payments” route fixed to hub
- AU English trust copy; no Gateway / Commerce / Store in hub UI

## Architecture

```text
/vendor/payments  ← VendorPaymentsHubController
  └─ VendorPaymentsHubBuilder
       ├─ VendorPaymentsHealthService (stored Stripe flags)
       ├─ TicketSalesService (net earnings)
       ├─ myeventlane_stripe.vendor_payout (balances / last payout)
       └─ optional refund metrics + BAS + MEL billing deep links
```

## Instrumentation (documented; logger + data attributes)

| Event | Where | Pipeline |
| --- | --- | --- |
| `payments_hub_opened` | Hub builder logger + Twig `data-mel-analytics-event` | Deferred |
| `payment_health_warning` | Hub builder when `needs_attention` | Deferred |
| `stripe_attention_viewed` | Primary CTA when attention required | Deferred |
| `payout_viewed` | Payouts section marker | Deferred |
| `stripe_connected` | Existing Connect success paths (unchanged) | Existing |
| `refund_processed` | Existing refund modules (unchanged) | Existing |

Analytics product wiring is deferred — events are logged / marked for a future collector.

## Manual QA checklist

- [ ] New organiser — not connected health + Connect Stripe CTA
- [ ] Stripe connected — Ready to receive payments
- [ ] Verification pending / incomplete — Fix issue CTA
- [ ] Payouts restricted — Payout delayed copy
- [ ] Paid events with sales — balances / net earnings
- [ ] Free-only organiser — empty payouts copy
- [ ] Refund activity present / empty
- [ ] Tax BAS entry (when finance module enabled)
- [ ] MEL billing entry (when donations billing applies)
- [ ] Desktop / tablet / 390px
- [ ] Keyboard: section links, primary/secondary CTAs, focus rings
- [ ] Screen reader: health region heading, KPI aria-labels, empty status

## Inventory

See `docs/implementation/vx2-07-payments-hub-surface-inventory.md`.

## Remaining roadmap

- Wire analytics collector for documented events
- Optionally embed live payout history table in hub (today: deep link)
- Retire parallel `myeventlane_event_state` refund routes after ownership parity review
- Settings: further purge residual stored-status raw phase strings
