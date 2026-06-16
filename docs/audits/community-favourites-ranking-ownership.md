# CF-7A — Community Favourites Ranking Ownership Audit

**Date:** 2026-06-16
**Branch:** `feature/community-favourites-signal-consolidation`
**Status:** **AUDIT ONLY — no code/config/Views/services modified.** Builds on `docs/audits/discovery-signal-ownership-map.md` and `docs/audits/brand-rollout/popular-events-service-audit.md`. Every conclusion cites repository evidence.

---

## Executive answer

Community Favourites ranking has a **single owner: `PopularEventsService`**. Every CF surface (homepage rail, `/events/popular` browse, visibility gate, merchandising exclusion) **delegates** to it; none re-implements scoring. `HomepageMerchandisingQueryAlter` is an **ordering bridge, not a second ranker**. Two *other* popularity rankers exist but **do not feed Community Favourites** and are removable as stale code.

---

## Q1 — What actually determines Community Favourites ranking?

**`PopularEventsService` engagement score**, computed in one place:

| Element | Evidence |
|---|---|
| **Score formula** | `score = (tickets_sold × 3) + (rsvps × 1)` — `PopularEventsService.php:152` (spec docblock `:19`) |
| **Lookback** | 7 days (default `DEFAULT_DAYS`), used by all CF callers (below) |
| **Ticket source** | Commerce paid tickets via `commerce_order_item__field_target_event`, `SUM(quantity)`, **excluding** boost + donation order-item types (`OrderItemClassifier` → `boost, checkout_donation, platform_donation, rsvp_donation`), **excluding** cancelled orders (`o.state <> 'canceled'`) and carts (`o.cart = 0`); published `event` nodes only (`n.status = 1`) — `PopularEventsService.php:180–261` |
| **RSVP source** | canonical `rsvp_submission` storage (schema-introspected), published events only — `:273–336` |
| **Sort** | upcoming-first → score DESC → going DESC → nid DESC (deterministic) — `:156–167` |

This single ranked list is what every Community Favourites surface renders.

---

## Q2 — Is PopularEventsService the sole owner?

**Yes, for Community Favourites.** All CF consumers delegate to it (no consumer re-scores):

| Consumer | Call | Purpose |
|---|---|---|
| `PopularEventsBlock` (homepage rail) | `getPopularEventIds($days, $fetchLimit)` — `:165` | renders the homepage CF rail |
| `HomepageMerchandisingQueryAlter` (browse) | `getPopularEventRows($days)` — `:122` | constrains `/events/popular` to CF ranking |
| `HomepageSectionVisibility` (gate) | `getPopularEventIds(7, 1)` — `:80` | hides the rail when no CF results |
| `HomepageMerchandising` (dedup/exclusion) | `getPopularEventIds(7, 24)` — `:416` | cross-rail exclusion sets |
| `TrendingCategoriesService` (category trending) | `getPopularEventIds/Rows` — `:103,216` | category aggregation (not a CF surface) |

> **Sole CF ranking owner confirmed.** No CF surface contains its own scoring; each pulls the ranked list from `PopularEventsService`. The score formula exists in exactly one location (`:152`).

**Caveat (non-CF):** two other popularity-style rankers exist but **do not feed Community Favourites** — see Q6.

---

## Q3 — Does HomepageMerchandisingQueryAlter duplicate ranking logic?

**No.** It is an **ordering bridge** that delegates to `PopularEventsService`:

- Injects the service: `private readonly PopularEventsService $popularEvents` (`HomepageMerchandisingQueryAlter.php:31`).
- Pulls the ranked nids: `$rows = $this->popularEvents->getPopularEventRows($days)` (`:122`, via `getCommunityFavouritesNids(7)` at `:87,117`).
- Re-applies the **engine's** order to the Views query so the rank survives pagination: `nid IN (…)` + `FIELD($alias.nid, <ranked nids>)` (`:94–100`), memoised per request (`:23`).

There is **no independent score, weight, or sort formula** in the QueryAlter — it only translates the engine's ranking into a Views-compatible ordering. **Not duplication.**

---

## Q4 — Are homepage and /events/popular using the same ranking source?

**Yes — identical source and formula.**

| | Homepage CF rail | `/events/popular` (browse CF) |
|---|---|---|
| Ranker | `PopularEventsService` | `PopularEventsService` (via QueryAlter) |
| Method | `getPopularEventIds(7, limit)` | `getPopularEventRows(7)` |
| Lookback | 7 days | 7 days |
| Formula | tickets×3 + rsvp×1 | tickets×3 + rsvp×1 |
| Cap | display `limit` (default 8; block may over-fetch then trim — `:165`) | uncapped; Views pager paginates the full ranked list |

The only difference is **presentation cap** (rail = top N; browse = all, paginated) — **not** the ranking source. Both render the same ordered engine output.

---

## Q5 — Are RSVPs and paid tickets weighted correctly?

**Internally consistent and uniformly applied.** Single formula `tickets_sold × 3 + rsvps × 1` (`:152`, "locked by spec" `:15`):

- Both signals counted once; **paid tickets weighted 3× a free RSVP** (paid intent > RSVP). Same weights on every CF surface (single source).
- Exclusions are correct for "community engagement, not paid placement": boost + donation order items excluded, cancelled orders excluded, carts excluded, unpublished excluded.

**Gap (filtering, not weighting) — cross-referenced, not new:** the engine filters **publication status** but **not event lifecycle state** — cancelled/archived events with recent engagement, and past events, are not removed by the engine (`popular-events-service-audit.md §4`). This affects *which events qualify*, not the weight ratio. Whether the 3:1 ratio is the desired product value is a **product decision** (the formula is spec-locked); no evidence of a second/competing weighting exists.

---

## Q6 — Is there stale ranking code that can be removed?

**Yes — two orphaned popularity rankers, neither feeding Community Favourites:**

| Stale item | Why removable | Evidence |
|---|---|---|
| `myeventlane_core\HomepagePopularityService` | Duplicate popularity logic (`rsvp + tickets`, excludes boosted); **no callers** | own `getPopularEventIds()` at `:79`; `rg` shows no consumers (still orphaned) |
| `myeventlane_analytics\TrendingScoreService` | Score (`recent RSVPs×2 + boost bonus`) consumed only by an **unrouted** controller | `TrendingEventsController` has **no route** in `myeventlane_analytics.routing.yml` |
| `TrendingEventsController` + `myeventlane-trending-events.html.twig` | Controller unreachable (no route); template serves it | routing.yml has no mapping |

**Independence check:** `TrendingCategoriesService` (live) depends on **`PopularEventsService`** (`:103,216`), **not** `TrendingScoreService` — so removing the Trending* trio does not affect category trending or Community Favourites.

> Removal is **out of scope for CF-7A** (audit only) and should be a separate bounded task (e.g. CF-7B — Stale Popularity Ranker Removal), since it touches `myeventlane_core` and `myeventlane_analytics` services.

---

## Ownership summary

```
Community Favourites RANKING
  └─ PopularEventsService  (sole owner; score = tickets×3 + rsvp×1, 7-day)
       ├─ PopularEventsBlock ............. homepage rail
       ├─ HomepageMerchandisingQueryAlter  /events/popular browse (FIELD() order bridge)
       ├─ HomepageSectionVisibility ...... rail visibility gate
       ├─ HomepageMerchandising .......... cross-rail exclusion sets
       └─ TrendingCategoriesService ...... category trending (non-CF)

STALE / NON-CF popularity rankers (removable, separate task)
  ├─ HomepagePopularityService ........... orphaned duplicate (no callers)
  └─ TrendingScoreService → TrendingEventsController (NO ROUTE)
```

## Findings vs the 6 questions

| # | Question | Answer |
|---|---|---|
| 1 | What determines CF ranking? | `PopularEventsService` engagement score (tickets×3+rsvp×1, 7-day, exclusions) |
| 2 | Sole owner? | **Yes** — all CF surfaces delegate to it; one formula location |
| 3 | Does QueryAlter duplicate ranking? | **No** — it injects the service and only re-applies order (FIELD) |
| 4 | Homepage & /events/popular same source? | **Yes** — same service, formula, 7-day; only display cap differs |
| 5 | RSVPs vs tickets weighted correctly? | Consistent 3:1, uniformly applied; lifecycle-state filtering gap cross-referenced |
| 6 | Stale ranking code? | **Yes** — `HomepagePopularityService` + `TrendingScoreService`/`TrendingEventsController`/template (separate removal task) |

## Validation

| Command | Result |
|---|---|
| `git diff --stat` | (this audit adds one doc only; CF-6B committed at `647851c89`) |
| `ddev drush cr` | success (run during CF-6B commit) |
| `ddev drush config:status` | No differences between DB and sync directory |
| `rg` ranking consumers | all CF surfaces resolve to `PopularEventsService` (evidence above) |

**No code, config, Views, routes, ranking, or analytics modified. Audit only.** CF-7B (stale-ranker removal) can proceed from this evidence without re-auditing ranking ownership.

---

# CF-7B — Ranking Architecture Cleanup (Implementation Outcome)

**Date:** 2026-06-16 · **Branch:** `feature/community-favourites-ranking-cleanup`

Removed the stale, unreachable ranking infrastructure identified in Q6 — **pure deletion, zero behaviour change** to Community Favourites, merchandising, attribution, analytics, Views, routes, or cards.

## Reachability matrix (Phase 1 — proven before removal)

| Candidate | Routes | Services consuming | DI / `\Drupal::service` | Twig / `#theme` | Tests | Config | Cron/Subscriber/Hook | Reachable |
|---|---|---|---|---|---|---|---|---|
| `HomepagePopularityService` | 0 | 0 | 0 (only its own `services.yml:287`) | 0 | 0 | 0 | 0 | **No** |
| `TrendingScoreService` | 0 | only `TrendingEventsController` (also removed) | only that controller | 0 | 0 | 0 | 0 | **No** |
| `TrendingEventsController` | **0 (no route anywhere)** | 0 | 0 | renders `myeventlane_trending_events` (also removed) | 0 | 0 | 0 | **No** |
| `myeventlane-trending-events.html.twig` | — | — | — | only `#theme` consumer was the removed controller | 0 | 0 | hook_theme entry (also removed) | **No** |

The Trending trio is a **closed unreachable subgraph** (controller has no route; it is the sole consumer of both the score service and the template theme hook). `HomepagePopularityService` is an independent orphan.

## Removed components (Phase 2)

| Removed | Type |
|---|---|
| `myeventlane_core/src/Service/HomepagePopularityService.php` | service class (orphan duplicate ranker) |
| `myeventlane_analytics/src/Service/TrendingScoreService.php` | service class |
| `myeventlane_analytics/src/Controller/TrendingEventsController.php` | controller (unrouted) |
| `myeventlane_analytics/templates/myeventlane-trending-events.html.twig` | template |
| `myeventlane_core.services.yml` → `myeventlane_core.homepage_popularity` | service registration |
| `myeventlane_analytics.services.yml` → `myeventlane_analytics.trending_score` | service registration |
| `myeventlane_analytics.module` → `myeventlane_trending_events` hook_theme entry | theme registration |

**Diff: 7 files, 0 insertions, 473 deletions.** Post-removal dangling-reference scan: **0** references in code/config.

## Final ownership map (post-CF-7B) — unchanged & protected (Phase 3)

| Concern | Owner | Status |
|---|---|---|
| Community Favourites ranking | `PopularEventsService` | untouched |
| Homepage/browse ranking bridge | `HomepageMerchandisingQueryAlter` | untouched |
| Homepage merchandising/rails | `HomepageMerchandising` | untouched |
| Attribution | `DiscoveryAttributionSources` | untouched |
| Analytics | `DiscoverySurfaceAnalyticsService`, `PublicAnalyticsController` | untouched |

Verified: none of the protected files appear in the changeset; `ddev drush cr` recompiled the container successfully (proves no live code depended on the removed services).

## Before → After

```
BEFORE (3 popularity rankers)
  PopularEventsService ........ ACTIVE (all CF surfaces)
  HomepagePopularityService ... ORPHAN (no callers)
  TrendingScoreService ........ ORPHAN → TrendingEventsController (NO ROUTE) → trending template

AFTER (1 ranker)
  PopularEventsService ........ ACTIVE — sole popularity/CF ranker
  (orphans removed)
```

## Lifecycle protection (Phase 4)
No published / cancelled / archived / past-event filtering, readiness, visibility, or state code was modified. The removed `HomepagePopularityService` contained its **own orphaned** eligibility checks; deleting dead code does not alter any active lifecycle path. The active lifecycle-filtering gap remains owned by **CF-7C — Lifecycle Eligibility Audit** (unchanged).

## Known remaining debt
- **CF-7C** — Lifecycle eligibility (cancelled/archived/past events qualify in `PopularEventsService`; filtering gap, untouched here).
- **CF-6C** — Signal CSS ownership (orphaned `.mel-event__signal*` selectors from CF-6B).

## Risk & validation
- **Risk: Low** — pure deletion of code with proven zero runtime reachability; container rebuild and analytics Kernel test confirm no breakage.
- `git diff --stat`: 7 files, −473. · `php -l`: clean. · `ddev drush cr`: success. · `ddev drush config:status`: no differences. · `composer validate`: valid. · `npm run mel:lint`: pass. · `npm run mel:build`: both themes built. · `OrderItemClassifierTest` (analytics Kernel): **OK, 8 tests / 119 assertions**.
- **Ownership impact: none** — Community Favourites ranking ownership unchanged (`PopularEventsService`).
