# STAGE A0 — Vendor KPI Data Audit

**Status:** READY FOR APPROVAL — STAGE A0  
**Date:** 2026-01-23  
**Scope:** Data accuracy audit only. No UI, no new services, no schema changes.

---

## STEP 1 — Vendor ownership model (CRITICAL)

### Canonical ownership model

**User → Vendor → Store:** A user is linked to a vendor via the `myeventlane_vendor` entity: either as owner (`uid`) or as a member (`field_vendor_users`). Each vendor has exactly one Commerce store via `field_vendor_store`. The store references the vendor via `field_vendor_reference`. Resolving “vendor for current user” is done by loading the vendor (by `uid` or `field_vendor_users`) and then `vendor->field_vendor_store->entity` to get the store. `VendorOwnershipResolver::getStoreForUser` and `VendorContextService` implement this. Some code also falls back to `commerce_store.uid` when `field_vendor_store` is empty.

**Events:** An event node has `field_event_vendor` (→ `myeventlane_vendor`) and `field_event_store` (→ `commerce_store`). `field_event_store` is derived from the vendor’s store by `EventVendorSubscriber` and is the join key used in analytics. **Event ownership for KPIs = event’s `field_event_store`** (i.e. the store that receives the order for that event).

**Commerce orders:** Orders are placed against a store. `commerce_order.store_id` is the canonical link to the vendor: it equals the store used at checkout, which for event ticket purchases is the event’s `field_event_store` (and thus the vendor’s store).

**RSVPs:** `rsvp_submission.event_id` → event node. Vendor scope is derived via the event’s `field_event_store` (join to `node__field_event_store`).

### Analytics scope: Store-based and Event-based

- **Store-based:** Revenue, Orders, and Tickets are scoped by `commerce_order.store_id` = the vendor’s store. This is how `VendorMetricsService` works.
- **Event-based:** RSVPs are scoped by event and then by the event’s `field_event_store` = vendor’s store. Per-event breakdowns (e.g. `getPaidTicketsSoldByEventForStore`) join order items to `field_target_event` and then to events whose `field_event_store` matches the store.

**Decision:** Use **both** in the following way:
- **Vendor-level KPIs:** Resolve the vendor’s store once; all aggregations (Revenue, Orders, Tickets, RSVPs) are filtered by that store (and for RSVPs, by events where `node.field_event_store` = that store).
- **Per-event analytics:** Use `field_event_store` to ensure only that vendor’s events are included; for Commerce data, also join via `field_target_event` to the event.

### Ownership by entity

| Entity | Ownership |
|--------|-----------|
| **Commerce orders** | `order.store_id` = vendor’s `commerce_store` (the store used at checkout for that vendor’s events). |
| **Events** | `node.field_event_vendor` → vendor; `node.field_event_store` → vendor’s store. `field_event_store` is the join used for metrics. |
| **RSVPs** | `rsvp_submission.event_id` → event; vendor = event’s `field_event_store` → store. |

### Ambiguity / inconsistency (non‑blocking)

- **`node.uid` vs `field_event_vendor`:** `_myeventlane_vendor_theme_get_max_completed_order_id_for_user` uses `node.uid = $uid` to find “user’s events.” `VendorMetricsService` and `VendorOwnershipResolver` use `field_event_store` / `field_event_vendor`. If an event has `field_event_vendor` set but `node.uid` is different (e.g. created by staff), the alert could disagree with dashboard metrics. **For KPI definitions, `field_event_store` is the authority;** no change to schema or logic in this audit.
- **Vendor `field_owner` vs `uid`:** `VendorOwnershipResolver::getStoreForUser` uses `field_owner`; other code uses `uid` on the vendor. If both exist, confirm which is authoritative; this does not change the fact that **store** (from `field_vendor_store`) is the KPI join.

---

## STEP 2 — Revenue & Orders (Commerce)

### Order state that counts as “completed”

- Commerce workflow (`order_default`): `draft` → `completed` (or `canceled`). The state **`completed`** is the placed, billable state.
- `VendorMetricsService` filters with **`state = 'completed'`**. Other modules (refunds, reminders, etc.) also treat `placed` and `fulfilled` as valid for their flows; for **Revenue and Orders KPIs, `state = 'completed'`** is the agreed filter.

### Refunds

- **Storage:** `myeventlane_refund_log` (see `myeventlane_refunds.install`).
- **Fields used:** `order_id`, `event_id`, `vendor_uid`, `refund_type` (`full`|`partial`), `refund_scope`, `amount_cents`, `currency`, `status` (`pending`|`completed`|`failed`), `created`.
- **Partial refunds:** Yes. `refund_type = 'partial'` and `amount_cents` hold the amount. `RefundProcessor::requestRefund` supports `refund_type` and `amount_cents`.

### Revenue

**Net revenue (as in current dashboard):**

- **Gross:**  
  `SUM(commerce_order.total_price__number)`  
  from `commerce_order`  
  where `store_id = :store_id`  
  and `state = 'completed'`  
  and `placed >= :start` and `placed <= :end`  
  and `total_price__currency_code = 'AUD'` (or restrict to one currency).
- **Refunds:**  
  `SUM(myeventlane_refund_log.amount_cents)`  
  from `myeventlane_refund_log`  
  join `commerce_order` on `order_id`  
  where `commerce_order.store_id = :store_id`  
  and `myeventlane_refund_log.status = 'completed'`  
  and `myeventlane_refund_log.created` in the chosen time range (or `completed` if a “refund completed” filter is added later).
- **Net:** `net = max(0, gross - refunds)` (after converting `total_price__number` to cents if needed).  
  `VendorMetricsService::getGrossRevenueCents` and `getRefundedAmountCents` implement this; `getMetrics` uses `getRefundedAmountCents` with `r.created` in range.

**If “Revenue” means gross only:**  
Use the same `SUM(commerce_order.total_price__number)` with the same filters, and do not subtract refunds.

### Orders

**Orders =**  
`COUNT(commerce_order.order_id)`  
from `commerce_order`  
where `store_id = :store_id`  
and `state = 'completed'`  
and `placed >= :start` and `placed <= :end`.

(No existing `getOrderCount` in `VendorMetricsService`; this is the intended logic.)

### Time filter

- **Source:** `commerce_order.placed` (timestamp when the order was placed).  
  Commerce Order `placed` is set when the order leaves `draft` (e.g. transitions to `completed`).
- **30‑day window:**  
  `placed >= (now - 30 days)` and `placed <= now`  
  (or `[start, end]` in unix timestamps).  
  The spec’s “completed_time” is implemented as **`placed`** in this codebase.

### Edge cases

| Topic | Finding |
|-------|---------|
| **Test orders** | No `is_test` or similar on `commerce_order`. Test orders in the same store and state will be included. Excluding them would require a new flag or convention. |
| **Zero‑value orders** | Possible. `total_price__number` can be 0. They are included in Revenue (as 0) and in Orders count. |
| **Free tickets** | Free tickets create Commerce orders. The order exists; `total_price` may be 0. For **Tickets sold**, `VendorMetricsService` uses `unit_price__number > 0`, so those quantities are excluded (see Step 3). |

### Exact logic (SQL‑equivalent)

**Revenue (gross):**

```sql
SELECT COALESCE(SUM(o.total_price__number), 0)
FROM commerce_order o
WHERE o.store_id = :store_id
  AND o.state = 'completed'
  AND o.placed >= :start AND o.placed <= :end
  AND o.total_price__currency_code = 'AUD';
```

**Revenue (refunds):**

```sql
SELECT COALESCE(SUM(r.amount_cents), 0)
FROM myeventlane_refund_log r
JOIN commerce_order o ON o.order_id = r.order_id
WHERE o.store_id = :store_id
  AND r.status = 'completed'
  AND r.created >= :start AND r.created <= :end;
```

**Orders:**

```sql
SELECT COUNT(*)
FROM commerce_order o
WHERE o.store_id = :store_id
  AND o.state = 'completed'
  AND o.placed >= :start AND o.placed <= :end;
```

### Risks / exclusions

- Test orders are in scope unless a separate exclusion is defined.
- Refund timing: refund amounts are filtered by `myeventlane_refund_log.created` (request time), not a separate “completed” timestamp. If “refund completed” time is needed, the schema has `completed` (int, nullable); it is not used in `getRefundedAmountCents` today.
- Non‑AUD orders are excluded from the gross revenue sum in the logic above.

---

## STEP 3 — Tickets sold

### Where quantity lives

- **Table:** `commerce_order_item`.
- **Quantity:** `commerce_order_item.quantity`.
- **Link to order:** `commerce_order_item.order_id` → `commerce_order.order_id`.
- **Link to event:** `commerce_order_item__field_target_event` (or `field_target_event` on the order item).

### Excluding refunds and canceled orders

- **Canceled orders:** Only `state = 'completed'` orders are included, so canceled orders are excluded.
- **Refunds:** `VendorMetricsService::getTicketsSoldCount` does **not** reduce quantity by refunded amounts. Refunds are applied at the **revenue** level only. So:
  - A fully refunded order is still in `state = 'completed'`; its ticket quantities remain in “Tickets sold” unless we explicitly join to `myeventlane_refund_log` and adjust.  
  - **Current behaviour:** Tickets sold = sum of quantities from completed orders only; refunds do not deduct from this sum.  
  - **If product is “refunded items”:** There is no per–order‑item refund tracking; `myeventlane_refund_log` is per order/event. To exclude refunded tickets would require a rule (e.g. exclude all tickets from orders that have a `completed` refund in the period). That is not implemented today.

### Tickets sold = SUM(quantity) from completed orders only

**VendorMetricsService rules (today):**

- `commerce_order.store_id = :store_id`
- `commerce_order.state = 'completed'`
- `commerce_order.placed` in `[start, end]`
- **Paid items only:** `commerce_order_item.unit_price__number > 0` and `unit_price__currency_code = 'AUD'`.

So: **Tickets sold = SUM(commerce_order_item.quantity)** over order items in completed orders for the store in the period, **where `unit_price__number > 0`**. Free-ticket quantities are excluded.

### Exact aggregation logic

```sql
SELECT COALESCE(SUM(oi.quantity), 0)
FROM commerce_order_item oi
JOIN commerce_order o ON o.order_id = oi.order_id
WHERE o.store_id = :store_id
  AND o.state = 'completed'
  AND o.placed >= :start AND o.placed <= :end
  AND oi.unit_price__currency_code = 'AUD'
  AND oi.unit_price__number > 0;
```

For **per‑event** breakdown, add:

```sql
JOIN commerce_order_item__field_target_event lnk ON lnk.entity_id = oi.order_item_id
-- and filter/group by lnk.field_target_event_target_id
```

### Double‑counting

- Each `commerce_order_item` is one line; `quantity` is the number of units. Summing `quantity` does not double‑count.
- **Caveat:** This sums **all** paid order items for the store. If the store sells non‑ticket products (e.g. boost, merchandise) with `unit_price__number > 0`, those quantities are included. Restricting to items with `field_target_event` set would limit to event tickets only; that is done in `getPaidTicketsSoldByEventForStore` but not in `getTicketsSoldCount`.

---

## STEP 4 — RSVPs

### Storage

- **Primary:** `rsvp_submission` entity (table `rsvp_submission`; `event_id` as entity reference, `status`, `created`/`changed`).
- **Legacy:** `myeventlane_rsvp` (columns e.g. `event_nid`, `status` `active`/`cancelled`, `created`).  
  `RsvpStorage::add` is only called from `RsvpSubmissionForm` (entity add form). The main public flow is `RsvpFormController::form` → `RsvpPublicForm`, which creates `rsvp_submission` entities.  
  **For KPIs, `rsvp_submission` is the canonical source.**  
  `RsvpStatsService` falls back to `myeventlane_rsvp` when `rsvp_submission` count is 0; `VendorMetricsService` uses only `rsvp_submission`. If `myeventlane_rsvp` still has data, vendor RSVP counts may be understated; a product decision is needed on whether to merge or migrate.

### “Confirmed”

- **`rsvp_submission`:** `status = 'confirmed'`. `waitlist` is not treated as confirmed in `VendorMetricsService` or `RsvpStatsService`.
- **`myeventlane_rsvp` (if used):** `status = 'active'`. `RsvpStorage::cancel` sets `status = 'cancelled'`.

### Cancellations

- **`rsvp_submission`:** `RsvpCancelConfirmForm` **deletes** the entity. Cancelled RSVPs are not in the table; no need to filter by status for exclusions.
- **`myeventlane_rsvp`:** `RsvpStorage::cancel` sets `status = 'cancelled'`. Counts use `status = 'active'` only.

### RSVPs = COUNT(confirmed RSVPs)

**`VendorMetricsService::getConfirmedRsvpCountByStore` (store‑scoped):**

- `rsvp_submission`  
  join `rsvp_submission__event_id` on `entity_id = rsvp_submission.id`  
  join `node__field_event_store` on `entity_id = event_id_target_id`  
- `rsvp_submission.status = 'confirmed'`  
- `node__field_event_store.field_event_store_target_id = :store_id`  
- `rsvp_submission.created` (or `changed` if `created` missing) in `[start, end]`

**Query logic (SQL‑equivalent):**

```sql
SELECT COUNT(r.id)
FROM rsvp_submission r
JOIN rsvp_submission__event_id re ON re.entity_id = r.id
JOIN node__field_event_store nes ON nes.entity_id = re.event_id_target_id
WHERE r.status = 'confirmed'
  AND nes.field_event_store_target_id = :store_id
  AND r.created >= :start AND r.created <= :end;
```

(If `created` is missing, use `changed`.)

### Time filter

- **Field:** `rsvp_submission.created` (or `changed` as fallback).
- **30‑day:** `created >= (now - 30 days)` and `created <= now`.

### Vendor ownership

- Via event: `rsvp_submission.event_id` → `node` → `node__field_event_store` = vendor’s store.

### Edits / cancellations

- **Edits:** If status is changed from `confirmed` to something else, it drops out of the count. No separate “edited” flag is used.
- **Cancellations:** For `rsvp_submission`, cancellation = delete, so they are not in the table. For `myeventlane_rsvp`, `status = 'cancelled'` is excluded by only counting `status = 'active'`.

---

## STEP 5 — Views

### How views are tracked

- **`myeventlane_analytics_pageviews`:** Stub only. README states it is “NOT IMPLEMENTED” and will later store event_id, date_bucket, count, etc.
- **Drupal core Statistics (`node_counter`):** Not found in this codebase. No `statistics` or `node_counter` in `*.yml`/`*.module`/`*.php` references.
- **Custom counter:** None found for event nodes.

### Per‑event

- Intended design in the stub: per‑event, per‑day.
- **Current:** No stored counts.

### Bots

- Not applicable; no view tracking is implemented.

### Views = SUM(node view counts) for vendor events

- **Today:** There is **no** view data. This cannot be implemented as specified.
- **If/when** `myeventlane_analytics_pageviews` or `node_counter` is introduced:  
  - Views = SUM of those counts over event nodes where `field_event_store` = vendor’s store (and optionally `created` or event date in range).  
  - Timeframe would be defined by the chosen implementation (e.g. daily buckets).

### Accuracy

- **Confidence: N/A (no data).** Views-based KPIs and conversion that depend on views are **blocked** until a view-tracking implementation exists.

---

## STEP 6 — Conversion rate

### Formula

- **Conversion = (Tickets sold + RSVPs) / Views**
- **Guard:** If `Views = 0`, do not divide; treat conversion as `0` or “N/A” (product choice).
- **Format:** Rounded to 1 decimal place, e.g. `(Tickets + RSVPs) / Views * 100` then `round(..., 1)` for a percentage.

### Dependence on Views

- Conversion **depends on Views.** With no view data (Step 5), conversion cannot be computed. This is a **blocker** for the conversion KPI until view tracking is available.

---

## STEP 7 — Audit summary

| Metric       | Source                         | Confidence | Notes |
|-------------|----------------------------------|------------|-------|
| **Revenue** | Commerce `commerce_order` + `myeventlane_refund_log` | **High**   | Net = gross − refunds. Gross: `SUM(total_price__number)`, `store_id`, `state='completed'`, `placed` in range, single currency. Refunds: `SUM(amount_cents)` from `myeventlane_refund_log` where `status='completed'`, join order, same store, `created` in range. Partial refunds supported. |
| **Orders**  | Commerce `commerce_order`       | **High**   | `COUNT(*)` where `store_id`, `state='completed'`, `placed` in range. Straightforward. |
| **Tickets** | Commerce `commerce_order_item` + `commerce_order` | **High**   | `SUM(quantity)` where order `store_id`, `state='completed'`, `placed` in range, and `unit_price__number > 0`. Excludes free tickets. Refunds do not reduce ticket count in current logic. Non‑ticket paid products may be included if not filtered by `field_target_event`. |
| **RSVPs**   | `rsvp_submission` (and optionally `myeventlane_rsvp`) | **Medium** | `rsvp_submission.status='confirmed'`, join to event and `field_event_store`. `created` (or `changed`) in range. If `myeventlane_rsvp` has material data, VendorMetricsService undercounts; RsvpStatsService only uses it as fallback when rsvp_submission count is 0. |
| **Views**   | (None)                          | **None**   | No tracking. `myeventlane_analytics_pageviews` is a stub; `node_counter`/statistics not in use. **Blocker** for any Views-based KPI. |
| **Conversion** | (Tickets + RSVPs) / Views  | **Blocked**| Depends on Views; cannot be implemented until view tracking exists. |

---

## DELIVERABLE — READY FOR APPROVAL (STAGE A0)

### Ownership model

- **Vendor** = `myeventlane_vendor`; **Store** = `commerce_store` from `vendor->field_vendor_store`.
- **Commerce:** `order.store_id` = vendor’s store.
- **Events:** `node.field_event_vendor` + `node.field_event_store`; **`field_event_store`** is the join for metrics.
- **RSVPs:** `rsvp_submission.event_id` → event → `field_event_store` = store.

Analytics are **store-based** for Revenue, Orders, Tickets, and **event-based then store-filtered** for RSVPs. Both dimensions align via `field_event_store`.

### Per‑metric logic (30‑day, one store)

- **Revenue (net):**  
  `SUM(order.total_price__number)` for `store_id`, `state='completed'`, `placed` in `[now-30d, now]`, one currency; minus  
  `SUM(myeventlane_refund_log.amount_cents)` for `status='completed'`, join order by `store_id`, `created` in same range.
- **Orders:**  
  `COUNT(commerce_order.order_id)` with same order filters.
- **Tickets:**  
  `SUM(commerce_order_item.quantity)` where order has same filters and `unit_price__number > 0` (and optionally `field_target_event` if restricting to tickets only).
- **RSVPs:**  
  `COUNT(rsvp_submission.id)` where `status='confirmed'`, join to `node__field_event_store` = store, `created` in range.
- **Views:**  
  No logic until a view source exists.
- **Conversion:**  
  `(Tickets + RSVPs) / Views`, with divide‑by‑zero guard; 1 decimal place. Blocked until Views exist.

### Confidence

- **High:** Revenue, Orders, Tickets (with noted exclusions).
- **Medium:** RSVPs (canonical source clear; possible undercount if legacy `myeventlane_rsvp` is still in use).
- **None/blocked:** Views, Conversion.

### Blockers before STAGE A1

1. **Views**  
   - No view data. Either:
     - Implement `myeventlane_analytics_pageviews` (or equivalent) and define schema/ETL, or  
     - Enable and use `node_counter` (or similar) and document how it’s scoped (event, date, bot handling), or  
     - Remove or relax the Conversion KPI (and any Views-only metric) until a source exists.

2. **Conversion**  
   - Depends on Views. Unblock when Views are available.

### Optional to resolve in later stages (non‑blocking)

- **Test orders:** Decide if and how to exclude (e.g. `is_test` or store/order type).
- **Tickets sold:**  
  - Exclude refunded orders (or items) if product requires it; today refunds do not reduce ticket count.  
  - Exclude non‑ticket products by requiring `field_target_event` if the store sells non‑ticket paid products.
- **RSVPs:**  
  - If `myeventlane_rsvp` has production data, decide: merge into KPI, migrate to `rsvp_submission`, or accept undercount.
- **Refund timing:**  
  - Use `myeventlane_refund_log.completed` for “refund completed” in range, if that is preferred over `created`.
- **Alert vs dashboard:**  
  - Align `_myeventlane_vendor_theme_get_max_completed_order_id_for_user` (today `node.uid`) with store-based ownership (`field_event_store` / `field_event_vendor`) if both must stay in sync.

---

*End of STAGE A0 — Vendor KPI Data Audit.*
