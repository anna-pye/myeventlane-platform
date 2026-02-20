# Phase 6 — Performance Review

**Audit Date:** 2026-02-20  
**Branch:** audit/staging-2026-Q1  
**Source:** system.performance config, theme structure

---

## 1. BigPipe

- **Status:** Not explicitly verified. Drupal 11 enables BigPipe when Page Cache is enabled.
- **Page cache:** system.performance cache.page.max_age = 0 (disabled for development).
- **Classification:** **HIGH** for production — enable page cache (and thus BigPipe) for anonymous users.

---

## 2. Dynamic Page Cache

- **Module:** dynamic_page_cache (core) — enabled in pm:list.
- **Behaviour:** Caches cacheable portions of pages. Works alongside (not replaces) full page cache.
- **Status:** OK when page cache disabled, still provides partial caching.

---

## 3. CSS/JS Aggregation

**system.performance.yml:**
```yaml
css:
  preprocess: false
  gzip: true
js:
  preprocess: false
  gzip: true
```

- **Status:** Aggregation **disabled** (preprocess: false).
- **Classification:** **HIGH** for production — many unaggregated assets increase requests and latency. Enable for staging/production.

---

## 4. Twig Debug

- **Status:** Not found in config. Default Drupal 11 has twig_debug disabled.
- **Recommendation:** Confirm twig_debug and twig_cache are off in production.

---

## 5. Image Styles

- **Status:** Not audited in depth. Focal Point, Image Widget Crop enabled.
- **Recommendation:** Ensure critical image styles exist and are not over-generated.

---

## 6. Render-Blocking Custom JS

- **Theme:** myeventlane_theme uses Vite-built assets (main.*.js, front.*.js).
- **category-pie-chart.html.twig:** Inline Chart.js config with json_encode|raw — may block if loaded synchronously.
- **Recommendation:** Ensure main JS is deferred/async where appropriate.

---

## 7. Unindexed Commerce Queries

- **Status:** Not verified programmatically. Commerce uses entity queries; indexes depend on schema.
- **Recommendation:** Profile event listing, event detail, checkout, and organizer dashboard under load. Check for N+1 or missing indexes.

---

## Test Pages (Recommended)

| Page | Notes |
|------|-------|
| Event listing | Views (front_discover, front_featured, etc.) — check query count |
| Event detail | node/event full — paragraphs, ticket types |
| Checkout | Commerce checkout flow — payment, validation |
| Organizer dashboard | VendorDashboardController — vendor-scoped queries |

---

## Summary

| Item | Status | Severity |
|------|--------|----------|
| BigPipe | Depends on page cache | High (page cache off) |
| Dynamic Page Cache | Enabled | OK |
| CSS/JS aggregation | **Disabled** | **HIGH** |
| Twig debug | Not found (likely off) | OK |
| Image styles | Not audited | Low |
| Render-blocking JS | Possible in pie chart | Medium |
| Commerce queries | Not profiled | Recommend review |
