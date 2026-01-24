# STAGE A1 — Vendor KPI Aggregation Service

**Status:** READY FOR APPROVAL — STAGE A1  
**Date:** 2026-01-23  
**Scope:** Service only. No UI, Twig, SCSS, schema changes, Views, or Conversion KPI.

---

## 1. Where the service was placed

**Module:** `web/modules/custom/myeventlane_vendor_analytics`

- `myeventlane_vendor_dashboard` does **not** exist; the spec required creating `myeventlane_vendor_analytics` when the preferred module is absent.
- **Service:** `web/modules/custom/myeventlane_vendor_analytics/src/Service/VendorKpiService.php`
- **Registration:** `myeventlane_vendor_analytics.services.yml` — `myeventlane_vendor_analytics.vendor_kpi`

---

## 2. Wrapped vs implemented

**Path: Implemented queries (did not wrap VendorMetricsService).**

- **VendorMetricsService** (in `myeventlane_dashboard`) already has `getGrossRevenueCents`, `getRefundedAmountCents`, `getTicketsSoldCount`, `getConfirmedRsvpCountByStore`, and `getDebugTotals`, and matches A0 for revenue, tickets, and RSVPs. Its aggregate methods are **private**, and it does **not** expose:
  - **orders_count**, or
  - a **currency** parameter (it uses a fixed `AUD`).
- To support `getKpisForStore(…, $currency)` and **orders_count**, the required KPIs were implemented directly in `VendorKpiService` using the A0 definitions. The logic mirrors `VendorMetricsService` where it applies (filters, joins, refund handling).

---

## 3. KPI definitions used

| KPI | Definition (brief) |
|-----|--------------------|
| **Net revenue** | `max(0, gross_cents - refunded_cents)`. Gross: `SUM(commerce_order.total_price__number)` → cents. Filters: `store_id`, `state = 'completed'`, `placed` in `[start, end]`, `total_price__currency_code = :currency`. Refunds: `SUM(myeventlane_refund_log.amount_cents)` where `status = 'completed'`, join `commerce_order` on `order_id`, `store_id`, `created` in range; `currency` filtered when column exists (`LOWER(r.currency) = LOWER(:currency)`). |
| **Orders** | `COUNT(commerce_order.order_id)` with same order filters as gross (store, state, placed, currency). |
| **Tickets sold** | `SUM(commerce_order_item.quantity)` over `commerce_order_item` joined to `commerce_order`. Order: `store_id`, `state = 'completed'`, `placed` in range. Item: `unit_price__currency_code = :currency`, `unit_price__number > 0`. No refund deduction; no `field_target_event` filter (noted as follow-up). |
| **RSVPs** | `COUNT(rsvp_submission.id)`. Join `rsvp_submission__event_id` and `node__field_event_store`; `status = 'confirmed'`, `field_event_store_target_id = store_id`, `rsvp_submission.created` in `[start, end]` (fallback `changed` if `created` missing). Legacy `myeventlane_rsvp` **not** included. |

**Time fields:** `commerce_order.placed` for Commerce; `rsvp_submission.created` for RSVPs.

---

## 4. Cache key and TTL

- **Key:** `vendor_kpi:{store_id}:{start_ts}:{end_ts}:{currency}`
- **TTL:** 300 seconds (5 minutes)
- **Tags:** `commerce_order_list`, `commerce_order_item_list`, `rsvp_submission_list`, `commerce_store:{id}`

---

## 5. Drush php-eval test snippet

Run as a user that has (or can resolve to) a vendor store. `myeventlane_checkout_flow` and `myeventlane_vendor_analytics` must be enabled.

```php
$resolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
$user = \Drupal::entityTypeManager()->getStorage('user')->load(1);
$store = $user ? $resolver->getStoreForUser($user) : NULL;
if (!$store) {
  $stores = \Drupal::entityTypeManager()->getStorage('commerce_store')->loadByProperties(['type' => 'online']);
  $store = $stores ? reset($stores) : NULL;
}
if (!$store) {
  print "No store found. Ensure a vendor store exists.\n";
  return;
}
$kpi = \Drupal::service('myeventlane_vendor_analytics.vendor_kpi');
$range = $kpi->getDefaultRangeLast30Days();
$out = $kpi->getKpisForStore($store, $range['start'], $range['end']);
print_r($out);
```

**One-liner for `drush php:eval`:**

```bash
ddev drush php:eval "\$r = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver'); \$u = \Drupal::entityTypeManager()->getStorage('user')->load(1); \$s = \$u ? \$r->getStoreForUser(\$u) : null; if (!\$s) { \$st = \Drupal::entityTypeManager()->getStorage('commerce_store')->loadByProperties(['type' => 'online']); \$s = \$st ? reset(\$st) : null; } if (!\$s) { print \"No store.\n\"; return; } \$k = \Drupal::service('myeventlane_vendor_analytics.vendor_kpi'); \$rg = \$k->getDefaultRangeLast30Days(); print_r(\$k->getKpisForStore(\$s, \$rg['start'], \$rg['end']));"
```

**Example output (shape):**

```
Array
(
    [revenue_net_cents] => 624496
    [orders_count] => 39
    [tickets_sold] => 58
    [rsvps_confirmed] => 0
    [currency] => AUD
)
```

---

## 6. Files created/updated

| Path | Purpose |
|------|---------|
| `web/modules/custom/myeventlane_vendor_analytics/myeventlane_vendor_analytics.info.yml` | Module definition; deps: `commerce:commerce_order`, `commerce:commerce_store`, `drupal:user`. |
| `web/modules/custom/myeventlane_vendor_analytics/myeventlane_vendor_analytics.module` | Empty hook file. |
| `web/modules/custom/myeventlane_vendor_analytics/myeventlane_vendor_analytics.services.yml` | `logger.channel.myeventlane_vendor_analytics`, `myeventlane_vendor_analytics.vendor_kpi`. |
| `web/modules/custom/myeventlane_vendor_analytics/src/Service/VendorKpiService.php` | KPI service: `getKpisForStore`, `getDefaultRangeLast30Days`, and private query helpers. |

---

*End of STAGE A1 deliverable.*
