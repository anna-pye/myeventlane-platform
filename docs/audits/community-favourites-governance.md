# CF-7F — Community Favourites Governance

**Date:** 2026-06-16  
**Task:** CF-7F — Community Favourites Governance Finalisation  
**Method:** Repository evidence only. Builds on CF-7A (ranking), CF-7B (stale ranker removal), CF-7D (lifecycle eligibility), CF-7E (signal/analytics consolidation).  
**Status:** Governance documentation only — no runtime behaviour changed in CF-7F.

**Prior audit authority:**

| Task | Document / evidence | Conclusion retained |
|------|---------------------|---------------------|
| CF-7A | `docs/audits/community-favourites-ranking-ownership.md` | Single ranking owner: `PopularEventsService` |
| CF-7B | Same file, § CF-7B | Orphan rankers removed; ownership unchanged |
| CF-7D | `PopularEventsBlock.php:222–232`; `PopularEventsService.php:186–217` | Eligibility via `PublicEventVisibility::isPubliclyListable()` |
| CF-7E | `DiscoverySurfaceAnalyticsService.php:30–31`; `discovery-signal-ownership-map.md` | Analytics surfaces collapse to `community_favourites`; fake `mel_signal` removed (CF-6B) |

Related maps (do not duplicate — reference only): `popularity-ownership-map.md`, `discovery-signal-ownership-map.md`, `badge-ownership-map.md`.

---

## Purpose

This document is the **single governance authority** for Community Favourites on MyEventLane v2. It records canonical owners, approved terminology, lifecycle rules, analytics mapping, badge status, and forbidden extension patterns.

Goal: prevent future duplication of popularity ranking, eligibility filtering, visibility filtering, attribution, analytics, homepage merchandising, and Community Favourites terminology.

---

## Public Terminology

### Approved (user-facing)

| Term | Usage | Evidence |
|------|-------|----------|
| **Community Favourites** | Section titles, browse headings, nav, filters, empty states | `page--front.html.twig:100`; `views.view.upcoming_events.yml` `page_popular`; `myeventlane_core.links.menu.yml`; `mel-browse-events-page-shell.html.twig` |
| **Popular with the community** | Per-card discovery reason on CF rails only | `mel-event-card.html.twig:119–121` when `mel_source` is `homepage_community_favourites` or `browse_community_favourites` |

Australian English spelling **Favourites** matches `docs/brand/copy-guidelines.md`.

### Internal only (not public copy)

| Term | Location | Notes |
|------|----------|-------|
| `PopularEventsService` | `myeventlane_analytics` service class | Engine name; do not expose in UI |
| `PopularEventsBlock` / `myeventlane_popular_events_block` | Block plugin | Plugin `admin_label` still says "Popular this week" — legacy internal label; placed block config uses `label: 'Community Favourites'`, `title: ''` (`block.block.myeventlane_theme_homepage_community_favourites.yml`) |
| `homepage_community_favourites` | Attribution + theme region | Internal analytics/attribution key and Drupal region id |
| `browse_community_favourites` | `DiscoveryAttributionSources` const | Internal attribution key for `/events/popular` browse |
| `page_popular` | Views display machine name | Route/display id; user-facing label is Community Favourites |

### Legacy / adjacent (document only — do not rename in CF-7F)

| Term | User-facing? | Approved? | Evidence |
|------|--------------|-----------|----------|
| Popular this week | No (block plugin defaults only) | No — internal default | `PopularEventsBlock.php:22–26,103` |
| Popular categories | Yes (hero) | Yes — unrelated surface | `hero.twig:49` |
| Popular articles / Popular help | Yes | Yes — Help Centre, not CF | help templates |
| Community favourite (singular) | Yes on **featured** cards only | **Misaligned** — discovery reason for `homepage_featured`, not CF | `mel-event-card.html.twig:98` |
| Popular on MyEventLane | Yes (booking sidebar) | Separate trust copy | `mel-event-sidebar-slide-trust.html.twig` |

---

## Ownership Map

| Concern | Canonical owner | File | Method / mechanism | Consumer |
|---------|-----------------|------|-------------------|----------|
| **Community Favourites ranking** | `PopularEventsService` | `web/modules/custom/myeventlane_analytics/src/Service/PopularEventsService.php` | `getPopularEventRows()` / `getPopularEventIds()` — score `(tickets×3)+(rsvps×1)`, 7-day lookback | `PopularEventsBlock`, `HomepageMerchandising`, `HomepageSectionVisibility`, `HomepageMerchandisingQueryAlter`, `TrendingCategoriesService` |
| **Community Favourites eligibility** | `PublicEventVisibility` | `web/modules/custom/myeventlane_event/src/Service/PublicEventVisibility.php` | `isPubliclyListable()` | `PopularEventsService::filterPubliclyListableRows()`; `PopularEventsBlock::build()` (defence in depth); `PublicEventDiscoveryQueryAlter` (SQL mirrors for Views) |
| **Public visibility contract** | `PublicEventVisibility` | Same | `isPubliclyListable()` — single PHP contract | APIs, search, SEO, CF engine, CF block, structured data |
| **Homepage rail rendering** | `PopularEventsBlock` + theme shell | `PopularEventsBlock.php`; `page--front.html.twig`; `myeventlane_theme.theme` | Block `build()`; `mel-section-shell` when `mel_home_show_community_favourites` | Homepage region `homepage_community_favourites` |
| **Homepage rail query / ordering bridge** | `HomepageMerchandisingQueryAlter` | `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandisingQueryAlter.php` | `applyCommunityFavouritesBrowseRanking()` — **not a ranker** | Views `upcoming_events:page_popular` only |
| **Homepage dedup / exclusion** | `HomepageMerchandising` | `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandising.php` | `getCommunityFavouritesExclusionNids()`, `getCommunityFavouritesEventIds()` | `PopularEventsBlock`, `HomepageSectionVisibility` |
| **Discovery attribution** | `DiscoveryAttributionSources` | `web/modules/custom/myeventlane_core/src/Service/DiscoveryAttributionSources.php` | Constants + `VIEW_DISPLAY_MAP` + `forViewDisplay()` | `PopularEventsBlock` (homepage); Views row stamp (`myeventlane_event.module`); `PublicAnalyticsController` allowlist |
| **Discovery analytics (reporting)** | `DiscoverySurfaceAnalyticsService` | `web/modules/custom/myeventlane_core/src/Service/DiscoverySurfaceAnalyticsService.php` | `SOURCE_SURFACE_MAP` → surface `community_favourites` | Vendor/organiser dashboards via `AnalyticsService` click counts |
| **Discovery analytics (capture)** | `PublicAnalyticsController` + `AnalyticsService` | `PublicAnalyticsController.php`; `AnalyticsService.php` | `eventClick()` → `track(..., $source)` | Client `vendor_public.js` on discovery surfaces (`DiscoveryAnalyticsPageAttachments`) |
| **Card discovery messaging** | Theme Twig | `web/themes/custom/myeventlane_theme/templates/components/event-card/mel-event-card.html.twig` | `mel_source` → "Popular with the community" | CF homepage + browse cards |
| **Card image badge ownership** | `EventMerchandisingPresenter` | `web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php` | `present()` — Sold out → Spotlight → Hidden Gem | **No Community Favourite image badge** |
| **Full-page hero badge ownership** | `EventMerchandisingPresenter` via hook | `myeventlane_event.module` → `myeventlane_event_apply_full_page_discovery_badge()` | Same presenter chain | **No Community Favourite hero badge** |

**Multiple active owners check:** **PASS.** No competing rankers feed CF surfaces (CF-7B removed orphans). Eligibility and visibility share one contract (`isPubliclyListable()`), not parallel boolean systems.

---

## Ranking Ownership

**Owner:** `Drupal\myeventlane_analytics\Service\PopularEventsService` (`myeventlane_analytics.popular_events`).

| Property | Value | Evidence |
|----------|-------|----------|
| Formula | `score = (tickets_sold × 3) + (rsvps × 1)` | `PopularEventsService.php:152` |
| Ticket source | Commerce paid order items; excludes boost/donation types via `OrderItemClassifier` | `PopularEventsService.php:277` |
| RSVP source | `rsvp_submission` (+ legacy `myeventlane_rsvp` merge) | `PopularEventsService.php:320–327` |
| Window | Default 7 days | `PopularEventsService.php:36,114` |
| Sort | Upcoming first → score DESC → going DESC → nid DESC | `PopularEventsService.php:168–184` |
| Post-rank filter | `filterPubliclyListableRows()` | `PopularEventsService.php:186–217` |

**Bridge (not a ranker):** `HomepageMerchandisingQueryAlter` applies `FIELD(nid, …)` ordering from engine output for browse only.

**Forbidden:** new popularity services, duplicate scoring in blocks/Views/Twig, alternate CF ranking routes.

---

## Visibility Ownership

**Owner:** `PublicEventVisibility::isPubliclyListable()` — single public discovery contract.

### Contract rules (`PublicEventVisibility.php:106–127`)

| Rule | Enforced |
|------|----------|
| Published only | `$event->isPublished()` |
| Lifecycle | Excludes draft, cancelled, archived, ended (`isExcludedLifecycleState`, `hasEnded`) |
| Visibility | `field_event_visibility === public` (empty/null → public) |
| Internal titles | `hasInternalMarkerTitle()` |

### Consumers of `isPubliclyListable()`

| Consumer | Role |
|----------|------|
| `PopularEventsService::filterPubliclyListableRows()` | CF engine output |
| `PopularEventsBlock::build()` | Homepage rail defence in depth after over-fetch |
| `PublicEventApiController` | Public API |
| `SearchController` | Search results |
| `EventStructuredDataBuilder` | JSON-LD / SEO |

### Views SQL mirror (`PublicEventDiscoveryQueryAlter`)

Allow-listed displays including `upcoming_events:page_popular` receive SQL hygiene: ended-state exclusion, internal title exclusion, public-visibility-only (`applyVisibilityExclusion`). Views config also excludes draft/cancelled/archived on discovery displays (`views.view.upcoming_events.yml`).

**Forbidden:** duplicate visibility checks in CF-specific services; bypassing `isPubliclyListable()` for CF surfaces.

---

## Eligibility Ownership

CF eligibility **is** public listability plus engagement in the lookback window plus homepage merchandising dedup.

| Stage | Owner | What it decides |
|-------|-------|-----------------|
| Engagement pool | `PopularEventsService` | Events with tickets/RSVPs in window |
| Listability | `PublicEventVisibility::isPubliclyListable()` | Lifecycle + visibility + ended |
| Homepage dedup | `HomepageMerchandising` | Exclude nids already in hero/spotlight/discover/tonight/hidden gems |
| Homepage diversity | `HomepageRailDiversityFilter` | Category/vendor spread on homepage rail |
| Browse constraint | `HomepageMerchandisingQueryAlter` | Restrict `page_popular` to engine nids + rank order |
| Section shell gate | `HomepageSectionVisibility::hasCommunityFavouritesEvents()` | Hide homepage section when post-dedup pool empty |

There is **no** `field_community_favourite` or presenter eligibility boolean.

---

## Homepage Ownership

| Layer | Owner | File |
|-------|-------|------|
| Section title / subtitle / CTA | Theme | `page--front.html.twig:94–106` |
| Show/hide section shell | `HomepageSectionVisibility` + theme preprocess | `HomepageSectionVisibility.php:74–85`; `myeventlane_theme.theme:1968–1971` |
| Card grid | `PopularEventsBlock` | `PopularEventsBlock.php:163–335` |
| Block placement | Config | `config/sync/block.block.myeventlane_theme_homepage_community_favourites.yml` |

### Can the section header render with zero eligible events?

**No.** `mel_home_show_community_favourites` requires **both**:

1. `HomepageSectionVisibility::hasCommunityFavouritesEvents()` — post-dedup candidate pool non-empty (`HomepageMerchandising::getCommunityFavouritesEventIds()`).
2. Block region has rendered children (`count(Element::children(...)) > 0`).

If the block builds zero cards, it returns empty `#markup` and the region has no children — section shell is suppressed.

Browse `/events/popular` may show an empty state heading ("No Community Favourites yet") via `views-view--upcoming-events.html.twig` — that is browse UX, not a homepage empty shell.

---

## Attribution Ownership

**Owner:** `DiscoveryAttributionSources` (`myeventlane_core.discovery_attribution_sources`).

| Source constant | Set where | Analytics surface |
|-----------------|-----------|-------------------|
| `homepage_community_favourites` | `PopularEventsBlock.php:269` (`#mel_discovery_source`) | `community_favourites` |
| `browse_community_favourites` | `VIEW_DISPLAY_MAP` → `upcoming_events:page_popular`; stamped in `myeventlane_event_apply_discovery_source_to_card_build()` | `community_favourites` |

Allowlist: `DiscoveryAttributionSources::ALLOWED` includes both keys.  
Write path: `PublicAnalyticsController::eventClick()` rejects non-allowlisted sources.

**Minor inconsistency (document only):** homepage source is block-set, not display-mapped — intentional; do not add a second homepage attribution path.

---

## Analytics Ownership

| Layer | Owner | Responsibility |
|-------|-------|----------------|
| Click capture | `PublicAnalyticsController` → `AnalyticsService::track()` | Stores `event_click` with `mel_source` |
| Surface mapping | `DiscoverySurfaceAnalyticsService::SOURCE_SURFACE_MAP` | Collapses attribution → reporting surface |
| Page attachment | `DiscoveryAnalyticsPageAttachments` | Attaches `vendor_public.js` on front + view routes |
| CF reporting label | `DiscoverySurfaceAnalyticsService::SURFACE_LABELS` | `'community_favourites' => 'Community Favourites'` |

### Analytics matrix

| Attribution source | Analytics surface key | Organiser-facing label | Consumer |
|--------------------|----------------------|------------------------|----------|
| `homepage_community_favourites` | `community_favourites` | Community Favourites | `DiscoverySurfaceAnalyticsService` |
| `browse_community_favourites` | `community_favourites` | Community Favourites | Same |

Both sources **collapse** into one surface — do not create separate homepage vs browse analytics labels.

---

## Badge Ownership

### Card image badges

**Owner:** `EventMerchandisingPresenter::present()`.  
Active image badges: Sold out → Spotlight (promoted) → Hidden Gem.  
**Community Favourite image badge: does not exist, not active, intentionally disabled.**

See `docs/audits/badge-ownership-map.md` § Community Favourite image badge.

### Card discovery reason (not a badge)

**Owner:** `mel-event-card.html.twig` — "Popular with the community" for CF attribution sources only.

### Full-page hero badge

**Owner:** `myeventlane_event_apply_full_page_discovery_badge()` → presenter.  
Emits presenter image badges only (Sold out / Spotlight / Hidden Gem). **No CF hero badge.**

---

## Event Lifecycle Rules

Evidence map (ownership validation only — logic audited in CF-7D):

```
Event node
  → engagement counted (PopularEventsService — published nodes in SQL joins)
  → isPubliclyListable() (PopularEventsService filter + PopularEventsBlock + Views alter)
  → ranked (PopularEventsService sort)
  → homepage: dedup (HomepageMerchandising) → diversity (HomepageRailDiversityFilter) → render (PopularEventsBlock)
  → browse: nid IN engine list + Views hygiene (PublicEventDiscoveryQueryAlter) → render (Views)
  → attribution (#mel_discovery_source via DiscoveryAttributionSources)
  → analytics click (PublicAnalyticsController → community_favourites surface)
  → card copy ("Popular with the community" in mel-event-card.html.twig)
```

---

## Excluded States

Confirmed via `PublicEventVisibility::isPubliclyListable()` and CF-7D implementation:

| State | Excluded from CF? | Mechanism |
|-------|-------------------|-----------|
| Draft | Yes | `EXCLUDED_STATES` + Views filters |
| Unpublished | Yes | `!$event->isPublished()` |
| Cancelled | Yes | `EXCLUDED_STATES` + Views filters |
| Archived | Yes | `EXCLUDED_STATES` + Views filters |
| Ended / past | Yes | `hasEnded()`, `STATE_ENDED`; engine deprioritises past in sort but listability filter removes them |
| Private | Yes | visibility !== public |
| Unlisted | Yes | visibility !== public |
| Passcode | Yes | visibility !== public |

**Note:** Past events with engagement may appear in raw SQL engagement queries but are **removed** before CF surfaces render (`filterPubliclyListableRows`, block filter, Views hygiene).

---

## Approved Discovery Sources

Only these attribution keys may label Community Favourites clicks:

| Key | Surface |
|-----|---------|
| `homepage_community_favourites` | Homepage CF rail |
| `browse_community_favourites` | Browse `/events/popular` (`view.upcoming_events.page_popular`) |

Route: `view.upcoming_events.page_popular` — **do not** add alternate CF browse routes without governance review.

---

## Community Favourite Badge Status

| Question | Answer |
|----------|--------|
| Image badge exists? | **No** |
| Active in presenter? | **No** |
| Intentionally disabled? | **Yes** |
| Why? | Rail already has section title + discovery reason; no eligibility boolean; brand requires evidence-based criteria (`docs/brand/event-card-system.md`); Spotlight collision risk for promoted events |
| Owner of decision | Product + brand governance (`badge-ownership-map.md`, `community-favourites-audit.md`) |
| Future enablement requires | Documented eligibility criteria, single boolean or field, product sign-off, governance approval, presenter priority chain update |

---

## Future Extension Rules

1. **Reuse `PopularEventsService`** for any new CF surface — inject and consume ranked rows; never re-score.
2. **Reuse `isPubliclyListable()`** for eligibility — never add CF-specific lifecycle checks.
3. **Reuse `DiscoveryAttributionSources`** — add new const + allowlist + `SOURCE_SURFACE_MAP` entry if a new CF placement is approved.
4. **One analytics surface** — new placements map to `community_favourites` unless product explicitly splits reporting.
5. **Homepage sections** — use `HomepageSectionVisibility` pattern before adding empty shells.
6. **Badges** — discovery reason only until governance approves image badge criteria.

---

## Forbidden Patterns

- New popularity / engagement ranking services for Community Favourites
- Duplicate visibility or eligibility checks outside `PublicEventVisibility`
- Duplicate analytics surface labels for homepage vs browse CF
- Duplicate attribution source constants for the same surface
- Alternate Community Favourites browse routes or promoted-first CF sorts
- Community Favourite **image** badges in `EventMerchandisingPresenter` without governance approval
- Injecting `PopularEventsService` into the presenter for badge eligibility
- User-facing "Popular Events" labelling for CF surfaces (use Community Favourites)
- Reintroducing fake `mel_signal` chips (removed CF-6B)

---

## Matrices (deliverables)

### Ownership matrix

See § Ownership Map.

### Lifecycle matrix

See § Event Lifecycle Rules and § Excluded States.

### Visibility matrix

| Layer | Owner | CF homepage | CF browse |
|-------|-------|-------------|-----------|
| PHP contract | `PublicEventVisibility::isPubliclyListable()` | Via engine + block | Via engine nids + Views alter |
| Views SQL | `PublicEventDiscoveryQueryAlter` | N/A (block not Views) | `page_popular` allow-listed |
| Published filter | Engine SQL + node load | Yes | Yes |

### Analytics matrix

See § Analytics Ownership.

### Terminology matrix

See § Public Terminology.

---

## Optional Recommendations (max 3)

| # | Recommendation | Risk | Owner | Diff size | Justification |
|---|----------------|------|-------|-----------|---------------|
| 1 | Rename block plugin `admin_label` / default `title` from "Popular this week" to "Community Favourites" | Low — admin UI only | `myeventlane_front` | ~5 lines | Removes last internal legacy label; no public impact (config already uses CF) |
| 2 | Correct `homepage_featured` discovery reason in `mel-event-card.html.twig` from "Community favourite" to Spotlight-aligned copy | Low — copy only | Theme | ~2 lines | Misaligned terminology on featured rail; unrelated to CF badge |
| 3 | Add `mel_home_events`-style display map entry for homepage CF block in `DiscoveryAttributionSources` (optional consistency) | Low | `myeventlane_core` | ~3 lines | Documents homepage source in SSOT map; block-set remains authoritative setter |

**Do not implement in CF-7F** unless explicitly requested — governance task is documentation-only.

---

## Reuse gate (Phase 9)

| Question | Answer |
|----------|--------|
| Existing owner cannot perform task? | **N/A** — no new capability proposed |
| Existing service cannot be reused? | **No** — all CF concerns already have owners |
| Governance doc already exists? | **This document** supersedes scattered audit fragments for CF governance |

**Verdict:** Reuse existing owners. Do not create replacements.

---

## Validation (CF-7F)

Run after adding this document:

```bash
git diff --stat
rg "Community Favourites" web/modules/custom web/themes/custom docs
rg "homepage_community_favourites" web/modules/custom
rg "browse_community_favourites" web/modules/custom
rg "PopularEventsService" web/modules/custom
rg "isPubliclyListable" web/modules/custom
php -l web/modules/custom/myeventlane_core/src/Service/DiscoveryAttributionSources.php
php -l web/modules/custom/myeventlane_core/src/Service/DiscoverySurfaceAnalyticsService.php
ddev drush cr
ddev drush config:status
composer validate
npm run mel:lint
npm run mel:build
```

---

## Success criteria checklist

| # | Criterion | Status |
|---|-----------|--------|
| 1 | Single ranking owner documented | Yes — `PopularEventsService` |
| 2 | Single visibility owner documented | Yes — `PublicEventVisibility::isPubliclyListable()` |
| 3 | Single eligibility owner documented | Yes — same contract + engine engagement pool |
| 4 | Single attribution owner documented | Yes — `DiscoveryAttributionSources` |
| 5 | Single analytics owner documented | Yes — `DiscoverySurfaceAnalyticsService` + `PublicAnalyticsController` |
| 6 | CF badge status documented | Yes — disabled by design |
| 7 | Future governance documented | Yes — § Future Extension Rules, § Forbidden Patterns |
| 8 | No duplicate systems introduced | Yes — documentation only |
| 9–11 | Drupal 11 / Commerce 3 / small diff | Yes — one new markdown file |
