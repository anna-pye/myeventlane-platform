# Donation Revenue Exclusion Implementation

**Date:** 2026-02-19  
**Branch:** `fix/analytics-donation-exclusion`  
**Status:** Implemented

## Summary

Donation and Boost order item types are now excluded from vendor revenue and ticket counts across all analytics, dashboards, BAS reporting, and Phase 7 metrics. A canonical `OrderItemClassifier` service centralizes exclusion rules.

## Canonical Exclusion List

| Type | Description |
|------|-------------|
| `boost` | Boost purchases (platform revenue) |
| `checkout_donation` | Checkout donations |
| `platform_donation` | Platform donations |
| `rsvp_donation` | RSVP donations |

## Phase 1 — OrderItemClassifier

**File:** `web/modules/custom/myeventlane_analytics/src/Service/OrderItemClassifier.php`

- `isBoost(OrderItemInterface $item): bool`
- `isDonation(OrderItemInterface $item): bool`
- `isVendorRevenueEligible(OrderItemInterface $item): bool`
- `getExcludedTypes(): array`
- `getDonationTypes(): array`

Service ID: `myeventlane_analytics.order_item_classifier`

## Phase 2 — Services Refactored

| Service | Module | Changes |
|---------|--------|---------|
| EventMetricsService | myeventlane_metrics | Injects classifier; replaces `isBoostItem()` with `isVendorRevenueEligible()` |
| AnalyticsDataService | myeventlane_analytics | Injects classifier; excludes non-eligible items from time series, breakdown, funnel |
| TicketSalesService | myeventlane_vendor | Injects classifier; replaces `isBoostItem()` with `isVendorRevenueEligible()` |
| AnalyticsQueryService | myeventlane_analytics | Injects classifier; replaces `oi.type <> 'boost'` with `oi.type NOT IN (getExcludedTypes())` |
| BasReportService | myeventlane_finance | Injects classifier; excludes donations/boost from vendor BAS totals |
| PopularEventsService | myeventlane_analytics | Injects classifier; excludes donation/boost from tickets-sold scoring |

## Phase 3 — PlatformSummaryAggregator

**File:** `web/modules/custom/myeventlane_summary/src/Service/PlatformSummaryAggregator.php`

Revenue derived from order items by type (not order total):

- `vendor_ticket_revenue` — Eligible ticket items (excludes donations, Boost)
- `donation_revenue` — Donation order items
- `boost_revenue` — Boost order items
- `application_fees` — Derived from vendor_ticket_revenue × fee rate

**Platform revenue** = donation_revenue + boost_revenue + application_fees  
**Vendor revenue** = vendor_ticket_revenue − refunds

Schema update: `myeventlane_summary_update_10002()` adds `vendor_ticket_revenue`, `donation_revenue`, `boost_revenue` columns.

## Phase 4 — BAS Report

**File:** `web/modules/custom/myeventlane_finance/src/Service/BasReportService.php`

- Vendor BAS totals exclude donation and Boost order items
- Only vendor-revenue-eligible items counted
- Documented in class and method docblocks: "Donations and Boost are platform revenue and excluded from vendor BAS totals"

## Phase 5 — Regression Tests

**File:** `web/modules/custom/myeventlane_analytics/tests/src/Kernel/OrderItemClassifierTest.php`

- `testBoostItemNotVendorEligible`
- `testDonationItemsNotVendorEligible`
- `testNormalTicketVendorEligible`
- `testGetExcludedTypes`

## Phase 6 — Validation Checklist

After implementation:

```bash
ddev drush cr
ddev drush cim -y
ddev drush updb -y
```

**Manual verification:**

- [ ] Vendor dashboard gross/net unchanged for events without donations
- [ ] Vendor dashboard excludes donation revenue
- [ ] Ticket count excludes donation items
- [ ] Platform dashboard shows donation revenue separately (via `platform_revenue`, `donation_revenue`, `boost_revenue` in `getTotalsLastNDays`)
- [ ] BAS vendor report excludes donations
- [ ] Phase 7 numbers consistent

## Files Changed

| File | Change |
|------|--------|
| `myeventlane_analytics/src/Service/OrderItemClassifier.php` | New |
| `myeventlane_analytics/services.yml` | Added classifier, updated 6 services |
| `myeventlane_metrics/src/Service/EventMetricsService.php` | Refactored |
| `myeventlane_metrics/services.yml` | Added classifier arg |
| `myeventlane_metrics.info.yml` | Added myeventlane_analytics dep |
| `myeventlane_analytics/src/Service/AnalyticsDataService.php` | Refactored |
| `myeventlane_vendor/src/Service/TicketSalesService.php` | Refactored |
| `myeventlane_vendor/services.yml` | Added classifier arg |
| `myeventlane_analytics/src/Phase7/Service/AnalyticsQueryService.php` | Refactored |
| `myeventlane_finance/src/Service/BasReportService.php` | Refactored |
| `myeventlane_finance/services.yml` | Added classifier arg |
| `myeventlane_finance.info.yml` | Added myeventlane_analytics dep |
| `myeventlane_summary/src/Service/PlatformSummaryAggregator.php` | Rewritten |
| `myeventlane_summary/services.yml` | Added classifier arg |
| `myeventlane_summary.info.yml` | Added myeventlane_analytics dep |
| `myeventlane_summary.install` | Added schema cols, update 10002 |
| `myeventlane_summary/src/Service/PlatformSummaryReader.php` | Added new fields to output |
| `myeventlane_analytics/src/Service/PopularEventsService.php` | Refactored |
| `myeventlane_analytics/tests/.../OrderItemClassifierTest.php` | New |
| `myeventlane_analytics/tests/.../AnalyticsKernelTestBase.php` | Added classifier to query service |

## Services Using OrderItemClassifier

- `myeventlane_metrics.service` (EventMetricsService)
- `myeventlane_analytics.data` (AnalyticsDataService)
- `myeventlane_vendor.service.ticket_sales` (TicketSalesService)
- `myeventlane_analytics.phase7.query` (AnalyticsQueryService)
- `myeventlane_finance.bas_report` (BasReportService)
- `myeventlane_summary.aggregator` (PlatformSummaryAggregator)
- `myeventlane_analytics.popular_events` (PopularEventsService)

## Confirmations

- **No `\Drupal::` calls introduced** — All services use dependency injection.
- **No duplicate exclusion logic** — All exclusion logic centralized in OrderItemClassifier.
- **Refund logic preserved** — Phase 7 getRefundAmount still uses ticket-only linkage; net = gross − refunds unchanged.
