# Audit — PopularEventsService

**Date:** 2026-06-15
**Branch:** `feature/community-favourites-audit`
**Status:** **Findings only. No code or config changed.** Every claim cites repository evidence.
**Subject:** `web/modules/custom/myeventlane_analytics/src/Service/PopularEventsService.php` (service id `myeventlane_analytics.popular_events`).

---

## 1. Exactly where PopularEventsService is called

| Caller | Location | Method | Notes |
|---|---|---|---|
| `PopularEventsBlock::build()` | `myeventlane_front/src/Plugin/Block/PopularEventsBlock.php:133` | `getPopularEventIds($days, $limit)` | Injected via `create()` line 54 (`@myeventlane_analytics.popular_events`) |
| `TrendingCategoriesService` | `myeventlane_analytics/src/Service/TrendingCategoriesService.php:103` | `getPopularEventIds($days, $eventScanLimit)` | Injected ctor arg (`services.yml:88`) |
| `TrendingCategoriesService` | `TrendingCategoriesService.php:216` | `getPopularEventRows($days)` | Uncapped rows for category aggregation |

**Service registration:** `myeventlane_analytics.services.yml:74–80` — args `@database`, `@datetime.time`, `@logger.factory`, `@myeventlane_analytics.order_item_classifier`.

> **Not a caller:** `myeventlane_core/src/Service/HomepagePopularityService.php:71` matches only because it *defines its own* method also named `getPopularEventIds()`. It is a **separate, orphaned** duplicate service (no callers) — see `community-favourites-audit.md §2`.

**Total real callers: 2** — one render block (`PopularEventsBlock`) and one backend aggregator (`TrendingCategoriesService`).

---

## 2. Which homepage blocks currently consume it

| Block | Plugin id | Consumes | Placed? |
|---|---|---|---|
| `PopularEventsBlock` | `myeventlane_popular_events_block` | `PopularEventsService` directly (renders event cards) | **NO** — not in any `block.block.*.yml`, and no code/layout placement (`rg` confirmed) |
| `TrendingCategoriesBlock` | (myeventlane_front) | `PopularEventsService` **indirectly** via `TrendingCategoriesService` (renders *categories*, not events) | **NO** — not placed |
| `TrendingInCategoryBlock` | (myeventlane_front) | indirectly via `TrendingCategoriesService` | **NO** — not placed |

**Finding: ZERO placed homepage blocks currently consume PopularEventsService.** Community popularity is computed but **never surfaced** on the homepage today. The only event-rendering consumer (`PopularEventsBlock`) is **defined but unplaced**.

---

## 3. Which event IDs it returns

`getPopularEventIds(int $days = 7, int $limit = 8)` → top-`$limit` ranked rows; `getPopularEventRows(int $days = 7)` → full uncapped ranked list.

Each row (`PopularEventsService.php:76, 141–148`):
```
{ nid, score, tickets_sold, rsvps, going, is_past }
```

- **nids** = `event`-bundle, **published** nodes that had ≥1 paid ticket OR ≥1 RSVP within the lookback window (default 7 days).
- **score** = `tickets_sold × 3 + rsvps × 1` (line 135, "locked by spec").
- **going** = `tickets_sold + rsvps`.
- **Sort** (lines 156–167): upcoming-first (`is_past` last), then `score DESC`, then `going DESC`, then `nid DESC` (deterministic).
- **Sources:** tickets from Commerce `commerce_order_item__field_target_event` joined to orders (lines 217–250); RSVPs from `rsvp_submission` field tables, schema-introspected (lines 273–336).

---

## 4. Exclusions

| Candidate | Excluded? | Evidence |
|---|---|---|
| **Draft / unpublished events** | ✅ **YES** | Both source queries `innerJoin node_field_data … n.status = 1` (lines 224, 306) |
| **Boosted events** | ❌ **NO** (events not excluded) | Only **boost order *items*** are removed from ticket scoring via `OrderItemClassifier::getExcludedTypes()` = `['boost','checkout_donation','platform_donation','rsvp_donation']` (`OrderItemClassifier.php:46–51`; applied at `PopularEventsService.php:229`). A boosted event with genuine sales/RSVPs **still appears**, ranked on real engagement. |
| **Cancelled events** | ❌ **NO** (event-state not checked) | Only **cancelled *orders*** are excluded (`o.state <> 'canceled'`, line 239, if column exists). There is **no `field_event_state` filter** anywhere in the service. |
| **Archived events** | ❌ **NO** | No `field_event_state` / archival filter in the service. |
| **(also) Past events** | ❌ not hidden | `is_past` only **deprioritises** at sort time (lines 137–139, 157); "do not hide past events" (header line 26). |
| **(also) Carts / draft orders** | ✅ excluded | `o.cart = 0` (line 233, if column exists) |
| **(also) Boost/donation spend** | ✅ excluded from scoring | `oi.type NOT IN EXCLUDED_TYPES` (line 229) |

> **Critical gap for a public rail:** the service filters **publication status** but **not event lifecycle state**. Cancelled, archived, or past events with recent engagement **can be returned**. Any Community Favourites rail built directly on this output must add an event-state/visibility guard (see §6).

---

## 5. Does output pass through the discovery pipelines?

| Pipeline | Applies? | Evidence |
|---|---|---|
| **PublicEventDiscoveryQueryAlter** | ❌ **NO** | It is a **Views** `hook_views_query_alter` over allow-listed *Views displays*. `PopularEventsService` runs **direct `$this->database->select(...)`** queries, not Views — so none of the canonical public discovery filters (state, visibility, dedupe) apply. |
| **HomepageMerchandising** | ❌ **NO** | `PopularEventsBlock::build()` never calls it; no hero/spotlight cross-rail dedup. (`rg` over block + analytics: none.) |
| **HomepageRailDiversityFilter** | ❌ **NO** | Never invoked by the block; no category/venue/organiser anti-dominance applied. |

**Consequence:** the engine output is rendered **raw**. No cross-rail dedup (an event may appear in Featured/Tonight *and* this rail), no diversity capping, and **no state/visibility filtering beyond `status=1` + `type=event`**.

---

## 6. Smallest implementation path — Community Favourites homepage rail (existing architecture only)

**Smallest path = place the existing `PopularEventsBlock`** (it already consumes the engine and renders the canonical `compact_commerce` card). No new service, View, table, or engine is required.

### Minimal steps (config + copy only)
1. Place block `myeventlane_popular_events_block` into a homepage region (per `theme-architecture.md` region model), via block placement config.
2. Set block title → **"Community Favourites"** (existing `title` config field, `PopularEventsBlock.php:74–79`).
3. Position after Hidden Gems / before "Latest" (per `community-favourites-audit.md §4`).

### Mandatory guards before this is *safe* (each reuses existing architecture — no new infra)
Because the block bypasses all three pipelines (§5) and the engine omits lifecycle filtering (§4), the smallest **safe** path must additionally:

| Gap | Existing mechanism to reuse |
|---|---|
| Cancelled / archived / past events can appear | Filter loaded nodes by `field_event_state` / upcoming, mirroring the filters `PublicEventDiscoveryQueryAlter` already applies to discovery Views — applied to the nid set after `getPopularEventIds()` |
| No diversity (organiser/venue/category dominance) | Pass the nid set through **`HomepageRailDiversityFilter`** (already designed for "homepage discovery rails", post-query, no SQL) |
| Cross-rail duplication with Featured/Tonight | Reuse **`HomepageMerchandising`** dedup/cascade set (it already computes "exclude higher-priority sections") |
| Honest badge / attribution | Add a `community_favourites` source to `DiscoveryAttributionSources` (mirrors the Hidden Gems precedent) + engine-backed badge in `EventMerchandisingPresenter` |

> **Recommendation (not implemented):** the truly smallest *correct* path is **place `PopularEventsBlock` + apply the existing `HomepageRailDiversityFilter` and an event-state guard to its nid set**. This stays within existing architecture, adds no duplicate ranking logic, and closes the §4/§5 gaps. A Views-display alternative (so the rail inherits `PublicEventDiscoveryQueryAlter`) is larger because Views cannot natively rank by the engine's score without a custom sort/contextual-nid feed.

---

## Ownership map

| Layer | Owner | Evidence |
|---|---|---|
| Engine | `myeventlane_analytics\PopularEventsService` (`myeventlane_analytics.popular_events`) | service file |
| Excluded-type policy | `myeventlane_analytics\OrderItemClassifier` (`getExcludedTypes()` → boost + donations) | `OrderItemClassifier.php:46–51,97–99` |
| Render block | `myeventlane_front\…\PopularEventsBlock` (`myeventlane_popular_events_block`) — **unplaced** | block file; no block config |
| Indirect consumer | `myeventlane_analytics\TrendingCategoriesService` → `TrendingCategoriesBlock`/`TrendingInCategoryBlock` (unplaced) | service + blocks |
| Orphaned duplicate | `myeventlane_core\HomepagePopularityService` (no callers) | service file |

## Route map

- **No route** is owned by `PopularEventsService` — it is a service consumed by blocks, not a controller/Views route.
- The separate `/events/popular` route (`view.upcoming_events.page_popular`) is **not** wired to this service (ranks by `field_promoted`; see `community-favourites-audit.md §1–2`).

## Service map

```
PopularEventsService (engine)
  ├─ OrderItemClassifier::getExcludedTypes()  → excludes boost + donation order items
  ├─ database (direct SQL: commerce_order_item__field_target_event, rsvp_submission, node__field_event_start)
  └─ consumed by:
       ├─ PopularEventsBlock  (renders event cards)            [UNPLACED]
       └─ TrendingCategoriesService → Trending*Block (categories) [UNPLACED]
NOT connected to: HomepageMerchandising · HomepageRailDiversityFilter · PublicEventDiscoveryQueryAlter
```

## View map

- **None.** `PopularEventsService` does **not** use Views; it issues direct DB queries. (Contrast: `/events/popular` and the Hidden Gems rails are Views-driven and therefore pass through `PublicEventDiscoveryQueryAlter`.)

## Rendering path

```
PopularEventsBlock::build()
  → getPopularEventIds(days=7, limit=8)            // ranked rows
  → extract nids → entityTypeManager.node.loadMultiple(nids)
  → preserve popularity order
  → node view_builder->view(node, 'compact_commerce')   // canonical event card
  → wrap each in .mel-popular-event (+ optional "X going", gated by
      myeventlane_event_should_show_block_going() social-proof mode)
  → container .mel-popular-events-block > .mel-event-grid
```
View-mode fallback to `teaser` is described in config text but **not implemented** in `build()` (it uses the configured view mode as-is) — minor robustness note.

## Cache implications

| Property | Value | Note |
|---|---|---|
| `max-age` | **900s (15 min)** | Engine reads RSVP/order tables that are **not cache-tagged**; TTL is the staleness safety net (block comment lines 234–235) |
| `contexts` | `languages:language_interface` only | No `user`/`url`/permission context → treated as identical for all viewers (fine for public, anon-safe popularity) |
| `tags` | `node_list` + per-node `node:{nid}` (lines 226, 238) | Node edits/creates invalidate; **RSVP and ticket-sale changes do NOT invalidate** (no order/rsvp tags) — only the 900s TTL refreshes ranking |
| Empty result branch | `max-age 900` + language context, **no tags** (lines 135–141, 160–166) | An empty rail won't re-evaluate until TTL even if events gain engagement |

**Implications for a homepage rail:** placing this block caps the homepage block's cache at 15 min and makes popularity eventually-consistent (≤15 min lag). Acceptable for anonymous discovery; no security/permission caching concern (no private data, public `node` access enforced by the view builder per node).

## Validation commands (run for this audit)

```bash
git status -sb                                   # ## feature/community-favourites-audit (clean)
ddev drush cr                                    # success
ddev drush config:status                         # No differences between DB and sync directory
rg -n "PopularEventsService|myeventlane_analytics.popular_events" web/modules/custom   # 2 callers
rg -l "myeventlane_popular_events_block" config/sync     # (no output) → block UNPLACED
rg -n "RailDiversityFilter|HomepageMerchandising|PublicEventDiscoveryQueryAlter" \
   web/modules/custom/myeventlane_front/src/Plugin/Block/PopularEventsBlock.php   # (none) → bypasses pipelines
rg -nA6 "EXCLUDED_TYPES = " web/modules/custom/myeventlane_commerce/src/Service/OrderItemClassifier.php
```

---

## Summary of findings

1. **Called by 2 consumers**: `PopularEventsBlock` (render) and `TrendingCategoriesService` (aggregate). The core `HomepagePopularityService` is an unrelated orphan.
2. **Zero placed homepage blocks consume it** — the engine is live but unsurfaced.
3. **Returns** ranked `{nid,score,tickets_sold,rsvps,going,is_past}` for published events with sales/RSVP engagement (score = sales×3 + rsvp×1).
4. **Excludes drafts** (✅) and boost/donation *spend*; **does NOT exclude** boosted events, cancelled events, archived events, or past events (only deprioritises past).
5. **Bypasses all three pipelines** (Merchandising, RailDiversityFilter, PublicEventDiscoveryQueryAlter) — it is direct-SQL, not Views.
6. **Smallest path** to a Community Favourites rail = **place the existing `PopularEventsBlock`** + apply the existing `HomepageRailDiversityFilter` and an event-state guard to its nid set (no new tables/entities/engines). The block-only placement is smallest but unsafe until §4/§5 gaps are closed with existing services.

**No code or config modified. Findings only.**
