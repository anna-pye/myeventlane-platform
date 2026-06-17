# Homepage Rail Architecture Audit (Phase 3)

**Product surface:** Homepage only (`/home`, front page)  
**Audit date:** 2026-06-17  
**Method:** Repository evidence only — no runtime code changes  
**Branch state at audit:** Clean working tree (`git status --short` empty)

---

## Executive summary

The homepage is a **template-ordered, region + block + View** composition. Rail order is fixed in `page--front.html.twig`; block weights cannot reorder sections across regions.

**Finding:** Current rail order **partially** supports MEL discovery strategy but **misaligns** with approved brand hierarchy in `docs/brand/homepage-system.md` on three critical dimensions:

1. **Utility before brand differentiator** — *Happening tonight* (#5) and *Hidden Gems* (#6) sit below *Worth exploring next* (#3) and *New this week* (#4).
2. **Hidden Gems too low** — Brand canon places Hidden Gems at priority #3; runtime order is #6 among discovery rails.
3. **Recommended rail positioning and semantics** — Authenticated-only, promoted-event sort (not `EventRecommendationService`), with copy implying community popularity; occupies #3 before time-sensitive and brand rails.

Merchandising deduplication (`HomepageMerchandising`) and visibility gating (`HomepageSectionVisibility`) are well-evidenced and reduce duplicate cards, but increase **empty-rail risk** for lower-priority rails (Community Favourites, Hidden Gems after dedup).

**Recommendation:** Phase 4 should reorder template blocks only (no query/logic changes in Phase 4 scope unless separately approved). Proposed order aligns with brand canon: Hero → Tonight → Hidden Gems → Discover → Community spotlight → Community Favourites → New this week → Free & RSVP → Recommended (auth) → Blog → Host CTA.

---

## 1. Render path and ownership map

### 1.1 Front page route and template

| Layer | Evidence |
|---|---|
| Front route | `config/sync/system.site.yml` → `page.front: /home` |
| Page template | `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` |
| Theme regions | `web/themes/custom/myeventlane_theme/myeventlane_theme.info.yml` (lines 18–36) |
| Section gating preprocess | `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` (lines 1889–1982), front page only via `\Drupal::service('path.matcher')->isFrontPage()` |
| Visibility service | `web/modules/custom/myeventlane_front/src/Service/HomepageSectionVisibility.php` — wired as `myeventlane_front.homepage_section_visibility` in `myeventlane_front.services.yml` |
| Merchandising / dedup | `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandising.php` — `myeventlane_front.homepage_merchandising` |
| Query alters | `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandisingQueryAlter.php` via `myeventlane_front_views_query_alter()` in `myeventlane_front.module` (lines 75–88) |
| Diversity filter | `web/modules/custom/myeventlane_front/src/Service/HomepageRailDiversityFilter.php` via `myeventlane_front_views_pre_render()` in `myeventlane_front.module` (lines 96–115) |
| Tonight date window | `web/modules/custom/myeventlane_views/myeventlane_views.module` (lines 37–61) — calendar-day bounds for `upcoming_events:homepage_tonight` |
| Public discovery hygiene | `web/modules/custom/myeventlane_event/src/Service/PublicEventDiscoveryQueryAlter.php` |

### 1.2 Order mechanism

Order is **template-driven**. Each rail is an `{% if … %}` block in sequence inside `page--front.html.twig`. Block placement weights in Drupal regions do **not** control cross-region order.

### 1.3 Master ownership map

| Rail | Render source | Region | Block config | View / plugin | Visibility owner | Query owner | Merchandising owner |
|---|---|---|---|---|---|---|---|
| **Hero** | `HomeHeroBlock` → `myeventlane-home-hero.html.twig` | `homepage_hero` | `block.block.myeventlane_theme_homeheromyeventlane.yml` | Plugin: `myeventlane_home_hero`; hero rotator: `front_featured_events:block_hero` via `FeaturedEventsRenderBuilder` | Block `<front>` path; hero rotator: `FeaturedEventsRenderBuilder::hasHeroResults()` | `front_featured_events` (promoted filter) + `PublicEventDiscoveryQueryAlter` | `HomepageMerchandising` (hero exclusivity on other displays) |
| **Discover events** | Views block in section shell | `home_discover` | `block.block.myeventlane_theme_views_block__mel_home_events_discover.yml` | `mel_home_events:embed_discover` | `mel_home_show_discover` — preprocess + `HomepageSectionVisibility::hasDiscoverEvents()` | View config + `PublicEventDiscoveryQueryAlter` + `HomepageMerchandisingQueryAlter` | `HomepageMerchandising` + `HomepageRailDiversityFilter` |
| **Community spotlight** | Views block in section shell | `homepage_featured` | `block.block.front_featured_events.yml` | `front_featured_events:block_featured` | `mel_home_show_featured` — preprocess + `HomepageSectionVisibility::hasFeaturedEvents()` → `FeaturedEventsRenderBuilder` | View (`field_promoted = 1`) + `PublicEventDiscoveryQueryAlter` + quality gate in merchandising | `HomepageMerchandising` (hero/spotlight split, dedup cascade) |
| **Worth exploring next** | Views block in section shell | `home_recommended` | `block.block.myeventlane_theme_views_block__front_recommended_events_block_1.yml` | `front_recommended_events:block_1` | Block: **authenticated role** + `mel_home_show_recommended` (view has rows + region children) | View (promoted sort) + `PublicEventDiscoveryQueryAlter` + `HomepageMerchandisingQueryAlter` | `HomepageMerchandising` + `HomepageRailDiversityFilter` |
| **New this week** | Views block in section shell | `homepage_latest` | `block.block.myeventlane_theme_homepage_latest.yml` | `upcoming_events:homepage_latest` | **Region truthiness only** — `{% if page.homepage_latest %}`; no `mel_home_show_*` | View + `PublicEventDiscoveryQueryAlter` + `HomepageMerchandisingQueryAlter` | `HomepageMerchandising` + `HomepageRailDiversityFilter` |
| **Happening tonight** | Views block in section shell | `homepage_tonight` | `block.block.myeventlane_theme_homepage_tonight.yml` | `upcoming_events:homepage_tonight` | `mel_home_show_tonight` + view `block_hide_empty: true` | View + `myeventlane_views_views_query_alter` (today window) + hygiene + merchandising | `HomepageMerchandising` + `HomepageRailDiversityFilter` |
| **Hidden Gems Near You** | Views block in section shell | `homepage_hidden_gems` | `block.block.myeventlane_theme_homepage_hidden_gems.yml` | `upcoming_events:homepage_hidden_gems` | `mel_home_show_hidden_gems` + view `block_hide_empty: true` | View (`field_hidden_gem = 1`) + hygiene + merchandising | `HomepageMerchandising` + `HomepageRailDiversityFilter` |
| **Community Favourites** | Custom block in section shell | `homepage_community_favourites` | `block.block.myeventlane_theme_homepage_community_favourites.yml` | Plugin: `myeventlane_popular_events_block` | `mel_home_show_community_favourites` — uses post-dedup pool via `HomepageMerchandising::getCommunityFavouritesEventIds()` | `PopularEventsService` (7-day engagement) | `PopularEventsBlock` dedup + `HomepageRailDiversityFilter`; browse ranking in `HomepageMerchandisingQueryAlter` |
| **Easy ways to join in** | Views block in section shell | `homepage_free` | `block.block.myeventlane_theme_homepage_free.yml` | `mel_home_events:under_20` | `mel_home_show_free_rsvp` | View (no `field_product_target`) + hygiene + merchandising | `HomepageMerchandising` + `HomepageRailDiversityFilter` |
| **Nearby events** | Section shell (if region populated) | `homepage_nearby` | **No block in `config/sync`** | Region defined; `mel_home_events:near_you` display exists | Region truthiness only | N/A (unplaced) | N/A (unplaced) |
| **Online events** | Section shell (if region populated) | `homepage_online` | **No block in `config/sync`** | Region defined; no matching block config found | Region truthiness only | N/A (unplaced) | N/A (unplaced) |
| **Guides (blog)** | Views block in section shell | `homepage_blog` | `block.block.myeventlane_theme_homepage_blog.yml` | `mel_blog:homepage_preview` | `mel_home_show_blog` — `hasPublicBlogPosts()` + region children | View + `BlogAudienceFilterService` (query alter in same hook) | None |
| **Host CTA** | Region or default include | `homepage_host_cta` | **No block in `config/sync`** | `mel-host-cta-default.html.twig` fallback | Always renders default when region empty | N/A | N/A |
| **Newsletter** | Region output | `homepage_newsletter` | **No block in `config/sync`** | — | Region truthiness only | N/A | N/A |

**Note:** `homepage_categories` block (`block.block.myeventlane_theme_homepage_categories.yml`) is **disabled** (`status: false`). Category pills render inside the hero via `HomeHeroBlock` → `myeventlane_category_pills` plugin, not as a separate homepage rail.

---

## 2. Visibility map

| Rail | Shell renders when | Block visibility | Content-empty behaviour |
|---|---|---|---|
| Hero | `page.homepage_hero` has block | `<front>` | Hero always shows; rotator optional when `FeaturedEventsRenderBuilder::hasHeroResults()` |
| Discover | `mel_home_show_discover` | `<front>` | Hidden when view returns 0 rows |
| Community spotlight | `mel_home_show_featured` | `<front>` | Hidden when no promoted/quality-gate-ready featured rows |
| Worth exploring next | `mel_home_show_recommended` | `<front>` + **authenticated role** | Hidden for anonymous; hidden when view empty after dedup |
| New this week | `page.homepage_latest` (region has block) | `<front>` | **Section shell always shown**; view may render governed empty state inside |
| Happening tonight | `mel_home_show_tonight` | `<front>` | Hidden when no calendar-day events |
| Hidden Gems | `mel_home_show_hidden_gems` | `<front>` | Hidden when no `field_hidden_gem` events after dedup |
| Community Favourites | `mel_home_show_community_favourites` | `<front>` | Hidden when popularity pool empty after higher-rail dedup |
| Easy ways to join in | `mel_home_show_free_rsvp` | `<front>` | Hidden when no free/RSVP events after dedup |
| Nearby | `page.homepage_nearby` | — (unplaced) | Does not render today |
| Online | `page.homepage_online` | — (unplaced) | Does not render today |
| Blog | `mel_home_show_blog` | `<front>` | Hidden when no published `blog_post` nodes |
| Host CTA | Always (default include) | — | Always renders fallback CTA |
| Newsletter | `page.homepage_newsletter` | — (unplaced) | Does not render today |

### Visibility service methods (evidence)

`HomepageSectionVisibility` exposes:

- `hasPublicBlogPosts()` — entity query on `blog_post`
- `hasFeaturedEvents()` — delegates to `FeaturedEventsRenderBuilder::hasResults()`
- `hasDiscoverEvents()` — `mel_home_events:embed_discover`
- `hasHiddenGemEvents()` — `upcoming_events:homepage_hidden_gems`
- `hasCommunityFavouritesEvents()` — on front page uses `HomepageMerchandising::getCommunityFavouritesEventIds()` (post-dedup)
- `viewDisplayHasResults()` — generic view execute check (used for free, recommended, tonight)

Preprocess combines each flag with **region child count** (`Element::children($variables['page'][region]) > 0`) so empty block plugins do not show section headings.

---

## 3. Rail inventory

### 3.1 Hero

| Attribute | Value |
|---|---|
| **Heading** | “What could you discover this weekend?” |
| **Subtitle** | “Local events, markets, and community gatherings — all in one place.” |
| **Data** | `myeventlane_home_hero` block; optional `front_featured_events:block_hero` rotator |
| **Merchandising** | Spotlight (hero lead promoted event); category pills |
| **Visibility** | Always on front page |

Evidence: `myeventlane-home-hero.html.twig` (lines 21–24); `HomeHeroBlock.php` (lines 93–96).

### 3.2 Discover events

| Attribute | Value |
|---|---|
| **Heading** | “Discover events” |
| **Subtitle** | “Discover more of your city — filter by mood or moment” |
| **See all** | `view.upcoming_events.page_events` |
| **View** | `mel_home_events:embed_discover` (6 items, exposed chip filters: start window, free/RSVP, trending) |
| **Merchandising** | Cross-rail dedup; diversity filter |
| **Visibility** | Conditional (`mel_home_show_discover`) |

Evidence: `page--front.html.twig` (lines 33–45); `views.view.mel_home_events.yml` `embed_discover` display (block_description: “Discover (chips + filters)”).

### 3.3 Community spotlight

| Attribute | Value |
|---|---|
| **Heading** | “Community spotlight” |
| **Subtitle** | “Unexpected experiences. Real community.” |
| **See all** | `view.upcoming_events.page_events` |
| **View** | `front_featured_events:block_featured` (`field_promoted = 1`, `teaser_featured` view mode) |
| **Merchandising** | Spotlight badge on cards (`EventMerchandisingPresenter`); hero exclusivity removes lead promoted event from rail |
| **Visibility** | Conditional (`mel_home_show_featured`) |

Evidence: `page--front.html.twig` (lines 48–61); `views.view.front_featured_events.yml` (lines 239–248 promoted filter).

### 3.4 Worth exploring next (Recommended)

| Attribute | Value |
|---|---|
| **Heading** | “Worth exploring next” |
| **Subtitle** | “Events popular with the community right now.” |
| **See all** | `view.upcoming_events.page_events` |
| **View** | `front_recommended_events:block_1` — sorts `field_promoted DESC`, `field_event_start ASC`; **no user-specific arguments** |
| **Merchandising** | Cross-rail dedup; diversity filter |
| **Visibility** | **Authenticated only** + conditional content gate |

Evidence: block config lines 32–38 (`user_role: authenticated`); `views.view.front_recommended_events.yml` (lines 54–79 sorts). **No reference** to `EventRecommendationService` in recommended view pipeline.

**Copy/data mismatch:** Subtitle implies community popularity; query is promoted-upcoming sort, not `PopularEventsService`.

### 3.5 New this week

| Attribute | Value |
|---|---|
| **Heading** | “New this week” |
| **Subtitle** | “Recently added experiences on MyEventLane” |
| **See all** | `view.upcoming_events.page_events` |
| **View** | `upcoming_events:homepage_latest` (12 items, upcoming filters) |
| **Merchandising** | Cross-rail dedup; diversity filter |
| **Visibility** | Region truthiness only — **no content gate** |

Evidence: `page--front.html.twig` (lines 79–91).

**Copy/query mismatch:** Display inherits default sorts (`field_promoted DESC`, `field_event_start ASC` from view default) and has **no `created` date filter**. Marketing copy claims “recently added”; query returns upcoming events by promotion/start order.

### 3.6 Happening tonight

| Attribute | Value |
|---|---|
| **Heading** | “Happening tonight” |
| **Subtitle** | “Don’t miss these events starting soon” |
| **See all** | `view.upcoming_events.page_today` |
| **View** | `upcoming_events:homepage_tonight` (6 items) |
| **Merchandising** | Tonight urgency label on cards when source is `homepage_tonight` (`EventMerchandisingPresenter`); dedup |
| **Visibility** | Conditional (`mel_home_show_tonight`) |

Evidence: `myeventlane_views.module` (lines 55–61) constrains start to `[start_of_today, start_of_tomorrow)` in site timezone.

### 3.7 Hidden Gems Near You

| Attribute | Value |
|---|---|
| **Heading** | “Hidden Gems Near You” |
| **Subtitle** | “The Guide has found a few experiences worth discovering.” (Explorer Guide tone) |
| **See all** | `view.upcoming_events.page_hidden_gems` |
| **View** | `upcoming_events:homepage_hidden_gems` — `field_hidden_gem = 1`, upcoming |
| **Merchandising** | Hidden Gem badge (`EventMerchandisingPresenter`); dedup |
| **Visibility** | Conditional (`mel_home_show_hidden_gems`) |

Evidence: `views.view.upcoming_events.yml` (lines 1083–1094 hidden gem filter). **No geo filter** confirmed on this display — “Near You” is copy, not a proven location query.

### 3.8 Community Favourites

| Attribute | Value |
|---|---|
| **Heading** | “Community Favourites” |
| **Subtitle** | “Experiences people are joining — from real tickets and RSVPs this week.” |
| **See all** | `view.upcoming_events.page_popular` |
| **Block** | `myeventlane_popular_events_block` (7-day lookback, limit 8) |
| **Service** | `PopularEventsService` — score = `(tickets_sold × 3) + (rsvps × 1)` |
| **Merchandising** | Excludes hero, spotlight, discover, tonight, hidden gems NIDs; diversity filter |
| **Visibility** | Conditional — uses post-dedup candidate pool |

Evidence: `PopularEventsBlock.php`; `PopularEventsService.php` (lines 18–19); `HomepageMerchandising.php` (lines 377–394 exclusions).

### 3.9 Easy ways to join in (Free & RSVP)

| Attribute | Value |
|---|---|
| **Heading** | “Easy ways to join in” |
| **Subtitle** | “Community gatherings with no cost to join” |
| **See all** | `view.upcoming_events.page_free` |
| **View** | `mel_home_events:under_20` — `field_product_target` empty (free/RSVP) |
| **Merchandising** | Dedup after CF, hidden gems, tonight, etc. |
| **Visibility** | Conditional (`mel_home_show_free_rsvp`) |

Evidence: `views.view.mel_home_events.yml` `under_20` (lines 1173–1230, block_description documents free/RSVP semantics).

### 3.10 Nearby / Online

Regions and section shells exist in `page--front.html.twig` (lines 154–179) but **no blocks are placed** in `config/sync`. `mel_home_events:near_you` display exists but is not wired to the homepage.

### 3.11 Guides for better nights out (Blog)

| Attribute | Value |
|---|---|
| **Heading** | “Guides for better nights out” |
| **Subtitle** | “Lightweight ideas for discovering, hosting, and growing events.” |
| **See all** | `/blog` |
| **View** | `mel_blog:homepage_preview` (3 posts) |
| **Visibility** | Conditional on published blog posts |

### 3.12 Host CTA

| Attribute | Value |
|---|---|
| **Heading** | “Empowering event creators everywhere.” |
| **Subtitle** | “Create a free RSVP or ticketed event…” |
| **Source** | Default `mel-host-cta-default.html.twig` when `homepage_host_cta` region empty |
| **Visibility** | Always (fallback) |

---

## 4. Content availability audit

| Rail | Classification | Evidence |
|---|---|---|
| Hero | **High confidence populated** | Block always placed; static hero copy + search always render; rotator optional |
| Discover events | **Medium confidence** | General upcoming pool with chip filters; depends on catalogue size |
| Community spotlight | **Medium confidence** | Requires `field_promoted = 1` + `BoostedEventQualityGate` marketplace-ready (`HomepageMerchandising`) |
| Worth exploring next | **Medium confidence (auth)** | Promoted upcoming events; hidden for anonymous; dedup shrinks pool |
| New this week | **High confidence populated** | Broad upcoming filter (no created window); section shell **always** renders even if view empty |
| Happening tonight | **Medium confidence** | Calendar-day window (`myeventlane_views.module`); sparse in early hours or low-density markets |
| Hidden Gems | **High risk empty** | Requires editorial `field_hidden_gem = 1`; dedup after 5 higher rails; `block_hide_empty: true` |
| Community Favourites | **High risk empty** | Requires 7-day ticket/RSVP engagement; `hasCommunityFavouritesEvents()` uses post-dedup pool — all popular events may already appear above |
| Easy ways to join in | **Medium confidence** | Free/RSVP subset; dedup after many higher rails |
| Nearby / Online | **N/A (unplaced)** | No blocks configured |
| Blog | **Medium confidence** | Gated on `blog_post` count |
| Host CTA | **Always populated** | Static fallback template |

---

## 5. Personalisation audit

| Rail | Personalised? | Service / mechanism | Anonymous behaviour | Fallback |
|---|---|---|---|---|
| Hero search | **Input-personalised** | User-entered query/location in form | Same UI | Browse all events URL |
| Hero category pills | No | Taxonomy links via `myeventlane_category_pills` | Same | Hidden if pills block fails |
| Discover chips | **Filter-personalised** | Exposed View filters (`start_filter`, `type_filter`, `trending_filter`) via URL query | Same filters available | Section hidden if no default rows |
| Community spotlight | No | Promoted editorial sort | Same | Section hidden |
| Worth exploring next | **Auth-gated only** — **not** recommendation-engine personalised | `front_recommended_events:block_1` promoted sort; **not** `EventRecommendationService` | **Rail not placed in block output** (block visibility) | Hidden when empty after dedup |
| New this week | No | Upcoming + promoted sort | Same | Empty state inside shell |
| Happening tonight | No | Calendar-day query | Same | Section hidden |
| Hidden Gems | No | Editorial flag | Same | Section hidden |
| Community Favourites | No | Global 7-day popularity | Same | Section hidden |
| Free & RSVP | No | Product-target empty filter | Same | Section hidden |

`EventRecommendationService` (`web/modules/custom/myeventlane_core/src/Service/EventRecommendationService.php`) powers related-event surfaces elsewhere; **no repository evidence** links it to homepage recommended rail.

---

## 6. Discovery strategy classification

Legend: ● = primary role, ○ = secondary, — = minimal

| Rail | Discovery (“What could I discover?”) | Social proof (“What do others like?”) | Personal relevance | Utility (“What is happening soon?”) |
|---|---|---|---|---|
| Hero | ● | — | ○ (search/location) | ○ |
| Discover events | ● | — | ○ (filters) | ○ |
| Community spotlight | ○ | ○ (editorial promoted) | — | — |
| Worth exploring next | ○ | ○ (copy claims community; data is promoted) | ○ (auth-only surface) | — |
| New this week | ○ | — | — | ○ (freshness intent; sort mismatch) |
| Happening tonight | ○ | — | — | ● |
| Hidden Gems Near You | ● | — | — | ○ |
| Community Favourites | ○ | ● | — | ○ |
| Easy ways to join in | ○ | — | — | ○ (accessibility) |
| Blog | ○ | — | — | — |
| Host CTA | — | — | — | — (host conversion) |

---

## 7. MEL brand alignment

Reference: `docs/brand/homepage-system.md`, `docs/brand/mel-brand-system-v1.md`, `docs/brand/guide-character-system.md`.

### Strongly supports brand

| Rail | Alignment evidence |
|---|---|
| **Hero** | Discovery headline (“What could you discover this weekend?”); no exclusivity language (`myeventlane-home-hero.html.twig`) |
| **Hidden Gems** | Brand differentiator; Explorer Guide subtitle; `Hidden Gem` badge in `EventMerchandisingPresenter.php` |
| **Community Favourites** | Community-first social proof from real tickets/RSVPs; subtitle transparent about data source |
| **Discover events** | Discovery-first wayfinding; chip filters match “browse by mood/moment” |
| **Easy ways to join in** | Inclusive, no-cost framing; aligns with belonging pillar |

### Neutral

| Rail | Notes |
|---|---|
| **Community spotlight** | Valid editorial surface; `Spotlight` badge replaces legacy “Featured”; competes with Hidden Gem priority if placed too high |
| **New this week** | Freshness signal intent good; query/copy alignment weak |
| **Blog** | Lower priority inspiration/SEO per brand canon — correctly low placement |
| **Host CTA** | Host Guide territory; appropriate below discovery rails |

### Dilutes or conflicts with brand

| Rail | Issue | Evidence |
|---|---|---|
| **Worth exploring next** | Occupies #3 but is auth-gated; subtitle implies community popularity while query is promoted sort; Curator Guide archetype expects true recommendations (`guide-character-system.md`) | Block auth visibility; `front_recommended_events` sorts |
| **Rail order overall** | Brand canon: Tonight (#2) → Hidden Gems (#3) → Browse (#4). Runtime: Recommended (#3), Latest (#4), Tonight (#5), Hidden Gems (#6) | `homepage-system.md` lines 48–58 vs `page--front.html.twig` |
| **Hidden Gems “Near You”** | Copy implies local relevance; no geo filter on view — may over-promise locality | `views.view.upcoming_events.yml` `homepage_hidden_gems` filters |
| **New this week copy** | “Recently added” vs promoted/start sort | `homepage_latest` display inherits default sorts |

### Guide placement

Approved max **two guide moments** on homepage (`homepage-system.md` lines 91–100). Current explicit Guide copy: Hidden Gems subtitle only. Hero is discovery-oriented without Guide illustration. **Within brand limits.**

---

## 8. Current order (runtime)

From `page--front.html.twig` render sequence:

| # | Rail | Gated? |
|---|---|---|
| 0 | Hero | Block placed |
| 1 | Discover events | `mel_home_show_discover` |
| 2 | Community spotlight | `mel_home_show_featured` |
| 3 | Worth exploring next | `mel_home_show_recommended` (+ auth block visibility) |
| 4 | New this week | Region only |
| 5 | Happening tonight | `mel_home_show_tonight` |
| 6 | Hidden Gems Near You | `mel_home_show_hidden_gems` |
| 7 | Community Favourites | `mel_home_show_community_favourites` |
| 8 | Easy ways to join in | `mel_home_show_free_rsvp` |
| 9 | Nearby events | Region only (unplaced) |
| 10 | Online events | Region only (unplaced) |
| 11 | Guides (blog) | `mel_home_show_blog` |
| 12 | Host CTA | Always (default fallback) |
| 13 | Newsletter | Region only (unplaced) |

---

## 9. Proposed order (Phase 4 — template only, not implemented)

Aligned to `docs/brand/homepage-system.md` mobile-canonical priority while preserving merchandising cascade semantics (dedup order in `HomepageMerchandising::getExclusionNids()` is **independent** of template order — reordering rails does **not** automatically reorder dedup priority; see risks).

| # | Rail | Change from current |
|---|---|---|
| 0 | Hero | — |
| 1 | Happening tonight | ↑ from #5 |
| 2 | Hidden Gems Near You | ↑ from #6 |
| 3 | Discover events | ↓ from #1 |
| 4 | Community spotlight | ↓ from #2 |
| 5 | Community Favourites | ↓ from #7 |
| 6 | New this week | ↓ from #4 |
| 7 | Easy ways to join in | ↓ from #8 |
| 8 | Worth exploring next | ↓ from #3 |
| 9 | Guides (blog) | — |
| 10 | Host CTA | — |
| 11+ | Nearby / Online / Newsletter | When blocks placed |

### Movement rationale

| Movement | Why | User benefit | Risk |
|---|---|---|---|
| **Tonight → #1** | Brand P0: “events happening soon”; utility for same-day decisions | Immediate “what’s on today” above fold after hero | Merchandising dedup cascade unchanged — tonight NIDs still computed before hidden gems in service layer; visual order improves but dedup may still strip tonight from lower rails |
| **Hidden Gems → #2** | Brand differentiator (`homepage-system.md` #3 priority) | Surfaces discovery promise early | Empty rail more visible if editorial pool thin; mitigated by existing visibility gate |
| **Discover → #3** | Wayfinding after emotional discovery hooks | Category/chip exploration when user is primed | Slightly later filter access for power users |
| **Community spotlight → #4** | Maps to Editor’s Pick / Curator trust rail | Editorial quality after user-led discovery | Promoted content lower — may reduce spotlight impressions |
| **Community Favourites → #5** | Social proof after brand rails, before freshness | Real engagement signal at natural trust point | Dedup logic in `HomepageMerchandising` still treats CF as after hidden gems in **code** — template move alone may show duplicates unless Phase 4 includes dedup cascade review (out of scope for template-only) |
| **Recommended → #8** | Auth-gated, not true personalisation; copy overlap with CF | Reduces anonymous “missing rail” gap perception; puts authenticated extras lower | Logged-in users see recommended later |
| **Latest / Free** | Freshness and access rails appropriately mid-page | Maintains utility without crowding brand rails | “New this week” shell still ungated |

**Critical note for Phase 4 planning:** `HomepageMerchandising` dedup cascade order is **hard-coded** by display match keys (e.g. CF exclusions reference discover, tonight, hidden gems — lines 149–194). **Template reorder alone does not reorder dedup priority.** A separate Phase 4 task may be needed to align merchandising cascade with visual order if duplicate suppression should follow new priority.

---

## 10. Risk assessment

| Risk | Severity | Evidence | Mitigation (Phase 4+) |
|---|---|---|---|
| Template reorder without merchandising cascade update | **High** | `HomepageMerchandising.php` exclusion map fixed by display, not template order | Update cascade in dedicated change; test cross-rail dedup |
| Hidden Gems / CF empty after dedup | **Medium** | `hasCommunityFavouritesEvents()` post-dedup; hidden gems editorial flag | Monitor visibility gates; editorial seeding |
| Recommended rail copy vs data | **Medium** | Promoted sort vs “popular with community” subtitle | Copy change (separate from order) or wire true recommendations |
| New this week copy vs query | **Medium** | No `created` sort/filter on `homepage_latest` | Fix view or revise copy — separate task |
| Latest section always shows shell | **Low** | No `mel_home_show_latest` gate | Add visibility gate in preprocess |
| Nearby/Online dormant regions | **Low** | No block config | Place blocks when geo/online product ready |
| Auth-only recommended high placement | **Medium** | Block visibility + position #3 | Move down in proposed order |
| Anonymous users never see recommended | **By design** | Block `user_role: authenticated` | Confirm product intent before exposing |

---

## 11. Comparison to brand canonical order

| Brand priority (`homepage-system.md`) | Brand section | Current runtime # | Proposed # |
|---|---|---|---|
| 1 | Hero | 0 | 0 |
| 2 | Tonight / This week near you | 5 | 1 |
| 3 | Hidden Gems | 6 | 2 |
| 4 | Browse by category | 1 (Discover) | 3 |
| 5 | Editor’s Pick / Curator | 2 (Community spotlight) | 4 |
| 6 | Community Favourites | 7 | 5 |
| 7 | Just Added | 4 (New this week) | 6 |
| 8 | Blog | 11 | 9 |

**Verdict:** Current order **does not** support approved discovery strategy on priority rails #2–#3 (Tonight, Hidden Gems). Discover-first at #1 is defensible for wayfinding but conflicts with brand doc ordering. Phase 4 template reorder recommended; merchandising cascade alignment should be scoped explicitly.

---

## 12. Files referenced

| Path | Role |
|---|---|
| `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` | Rail order, headings, subtitles |
| `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` | `mel_home_show_*` preprocess |
| `web/modules/custom/myeventlane_front/src/Service/HomepageSectionVisibility.php` | Content visibility gates |
| `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandising.php` | Cross-rail dedup cascade |
| `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandisingQueryAlter.php` | View query exclusions |
| `web/modules/custom/myeventlane_front/src/Service/HomepageRailDiversityFilter.php` | Category/venue/organiser diversity |
| `web/modules/custom/myeventlane_front/src/Plugin/Block/PopularEventsBlock.php` | Community Favourites render |
| `web/modules/custom/myeventlane_front/src/Plugin/Block/HomeHeroBlock.php` | Hero render |
| `web/modules/custom/myeventlane_front/src/Service/FeaturedEventsRenderBuilder.php` | Featured / hero rotator |
| `web/modules/custom/myeventlane_analytics/src/Service/PopularEventsService.php` | Popularity scoring |
| `web/modules/custom/myeventlane_views/myeventlane_views.module` | Tonight calendar-day window |
| `web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php` | Spotlight / Hidden Gem badges |
| `config/sync/block.block.*.yml` | Block → region placement |
| `config/sync/views.view.*.yml` | Query definitions |
| `docs/brand/homepage-system.md` | Approved hierarchy |
| `docs/brand/mel-brand-system-v1.md` | Brand pillars |
| `docs/brand/guide-character-system.md` | Guide placement rules |

---

## 13. Validation

```bash
git status --short   # expected: only new audit file (before commit)
git diff --stat      # expected: homepage-rail-architecture-audit.md added only
```

**Stop point:** Phase 3 complete. Do **not** implement rail reordering until Phase 4 approval.
