# MyEventLane v2 – Platform Control Centre (Admin Dashboard Redesign)

**Design Document**  
**Version:** 1.0  
**Date:** 2026-02-14  
**Audience:** Senior Drupal architects, product owners, engineering leads

---

## 1. Dashboard Philosophy

### What This Dashboard IS

- **Executive at-a-glance:** A single view to answer “Is the platform healthy?”
- **Decision trigger:** Surfaces items that need action (escalations, revenue risk, SLA).
- **Navigation hub:** Entry point to operational tools and detailed reports.
- **Lightweight:** Fast to load, cacheable, low database pressure.

### What It Is NOT

- **Not a reporting tool:** No drill-down analytics, no exports, no trend analysis.
- **Not a data warehouse:** No raw Commerce/order scanning per request.
- **Not a replacement for Views:** It does not replicate Views; it defers to `/admin/myeventlane/reports`.
- **Not a vendor dashboard:** Vendors have `/vendor/*`; this is admin-only.

### Who It Serves

- **Platform operators:** Staff who monitor health and triage.
- **Escalation managers:** Need to see open/urgent escalations.
- **Finance observers:** Need high-level revenue signal.
- **Executives:** Need quick “green/amber/red” status.

### Decisions It Supports

1. “Do I need to act on escalations now?”
2. “Is revenue on track?”
3. “Are there operational anomalies?”
4. “Where do I go for the full picture?”

---

## 2. Information Architecture

### Page Layout (Progressive Disclosure)

```
┌─────────────────────────────────────────────────────────────────┐
│ ROW 1: Page header                                              │
│   Platform Control Centre                                       │
│   Last updated: [time]  |  [Reports →] link                     │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ ROW 2: KPI tiles (max 4)                                        │
│ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐│
│ │ Revenue 30d │ │ Orders 30d  │ │ Open Escal. │ │ SLA at Risk ││
│ │ $XXX,XXX    │ │ X,XXX       │ │ XX          │ │ X           ││
│ │ [Reports →] │ │ [Reports →] │ │ [Manage →]  │ │ [View →]    ││
│ └─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘│
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ ROW 3: Operational alerts (max 4)                               │
│   • Urgent escalations (3) – [View]                              │
│   • SLA breach risk (2) – [View]                                │
│   • [Other high-priority alerts only]                            │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ ROW 4: Trend snapshot (max 1)                                   │
│   Revenue last 6 months – bar or sparkline                      │
│   [View full report →]                                          │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ ROW 5: Recent activity tables (max 2, 5 rows each)               │
│   Recent escalations (5)    |    Recent orders (5)               │
│   [Manage All →]            |    [Reports →]                    │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ ROW 6: Quick actions (sidebar or footer)                         │
│   Manage Events | Vendors | Escalations | Reports                │
└─────────────────────────────────────────────────────────────────┘
```

### Section Hierarchy

1. **Primary:** KPI tiles + alerts (above the fold)
2. **Secondary:** Trend snapshot + recent activity
3. **Tertiary:** Quick actions / navigation

### Constraints

- **Max 4 KPI tiles**
- **Max 4 operational alerts**
- **Max 1 trend snapshot**
- **Max 5 rows** per preview table
- **Clear “View all” / “Reports”** links for every section

---

## 3. KPI Selection Logic

### Metrics That STAY (Dashboard)

| Metric | Time Range | Why |
|--------|------------|-----|
| Revenue (30d) | Last 30 days | Primary health signal; cached aggregate |
| Orders (30d) | Last 30 days | Volume indicator; cached aggregate |
| Open escalations | Current | Operational need; count-only query |
| SLA at risk | Current | Operational need; pre-aggregated or lightweight |

### Metrics That MOVE to Reports (`/admin/myeventlane/reports`)

| Metric | Destination | Why |
|--------|-------------|-----|
| Total revenue (all-time) | Finance report | Heavy; not needed daily |
| Platform fees, net revenue | Finance report | Derived; drill-down only |
| Events by status, orders by status | Events / Finance | Detailed breakdown |
| Revenue by month (6mo) | Finance report | Trend; full chart there |
| Top vendors, top events | Vendor / Event reports | Heavy joins |
| Event breakdown, vendor breakdown | Event / Vendor reports | Very heavy |
| Customer activity, top customers | Finance / custom report | Heavy order iteration |
| Tickets sold, RSVPs, attendees | Event / Vendor reports | Aggregate per report |
| Donation totals | Donations report | Module-specific |

### Metrics That Are REMOVED

| Metric | Why |
|--------|-----|
| Sidebar duplicate totals | Redundant with main KPIs |
| Unpublished events count | Low value; available in content list |
| Orders by non-completed status | Move to reports; not executive-level |
| Platform vs RSVP donations split | Report-level detail |

### Default Time Range

- **30 days** for revenue and orders (dashboard)
- Configurable in reports; dashboard stays fixed for simplicity

---

## 4. Drupal Architecture Design

### Module Structure

**Option A:** Enhance `myeventlane_admin_dashboard`  
- Add services, keep single module.  
- Route stays `/admin/myeventlane`.

**Option B:** New submodule `myeventlane_admin_dashboard_control`  
- Isolates new logic from legacy.  
- Can deprecate old controller gradually.

**Recommendation:** Option A with clear internal separation. Avoid module proliferation.

### Routing

```yaml
# myeventlane_admin_dashboard.routing.yml
myeventlane_admin_dashboard.overview:
  path: '/admin/myeventlane'
  defaults:
    _controller: '\Drupal\myeventlane_admin_dashboard\Controller\PlatformControlCentreController::overview'
    _title: 'Platform Control Centre'
  requirements:
    _permission: 'access admin dashboard'
  options:
    _admin_route: TRUE
```

Route path **unchanged** (`/admin/myeventlane`) to avoid redirects and bookmarks.

### Controller Structure

```php
// PlatformControlCentreController
// - Thin controller: delegates to services
// - Returns render array with placeholders for lazy builders
// - No direct entity queries in controller
```

Controller responsibilities:

1. Build page skeleton
2. Attach libraries
3. Assign cache metadata
4. Delegate data to services / lazy builders

### Service Structure

| Service | Responsibility |
|---------|----------------|
| `PlatformKpiService` | Returns 4 KPIs from summary storage or cache |
| `PlatformAlertService` | Returns up to 4 operational alerts (escalations, SLA) |
| `PlatformTrendService` | Returns revenue trend snapshot (6 months) |
| `PlatformRecentActivityService` | Returns recent escalations + recent orders (5 each) |

Services **must not** load `commerce_order` or `commerce_order_item` on dashboard request. They read from:

- Summary tables (recommended), or
- Cache bins (e.g. `cache.default` or a custom cache bin)

### Lazy Builder Strategy

Use `#lazy_builder` for sections that can be deferred:

```php
'#lazy_builder' => [
  '\Drupal\myeventlane_admin_dashboard\DashboardRenderer::renderKpiTiles',
  [],
],
'#create_placeholder' => TRUE,
```

Each lazy builder:

- Has its own cache context/tags
- Can use different max-age
- Fails gracefully (returns empty) if data unavailable

**BigPipe:** Use `#create_placeholder` + `#lazy_builder` so above-the-fold content renders first; KPIs and tables stream in.

### Cache Strategy

| Section | Contexts | Tags | Max-Age |
|---------|----------|------|---------|
| KPI tiles | `user.permissions` | `platform:kpis` | 300 (5 min) |
| Alerts | `user.permissions` | `escalation_list`, `escalation:{id}` | 60 |
| Trend snapshot | `user.permissions` | `platform:trend` | 3600 |
| Recent activity | `user.permissions` | `escalation_list`, `commerce_order_list` | 120 |

**No** `node_list`, `user_list` on dashboard. Invalidation via custom tags (e.g. `platform:kpis`) from cron or post-order hooks.

### Performance Safeguards

1. **Query guards:** Services throw or log if asked to run uncached heavy queries.
2. **Timeouts:** Lazy builders use short time limits (e.g. 2s) and return placeholder on timeout.
3. **Fallback:** If summary/cache empty, show “Data updating…” instead of running live queries.

---

## 5. Performance Plan

### Preventing Heavy Commerce Joins

**Current problem (from codebase analysis):**

- `getPlatformMetrics()`: Loads **all** completed orders.
- `getTopEvents()`: `loadByProperties` on `commerce_order_item` for all events.
- `getEventBreakdown()`: For each of 20 events, loads order items + orders.
- `getVendorBreakdown()`: For each vendor, loads order items across all vendor events.

At 1M+ orders this becomes **unacceptable**.

### Aggregation Strategy

**Summary table (recommended):**

```sql
-- Concept; actual schema TBD
CREATE TABLE platform_daily_summary (
  date DATE PRIMARY KEY,
  revenue DECIMAL(12,2),
  orders_count INT,
  tickets_sold INT,
  -- etc.
);
```

**Populated by cron** (e.g. hourly or daily):

- Sum completed orders by date
- No per-request order scans
- Dashboard reads from summary only

### Cron Aggregation

- **Frequency:** Hourly minimum; daily acceptable for 30d KPIs.
- **Job:** `PlatformSummaryAggregator` (or similar)
- **Scope:** Last 90 days (buffer for trends)
- **Idempotent:** Overwrites by date

### Per-Metric Caching

| Metric | Source | Cache |
|--------|--------|-------|
| Revenue 30d | Summary table | 5 min |
| Orders 30d | Summary table | 5 min |
| Open escalations | Count query (indexed) | 1 min |
| SLA at risk | Count query or pre-aggregate | 1 min |
| Trend (6mo) | Summary table | 1 hour |
| Recent escalations | Simple query, `range(0,5)` | 2 min |
| Recent orders | Simple query, `range(0,5)` | 2 min |

**Escalation queries:** Single `count()` or `getQuery()->range(0,5)` with indexed `status`/`priority`/`created`. No joins to Commerce.

---

## 6. UX / UI Structure

### Grid Layout

- **Desktop:** 4-column grid for KPI tiles; 2-column for tables.
- **Tablet:** 2-column KPIs; stacked tables.
- **Mobile:** Single column; KPIs scroll horizontally or stack.

### Visual Hierarchy

1. **Level 1:** Page title (H1)
2. **Level 2:** Section titles (H2) – “Platform health”, “Operational alerts”
3. **Level 3:** Card titles, table captions

### Color System

- **Green:** Healthy, on track
- **Amber:** Warning, attention
- **Red:** Urgent, breach risk
- **Neutral:** Informational (e.g. counts)

Use existing MEL admin theme tokens; no new palette.

### Status Indicators

- **Badge:** Small pill for status (e.g. “New”, “Urgent”)
- **Icon:** Optional for alert severity
- **No decorative icons** – only functional

### Mobile-First

- Touch targets ≥ 44px
- Tables: horizontal scroll or card layout on small screens
- Alerts always visible; no hidden critical info

### WCAG Considerations

- Sufficient colour contrast (4.5:1 text, 3:1 large)
- `aria-label` on sections
- Focus order matches visual order
- Status communicated by more than colour (text + icon)

### Scroll Reduction

- Above-the-fold: KPIs + alerts
- Trend + recent activity in one viewport where possible
- No long tables; “View all” for details

---

## 7. Migration Strategy

### Transition Plan

1. **Phase 1:** Implement new controller + template alongside old.
   - Route `/admin/myeventlane` unchanged.
   - Feature flag or config toggle: `platform_control_centre_enabled`.
   - Default: `FALSE` (old dashboard).

2. **Phase 2:** Implement summary aggregation (cron).
   - Deploy aggregation job.
   - Populate summary table for last 90 days.
   - Validate metrics against old controller (staging).

3. **Phase 3:** Switch to new dashboard.
   - Set `platform_control_centre_enabled` = TRUE.
   - Monitor performance and errors.
   - Keep old controller code for rollback.

4. **Phase 4:** Deprecate old logic.
   - Remove old controller methods.
   - Remove unused template sections.
   - Update “Reports” links to `/admin/myeventlane/reports`.

### Views Deprecation

- **Current dashboard:** No Views used; custom controller + template.
- **No Views to deprecate** for the main overview.
- Reporting section (`/admin/myeventlane/reports`) may use Views; leave as-is.

### Route

- **Same route:** `/admin/myeventlane`
- **No redirects** required.

### Git Branch Plan

- **Branch:** `feature/platform-control-centre`
- **Commits:** Separate for aggregation, controller, template, config.
- **Tag:** After staging verification.

### Risk Mitigation

- Feature flag allows instant rollback.
- Summary table backfill script for disaster recovery.
- Logging when aggregation fails or cache is empty.

---

## 8. Technical Debt Warnings

### 1. Views Overuse

**Status:** Current dashboard does **not** use Views.  
**Warning:** If future widgets use Views with Commerce relationships, enforce: (a) Views query caching, (b) reasonable `range()`, (c) no N+1. Prefer custom queries or summary data.

### 2. Uncached Queries

**Current:** Controller runs many uncached entity queries per request.  
**Risk:** At 1M orders, `loadMultiple($order_ids)` for all completed orders will fail.  
**Action:** Remove all direct Commerce order/order_item access from dashboard path. Use summary or cache only.

### 3. Commerce Order Joins

**Current:** `getOrderFromItem()` + `loadByProperties(['field_target_event' => ...])` create large joins.  
**Risk:** Order items × events × orders = explosive growth.  
**Action:** Never join Commerce tables on dashboard request. Aggregate in cron.

### 4. Escalation Data Cost

**Current:** Multiple count queries per status/priority; ES small now.  
**Risk:** At 100k+ escalations, unindexed counts will slow.  
**Action:** Ensure indexes on `status`, `priority`, `created`. Consider daily summary for “open” counts.

### 5. Aggregation Must Move to Summary Storage

**Critical:** Without a summary table (or equivalent pre-aggregation), the dashboard **cannot** scale to 1M+ orders.  
**Action:** Implement `platform_daily_summary` (or equivalent) and cron aggregation before launch.

---

## 9. Final Recommendation

**Chosen approach: Minimal executive dashboard**

### Justification

1. **Tabbed dashboard** adds navigation and complexity without solving the core problem (too much data, heavy queries). Tabs do not reduce load.

2. **Widget-based dashboard** implies many independent data sources and more moving parts. Increases maintenance and failure modes. Overkill for 4 KPIs + 4 alerts + 2 tables.

3. **Minimal executive dashboard** matches the philosophy: one page, essential only, fast.

### Characteristics

- Single full-width layout
- 4 KPIs, 4 alerts, 1 trend, 2 mini-tables (5 rows each)
- All heavy data deferred to `/admin/myeventlane/reports`
- Summary-table-backed or cache-backed metrics
- Lazy builders + BigPipe for perceived performance
- Feature flag for safe rollout

### Success Criteria

- Dashboard loads in < 2 seconds (P95) with cold cache
- No `commerce_order` or `commerce_order_item` queries on dashboard path
- Above-the-fold content visible in < 1 second
- Clear path to Reports for detailed analysis

---

## Appendix A: Current Dashboard Pain Points (from Code Analysis)

| Method | Problem |
|--------|---------|
| `getPlatformMetrics()` | Loads ALL completed orders |
| `getRecentTransactions()` | Loads 10 orders + items + nodes |
| `getTopEvents()` | loadByProperties order_item for all events |
| `getSidebarMetrics()` | Loads ALL order items for tickets_sold |
| `getDetailedAnalytics()` | Loads ALL orders for status + revenue by month |
| `getVendorActivity()` | Loads ALL vendors + event counts |
| `getEventBreakdown()` | 20 events × (order items + orders + RSVP + attendee + donation) |
| `getVendorBreakdown()` | All vendors × (all events × order items) |
| `getCustomerActivity()` | Loads ALL completed orders, iterates customers |

---

## Appendix B: Routes Reference

| Path | Purpose |
|------|---------|
| `/admin/myeventlane` | Platform Control Centre (this design) |
| `/admin/myeventlane/reports` | Platform Reports overview |
| `/admin/myeventlane/reports/vendors` | Vendor reports |
| `/admin/myeventlane/reports/events` | Event reports |
| `/admin/myeventlane/reports/finance` | Finance reports |
| `/admin/myeventlane/revenue` | Admin revenue dashboard (consider merge with reports) |
| `/admin/myeventlane/escalations` | Manage escalations |
| `/admin/myeventlane/support-console` | Support console |

---

*Document complete. No implementation performed; architecture and design only.*
