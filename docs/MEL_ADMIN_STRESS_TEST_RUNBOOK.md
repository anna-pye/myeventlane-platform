# MEL Admin Module Stress Test Runbook

**MyEventLane Studio** · DDEV + Staging · Platform Control Centre  
**Last updated:** 2025-02-16

---

## 1. Scope

This runbook validates the **myeventlane_admin_dashboard** Platform Control Centre (`/admin/myeventlane`) under load for:

- N+1 query risks  
- Cache tag correctness  
- Failure modes and graceful degradation  
- PII leakage

**Services analyzed:**

| Service | File | Purpose |
|---------|------|---------|
| `DashboardRenderer` | `DashboardRenderer.php` | Lazy builders for 4 sections |
| `PlatformKpiService` | `PlatformKpiService.php` | 4 KPI tiles (summary + escalation count) |
| `PlatformAlertService` | `PlatformAlertService.php` | Up to 4 operational alerts |
| `PlatformTrendService` | `PlatformTrendService.php` | 6-month revenue trend |
| `PlatformRecentActivityService` | `PlatformRecentActivityService.php` | 5 escalations + 5 orders |

---

## 2. Risk Summary (from service analysis)

### 2.1 N+1 risks

| Location | Risk | Severity |
|----------|------|----------|
| `PlatformRecentActivityService::getRecentEscalations()` | ~~Per-row `users_field_data` lookup~~ | **FIXED** |

**Status:** Single query; no user data fetched; PII removed.

### 2.2 Cache tag correctness

| Tag | Source | Invalidated? | Risk |
|-----|--------|--------------|------|
| `platform:summary` | KPI, Trend | **Yes** – `PlatformSummaryAggregator` L89 | **FIXED** |
| `escalation_list` | Alerts, Recent Activity | **Yes** – `PlatformSummaryAggregator` L90 | **FIXED** |
| `commerce_order_list` | Recent Activity (orders) | **Yes** – `PlatformSummaryAggregator` L91 | **FIXED** |

**Status:** All three tags invalidated in `PlatformSummaryAggregator::aggregate()` after successful aggregation (L88-92), via injected `CacheTagsInvalidatorInterface`.

### 2.3 Failure modes

| Condition | Behaviour | PASS criterion |
|-----------|----------|----------------|
| `platform_daily_summary` missing | KPI/Trend return placeholders; logged | No fatal, placeholders shown |
| `escalation` table/entity missing | Alerts/Recent Activity return []; logged | No fatal, empty sections |
| `platform_recent_orders` cache empty | Orders section empty; escalations still shown | No fatal |
| `commerce_order` table missing | Aggregator skips; summary/orders empty | No fatal |
| Summary has no rows | KPI/Trend placeholders; Trend `empty: true` | No fatal |

### 2.4 PII leakage

| Data | Status |
|------|--------|
| `users_field_data.name` / `customer` | **FIXED** — no longer fetched or returned |

**Status:** FIXED — `customer` (username) removed from service output; no PII in escalation rows.

---

## 3. Prerequisites

- DDEV: `ddev start` (or staging equivalent)
- User with `access admin dashboard` permission
- `platform_daily_summary` table: created by `myeventlane_summary_update_10001` (run `ddev drush updb -y` if table missing)
- Cron run at least once (for `platform_daily_summary` and `platform_recent_orders`)

### 3.1 Ensure platform_daily_summary exists

```bash
ddev drush sqlq "SHOW TABLES LIKE 'platform_daily_summary';"
# If empty, run:
ddev drush updb -y
```

---

## 4. DDEV Commands

### 4.1 Setup

```bash
# 1. Start DDEV
ddev start

# 2. Run cron (populate summary + recent orders cache)
ddev drush cron

# 3. Clear cache for cold-cache tests
ddev drush cr
```

### 4.2 Base URL (DDEV)

- Admin: `https://myeventlane.ddev.site/admin/myeventlane`  
- Or: `https://admin.myeventlane.ddev.site/admin/myeventlane`

### 4.3 Staging base URL

Replace with your staging URL, e.g. `https://staging.myeventlane.example/admin/myeventlane`.

---

## 5. Test Procedures

### 5.1 Cold-cache load (PASS/FAIL)

**Purpose:** Verify no heavy queries on first load; BigPipe + lazy builders used.

```bash
# 1. Clear caches
ddev drush cr

# 2. Generate one-time login link (copy the URL from output)
ddev drush uli --uid=1

# 3. Visit the URL in browser to establish session, then:
#    Either use browser DevTools Network tab to time the request,
#    Or use curl with your session cookie after logging in:
curl -s -o /dev/null -w "HTTP %{http_code} Time %{time_total}s\n" \
  -b "SESS_COOKIE=YOUR_SESSION_ID" \
  "https://myeventlane.ddev.site/admin/myeventlane"
```

**Staging:** Replace `myeventlane.ddev.site` with staging host. Use a logged-in session cookie.

**PASS:** HTTP 200; total time &lt; 5s (typical &lt; 2s).  
**FAIL:** 5xx, or time &gt; 10s.

**Manual (recommended):**

1. `ddev drush cr`
2. `ddev drush uli --uid=1` → open link in browser
3. Navigate to `/admin/myeventlane`
4. **PASS:** Page loads with KPI tiles, alerts (or empty), trend, recent activity
5. **FAIL:** 5xx, blank page, or spinner never resolves

---

### 5.2 Query count (N+1 check)

**Purpose:** Detect N+1 in escalations (user lookups).

```bash
# Enable DB logging (Drupal Devel or custom)
ddev drush config:set system.logging error_level verbose -y 2>/dev/null || true

# Run with Devel's query log (if devel installed)
# ddev drush ev "
#   \$n = count(\Drupal::database()->getLog());
#   echo \$n;
# "

# Alternative: Use drush watchdog to confirm no repeated user lookups
ddev drush watchdog:show --count=20 2>/dev/null
```

**Manual N+1 check:**

1. Enable query logging (e.g. Devel `db_query` + UI, or `hook_query_alter` + log)
2. Load `/admin/myeventlane` once (cold cache)
3. Count queries for `users_field_data` / `user`
4. **PASS:** 0–1 such queries (none if no escalations with `user_id`)  
5. **FAIL:** 2–5 such queries for 5 escalations (N+1)

---

### 5.3 Cache tags on response

**Purpose:** Confirm correct cache tags for invalidation.

```bash
# Fetch page with cache debug header (if enabled)
curl -sI -b "SESS_COOKIE_HERE" \
  "https://myeventlane.ddev.site/admin/myeventlane" \
  | grep -i x-drupal-cache-tags
```

**PASS:** Header includes `platform:summary`, `escalation_list`, `commerce_order_list`.  
**FAIL:** Missing tags, or page not cacheable.

---

### 5.4 Failure-mode resilience

**5.4.1 Missing summary table**

```bash
# Simulate: rename table (DDEV only, revert after)
ddev mysql -e "RENAME TABLE platform_daily_summary TO platform_daily_summary_bak;" 2>/dev/null || true

# Load dashboard
curl -s -o /dev/null -w "%{http_code}\n" -b "SESS_COOKIE" "https://myeventlane.ddev.site/admin/myeventlane"

# Restore
ddev mysql -e "RENAME TABLE platform_daily_summary_bak TO platform_daily_summary;" 2>/dev/null || true
```

**PASS:** HTTP 200; KPI shows "—" placeholders.  
**FAIL:** 5xx or fatal.

**5.4.2 Empty recent orders cache**

```bash
# Invalidate platform_recent_orders (or delete)
ddev drush ev "\Drupal::cache()->delete('platform_recent_orders');"

# Load dashboard
curl -s -o /dev/null -w "%{http_code}\n" -b "SESS_COOKIE" "https://myeventlane.ddev.site/admin/myeventlane"
```

**PASS:** HTTP 200; "Recent orders" shows "No recent activity".  
**FAIL:** 5xx or fatal.

---

### 5.5 Concurrency (staging)

**Purpose:** Basic load under concurrent requests.

```bash
# 10 concurrent requests (staging URL)
for i in $(seq 1 10); do
  curl -s -o /dev/null -w "%{http_code} %{time_total}\n" \
    -b "SESS_COOKIE" \
    "https://STAGING_HOST/admin/myeventlane" &
done
wait
```

**PASS:** All HTTP 200; no 5xx; no extreme outliers (e.g. &lt; 5s).  
**FAIL:** Any 5xx, or consistent time &gt; 5s.

---

### 5.6 PII check (content inspection)

**Purpose:** Confirm usernames are not rendered where they should not be.

```bash
# Fetch full HTML
curl -s -b "SESS_COOKIE" "https://myeventlane.ddev.site/admin/myeventlane" \
  | grep -E "customer|username|user\.name" || echo "No obvious PII strings found"
```

**PASS:** No user identifiers in HTML, or only in admin-intended regions.  
**FAIL:** Customer email/name in unexpected client-facing output.

---

### 5.7 Post-cron cache invalidation (platform:summary)

**Purpose:** Verify platform summary caches refresh after cron.

```bash
# 1. Load dashboard (populate cache)
curl -s -b "SESS_COOKIE" "https://myeventlane.ddev.site/admin/myeventlane" > /dev/null

# 2. Modify summary directly (simulate new aggregation)
ddev mysql -e "UPDATE platform_daily_summary SET revenue_gross = 999999 WHERE date = CURDATE();"

# 3. Run cron (aggregator invalidates platform:summary, escalation_list, commerce_order_list)
ddev drush cron

# 4. Load dashboard again
curl -s -b "SESS_COOKIE" "https://myeventlane.ddev.site/admin/myeventlane" | grep -o '\$[0-9,]*\.[0-9]*' | head -1
```

**Expected today:** KPI cache invalidated on cron; fresh revenue shown on next load.  
**PASS (current design):** Page loads; revenue updates within 5 minutes.  
**FAIL:** 5xx or fatal after cron.

---

## 6. PASS/FAIL Summary

| Test | PASS | FAIL |
|------|------|------|
| 5.1 Cold-cache load | HTTP 200, &lt; 5s | 5xx or &gt; 10s |
| 5.2 Query count (N+1) | 0–1 `users` queries | 2+ per escalation row |
| 5.3 Cache tags | Tags present in response | Missing tags |
| 5.4.1 Missing summary | 200, placeholders | 5xx/fatal |
| 5.4.2 Empty orders cache | 200, empty tables | 5xx/fatal |
| 5.5 Concurrency | All 200, &lt; 5s | Any 5xx or slow |
| 5.6 PII | No unexpected PII in HTML | PII in response |
| 5.7 Post-cron | Page loads after cron | 5xx/fatal |

---

## 7. Recommended Fixes (post-runbook)

1. **N+1:** ~~Replace per-row user lookup~~ FIXED — user lookup removed; customer (PII) removed from output.
2. **Cache tag:** ~~Add `platform:summary` invalidation~~ DONE. All three tags (`platform:summary`, `escalation_list`, `commerce_order_list`) invalidated in `PlatformSummaryAggregator::aggregate()`.
3. **PII:** ~~Remove `customer`~~ DONE — no longer fetched or returned.

---

## 8. Quick reference

### Routes

| Path | Route |
|------|-------|
| `/admin/myeventlane` | `myeventlane_admin_dashboard.overview` |

### Cache keys

| Key | Service | TTL |
|-----|---------|-----|
| `platform_control_centre:kpis` | PlatformKpiService | 300s |
| `platform_control_centre:alerts` | PlatformAlertService | 60s |
| `platform_control_centre:trend` | PlatformTrendService | 3600s |
| `platform_control_centre:recent_escalations` | PlatformRecentActivityService | 120s |
| `platform_recent_orders` | PlatformSummaryAggregator (cron) | ~120s |

### Database tables

| Table | Purpose |
|-------|---------|
| `platform_daily_summary` | Daily revenue, orders, escalation counts |
| `escalation` | Escalation entities |
| `commerce_order` | Orders |
| `users_field_data` | User names (PII) |
