# MEL Payment Ledger Review

**Status:** Audit + launch remediation (insert eligibility)  
**Date:** 20 July 2026  
**Table:** `myeventlane_payout_ledger`  
**Primary writer:** `Drupal\myeventlane_admin_dashboard\Service\PlatformMetricsService::buildKpis()`  
**Service ID:** `myeventlane_admin_dashboard.metrics`  
**Critical findings:** CF-006, CF-007  
**Remediation:** [`../release/payment-launch-remediation-report.md`](../release/payment-launch-remediation-report.md)

---

## 1. Does PlatformMetricsService lazily create ledger rows?

**Yes.**

### Evidence

In `PlatformMetricsService::buildKpis(int $days)`:

1. Selects `commerce_order` rows with `state = completed` in a date window.  
2. Loads existing `myeventlane_payout_ledger.order_id` values for those orders.  
3. For each missing order, **inserts** a row with `status = unpaid`, computing:
   - `gross` = order `total_price__number`
   - `commission` = `gross * commission_rate` (from `myeventlane_admin_dashboard.settings`)
   - `net` = `gross - commission`

Comment in class (Phase 1): “Ensures ledger row exists for each completed order (inserts unpaid if missing).”

### Callers (proven)

Injected into admin controllers/services including:

- `PlatformControlCentreController`
- `FinancialController`
- `FinancialExportController`
- `PayoutController`
- `ProReportingBuilder`

**Not proven:** automatic cron invocation of `buildKpis()`.  
**Not present:** any `ORDER_PAID` listener that inserts ledger rows (Phase 2 listed 13 `ORDER_PAID` listeners; none are ledger writers).

### Runtime volume (DDEV, 20 Jul 2026)

| Metric | Value |
| --- | --- |
| Total ledger rows | 157 |
| Unpaid | 111 |
| Approved | 46 |

---

## 2. Advantages of lazy KPI insert

| Advantage | Why |
| --- | --- |
| Simple | Single insert site; no checkout coupling |
| Backfill | Opening admin finance/KPI views can create missing rows for a window |
| Low checkout latency | No ledger write on hot payment path |
| Idempotent-ish | Skips `order_id` already present |

---

## 3. Disadvantages

| Disadvantage | Why |
| --- | --- |
| Not payment-lifecycle-bound | Paid orders may lack rows until an admin metrics path runs |
| Window-limited | Only orders in the KPI `days` window are considered |
| Unfiltered scope | **All** completed orders — including donations, Boost, Pro (CF-007) |
| Wrong economics | Flat `commission_rate` on gross may not match ticket fee model / Connect fee math |
| Ops opacity | Payout batch may silently omit recent sales |
| Dual truth risk | Finance KPIs and payout eligibility share a side-effecting method |

---

## 4. Launch risk

| Risk | Severity | Notes |
| --- | --- | --- |
| Vendors unpaid because row missing | Critical | CF-006 |
| Vendors overpaid / wrong liabilities (donations, Boost, Pro in ledger) | Critical | CF-007 — runtime confirmed |
| Ops assumes ledger == ticket sales | High | Matrix shows contamination |
| Relying on admin page hits as “settlement job” | High | Fragile in production |

**Recommendation for payout operations at launch:** treat current ledger population as **not launch-safe** until scope + timing are fixed (implementation out of scope for this audit).

---

## 5. Recommendation

### Keep for launch?

**Keep the Transfer + ledger architecture (Option A)** as the intended marketplace model for launch — it matches wired gateways.

**Do not treat lazy KPI insert as an acceptable long-term guarantee** for payout correctness.

| Option | Recommendation |
| --- | --- |
| Keep lazy insert unchanged for launch | **No** for vendor payout go-live |
| Move to `ORDER_PAID` after launch | **Yes — preferred hardening**, with allowlisting |
| Minimum launch ops mitigation (no code in this phase) | Product/ops must confirm: (1) which order types belong on ledger; (2) who runs metrics/backfill before batches; (3) manual exclusion of donation/Boost/Pro rows |

### Launch remediation (CF-007 insert scope) — implemented

`buildKpis()` still lazy-inserts, but only when `OrderItemClassifier::isPayoutLedgerEligibleOrder()` is TRUE:

| Eligible | Not eligible |
| --- | --- |
| `ticket_variation` revenue | Boost |
| `checkout_donation` organiser lines | Platform donation orders/items |
| (organiser tip via ticket order adjustments included in ticket order gross) | RSVP donation orders |
| | MEL Pro / `recurring` |
| | Platform fees / system adjustments / support invoices |

**Not done in this remediation:** move writer to `ORDER_PAID` (CF-006); delete/fix historical polluted rows.

### Preferred post-launch design

1. On `ORDER_PAID` (or place), insert ledger row for payout-eligible orders only.  
2. Idempotent unique key on `order_id`.  
3. KPI/backfill becomes repair, not authority.  
4. Align commission source of truth with platform fee / MEL % model.

---

## 6. Confidence

| Claim | Confidence |
| --- | --- |
| Lazy insert exists | High (code + runtime rows) |
| Only writer for inserts | High (repo search Phase 1; not re-litigated) |
| Contaminates non-ticket orders | High (SQL Phase 2) |
| Production cron frequency | **Not proven** |
