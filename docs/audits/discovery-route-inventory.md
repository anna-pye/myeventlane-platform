# Discovery Route Inventory

**Audit date:** 2026-06-14  
**Branch:** `feature/discovery-route-audit`  
**Scope:** Public discovery routes only (no checkout, booking, or account routes).  
**Method:** Repository evidence from `config/sync`, routing YAML, Twig, and services.

---

## Primary routes (audit focus)

| Route | Path | Owner | Status | Evidence |
|-------|------|-------|--------|----------|
| `view.upcoming_events.page_events` | `/events` | Views display `page_events` on `upcoming_events` | **Active** | `config/sync/views.view.upcoming_events.yml` L1634–1978 (`path: events`); main menu link L1978–1987 |
| `view.upcoming_events.page_popular` | `/events/popular` | Views display `page_popular` on `upcoming_events` | **Active** | `config/sync/views.view.upcoming_events.yml` L2310–2695 (`path: events/popular`); filter `field_promoted_value = 1` L2639–2650 |
| `view.upcoming_events.page_this_weekend` | `/events/this-weekend` | Views display `page_this_weekend` on `upcoming_events` | **Active** | `config/sync/views.view.upcoming_events.yml` L2715–3012 (`path: events/this-weekend`); weekend date filter L2880–2882 |
| `view.upcoming_events.page_today` | `/events/today` | Views display `page_today` on `upcoming_events` | **Active** | `config/sync/views.view.upcoming_events.yml` L3023–3320 (`path: events/today`); today filter `between today/tomorrow` L3186–3190 |
| *(none)* | `/events/nearby` | **No route owner in repository** | **Broken** | No `path:` in `config/sync`; no `.routing.yml` entry; hardcoded links only (see Linked surfaces) |
| *(none)* | `/events/online` | **No route owner in repository** | **Broken** | No `path:` in `config/sync`; no `.routing.yml` entry; hardcoded links only; no `page_online` display |

---

## Additional public discovery routes (`/events/*` and adjacent)

| Route | Path | Owner | Status | Evidence |
|-------|------|-------|--------|----------|
| `view.upcoming_events.page_category` | `/events/category/{slug}` | Views display `page_category` | **Active** | `config/sync/views.view.upcoming_events.yml` L1386–1623 (`path: events/category/%`); canonical URLs via `EventCategoryUrlService` |
| `view.upcoming_events.page_free` | `/events/free` | Views display `page_free` | **Active** | `config/sync/views.view.upcoming_events.yml` L1998–2299 (`path: events/free`); `PublicEventDiscoveryQueryAlter` FREE_RSVP display L76–79 |
| `mel_search.view` | `/search` | `SearchController::build` | **Active** | `web/modules/custom/myeventlane_search/myeventlane_search.routing.yml` L1–7; `_access: 'TRUE'` |
| `view.events_calendar.page_1` | `/calendar` | Views display on `events_calendar` | **Active** | `config/sync/views.view.events_calendar.yml` L302 (`path: calendar`) |
| `myeventlane_core.event_filter` | `/mel/filter-events` | `EventFilterController::filter` (AJAX fragment) | **Active** (supporting) | `web/modules/custom/myeventlane_core/myeventlane_core.routing.yml` L124–129; not a standalone discovery page |
| Legacy flat slug | `/events/{slug}` | Redirect to category when slug matches term | **Redirect** (conditional) | `EventCategoryCanonicalRedirectSubscriber` L85–111; skips reserved slugs L95–97 |
| `view.mel_saved_events.page_1` | `/my-saved-events` | Views display | **Out of scope** | Account/saved list; `config/sync/views.view.mel_saved_events.yml` |

---

## Reserved `/events/*` segments (not category slugs)

From `EventCategoryUrlService::RESERVED_EVENT_PATHS` (`web/modules/custom/myeventlane_core/src/Service/EventCategoryUrlService.php` L26–32):

`category`, `today`, `this-weekend`, `free`, `popular`

**Not reserved:** `nearby`, `online` — but no View route exists for them either.

---

## Homepage block surfaces (not standalone routes)

| Region | View display | Block config | Status | Notes |
|--------|--------------|--------------|--------|-------|
| `homepage_featured` | `front_featured_events:block_featured` | `config/sync/block.block.front_featured_events.yml` | **Active** | Links to `/events` via `page--front.html.twig` L37 |
| `home_discover` | `mel_home_events:embed_discover` | `config/sync/block.block.myeventlane_theme_views_block__mel_home_events_discover.yml` | **Active** | Chip filters; no dedicated `/events/*` route |
| `homepage_tonight` | `upcoming_events:homepage_tonight` | `config/sync/block.block.myeventlane_theme_homepage_tonight.yml` | **Active** | Section link → `page_today` (`page--front.html.twig` L68) |
| `homepage_free` | `mel_home_events:under_20` | `config/sync/block.block.myeventlane_theme_homepage_free.yml` | **Active** | Section link → `page_free` L83 |
| `homepage_latest` | `upcoming_events:homepage_latest` | `config/sync/block.block.myeventlane_theme_homepage_latest.yml` | **Active** | Section link → `page_events` L98 |
| `home_recommended` | `front_recommended_events:block_1` | `config/sync/block.block.myeventlane_theme_views_block__front_recommended_events_block_1.yml` | **Active** | Section link → `page_events` L113 |
| `homepage_nearby` | *(no block in config/sync)* | **None** | **Unused** | Theme region defined (`myeventlane_theme.info.yml` L24); no block placement found |
| `homepage_online` | *(no block in config/sync)* | **None** | **Unused** | Theme region defined (`myeventlane_theme.info.yml` L25); no block placement found |

`mel_home_events:near_you` block display exists in View config (L675–720) with description *"Not geo-filtered yet"* — **no block placement** in `config/sync`.

---

## Linked surfaces referencing discovery paths

| Surface | Routes linked | File |
|---------|---------------|------|
| Homepage section CTAs | `page_events`, `page_today`, `page_free`, hardcoded `/events/nearby`, `/events/online` | `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` |
| Footer Discover column | `page_events`, `page_today`, `page_this_weekend`, `page_free`, `page_popular` + category URLs | `web/modules/custom/myeventlane_front/src/Service/PublicFooterNavigationBuilder.php` L44–51 |
| Search empty state | `page_events`, `<front>` | `web/modules/custom/myeventlane_search/templates/myeventlane-search-results.html.twig` L24–26 |
| Browse empty state | `page_events`, `page_this_weekend`, hardcoded `/events/nearby`, `<front>` | `web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events--page-events.html.twig` L44–51 |
| Category empty state | `page_events`, hardcoded `/events/nearby`, `<front>` | `web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events--page-category.html.twig` L82–86 |
| Date filter chips | `page_events`, `page_today`, `page_this_weekend`, `page_free` | `web/themes/custom/myeventlane_theme/templates/views/includes/mel-events-discovery-filters.html.twig` |
| Browse shell quicklinks | `page_events`, `page_free`, `page_this_weekend`, `page_today` | `web/themes/custom/myeventlane_theme/templates/includes/mel-browse-events-page-shell.html.twig` L26–30 |
| Main menu (View-generated) | `page_events`, `page_popular` | `config/sync/views.view.upcoming_events.yml` menu blocks L1978–1987, L2696–2704 |
| Static footer menu links | All primary discovery routes + blog | `web/modules/custom/myeventlane_core/myeventlane_core.links.menu.yml` L28–56 |
| Help search empty | `page_events` | `web/themes/custom/myeventlane_theme/templates/views/views-view--mel-help-search.html.twig` |
| 404 pages | `page_events` | `web/themes/custom/myeventlane_theme/templates/system/mel-404.html.twig` |

---

## Access and security (read-only audit)

| Route class | Access rule | Notes |
|-------------|-------------|-------|
| `upcoming_events` page displays | `perm: access content` | `config/sync/views.view.upcoming_events.yml` L91–94 |
| `/search` | `_access: 'TRUE'` | Public search |
| `/mel/filter-events` | `_permission: access content` | AJAX only; 400 without `category` query param |
| Category redirects | 301 to canonical slug | `EventCategoryCanonicalRedirectSubscriber`; does not bypass entity access |

No checkout, cart, order, or vendor-console routes included in this inventory.

---

## Status definitions

| Status | Meaning |
|--------|---------|
| **Active** | Route registered in config/routing; View or controller owner confirmed |
| **Broken** | Linked from UI but no route owner in repository |
| **Unused** | Region/display exists but no block placement or navigation link |
| **Duplicate** | Overlaps another discovery path (see conversion/duplication audits) |
| **Unknown** | Cannot confirm reachability without runtime HTTP test |
