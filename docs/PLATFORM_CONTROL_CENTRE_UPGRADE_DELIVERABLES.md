# Platform Control Centre Upgrade — Deliverables

**Date:** 2026-02-21  
**Branch:** audit/staging-2026-Q1 (or current)  
**Route:** `/admin/myeventlane`

---

## What Changed

### Phase 0 — Discovery (completed)

- **Module:** `myeventlane_admin_dashboard` (not `mel_admin_dashboard`)
- **Commerce:** `commerce_order` with `store_id`, `placed`, `state`, `total_price__number`
- **Commission:** No stored adjustments; `platform_daily_summary.platform_fees` from cron; fallback: `commission_rate` config (default 10%)
- **Payout tracking:** None; new ledger table added
- **Currency:** AUD (from `myeventlane_core.settings`)

### Phase 1 — Data Services

- **PlatformMetricsService** (`myeventlane_admin_dashboard.metrics`):
  - `getKpis($days)`: orders, gross, refunds, commission, net to vendors, open escalations
  - `getRevenueSeriesDaily($days)`: labels, gross, commission, net for Chart.js
  - `getVendorRanking($days, $limit)`: store-level orders, gross, commission, net, unpaid
  - `getPayoutLiabilitySummary($days)`: unpaid total, paid total, unpaid by store
  - `markPaid($orderId, $transferId)`: for future Stripe sync
- **Config:** `myeventlane_admin_dashboard.settings` with `commission_rate: 0.10`

### Phase 2 — Payout Ledger

- **Table:** `myeventlane_payout_ledger` (id, order_id, store_id, status, transfer_id, paid_at, created)
- **Update hook:** `myeventlane_admin_dashboard_update_10001()`

### Phase 3 — Controller & Template

- **Controller:** Injects `PlatformMetricsService`; passes KPIs, series, vendor ranking, payout summary
- **Template:** `platform-control-centre.html.twig` — 6 KPI cards, chart panel, vendor ranking table, payout liability panel, Export CSV link
- Lazy builders kept for alerts and recent activity

### Phase 4 — Chart.js

- **Library:** Chart.js 4.4.1 from CDN
- **JS:** `platform-control-centre-chart.js` — line chart for gross, commission, net
- **Settings:** `drupalSettings.myeventlaneAdminDashboard.revenueSeries`

### Phase 5 — CSV Export

- **Route:** `/admin/myeventlane/export/financial.csv`
- **Controller:** `FinancialExportController::export()`
- **Query params:** `days` (default 30), `store_id` (optional)
- **Columns:** date_range, store_id, store_label, orders, gross, commission, net, unpaid_liability

### Phase 6 — Styling

- KPI grid (6 columns desktop)
- Chart panel with canvas
- Zebra-striped tables, right-aligned numbers
- Unpaid liability coral highlight
- Responsive layout

---

## Files Touched

| File | Action |
|------|--------|
| `config/schema/myeventlane_admin_dashboard.schema.yml` | Created |
| `config/install/myeventlane_admin_dashboard.settings.yml` | Created |
| `src/Service/PlatformMetricsService.php` | Created |
| `src/Controller/FinancialExportController.php` | Created |
| `myeventlane_admin_dashboard.install` | Created |
| `myeventlane_admin_dashboard.services.yml` | Modified |
| `myeventlane_admin_dashboard.info.yml` | Modified (deps) |
| `myeventlane_admin_dashboard.routing.yml` | Modified (export route) |
| `myeventlane_admin_dashboard.module` | Modified (theme vars) |
| `myeventlane_admin_dashboard.libraries.yml` | Modified (Chart.js, JS) |
| `templates/platform-control-centre.html.twig` | Replaced |
| `src/Controller/PlatformControlCentreController.php` | Modified |
| `css/platform-control-centre.css` | Modified |
| `js/platform-control-centre-chart.js` | Created |

---

## How to Test

1. **Ensure `myeventlane_admin_dashboard` is enabled** (disable `mel_admin_dashboard` first if both exist):
   ```bash
   ddev drush pm:uninstall mel_admin_dashboard -y
   ddev drush pm:enable myeventlane_admin_dashboard -y
   ```

2. **Create ledger table if missing:**
   ```bash
   ddev drush sqlq "CREATE TABLE IF NOT EXISTS myeventlane_payout_ledger (
     id INT UNSIGNED NOT NULL AUTO_INCREMENT,
     order_id INT UNSIGNED NOT NULL,
     store_id INT UNSIGNED NOT NULL,
     status VARCHAR(16) NOT NULL DEFAULT 'unpaid',
     transfer_id VARCHAR(128) NULL,
     paid_at INT UNSIGNED NULL,
     created INT UNSIGNED NOT NULL,
     PRIMARY KEY (id),
     UNIQUE KEY order_id (order_id),
     INDEX store_id (store_id),
     INDEX status (status),
     INDEX created (created)
   )"
   ```

3. **Ensure config exists:**
   ```bash
   ddev drush config:set myeventlane_admin_dashboard.settings commission_rate 0.10 -y
   ```

4. **Clear cache:**
   ```bash
   ddev drush cr
   ```

5. **Visit `/admin/myeventlane`** as user with `access admin dashboard`.

6. **Check:**
   - [ ] KPI cards show orders, gross, commission, net, escalations, refunds
   - [ ] Chart renders (no console errors)
   - [ ] Vendor ranking table (or empty state)
   - [ ] Payout liability summary
   - [ ] “Export CSV” link
   - [ ] CSV at `/admin/myeventlane/export/financial.csv` downloads and matches screen data

---

## Rollback Plan

1. **Restore previous dashboard:**
   ```bash
   ddev drush pm:uninstall myeventlane_admin_dashboard -y
   ddev drush pm:enable mel_admin_dashboard -y
   ddev drush cr
   ```

2. **Optionally drop ledger table:**
   ```bash
   ddev drush sqlq "DROP TABLE IF EXISTS myeventlane_payout_ledger"
   ```

3. **Git:**
   ```bash
   git checkout -- web/modules/custom/myeventlane_admin_dashboard
   git checkout -- docs/PLATFORM_CONTROL_CENTRE_UPGRADE_DELIVERABLES.md
   ```

---

## Notes

- **PlatformSummaryReader:** `hasData()` can throw in some environments; `PlatformMetricsService` catches and falls back to Commerce queries.
- **addExpression:** Returns the alias string, not `$this`; do not chain `->addExpression()->condition()`.
- **Commission:** Uses `platform_daily_summary.platform_fees` when available; otherwise computed as `gross * commission_rate`.
