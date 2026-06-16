# MEL CF-6A — Discovery Signal Ownership, Ranking, Attribution & Merchandising Map

**Date:** 2026-06-16
**Branch:** `feature/community-favourites-audit`
**Status:** **AUDIT ONLY — no code/config/Views/Twig/services modified.** `git diff --stat` empty; working tree clean.
**Authority:** This is the single source of truth for discovery ranking, signals, attribution, merchandising, badges, overlays, and analytics. Every conclusion cites repository evidence; where ownership cannot be proven it states **"Ownership not proven."**

> **Supersession note:** earlier same-branch audits (`brand-rollout/community-favourites-audit.md`, `popular-events-service-audit.md`) described `/events/popular` as promoted-first and `PopularEventsBlock` as unplaced. **Both are now superseded by current repository state** (a Community Favourites implementation has since landed). This document reflects current `HEAD` and is authoritative.

---

## Validation evidence

| Command | Result |
|---|---|
| `git diff --stat` | (empty — audit only) |
| `ddev drush cr` | success |
| `ddev drush config:status` | No differences between DB and sync directory |
| `composer validate` | `./composer.json is valid` |
| `npm run mel:lint` | hero-variant guard pass; stylelint clean |
| `npm run mel:build` | both themes built; tree clean |
| `rg` signal counts | Spotlight 19 · Hidden Gem 21 · Community Favourite 13 · Popular 28 · Trending 14 · mel_signal 5 (files, excl. contrib/dist) |
| `rg` owner counts | DiscoveryAttributionSources 8 · DiscoverySurfaceAnalyticsService 3 · EventMerchandisingPresenter 6 · HomepageMerchandisingQueryAlter 3 |

---

## 1. Discovery Signal Inventory

| Signal | Defining file (evidence) | Owner | Consumer | Purpose |
|---|---|---|---|---|
| **Spotlight** (image badge) | `EventMerchandisingPresenter.php:119` (`$this->t('Spotlight')`, promoted) | `EventMerchandisingPresenter` | `EventCardViewModel` → `mel-event-card.html.twig` | Promoted-event image badge |
| **Hidden Gem** (image badge) | `EventMerchandisingPresenter.php:122`; data `field_hidden_gem` | `EventMerchandisingPresenter` | card; hero via `myeventlane_event.module` (`mel_hero_discovery_badge`) | Editorial discovery badge |
| **Sold out** (badge + body) | `EventMerchandisingPresenter.php:115,156` | `EventMerchandisingPresenter` | card | Availability state |
| **Going / attendance** (body) | `EventMerchandisingPresenter.php:159` (`signal('attendance', …, 'community')`) | `EventMerchandisingPresenter` | card | Social proof (real counts) |
| **Tonight urgency** (body) | `EventMerchandisingPresenter.php:162` | `EventMerchandisingPresenter` | card | Time urgency |
| **Selling fast / capacity hint** (body) | `EventMerchandisingPresenter.php:165,168` | `EventMerchandisingPresenter` | card | Scarcity (real) |
| **Community Favourites** (rail/surface, **not a card badge**) | `PopularEventsBlock.php:244`; `DiscoveryAttributionSources.php:25–26,61` | `PopularEventsService` (rank) + `DiscoveryAttributionSources` (attribution) | homepage rail + `/events/popular` browse | Engagement-ranked rail identity |
| **Featured** (rail) | `views.view.front_featured_events.yml`; `HomepageMerchandising` | `HomepageMerchandising` + `field_promoted` | homepage featured/hero | Promoted merchandising |
| **Promoted** (data flag) | `field_promoted` (35 files); `BoostManager` | field + `BoostManager` | Spotlight badge, Featured rail, merchandising sorts | Boost/promotion state |
| **Recommended** (rail) | `views.view.front_recommended_events.yml`; `EventRecommendationService` | View (`field_promoted DESC`) + `EventRecommendationService` (related) | homepage recommended; related events | Suggestion rail |
| **Popular / Trending / Just added** (card chip) | `myeventlane_theme.theme:2779–2785` (`['Popular','Trending','Just added']` by `node->id() % 3`) | **`myeventlane_theme.theme` (placeholder)** | `mel-event-card.html.twig:226`, `node--event--full.html.twig:127`, sidebar booking | **Placeholder chip — not real data** (see §7) |
| **Trending (scored)** | `TrendingScoreService.php:35` (`recent RSVPs×2 + boost bonus`) | `TrendingScoreService` | `TrendingEventsController` (**no route**) | Orphaned trending score (see §8) |
| **Booking signals** (`mel_signal_remaining_spots`, `_trust_label`, `_urgency_label`, `_booked_count`) | `myeventlane_theme.theme:4739–4918` | `myeventlane_theme.theme` (capacity-derived, real) | `myeventlane-event-book.html.twig`, sidebar booking | Real capacity/trust on booking page |
| **Staff Pick / Editor Choice** | — | — | — | **Ownership not proven** — `rg "Staff Pick"` = 0 files; not present in repo |

---

## 2. Discovery Ranking Ownership

| Ranking system | File | Owner | Consumer |
|---|---|---|---|
| **Engagement popularity** | `myeventlane_analytics/src/Service/PopularEventsService.php` | `PopularEventsService` | homepage CF rail (`PopularEventsBlock`), browse CF (via query alter), `TrendingCategoriesService` |
| **Browse CF ranking bridge** | `myeventlane_front/src/Service/HomepageMerchandisingQueryAlter.php:76–134` | `HomepageMerchandisingQueryAlter` | Views `upcoming_events:page_popular` |
| **Merchandising exclusions / hero** | `myeventlane_front/src/Service/HomepageMerchandising.php`; `HomepageMerchandisingQueryAlter.php:46–71` | `HomepageMerchandising` | homepage rails (hero/spotlight + cross-rail dedup) |
| **Canonical discovery filters** | `myeventlane_event/src/Service/PublicEventDiscoveryQueryAlter.php` | `PublicEventDiscoveryQueryAlter` | allow-listed Views displays (`page_events`, `page_popular`, `page_hidden_gems`, `homepage_*`) |
| **Default View sorts** | `config/sync/views.view.upcoming_events.yml` (default `sorts:`) | Views config | tonight/free/latest/category/hidden_gems displays |
| **Recommended/Featured sorts** | `views.view.front_recommended_events.yml:53`, `front_featured_events.yml` | Views config | recommended/featured rails |
| **Trending score** | `myeventlane_analytics/src/Service/TrendingScoreService.php:35` | `TrendingScoreService` | `TrendingEventsController` (unrouted) |
| **Category trending** | `myeventlane_analytics/src/Service/TrendingCategoriesService.php` | `TrendingCategoriesService` | `TrendingCategoriesBlock`/`TrendingInCategoryBlock` |
| **Orphaned popularity** | `myeventlane_core/src/Service/HomepagePopularityService.php` | (orphaned) | **none** (no callers) |

### Ranking logic evidence
1. **Sort logic** — Default discovery displays: `field_promoted_value DESC, field_event_start_value ASC` (`upcoming_events.yml` default sorts). CF browse: overridden to `FIELD(nid, <ranked nids>)` (`HomepageMerchandisingQueryAlter.php:94–100`).
2. **Weight logic** — Engagement score `= tickets_sold×3 + rsvps×1` (`PopularEventsService.php:135`). Trending score `= recent RSVPs×2 + boost bonus` (`TrendingScoreService.php`). Orphan `HomepagePopularityService` `= rsvp + tickets` (`:168`). **Three different formulas.**
3. **Promoted logic** — `field_promoted DESC` is the shared discovery primary sort; `HomepageMerchandising` builds hero = top promoted, spotlight = other promoted; ineligible promoted excluded (`HomepageMerchandisingQueryAlter.php:62`).
4. **Engagement logic** — Commerce paid tickets (`order_item.type NOT IN [boost,checkout_donation,platform_donation,rsvp_donation]`) + canonical `rsvp_submission`, last 7 days (`PopularEventsService.php:180–336`).
5. **Recency logic** — `field_event_start >= now` filters (published upcoming); past events deprioritised not hidden (`PopularEventsService.php:137–139`); "Just added" = latest display sort, but the **card "Just added" chip is the fake `mel_signal`** (§7), not the latest rail.

---

## 3. Attribution Ownership

**SSOT: `myeventlane_core/src/Service/DiscoveryAttributionSources.php`** (allowlist + `VIEW_DISPLAY_MAP` + `ROUTE_MAP`).

| Attribution source (const) | Owner (set where) | Consumer |
|---|---|---|
| `homepage_featured` | `VIEW_DISPLAY_MAP` `front_featured_events:block_featured/block_hero` | analytics `spotlight` |
| `homepage_discover` | `mel_home_events:embed_discover` | analytics `discover` |
| `homepage_tonight` | `upcoming_events:homepage_tonight` | analytics `tonight` |
| `homepage_free` | `mel_home_events:under_20` | analytics `free` |
| `homepage_recommended` | `front_recommended_events:block_1` | analytics `recommended` |
| `homepage_upcoming` | `upcoming_events:homepage_latest` | analytics `latest` |
| `homepage_hidden_gems` | `upcoming_events:homepage_hidden_gems` | analytics `hidden_gems` |
| `homepage_community_favourites` | **`PopularEventsBlock.php:244`** (`#mel_discovery_source`) — not via display map | analytics `community_favourites` |
| `browse_hidden_gems` | `upcoming_events:page_hidden_gems` | analytics `hidden_gems_browse` |
| `browse_community_favourites` | **`upcoming_events:page_popular`** (`VIEW_DISPLAY_MAP:61`) | analytics `community_favourites` |
| `category` | `upcoming_events:page_category` | analytics `category` |
| `search` | `ROUTE_MAP` `mel_search.view` | analytics `search` |
| `related_events` | `SOURCE_RELATED_EVENTS` (set by related builder) | analytics `related_events` |
| `vendor_profile` | `ROUTE_MAP` `entity.myeventlane_vendor.canonical` | (attribution only) |

Resolution API: `isAllowed()`, `forViewDisplay($view,$display)`, `forRoute($route)` (`DiscoveryAttributionSources.php:76–99`).

---

## 4. Analytics Ownership

| Event | Source (writer) | Analytics surface (reader/reporting) |
|---|---|---|
| **Event click** | `PublicAnalyticsController::eventClick` → `/mel/analytics/event-click` (`myeventlane_core.routing.yml:145`) | recorded with `mel_source` (attribution) |
| **Attachment / tracking JS** | `DiscoveryAnalyticsPageAttachments` ("Attaches public event-click analytics to discovery listing surfaces") | card `data-mel-track-event-click` / `data-mel-source` on `mel-event-card.html.twig` |
| **Surface reporting** | `DiscoverySurfaceAnalyticsService` (`SOURCE_SURFACE_MAP`, `SURFACE_LABELS`) | organiser-facing click counts/labels per surface: `getHomepageClickLabelsBySurface()`, `getAggregateHomepageSurfacePerformance()` |

- **Which signals feed analytics:** every `DiscoveryAttributionSources` source that appears in `SOURCE_SURFACE_MAP` (`DiscoverySurfaceAnalyticsService.php:22–33`) — featured→spotlight, discover, tonight, hidden_gems, free, recommended, latest, community_favourites (homepage+browse), related_events.
- **Which analytics feed reporting:** surface click aggregates → vendor analytics dashboards (`AnalyticsDashboardController` at `/vendor/analytics/event/{node}`), via `DiscoverySurfaceAnalyticsService` labels.
- **Impression tracking:** **Ownership not proven** as a discovery-surface metric — only **click** ingestion (`/mel/analytics/event-click`) was found; no impression endpoint located. `mel_signal` chips feed **no** analytics.

---

## 5. Merchandising Ownership

| Merchandising element | File (owner) | Consumer |
|---|---|---|
| Image badge (Sold out → Spotlight → Hidden Gem) | `EventMerchandisingPresenter::present()` | `EventCardViewModel.php:151,202` → `mel-event-card.html.twig` |
| Body signal (Sold out → Going → Tonight → Selling fast → Capacity) | `EventMerchandisingPresenter::bodySignal()` (`:146–169`) | card body |
| Hero discovery badge | `myeventlane_event.module` `mel_hero_discovery_badge` (calls presenter) | `node--event--full.html.twig:125–131` |
| Ticket pill / price pill | `EventMerchandisingPresenter` (`ticket_pill`, price) | card |
| Card assembly (vars + attribution) | `EventCardViewModel` (`mel_source`, `card_merchandising`) | card preprocess |
| "X going" wrapper | `PopularEventsBlock.php:206–219` + `myeventlane_event_should_show_block_going()` | CF rail cards |
| **Placeholder chip** (`mel_signal`) | `myeventlane_theme.theme:2779–2785` | card/hero/sidebar (parallel to presenter) |

**Presenter contract (`EventMerchandisingPresenter.php:11–32`):** *"Resolves a single primary merchandising signal… repository-backed signals only — no invented popularity labels… one badge / one body signal."*

---

## 6. Badge Ownership Validation (CF-3A–CF-3F)

| Badge | Owner | Singular? | Evidence |
|---|---|---|---|
| **Spotlight** | `EventMerchandisingPresenter` | ✅ singular | `:119` only producer |
| **Hidden Gem** | `EventMerchandisingPresenter` (+ `field_hidden_gem` data; hero via module re-calls presenter) | ✅ singular (presenter is the single label source) | `:122`; `myeventlane_event.module` `mel_hero_discovery_badge` |
| **Sold Out** | `EventMerchandisingPresenter` | ✅ singular | `:115,156` |
| **Community Favourite** | **Not a card badge** — rail identity (`PopularEventsService` + `DiscoveryAttributionSources`) | N/A (no per-card badge exists) | no presenter label; rail-only |

**DRIFT FOUND:** `mel_signal` emits **"Popular" / "Trending" / "Just added"** chips on the **same cards** that the presenter governs, from `myeventlane_theme.theme` (not the presenter). This **violates the presenter's "single primary signal / repository-backed only" contract** and is the primary badge-ownership drift (see §7–§8).

---

## 7. Overlay & Visual Signal Audit

Visual indicators **not** produced by `EventMerchandisingPresenter`:

| Indicator | Source | Consumer |
|---|---|---|
| **`mel_signal` chip** ("Popular"/"Trending"/"Just added") | `myeventlane_theme.theme:2769–2786` — **`$node->id() % 3`** over a hardcoded array; comment: *"Event Intelligence Layer: placeholder chip (node-id derived)"* | `mel-event-card.html.twig:226`, `node--event--full.html.twig:127`, `mel-event-sidebar-slide-booking.html.twig:27` |
| `mel_signal_remaining_spots` / `_trust_label` / `_urgency_label` / `_booked_count` | `myeventlane_theme.theme:4739–4918` (capacity/vendor-derived, **real**) | `myeventlane-event-book.html.twig`, sidebar booking |
| Carousel "spotlight" nav UI | `card-carousel.js`, `carousel-nav.twig`, `_event-card.scss` | carousel chrome (presentation, not a signal) |

**Proven:** `mel_signal` (the Popular/Trending/Just-added chip) **is generated outside presenter ownership** and is backed by **no real data** (deterministic node-id modulo). Gated only by `social_proof_mode==='auto'` and not sold-out/cancelled (`:2774–2778`). The booking `mel_signal_*` variables are a **different, real** capacity system and should not be conflated with the placeholder chip.

---

## 8. Dead / Orphaned Signal Audit

| Item | Why dead/orphaned | Evidence |
|---|---|---|
| `mel_signal` chip (Popular/Trending/Just added) | Placeholder; node-id-derived; no data source; no analytics; competes with presenter | `myeventlane_theme.theme:2769–2786` (comment "placeholder") |
| `HomepagePopularityService` | Duplicate popularity logic; **no callers** | `myeventlane_core/.../HomepagePopularityService.php`; `rg` shows only self + service def |
| `TrendingScoreService` | Score computed but only consumer is an **unrouted** controller | `TrendingScoreService.php`; `TrendingEventsController` has **no route** in `myeventlane_analytics.routing.yml` |
| `TrendingEventsController` | **No route** → publicly unreachable | routing.yml contains no controller mapping |
| `myeventlane-trending-events.html.twig` | Template for the unrouted Trending controller | `myeventlane_analytics/templates/` |
| Staff Pick / Editor Choice | Declared nowhere (absent, not dead) | `rg "Staff Pick"` = 0; **Ownership not proven** |

> **Not dead (verified reachable):** `SOURCE_HOMEPAGE_COMMUNITY_FAVOURITES` — set by `PopularEventsBlock.php:244` and rendered via region `homepage_community_favourites` (`page--front.html.twig:105`, block config `block.block.myeventlane_theme_homepage_community_favourites.yml`, `status: true`).

---

## 9. Consumer Matrix

| Surface | Ranking owner | Signal/badge owner | Attribution owner | Notes (evidence) |
|---|---|---|---|---|
| **Homepage (hero/featured)** | `HomepageMerchandising` (promoted) | `EventMerchandisingPresenter` | `homepage_featured` | hero=top promoted |
| **Community Favourites (homepage rail)** | `PopularEventsService` | presenter (+ "X going" wrapper) | `homepage_community_favourites` (block-set) | `PopularEventsBlock` placed, status true |
| **Community Favourites (browse `/events/popular`)** | `HomepageMerchandisingQueryAlter` → `PopularEventsService` | presenter | `browse_community_favourites` | heading `'Community Favourites'`; engagement `FIELD()` order |
| **Browse (`/events` page_events)** | View default sorts (`field_promoted`,start) + `PublicEventDiscoveryQueryAlter` | presenter | (no mapped source) | canonical filters apply |
| **Search (`/search`)** | `myeventlane_search` (Search API) | presenter | `search` (route map) | |
| **Related Events** | `EventRecommendationService` (same-category) | presenter | `related_events` | event detail |
| **Event Detail** | n/a | presenter hero badge (`mel_hero_discovery_badge`) + **`mel_signal` (placeholder)** | n/a | drift: two signal systems on detail |
| **Hidden Gems (homepage + browse)** | View (`field_hidden_gem` filter) + `PublicEventDiscoveryQueryAlter` | presenter (Hidden Gem badge) | `homepage_hidden_gems` / `browse_hidden_gems` | |
| **Featured** | `HomepageMerchandising` / `front_featured_events` | presenter (Spotlight) | `homepage_featured` | |
| **Category (`/events/category/%`)** | View default sorts + query alter | presenter | `category` | |
| **Today (`/events/today`)** | View `homepage_tonight`/today sorts | presenter | `homepage_tonight` (tonight) | |
| **Weekend (`/events/this-weekend`)** | View display sorts | presenter | (no mapped source) | **Ownership not proven** for a dedicated attribution source |

All public cards across all surfaces additionally render the **`mel_signal` placeholder chip** (theme preprocess) unless suppressed by social-proof mode — a cross-surface overlay independent of the matrix above.

---

## 10. Single Source Of Truth Assessment (repository-backed; current owners)

| Concern | Current repository SSOT (evidence) | Drift / competing owners |
|---|---|---|
| **Ranking** | Engagement: `PopularEventsService`; promoted/hero: `HomepageMerchandising(+QueryAlter)`; canonical filters: `PublicEventDiscoveryQueryAlter` | `HomepagePopularityService` (orphan dup), `TrendingScoreService` (orphan) — **3 popularity formulas exist** |
| **Signal / badge** | `EventMerchandisingPresenter` (declares itself the single, repository-backed signal owner) | `mel_signal` placeholder (theme) emits competing Popular/Trending/Just-added chips |
| **Attribution** | `DiscoveryAttributionSources` (allowlist + maps) | none competing; `homepage_community_favourites` is block-set rather than display-mapped (minor inconsistency) |
| **Analytics** | Write: `PublicAnalyticsController::eventClick`; read/report: `DiscoverySurfaceAnalyticsService`; attach: `DiscoveryAnalyticsPageAttachments` | no impression pipeline proven |
| **Badge (subset of signal)** | `EventMerchandisingPresenter` | `mel_signal` (theme) |

*(Statements above are the repository's de facto owners per concern — not a redesign or opinion.)*

---

## 11. Consolidation Readiness Report

### Before (current architecture)
```
                         ┌───────────────────────────── EventMerchandisingPresenter ──┐
                         │  image badge: Sold out / Spotlight / Hidden Gem             │  (repo-backed SSOT)
 EventCardViewModel ─────┤  body signal: Going / Tonight / Selling fast / Capacity     │
   (mel_source, card)    └────────────────────────────────────────────────────────────┘
                         ┌──────────── myeventlane_theme.theme (PLACEHOLDER) ──────────┐
   SAME CARD  ──────────►│  mel_signal = ['Popular','Trending','Just added'][nid % 3]  │  ◄── DRIFT (fake)
                         └────────────────────────────────────────────────────────────┘
 RANKING:  PopularEventsService (tickets×3+rsvp×1) ──► HomepageMerchandisingQueryAlter ──► page_popular (CF browse)
                                                  └──► PopularEventsBlock ──────────────► homepage CF rail
           HomepagePopularityService (rsvp+tickets) ── ORPHAN (no callers)
           TrendingScoreService (rsvp×2+boost) ──► TrendingEventsController (NO ROUTE) ── ORPHAN
           field_promoted DESC (Views default) ──► tonight/free/latest/category/featured/recommended
 ATTRIBUTION: DiscoveryAttributionSources ──► DiscoverySurfaceAnalyticsService ──► /mel/analytics/event-click
```

### Recommended (consolidated — direction only, no redesign of formulas)
```
 SIGNALS/BADGES:  EventMerchandisingPresenter  ── single producer (retire mel_signal placeholder)
 RANKING:         PopularEventsService  ── single engagement owner
                  (retire HomepagePopularityService + TrendingScoreService/Controller, or route+back with data)
 ATTRIBUTION:     DiscoveryAttributionSources  ── (add page-level map for block-set CF source for consistency)
 ANALYTICS:       PublicAnalyticsController (write) + DiscoverySurfaceAnalyticsService (read)
```

### Consolidation candidates
| Candidate | Risk | Complexity | Evidence |
|---|---|---|---|
| `mel_signal` placeholder chip | **Low** (fake data; removing only drops a misleading chip) | Low (1 preprocess block + 3 template guards) | `theme:2769–2786`; consumers in 3 templates |
| `HomepagePopularityService` (duplicate ranking) | **Low** (orphan, no callers) | Low | no callers |
| `TrendingScoreService` + `TrendingEventsController` + trending template (duplicate ranking, unrouted) | **Medium** (confirm truly unused; `TrendingCategoriesService` is separate and live) | Medium | controller unrouted; categories service distinct |
| Duplicate attribution wiring (`homepage_community_favourites` block-set vs display-mapped) | **Low** | Low | `PopularEventsBlock:244` vs `VIEW_DISPLAY_MAP` |
| Duplicate popularity formulas (3) | **Medium** | Medium | `PopularEventsService:135` / `HomepagePopularityService:168` / `TrendingScoreService:35` |

---

## CF-6B readiness

This document provides: every discovery signal + owner + consumer (§1), every ranking owner with formula evidence (§2), the attribution SSOT with full maps (§3), the analytics write/read/attach owners (§4), merchandising ownership (§5), badge singularity validation with the one proven drift (§6), the proven non-presenter overlay `mel_signal` (§7), dead/orphaned items with evidence (§8), a full consumer matrix (§9), repository-backed SSOT per concern (§10), and ranked consolidation candidates (§11). **CF-6B can proceed to implementation without re-auditing ownership.**

**No code, config, Views, Twig, or services were modified. Audit only.**

---

# CF-6B — Signal Ownership Consolidation (Implementation Outcome)

**Date:** 2026-06-16 · **Branch:** `feature/community-favourites-signal-consolidation`

Implements §6–§8 of this audit: removes the `mel_signal` placeholder chip so that **`EventMerchandisingPresenter` is the sole discovery signal/badge owner**. No ranking, attribution, analytics, Views, routes, or merchandising were touched.

## Validation re-confirmation (Phase 1)
Re-verified at implementation time — matched the audit exactly, so implementation proceeded:
- Single generator: `myeventlane_theme.theme:2770/2785`, `['Popular','Trending','Just added'][ node->id() % 3 ]`, comment *"placeholder chip (node-id derived)"*.
- Not ranking-, engagement-, attribution-, analytics-, or merchandising-backed (no data source; no `DiscoveryAttributionSources`/`PopularEventsService`/analytics wiring).
- Consumers (exactly three): `mel-event-card.html.twig:226`, `node--event--full.html.twig:125`, `mel-event-sidebar-slide-booking.html.twig:27`.

## Before → After

| Aspect | Before | After |
|---|---|---|
| Card chip "Popular/Trending/Just added" | Generated in theme by `node->id() % 3` (fake) | **Removed** |
| Signal generator | `myeventlane_theme.theme` (placeholder block) | **Removed** (replaced with breadcrumb comment) |
| Card consumer | `mel-event-card.html.twig` `{% if mel_signal … %}` | Removed |
| Hero consumer | `node--event--full.html.twig` `{% elseif mel_signal %}` | Removed (category-chip if/elseif chain preserved) |
| Sidebar consumer | `mel-event-sidebar-slide-booking.html.twig` `{% if mel_signal %}` | Removed |
| Signal/badge owner | `EventMerchandisingPresenter` **+ competing `mel_signal`** | **`EventMerchandisingPresenter` (sole owner)** |

## Retained (Phase 3 — real booking signals, untouched)
`mel_signal_remaining_spots`, `mel_signal_trust_label`, `mel_signal_urgency_label`, `mel_signal_booked_count`, `mel_signal_is_community` (`myeventlane_theme.theme:4739–4918`) — capacity/vendor-derived, unrelated to the removed chip. **10 lines verified intact.**

## Final ownership (post-CF-6B)
- **Signals & badges:** `EventMerchandisingPresenter` — sole owner. The presenter's image badges (Sold out → Spotlight → Hidden Gem) and body signals (Going / Tonight / Selling fast / Capacity) are now the only discovery signals on cards/hero/sidebar.
- **Ranking / attribution / analytics / merchandising:** unchanged (`PopularEventsService`, `HomepageMerchandising(QueryAlter)`, `DiscoveryAttributionSources`, `DiscoverySurfaceAnalyticsService` / `PublicAnalyticsController` / `DiscoveryAnalyticsPageAttachments`). **Not modified.**

## Files changed
| File | +/− |
|---|---|
| `myeventlane_theme/myeventlane_theme.theme` | generator block → comment |
| `templates/components/event-card/mel-event-card.html.twig` | −3 |
| `templates/node/node--event--full.html.twig` | −4 |
| `templates/event/sidebar/mel-event-sidebar-slide-booking.html.twig` | −3 |

Total: **4 files, +6 / −28.**

## Out of scope / follow-up
- **Orphaned SCSS** `.mel-event-card__signal` (exact), `.mel-event__signal`, `--strong`, `--hero-chip` are now unused but intermingled with the **live** `.mel-event-card__signal-row` (presenter body signal) across 6 partials. Left untouched to avoid visual-regression risk; flagged for a separate bounded SCSS-cleanup task.
- `HomepagePopularityService`, `TrendingScoreService`, `TrendingEventsController`, trending template — **not** in CF-6B scope (separate audit/implementation).

## CF-6B validation report
`php -l` clean · `ddev drush cr` success · `ddev drush config:status` **no differences** (code-only change) · `composer validate` valid · `npm run mel:lint` pass (hero guard + stylelint) · `npm run mel:build` both themes built; no stray tracked assets.
