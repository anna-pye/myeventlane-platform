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
