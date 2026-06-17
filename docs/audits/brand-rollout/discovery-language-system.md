# Discovery Language System Audit

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)  
**Phase:** 1A — Discovery Language Rollout (audit only)  
**Audit date:** 2026-06-17  
**Branch:** `feature/homepage-copy-phase-1a` @ `1d74ad246`  
**Method:** Repository evidence only. No runtime code changed by this document.

---

## 1. Safety check

| Check | Result |
|---|---|
| `git status --short` | **Clean** (no modified or untracked files) |
| Rollback point | `1d74ad246` — *Config: export theme and maintenance settings* |

---

## 2. Repository evidence gate

### Active themes

| Role | Theme | Evidence |
|---|---|---|
| **Public default** | `myeventlane_theme` | `config/sync/system.theme.yml` → `default: myeventlane_theme` |
| **Vendor console** | `myeventlane_vendor_theme` | `web/modules/custom/myeventlane_vendor/src/Theme/VendorThemeNegotiator.php` — applies to `myeventlane_vendor.*` routes and `/vendor/*` paths; **excludes** public routes such as `myeventlane_vendor.public_list` |
| **Admin** | `gin` | `config/sync/system.theme.yml` → `admin: gin` |

**Conclusion:** All public discovery surfaces audited here are owned by **`myeventlane_theme`** and/or module templates rendered inside that theme. Vendor theme is **out of scope** for Phase 1A.

### Template and preprocess ownership (public discovery)

| Surface | Owner file | Preprocess / builder | Consumer |
|---|---|---|---|
| Homepage layout + rail headings | `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` | `myeventlane_theme_preprocess_page()` L1940–1981 in `myeventlane_theme.theme` | Front page (`<front>`) |
| Homepage hero | `web/modules/custom/myeventlane_front/templates/myeventlane-home-hero.html.twig` | `HomeHeroBlock` (`myeventlane_home_hero`) + `FeaturedEventsRenderBuilder` | `homepage_hero` region |
| Hero fallback (inactive when block placed) | `web/themes/custom/myeventlane_theme/components/hero/hero.twig` | `page--front.html.twig` L18–25 fallback | Only when `homepage_hero` region empty |
| Browse page shell (H1, lede, quicklinks) | `web/themes/custom/myeventlane_theme/templates/includes/mel-browse-events-page-shell.html.twig` | Route-specific `page--view--upcoming-events--page-*.html.twig` pass variables | Browse displays |
| Browse results + `/events` empty | `web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events--page-events.html.twig` | View `upcoming_events:page_events` | `/events` |
| Intent browse empties | `web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events.html.twig` | `mel_empty_copy` map L42–63 | today, weekend, free, popular, hidden-gems |
| Category browse empty | `web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events--page-category.html.twig` L71–85 | `myeventlane_theme_preprocess_page__events__category()` | `/events/category/{slug}` |
| Search results + empty | `web/modules/custom/myeventlane_search/templates/myeventlane-search-results.html.twig` | `SearchController::build()` | `/search` |
| Search page shell | `web/themes/custom/myeventlane_theme/templates/page--search.html.twig` | Theme page suggestion | `/search` |
| Shared view empty default | `web/themes/custom/myeventlane_theme/templates/includes/mel-view-empty-events.html.twig` | Included by view templates | Homepage embeds, mel_home_events |
| Shared browse/search recovery | `web/themes/custom/myeventlane_theme/templates/includes/mel-browse-empty-recovery.html.twig` | Included by browse + search templates | Browse + search empty states |
| Section chrome (structure only) | `web/themes/custom/myeventlane_theme/components/layout/mel-section-shell.html.twig` | Headings passed from `page--front.html.twig` | Homepage rails |
| Event card badges | `web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php` | Consumed by `mel-event-card.html.twig` | All public event cards |

**Ownership confirmed** for all Phase 1A copy targets. No guessing required.

---

## 3. Routes (public discovery)

Front page is **`/home`** (`config/sync/system.site.yml` → `page.front: /home`), not a dedicated controller — it is a **region + block + View** composition.

### Homepage

| Route / entry | Path | Controller / builder | Primary template |
|---|---|---|---|
| `<front>` | `/home` | Drupal front page (block regions) | `page--front.html.twig` |
| Hero block | (region `homepage_hero`) | `HomeHeroBlock::build()` | `myeventlane-home-hero.html.twig` |
| Homepage rails | (regions `home_*`, `homepage_*`) | Views blocks + `PopularEventsBlock` | `page--front.html.twig` + per-view unformatted templates |

### Browse (`upcoming_events` View — `config/sync/views.view.upcoming_events.yml`)

| Route name | Path | Display | Controller / builder | Page template | View template |
|---|---|---|---|---|---|
| `view.upcoming_events.page_events` | `/events` | `page_events` | Views page display | `page--view--upcoming-events--page-events.html.twig` → browse shell | `views-view--upcoming-events--page-events.html.twig` |
| `view.upcoming_events.page_category` | `/events/category/%` | `page_category` | Views page display | `page--events--category.html.twig` | `views-view--upcoming-events--page-category.html.twig` |
| `view.upcoming_events.page_today` | `/events/today` | `page_today` | Views page display | `page--view--upcoming-events--page-today.html.twig` | `views-view--upcoming-events.html.twig` |
| `view.upcoming_events.page_this_weekend` | `/events/this-weekend` | `page_this_weekend` | Views page display | `page--view--upcoming-events--page-this-weekend.html.twig` | `views-view--upcoming-events.html.twig` |
| `view.upcoming_events.page_free` | `/events/free` | `page_free` | Views page display + `PublicEventDiscoveryQueryAlter` | `page--view--upcoming-events--page-free.html.twig` | `views-view--upcoming-events.html.twig` |
| `view.upcoming_events.page_popular` | `/events/popular` | `page_popular` | Views page display + `HomepageMerchandisingQueryAlter` | `page--view--upcoming-events--page-popular.html.twig` | `views-view--upcoming-events.html.twig` |
| `view.upcoming_events.page_hidden_gems` | `/events/hidden-gems` | `page_hidden_gems` | Views page display | `page--view--upcoming-events--page-hidden-gems.html.twig` | `views-view--upcoming-events.html.twig` |

**Supporting (adjacent discovery, not primary Phase 1A copy slice):**

| Route | Path | Builder | Template |
|---|---|---|---|
| `view.events_calendar.page_1` | `/calendar` | Views page display | `page--calendar.html.twig` |
| `myeventlane_core.event_filter` | `/mel/filter-events` | `EventFilterController::filter()` (AJAX) | Fragment only |
| `mel_search.autocomplete` | `/search/autocomplete` | `SearchAutocompleteController::autocomplete()` | JSON |

### Search

| Route | Path | Controller | Template |
|---|---|---|---|
| `mel_search.view` | `/search` | `SearchController::build()` | `myeventlane-search-results.html.twig` inside `page--search.html.twig` |

### Discovery rails (homepage blocks — config evidence)

| Rail (UI label) | Region | Block config | Plugin / View display |
|---|---|---|---|
| Hero | `homepage_hero` | `block.block.myeventlane_theme_homeheromyeventlane.yml` | `myeventlane_home_hero` |
| Discover (chips) | `home_discover` | `block.block.myeventlane_theme_views_block__mel_home_events_discover.yml` | `mel_home_events:embed_discover` |
| Community spotlight | `homepage_featured` | `block.block.front_featured_events.yml` | `front_featured_events:block_featured` |
| Recommended | `home_recommended` | `block.block.myeventlane_theme_views_block__front_recommended_events_block_1.yml` | `front_recommended_events:block_1` (**authenticated only**) |
| New this week | `homepage_latest` | `block.block.myeventlane_theme_homepage_latest.yml` | `upcoming_events:homepage_latest` |
| Happening tonight | `homepage_tonight` | `block.block.myeventlane_theme_homepage_tonight.yml` | `upcoming_events:homepage_tonight` |
| Hidden Gems | `homepage_hidden_gems` | `block.block.myeventlane_theme_homepage_hidden_gems.yml` | `upcoming_events:homepage_hidden_gems` |
| Community Favourites | `homepage_community_favourites` | `block.block.myeventlane_theme_homepage_community_favourites.yml` | `myeventlane_popular_events_block` |
| Free & RSVP | `homepage_free` | `block.block.myeventlane_theme_homepage_free.yml` | `mel_home_events:under_20` |
| Blog | `homepage_blog` | `block.block.myeventlane_theme_homepage_blog.yml` | `mel_blog:homepage_preview` |

---

## 4. Homepage ownership

### Hero source

| Layer | File / service | Role |
|---|---|---|
| Block plugin | `web/modules/custom/myeventlane_front/src/Plugin/Block/HomeHeroBlock.php` | Builds `#theme => myeventlane_home_hero` |
| Template (copy owner) | `web/modules/custom/myeventlane_front/templates/myeventlane-home-hero.html.twig` | H1, sub-line, search placeholders, CTAs |
| Hero rotator content | `FeaturedEventsRenderBuilder` via `front_featured_events:block_hero` | Community spotlight slides in hero art |
| Visual assets | `PageVisualResolver` (`myeventlane_page_visuals`) | Optional hero image |
| Fallback | `components/hero/hero.twig` | Used only if `homepage_hero` region empty |

### Homepage rail source and consumer

| Rail | Data / logic owner | Copy owner | Rendered in |
|---|---|---|---|
| Discover chips | View `mel_home_events:embed_discover` + `HomepageMerchandising` dedup | `page--front.html.twig` L33–45 | `home_discover` region |
| Community spotlight | View `front_featured_events:block_featured` + `HomepageMerchandising::getFeaturedBlockEventIds()` | `page--front.html.twig` L48–61 | `homepage_featured` region |
| Recommended | View `front_recommended_events:block_1` + `HomepageMerchandising::getRecommendedEventIds()` | `page--front.html.twig` L64–76 | `home_recommended` region |
| New this week | View `upcoming_events:homepage_latest` | `page--front.html.twig` L79–91 | `homepage_latest` region |
| Happening tonight | View `upcoming_events:homepage_tonight` | `page--front.html.twig` L94–106 | `homepage_tonight` region |
| Hidden Gems | View `upcoming_events:homepage_hidden_gems` + `HomepageMerchandising::getHiddenGemEventIds()` | `page--front.html.twig` L109–121 | `homepage_hidden_gems` region |
| Community Favourites | `PopularEventsBlock` + `HomepageMerchandisingQueryAlter` | `page--front.html.twig` L124–136 | `homepage_community_favourites` region |
| Free & RSVP | View `mel_home_events:under_20` | `page--front.html.twig` L139–151 | `homepage_free` region |

**Visibility gating** (does not change copy; controls whether section shell renders):

- Service: `web/modules/custom/myeventlane_front/src/Service/HomepageSectionVisibility.php`
- Injected in: `myeventlane_theme_preprocess_page()` → `mel_home_show_*` variables

### Current homepage rail order (template-driven)

Order is fixed in `page--front.html.twig` — **not** block weight across regions:

1. Discover events  
2. Community spotlight  
3. Worth exploring next (recommended)  
4. New this week  
5. Happening tonight  
6. Hidden Gems Near You  
7. Community Favourites  
8. Easy ways to join in  
9. Nearby events (only if region has blocks)  
10. Online events (only if region has blocks)  
11. Guides for better nights out  
12. Host CTA → Newsletter  

**Phase 3 candidate:** reorder `{% if mel_home_show_* %}` blocks only — repository confirms template ownership.

### Hidden Gems source

| Layer | Evidence |
|---|---|
| Editorial flag | `field_hidden_gem` on event nodes (`myeventlane_event.permissions.yml`) |
| Homepage rail | View display `upcoming_events:homepage_hidden_gems` |
| Browse page | Route `view.upcoming_events.page_hidden_gems` → `/events/hidden-gems` |
| Card badge | `EventMerchandisingPresenter` → `'Hidden Gem'` (singular on card) |
| Search zero-result fallback | `SearchController` → `FeaturedEventsRenderBuilder::buildHiddenGemsDiscoveryFallback()` |

### Community Favourites source

| Layer | Evidence |
|---|---|
| Homepage rail | `PopularEventsBlock` (`myeventlane_popular_events_block`) in `homepage_community_favourites` |
| Ranking logic | `HomepageMerchandisingQueryAlter::applyCommunityFavouritesBrowseRanking()` |
| Dedup pool | `HomepageMerchandising::getCommunityFavouritesExcludeIds()` |
| Browse page | Route `view.upcoming_events.page_popular` → `/events/popular` (UI label Community Favourites) |

---

## 5. Empty state ownership

### `mel-view-empty-events`

| Attribute | Detail |
|---|---|
| **Twig** | `web/themes/custom/myeventlane_theme/templates/includes/mel-view-empty-events.html.twig` |
| **Preprocess** | None — defaults in include |
| **Service** | None — presentation only |
| **Default copy** | Title: *Nothing here yet*; text: *Try a category, browse this weekend, or explore community spotlight on the homepage.*; CTAs: *Explore events* / *Back to discovery* |
| **Consumers** | `views-view--mel-home-events.html.twig`, `views-view--mel-home-events--tonight.html.twig`, `views-view--mel-home-events--under_20.html.twig`, `views-view-unformatted--mel-home-events--discover.html.twig`, `views-view-unformatted--upcoming-events--homepage-tonight.html.twig` (with overrides) |

### `mel-browse-empty-recovery`

| Attribute | Detail |
|---|---|
| **Twig** | `web/themes/custom/myeventlane_theme/templates/includes/mel-browse-empty-recovery.html.twig` |
| **Preprocess** | None |
| **Service** | `GovernedOperationalTemplates` documents aligned recovery routes (not copy owner) |
| **Recovery link labels** | *Browse all events*, *This weekend*, *Free events*, *Hidden Gems*, *Community spotlight* |
| **Consumers** | `/events` empty (`views-view--upcoming-events--page-events.html.twig`), intent browse empties (`views-view--upcoming-events.html.twig`), search zero-results (`myeventlane-search-results.html.twig`) |

### Search empty state

| Attribute | Detail |
|---|---|
| **Twig** | `web/modules/custom/myeventlane_search/templates/myeventlane-search-results.html.twig` L9–34 |
| **Builder** | `SearchController::build()` |
| **No-query copy** | *Enter a search term to find events, vendors, venues, pages, and categories.* |
| **Zero-results copy** | Title: *No results found*; text: *No results for '@query'. Try another keyword or explore these paths instead.* + `mel-browse-empty-recovery` |
| **Fallback rails** | *Discover events* (featured fallback), *Hidden Gems* (hidden gems fallback), *Suggested categories* |

### Browse empty states (by display)

| Surface | Template | Title / text (current) |
|---|---|---|
| `/events` | `views-view--upcoming-events--page-events.html.twig` | *No events found* / *Try a different filter, browse categories, or explore community spotlight on the homepage.* |
| `/events/today` | `views-view--upcoming-events.html.twig` | *Nothing on today yet* / *Browse upcoming events across MyEventLane — new listings land throughout the day.* |
| `/events/this-weekend` | same | *Nothing this weekend yet* / *Check back soon or explore all upcoming events and free gatherings.* |
| `/events/free` | same | *No free events right now* / *Try browsing all events or see what's on this weekend.* |
| `/events/popular` | same | *No Community Favourites yet* / *When people start joining events this week, the most popular experiences will show up here.* |
| `/events/hidden-gems` | same | *No Hidden Gems right now* / *Check back soon or browse all upcoming events.* |
| `/events/category/{slug}` | `views-view--upcoming-events--page-category.html.twig` | *No events in this category* / *Check back soon, browse other categories, or explore community spotlight on the homepage.* |
| Homepage tonight embed | `views-view-unformatted--upcoming-events--homepage-tonight.html.twig` | *Nothing listed for tonight yet* / *Explore what's on this weekend or browse all upcoming events.* |

**Phase 5 requirement:** each empty state needs (1) what happened, (2) why, (3) what to do next — several current strings are functional but thin on *why*.

---

## 6. Discovery language inventory

Scope: **public discovery surfaces only** (homepage, browse, search, discovery empty states). Excludes vendor console, checkout, RSVP, admin, email (noted where grep surfaced adjacent usage).

### 6.1 Primary terms

| Term | File | Surface | Usage |
|---|---|---|---|
| **Discover** | `myeventlane-home-hero.html.twig` L23 | Homepage hero | Sub-line: *Discover more of your city. Unexpected experiences. Real community.* |
| **Discover** | `page--front.html.twig` L39–40 | Homepage rail | Heading *Discover events*; subtitle *Discover more of your city — filter by mood or moment* |
| **Discover** | `mel-browse-events-page-shell.html.twig` L25 | Browse default | Default lede: *Discover workshops, gigs, markets…* |
| **Discover** | `page--events--category.html.twig` L43 | Category browse | *Discover events in @name* |
| **Discover** | `myeventlane-search-results.html.twig` L25 | Search empty fallback | Group title *Discover events* |
| **Discover** | `mel-calendar-hero.html.twig` L25 | Calendar (adjacent) | *Discover what's happening near you this month.* |
| **Discover** | `PublicFooterNavigationBuilder.php` L99 | Footer (adjacent) | Column title *Discover events* |
| **Discovery** | `mel-view-empty-events.html.twig` L15 | View empty default | CTA *Back to discovery* |
| **Discovery** | `TrustContentFoundation.php` L109 | Legacy about (out of scope) | Bullet *Discovery — browse events by date…* |
| **Hidden Gem** | `EventMerchandisingPresenter.php` L122 | Event cards | Singular card badge label |
| **Hidden Gems** | `page--front.html.twig` L115–118 | Homepage rail | *Hidden Gems Near You*; *See all Hidden Gems* |
| **Hidden Gems** | `page--view--upcoming-events--page-hidden-gems.html.twig` L10 | Browse | H1 *Hidden Gems* |
| **Hidden Gems** | `mel-browse-events-page-shell.html.twig` L37 | Browse quicklinks | Chip label |
| **Hidden Gems** | `mel-browse-empty-recovery.html.twig` L25 | Empty recovery | Recovery link |
| **Hidden Gems** | `myeventlane-search-results.html.twig` L31 | Search empty fallback | Group title |
| **Hidden Gems** | `views-view--upcoming-events.html.twig` L60 | Browse empty | *No Hidden Gems right now* |
| **Community Favourite** | — | Event cards | **Not present** in `EventMerchandisingPresenter` |
| **Community Favourites** | `page--front.html.twig` L130–133 | Homepage rail | Heading + *See all Community Favourites* |
| **Community Favourites** | `page--view--upcoming-events--page-popular.html.twig` L10 | Browse | H1 |
| **Community Favourites** | `mel-browse-events-page-shell.html.twig` L36 | Browse quicklinks | Chip label |
| **Community Favourites** | `views-view--upcoming-events.html.twig` L56 | Browse empty | *No Community Favourites yet* |
| **Community Favourites** | `block.block.myeventlane_theme_homepage_community_favourites.yml` L18 | Config label | Block admin label only |
| **Spotlight** | `EventMerchandisingPresenter.php` L119 | Event cards | Promoted-event badge (replaces legacy *Featured* on cards) |
| **Spotlight** | `page--front.html.twig` L54 | Homepage rail | Section title *Community spotlight* (editorial rail, not card badge) |
| **Spotlight** | `mel-browse-empty-recovery.html.twig` L27 | Empty recovery | Link *Community spotlight* → homepage |
| **Spotlight** | `myeventlane-home-hero.html.twig` L87 | Homepage hero | `aria-label` *Community spotlight highlights* |
| **Featured** | `components/event-type-pill.html.twig` L36 | Event type pill | Pill value *Featured* (taxonomy/type, not discovery rail) |
| **Featured** | `featured-events.twig` | Component docblock | Internal component name only |
| **Recommended** | `page--front.html.twig` L70–71 | Homepage rail | *Worth exploring next* / *Events popular with the community right now.* (recommended rail; not the word *Recommended* in heading) |
| **Recommended** | `views-view-unformatted--front-recommended-events--block-1.html.twig` | Homepage embed | Template comment only |
| **Recommended** | `DiscoverySurfaceAnalyticsService.php` L43 | Analytics labels | Internal surface label *Recommended* |
| **Browse** | `mel-browse-events-page-shell.html.twig` L31–37 | Browse | Quicklink nav *Browse events*; chips *Browse all*, etc. |
| **Browse** | `page--front.html.twig` L88, L162 | Homepage | Link text *Browse all events*, *Browse events* |
| **Browse** | `views-view--upcoming-events--page-events.html.twig` L43–45 | Browse empty | Recovery copy uses *browse* |
| **Browse** | `mel-browse-empty-recovery.html.twig` L19 | Empty recovery | *Browse all events* link |
| **Browse** | `page--search.html.twig` L16 | Search | Page header title *Search results* (not Browse) |
| **Experience** | `myeventlane-home-hero.html.twig` L21 | Homepage hero | H1 *Find your next favourite **experience**.* |
| **Experience** | `hero.twig` L32 (fallback) | Hero fallback | Same H1 (inactive when block placed) |
| **Experience** | `mel-browse-events-page-shell.html.twig` L24 | Browse default H1 | Default *Find your next favourite experience.* |
| **Experiences** | `page--front.html.twig` L55, L86, L116, L131 | Homepage rails | Subtitles use *experiences* (spotlight, latest, hidden gems, community favourites) |
| **Experiences** | `page--view--upcoming-events--page-popular.html.twig` L11 | Browse | *Experiences people are joining…* |
| **Experiences** | `page--view--upcoming-events--page-hidden-gems.html.twig` L11 | Browse | *High-value local experiences…* |
| **Experiences** | `page--view--upcoming-events--page-free.html.twig` L11 | Browse | *Community experiences with no ticket cost…* |
| **Experiences** | `views-view--upcoming-events.html.twig` L57 | Browse empty | *most popular experiences* |

### 6.2 Terms to avoid (brand guide) — current violations on public discovery

| Avoid | Found on public discovery? | Evidence |
|---|---|---|
| **Experiences** (as primary noun) | **Yes — multiple** | Hero H1, browse default H1, homepage rail subtitles, browse ledes (§6.1) |
| **Adventures** | No | — |
| **Happenings** | No | — |
| **Things to do** | No | — |

### 6.3 Guide voice (approved merchandising)

| Phrase | File | Surface |
|---|---|---|
| *The Guide has found a few experiences worth discovering.* | `page--front.html.twig` L116 | Hidden Gems rail subtitle |
| *Community spotlight* / editorial framing | `page--front.html.twig` L54–55 | Featured rail |

---

## 7. Copy vs brand direction — gaps (Phase 2–5 input)

| Gap | Current evidence | Brand target | Phase |
|---|---|---|---|
| Hero uses *experience* not *discover* | `myeventlane-home-hero.html.twig` L21 | Discovery-first; primary *Discover*, secondary *Events* | 2 |
| Browse default H1 mirrors hero *experience* | `mel-browse-events-page-shell.html.twig` L24 | Align browse H1 to discovery language | 4 (browse headings) |
| *Experiences* in rail subtitles | `page--front.html.twig` L55, L86, L116, L131 | Prefer *events* / community framing | 4 |
| Blog rail nightlife tone | `page--front.html.twig` L187 | *Guides for better nights out* → discovery/community voice | 4 |
| Recommended rail not Guide/Curator voice | L70–71 *Worth exploring next* | Guide-assisted discovery (copy only) | 4 |
| Rail order: editorial before location/gems | Template order §4 | Discovery-first hierarchy per `docs/brand/homepage-system.md` | 3 |
| Empty states lack explicit *why* | §5 table | Three-part structure (what / why / next) | 5 |
| Card badge *Spotlight* vs brand *Editor's Pick* | `EventMerchandisingPresenter` L119 | Label re-voice only if approved (PHP string) | Out of plan scope unless explicitly approved |
| *Community Favourite* card badge | Not in presenter | Would require logic — **out of scope** | — |

---

## 8. What must not change (confirmed boundaries)

- `HomepageSectionVisibility` / `HomepageMerchandising` query and dedup logic  
- View filters, Search API index, access checks, cache metadata  
- Block plugin IDs, region placement, authenticated-only recommended visibility  
- Commerce, checkout, RSVP, cart, pricing  
- SCSS, design tokens, layout/components  
- No new routes, services, controllers, or plugins for Phase 1A  

---

## 9. Phase 1 deliverable status

| Requirement | Status |
|---|---|
| Routes documented | ✅ §3 |
| Homepage ownership documented | ✅ §4 |
| Empty state ownership documented | ✅ §5 |
| Discovery language inventory | ✅ §6 |
| Repository evidence gate | ✅ §2 |
| Runtime code modified | **No** |

**Phase 1 complete. Stop here.**

### Suggested validation (audit-only; no code changes)

```bash
git status --short
git diff --stat
```

### Manual surfaces for Phases 2–5

- `/home`
- `/events`
- `/search`
- `/events?keys=zzzznoresult` (if exposed filter accepts `search` param — verify on site)
- `/search?q=zzzznoresult`

---

## 10. Related audits

- `docs/audits/brand-rollout/homepage-audit.md`
- `docs/audits/brand-rollout/discovery-audit.md`
- `docs/audits/brand-rollout/phase-1a-implementation-plan.md`
- `docs/audits/discovery-route-ownership-map.md`
- Brand authority: `docs/brand/copy-guidelines.md`, `docs/brand/homepage-system.md`, `docs/brand/guide-character-system.md`
