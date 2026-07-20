# Payment Launch Remediation Plan

**Branch:** `feature/payment-launch-remediation`  
**Date:** 20 July 2026  
**Authority:** Payment architecture audit docs + ADR-002 / ADR-003 (Option A)  
**Backups:** `backups/payment-launch-remediation-20260720/`

---

## Current state

| Area | Proven runtime |
| --- | --- |
| Gateways on AUD carts | `mel_stripe_cc` (manual, no conditions), `stripe` (Card Element), `stripe_pe_recurring` (PE off_session) |
| MEL filtering | None (`FilterPaymentGateways` unused in MEL) |
| Ledger insert | `PlatformMetricsService::buildKpis()` inserts **all** completed orders |
| Connect destination charges | Plugin present, **0** entities — remains dormant |
| Stripe Card Element auth (DDEV) | `access_token` set → API uses Connect OAuth token while Elements uses platform `publishable_key` → `No such PaymentMethod` |
| Wallet | Unchanged / decoupled from charging |

---

## Target state

| Journey | Gateway | Ledger |
| --- | --- | --- |
| Tickets | `stripe` only (customers) | Eligible (vendor-payable) |
| Boost | `stripe` only | **Not** eligible |
| Platform donation | `stripe` only | **Not** eligible |
| RSVP donation | `stripe` only | **Not** eligible |
| MEL Pro / recurring | `stripe_pe_recurring` only | **Not** eligible |
| Organiser donation (on ticket / `checkout_donation`) | with ticket cart → `stripe` | Eligible with ticket revenue |
| Manual `mel_stripe_cc` | Administrators only | N/A |
| Stripe Connect destination charges | Still unwired | N/A |
| Wallet | Unchanged | N/A |

### Ledger eligibility rules (authoritative for this remediation)

**Eligible (insert unpaid liability):**

1. Order type is **not** `platform_donation`, `rsvp_donation`, or `recurring`.
2. Order contains at least one of:
   - purchased entity bundle `ticket_variation`, or
   - order item type `checkout_donation` (organiser donation line).

**Not eligible:**

- Boost (`boost` / `boost_duration` / `boost_upgrade`)
- Platform donation orders / items
- RSVP donation orders
- MEL Pro (`mel_pro_subscription_variation`) and `recurring` orders
- Platform fees / system adjustments / support invoices (never inserted as ledger rows)

**Existing polluted ledger rows:** left in place (no financial deletion). Ops must not batch-pay non-vendor rows until a separate cleanup is approved.

---

## Rollback plan

1. Restore gateway YAML from `backups/payment-launch-remediation-20260720/`.
2. Restore `PlatformMetricsService.php` from the same backup directory.
3. Remove `FilterPaymentGatewaysSubscriber` + service definition.
4. Revert `OrderItemClassifier` payout-ledger methods.
5. `ddev drush cr` (and `cim` if config was imported).
6. Re-apply environment Stripe secrets via existing deploy overlay (never from git).

Active DDEV `access_token` clear is **environment-only** — re-set via Commerce UI / deploy secrets if rollback of that env fix is required.

---

## Deployment order

1. Deploy code (classifier, gateway filter subscriber, ledger eligibility).
2. Import config: gateway conditions for `mel_stripe_cc` and `stripe_pe_recurring`.
3. Cache rebuild.
4. **Per environment:** ensure Commerce gateway `stripe` has **empty** `access_token` and uses platform `secret_key` + matching `publishable_key` (Option A).
5. Smoke-test each payment journey.
6. Do **not** enable `stripe_connect` gateway entity.
7. Do **not** run unrestricted payout batches until polluted historical ledger rows are reviewed.

---

## Regression checklist

- [ ] Ticket checkout offers only `stripe` to anonymous/customer
- [ ] Boost checkout offers only `stripe`
- [ ] Platform / RSVP donation checkout offers only `stripe`
- [ ] MEL Pro checkout offers `stripe_pe_recurring` (not Card Element / manual)
- [ ] Recurring order type still sees PE gateway
- [ ] Admin can still see `mel_stripe_cc` when role `administrator`
- [ ] New Boost / Pro / platform donation / RSVP completed orders do **not** gain ledger rows
- [ ] New ticket completed orders **do** gain ledger rows when KPIs run
- [ ] Confirmation email + wallet CTAs still work after ticket pay
- [ ] Refund path unchanged
- [ ] Vendor Connect onboarding unchanged
- [ ] No destination-charge PI params at checkout

---

## Expected runtime behaviour

```text
loadMultipleForOrder(order)
  → gateway conditions (currency / variation / role)
  → MEL FilterPaymentGatewaysSubscriber
       removes mel_stripe_cc unless administrator
       removes stripe_pe_recurring unless Pro/recurring
       removes stripe when order is Pro/recurring-only
  → customer pays on platform Stripe account
  → later: PlatformMetricsService inserts ledger only if payout-eligible
```

Fast checkout (PE-only) will **not** activate for ticket carts after PE is scoped to Pro — expected side effect of CF-008; standard Card Element checkout remains.
