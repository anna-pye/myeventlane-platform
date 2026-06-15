# MEL Audit — Community Favourites Discovery Route

**Date:** 2026-06-15
**Branch:** `feature/community-favourites-audit`
**Scope:** Audit `/events/popular`; determine rename / repurpose / replace to become **Community Favourites**.
**Status:** **Audit only. No code or config changed.** Every conclusion cites repository evidence.

> **Mandatory-reading note:** `docs/brand/discovery-principles.md` was listed for reading but **does not exist** (*Repository evidence not found*). The Discovery Principles live in `docs/brand/mel-brand-system-v1.md` (§"Discovery Principles") and are used as the source here. All other mandatory docs were read.

---

## Executive Summary

**Should `/events/popular` become Community Favourites? — YES, by REPURPOSE + RENAME. NOT by rename alone, and NOT by replacement.**

Evidence-based reasoning:

1. **`/events/popular` is not popularity-driven today.** The `page_popular` display inherits the default display sorts: `field_promoted_value DESC` then `field_event_start_value ASC` (`config/sync/views.view.upcoming_events.yml` default `sorts:`, lines ~115–141; `page_popular` `defaults:` does **not** override `sorts`, only `empty/pager/row/filters`, lines 3286–3290). Its own description reads *"Boosted and promoted public event discovery route."* So it ranks **paid/boosted events first**, then by soonest date. It contains **no** RSVP, sales, save, or view metric.

2. **A pure rename would breach the brand.** `docs/brand/event-card-system.md` defines **Community Favourite** as *"Strong repeat attendance or local sentiment… Evidence-based; not paid placement,"* and `mel-brand-system-v1.md` (Participation Principle 5) demands *"Social proof without pressure; fake scarcity is not [acceptable]."* Renaming a **promoted-first** route to "Community Favourites" would label paid placement as community sentiment — a direct violation.

3. **A genuine engine already exists — so replacement is unnecessary.** `myeventlane_analytics\PopularEventsService` computes real engagement: `score = tickets_sold×3 + rsvp×1` from Commerce paid tickets (**excluding boost order items**, `order_item.type <> 'boost'`) + canonical `rsvp_submission` storage, last N days (`PopularEventsService.php:79–160`, header lines 20–23). This is exactly "evidence-based, not paid placement."

**Conclusion:** **Repurpose** the surface to be driven by `PopularEventsService` (swap the ranking source away from `field_promoted`), **rename** to *Community Favourites*, and add honest attribution + a repository-backed badge. The engine, card system, rail shell, diversity filter, and attribution pattern all already exist — **no new tables, entities, recommendation engines, or ranking infrastructure are required** (see §3).

---

## 1. What is `/events/popular` today?

| Aspect | Evidence | Finding |
|---|---|---|
| **Route owner** | Views page display `view.upcoming_events.page_popular`; `path: events/popular` (`views.view.upcoming_events.yml:3293`) | Views-owned page route |
| **Display owner** | `upcoming_events:page_popular` (line 2908), `display_plugin: page`, `display_title: 'Popular events'` | One display of the shared `upcoming_events` View |
| **Menu** | In `main` menu as title **"Discover"**, weight 2, desc *"Discover boosted and popular events"* (page_popular `menu:` block) | User-facing nav entry |
| **Filters** | Overridden (`defaults: { filters: false }`): published (`status=1`), `type=event`, `field_event_state` exclusions, title-not-empty, `field_event_start >= now` (upcoming) | Standard "published upcoming events" — **no popularity filter** |
| **Sorts** | **Inherited** from default display (not overridden): `field_promoted_value DESC`, then `field_event_start_value ASC` | **Boosted-first, then soonest — not popularity** |
| **Access** | Inherited from default display: `access: { type: perm, perm: 'access content' }` (default display, line ~93–96) | **Public** |
| **Query alter** | `page_popular` is allow-listed in `PublicEventDiscoveryQueryAlter` (`PublicEventDiscoveryQueryAlter.php:29`) | Canonical public discovery filters applied |
| **Cards used** | Row `view_mode: compact_commerce` (page_popular `row:`, lines ~3282–3284) → canonical event card | Shared card system (`compact_commerce` node view display) |
| **Attribution** | **Not present** in `DiscoveryAttributionSources::VIEW_DISPLAY_MAP` (lines 47–58) — no `popular`/`community` source constant exists | **No click attribution** for this route |
| **Empty state** | Inline `mel-empty-state` markup: *"No popular events yet… Browse upcoming events while organisers boost new experiences."* (line ~2947) | Empty copy reinforces the boost framing |
| **Visibility logic** | Always-on page route (no `HomepageSectionVisibility` gating — that governs homepage rails, not this route) | Route is always reachable |

---

## 2. Is Popular actually Popular?

**No.** The route's ranking signal is `field_promoted` (a boolean boost/promotion flag), not engagement.

| Signal the brand expects | Present in `/events/popular`? | Evidence |
|---|---|---|
| RSVP counts | ❌ | No RSVP join/sort in `page_popular` or its inherited sorts |
| Ticket sales | ❌ | No Commerce metric in the View |
| Save counts | ❌ | No saved-events metric in the View |
| View / pageview counts | ❌ | No pageview metric in the View |
| `field_promoted` (boost) | ✅ **only signal** | Default sort `field_promoted_value DESC` |

**What `/events/popular` actually shows:** **boosted/promoted events first**, then upcoming events by soonest start. That is **promoted events**, not community popularity, editorial selection, or recommendation output.

### Real popularity DOES exist elsewhere (but is disconnected from this route)
| Service | Status | Logic | Evidence |
|---|---|---|---|
| **`myeventlane_analytics\PopularEventsService`** | **LIVE** | `score = tickets_sold×3 + rsvp×1`; sources = Commerce paid tickets (**excludes** `order_item.type='boost'`, excludes carts) + canonical `rsvp_submission`; sort: upcoming-first, score DESC, going DESC, nid DESC | `PopularEventsService.php:79–160`, header 20–23, 175 |
| `myeventlane_front\…\PopularEventsBlock` ("Popular this week", id `myeventlane_popular_events_block`) | Defined; **not placed in any `block.block.*` config** | Injects `@myeventlane_analytics.popular_events` | `PopularEventsBlock.php:12,20,54`; no block config found |
| `myeventlane_core\HomepagePopularityService` | **ORPHANED** (injected nowhere) | Duplicate RSVP+sales logic, excludes boosted via `BoostManager` | `HomepagePopularityService.php`; no callers found |

> **Three "popular" implementations exist, and the one named on the route (`/events/popular`) is the only one that is *not* engagement-based.** The live engine (`PopularEventsService`) is genuine community popularity and **explicitly excludes boost spend** — but it is surfaced only via an **unplaced** block. The core `HomepagePopularityService` is an orphaned third copy (duplicate logic — flag for cleanup; `drupal-design-governance.md` "no duplicate UI/logic patterns").

---

## 3. Community Favourites viability

**YES — Community Favourites can be built entirely on existing architecture.**

| Required capability | Exists? | Evidence | New infra needed? |
|---|---|---|---|
| Engagement ranking engine | ✅ `PopularEventsService` (sales+RSVP, excludes boost) | `PopularEventsService.php` | **No** |
| Data sources | ✅ Commerce `order_item` + canonical `rsvp_submission` (existing tables) | `PopularEventsService.php:175,264–284` | **No new tables** |
| Render block | ✅ `PopularEventsBlock` already consumes the engine | `PopularEventsBlock.php:54` | **No** |
| Card rendering | ✅ canonical card via `compact_commerce` view mode (used by `page_popular`) + `EventCardViewModel` | view config; `EventCardViewModel.php` | **No** |
| Rail shell | ✅ `mel-section-shell.html.twig` (used by every homepage rail) | `page--front.html.twig` | **No** |
| Diversity / anti-dominance | ✅ `HomepageRailDiversityFilter` (category/venue/organiser, post-query, no SQL) | `HomepageRailDiversityFilter.php` | **No** |
| Public discovery filters | ✅ `PublicEventDiscoveryQueryAlter` (allow-listed displays) | `PublicEventDiscoveryQueryAlter.php` | **No** |
| Attribution pattern | ✅ `DiscoveryAttributionSources` (extensible allowlist; Hidden Gems added 2 keys) | `DiscoveryAttributionSources.php:23–24,57` | New **constant only** (mirrors Hidden Gems), not new infra |
| Badge signal | ✅ `EventMerchandisingPresenter` ("repository-backed signals only — no invented popularity labels") | `EventMerchandisingPresenter.php` header | A Community-Favourite signal must be **engine-backed** (governance constraint, not new infra) |

**No new database tables, entities, recommendation engines, or custom ranking infrastructure are required.** The only net-new code at implementation time is wiring (route/rail → `PopularEventsService`), one attribution constant, copy, and an engine-backed badge — all mirroring the existing Hidden Gems precedent.

---

## 4. Homepage placement

**Brand-canonical position (`docs/brand/homepage-system.md`, "Homepage hierarchy"):**
Hero → Tonight/This week near you → **Hidden Gems** → Browse by category → Editor's Pick → **Community Favourites (item 6, "Social proof without pressure")** → Just Added → Blog.

**Current homepage order** (`page--front.html.twig` region sequence): Hero → Featured → Discover → Tonight → Free/RSVP → Latest → Recommended → Nearby → Online → Blog.

**Recommended placement (no redesign):** place a **Community Favourites** rail **after Hidden Gems and after the Tonight/Discover cluster, and before "Latest/Freshly added."** This matches the brand's "social proof after discovery, before recency" intent. Concretely: position it adjacent to and **after** the existing `home_recommended`/Hidden-Gems rails and **above** `homepage_latest`.

| Rail | Relationship to Community Favourites |
|---|---|
| Tonight | Above CF (immediate/time-scoped discovery leads) |
| Hidden Gems | Above CF (brand differentiator leads) |
| Free & RSVP | Sibling discovery cluster; CF sits near it |
| **Community Favourites** | **Social-proof layer — after discovery, before recency** |
| Latest / Freshly added | **Below** CF (recency is lower priority than social proof) |
| Recommended | Adjacent; CF is community-wide, Recommended is per-user (keep distinct) |

> Placement recommendation only; brand cap of **two guide moments per homepage** (`homepage-system.md`) still applies — CF should use copy/badge, not a third guide illustration.

---

## 5. Discovery continuity (reuse surfaces)

Community Favourites (engine output) can be reused across surfaces using existing patterns:

| Surface | Existing reusable mechanism | Evidence |
|---|---|---|
| **Homepage rail** | block placement in a homepage region + `mel-section-shell` | `page--front.html.twig`; `PopularEventsBlock` |
| **Browse route** | `upcoming_events` page display + `PublicEventDiscoveryQueryAlter` allowlist (as Hidden Gems did with `page_hidden_gems`) | `views.view.upcoming_events.yml`; `PublicEventDiscoveryQueryAlter.php:31` |
| **Related events** | `EventRecommendationService` (same-category next-step) + `mel_related_events` rail | `EventRecommendationService.php`; `node--event--full.html.twig` |
| **Search fallback** | `FeaturedEventsRenderBuilder` zero-result fallbacks (featured + hidden-gems already wired) — a CF fallback mirrors these | `SearchController.php` (PR #587 pattern), `FeaturedEventsRenderBuilder` |
| **Empty-state recovery** | `includes/mel-view-empty-events.html.twig` + `empty-state.html.twig` (CTA-driven) | shared templates |
| **Checkout continuity** | `MelCustomerContinuityPresenter` → `continuity_actions` slot in `commerce-checkout-completion.html.twig` | PR #588 review evidence |
| **RSVP continuity** | RSVP confirmation templates (`myeventlane_rsvp/templates/*`) | discovery/email audits |

All reuse paths exist; CF requires wiring + copy, not new surfaces.

---

## 6. Naming audit

Evaluated against `copy-guidelines.md`, `guide-character-system.md`, and Discovery Principles (`mel-brand-system-v1.md`).

| Candidate | Brand fit | Evidence / verdict |
|---|---|---|
| **Popular** | ❌ | Functional, not brand voice; current route is boosted-first so the word is misleading; `copy-guidelines.md` prefers Community-framed social proof and avoids hype/FOMO |
| **Community Favourites** | ✅ **canonical** | Used verbatim in brand docs: `homepage-system.md` hierarchy item 6; `event-card-system.md` badge "Community Favourite"; `copy-guidelines.md` header *"Community favourites in your area"*; `mel-brand-system-v1.md` *"Community Favourite is acceptable."* AU spelling "Favourites" matches `copy-guidelines.md` (favourite). |
| Community Picks | ◑ | "Picks" implies **editorial selection** → collides with **Editor's Pick** (a distinct brand badge). Misleading for an engagement-ranked rail. |
| Community Loved | ❌ | Not in brand docs; awkward grammar; childish risk vs. `guide-character-system.md` "Not childish." |
| Popular with the Community | ◑ | Retains "Popular"; verbose; weaker than the established term. |

**Recommended canonical label: "Community Favourites"** (Australian spelling), with optional Curator-guide section intro per `guide-character-system.md` ("I think you'll love this"). Card badge: **"Community Favourite"** (engine-backed only).

---

## Ownership Map

| Layer | Owner | Evidence |
|---|---|---|
| **Route** | `view.upcoming_events.page_popular` → `/events/popular` (menu "Discover") | `views.view.upcoming_events.yml:2908,3293` |
| **View** | `upcoming_events` (multi-display); ranking inherited (`field_promoted DESC`, start ASC) | default `sorts:` ~115–141 |
| **Related view** | `front_recommended_events` — also `field_promoted DESC` + start ASC (promoted-first, not personalised) | `views.view.front_recommended_events.yml:53–75` |
| **Live popularity engine** | `myeventlane_analytics\PopularEventsService` | `PopularEventsService.php` |
| **Popularity block** | `myeventlane_front\…\PopularEventsBlock` (unplaced) | `PopularEventsBlock.php` |
| **Orphaned duplicate** | `myeventlane_core\HomepagePopularityService` | `HomepagePopularityService.php` (no callers) |
| **Query filters** | `PublicEventDiscoveryQueryAlter` (allow-lists `page_popular`, `page_hidden_gems`, …) | `PublicEventDiscoveryQueryAlter.php:25–34` |
| **Merchandising/hero** | `HomepageMerchandising` (promoted hero + spotlight cascade) | `HomepageMerchandising.php` |
| **Diversity** | `HomepageRailDiversityFilter` | `HomepageRailDiversityFilter.php` |
| **Attribution** | `DiscoveryAttributionSources` (no `popular`/`community` key) | `DiscoveryAttributionSources.php:12–24,47–58` |
| **Cards** | `compact_commerce` view mode + `EventCardViewModel` + `EventMerchandisingPresenter` (repo-backed signals only) | view config; `EventCard/*` |
| **Templates** | `page--front.html.twig`, `mel-section-shell.html.twig`, `mel-event-card.html.twig`, inline empty-state | theme |
| **Recommendation** | `EventRecommendationService` (same-category next-step) | `EventRecommendationService.php` |

---

## Existing Reusable Architecture (repository evidence only)

1. `PopularEventsService` — engagement engine (sales+RSVP, excludes boost). *Reuse as CF ranking source.*
2. `PopularEventsBlock` — block already consuming the engine. *Reuse as CF rail.*
3. `compact_commerce` view mode + `EventCardViewModel` — canonical cards. *Reuse unchanged.*
4. `mel-section-shell.html.twig` — rail chrome. *Reuse for CF rail.*
5. `HomepageRailDiversityFilter` — anti-dominance. *Reuse to diversify CF rail.*
6. `PublicEventDiscoveryQueryAlter` — discovery filters + display allowlist. *Reuse; add CF display to allowlist at build time.*
7. `DiscoveryAttributionSources` — extensible attribution (Hidden Gems precedent). *Mirror with a CF source constant.*
8. `EventMerchandisingPresenter` — repo-backed badge signals. *Reuse for an engine-backed "Community Favourite" badge.*
9. `upcoming_events` View display pattern (`page_hidden_gems` precedent) — *clone shape for a CF browse display.*
10. Continuity surfaces (search fallback builder, empty-state include, `MelCustomerContinuityPresenter`). *Reuse per §5.*

---

## Gap Analysis

| Severity | Gap | Evidence |
|---|---|---|
| 🔴 **Critical** | `/events/popular` ranks by `field_promoted` (paid/boosted), labelled/marketed as "Popular" — contradicts brand "Community Favourite = not paid placement" | default sorts ~115; display desc |
| 🔴 **Critical** | The genuine engagement engine (`PopularEventsService`) is **not connected** to the `/events/popular` route | route inherits promoted sort; engine used only by an unplaced block |
| 🟠 **High** | No **Community Favourites** rail, route, badge, or attribution source exists (brand hierarchy item 6 + badge unfulfilled) | `homepage-system.md`; `DiscoveryAttributionSources` has no key |
| 🟠 **High** | `PopularEventsBlock` (real engine) is **unplaced** — community popularity is computed but not surfaced | no `block.block.*` config |
| 🟡 **Medium** | **Duplicate logic**: orphaned `HomepagePopularityService` vs live `PopularEventsService` — violates "no duplicate patterns" | both compute RSVP+sales |
| 🟡 **Medium** | `front_recommended_events` is also promoted-first (not personalised) — "Recommended" vs CF distinction is currently blurred | `front_recommended_events.yml:53` |
| 🟢 **Low** | `/events/popular` has no click attribution (analytics blind spot) | not in `VIEW_DISPLAY_MAP` |
| 🟢 **Low** | Empty-state copy references "boost" framing, off-brand for CF | line ~2947 |

---

## Recommended Implementation Plan (future phases — not executed)

> Each phase is independently reviewable, reuses existing systems, and adds no duplicate logic. **No code/config changed in this audit.**

### Phase CF-1 — Smallest safe implementation (honest rail, existing block)
- Place the existing **`PopularEventsBlock`** (engine-backed) into a homepage region as a **"Community Favourites"** rail; set its title to "Community Favourites" via the block's existing title config.
- Use the canonical card + `mel-section-shell`. Apply `HomepageRailDiversityFilter` if the block doesn't already.
- **Do not** touch `/events/popular`'s ranking yet. No new tables/entities/engines.
- *Outcome:* a genuine, brand-honest Community Favourites rail with zero new infra.

### Phase CF-2 — Continuity + attribution + badge
- Add a `SOURCE_HOMEPAGE_COMMUNITY_FAVOURITES` (and `SOURCE_BROWSE_COMMUNITY_FAVOURITES`) constant to `DiscoveryAttributionSources` + map the CF display (mirror Hidden Gems).
- Add an **engine-backed** "Community Favourite" badge signal in `EventMerchandisingPresenter` (repo-backed only; one-badge-per-card rule preserved).
- Wire CF into reuse surfaces per §5 (search fallback, empty-state recovery, checkout/RSVP continuity).

### Phase CF-3 — Homepage integration + route repurpose
- Repurpose the `/events/popular` **browse route** to a Community Favourites browse display driven by the engine (or add a dedicated `page_community_favourites` display mirroring `page_hidden_gems`), update menu label, and redirect/retire the misleading "Popular/boosted" semantics.
- Retire the orphaned `HomepagePopularityService` (dedupe) under review.
- Confirm brand homepage order (CF after Hidden Gems, before Latest).

---

## Validation (run for this audit)

| Command | Result |
|---|---|
| `git status -sb` | `## feature/community-favourites-audit` (clean) |
| `ddev drush cr` | success |
| `ddev drush config:status` | **No differences between DB and sync directory** |
| `rg "popular" web/modules/custom web/themes/custom config/sync` | Confirms three implementations: `upcoming_events:page_popular` (View, promoted-sort), `PopularEventsService`/`PopularEventsBlock` (engine), `HomepagePopularityService` (orphaned) |
| `rg "HomepagePopularityService" web/modules/custom` | `myeventlane_core/src/Service/HomepagePopularityService.php` + `myeventlane_core.services.yml` only — **no callers (orphaned)** |
| `rg "field_promoted" config/sync/views.view.*` | Present in `upcoming_events`, `front_recommended_events`, `front_featured_events`, `mel_home_events`, `featured_events_carousel`, `featured_events` — confirms promoted-sort is the shared discovery ranking |

---

## Rules compliance
Audit-first ✅ · Drupal 11 safe (no code) ✅ · Commerce 3 safe (read-only; engine excludes boost order items) ✅ · Security aware (public `access content`, no leakage) ✅ · Config aware (no config changed; `config:status` clean) ✅ · Mobile-first (reuses existing responsive rail/card) ✅ · MEL style-guide compliant (canonical card, no duplicate patterns recommended) ✅ · No guessing — every claim cited ✅ · No code/config changes ✅.

**Repository contradicted the implicit assumption that `/events/popular` reflects popularity. It does not — it reflects paid promotion. Findings reported; nothing implemented.**
