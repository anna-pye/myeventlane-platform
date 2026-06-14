# Discovery Route Ownership Map

**Audit date:** 2026-06-14  
**Branch:** `feature/discovery-route-audit`  
**Method:** Trace rendering path from route → display → template → navigation source.

---

## Canonical owner model

All primary `/events/*` listing routes (except broken `/events/nearby` and `/events/online`) are owned by:

- **View:** `upcoming_events` (`config/sync/views.view.upcoming_events.yml`)
- **Route name pattern:** `view.upcoming_events.{display_id}`
- **Query hygiene:** `PublicEventDiscoveryQueryAlter` (`web/modules/custom/myeventlane_event/src/Service/PublicEventDiscoveryQueryAlter.php`)
- **Category URLs:** `EventCategoryUrlService` (`web/modules/custom/myeventlane_core/src/Service/EventCategoryUrlService.php`)
- **Category redirects:** `EventCategoryCanonicalRedirectSubscriber`

---

## Route-by-route rendering path

### `/events` — `view.upcoming_events.page_events`

| Layer | Owner |
|-------|-------|
| **Route / display** | `upcoming_events:page_events` |
| **View** | `config/sync/views.view.upcoming_events.yml` L1634–1978 |
| **Page template** | `page--view--upcoming-events--page-events.html.twig` → `mel-browse-events-page-shell.html.twig` |
| **View template** | `views-view--upcoming-events--page-events.html.twig` (browse layout + exposed filters) |
| **Row style** | `entity:node` / `compact_commerce` |
| **Preprocess** | `myeventlane_theme.theme` — `mel_browse_active_display`, `mel_browse_result_count`, `mel_header_categories` (L1756–1805, L3312–3341) |
| **Navigation sources** | Homepage (multiple sections), footer, search recovery, browse shell quicklinks, main menu, 404, help search |

---

### `/events/popular` — `view.upcoming_events.page_popular`

| Layer | Owner |
|-------|-------|
| **Route / display** | `upcoming_events:page_popular` |
| **View filter** | `field_promoted_value = 1` (boosted/promoted events) L2639–2650 |
| **Page template** | Generic `page.html.twig` (no browse shell — **no** `page--view--*` override) |
| **View template** | `views-view--upcoming-events.html.twig` (shared discovery wrapper + date chips) |
| **Preprocess gap** | `page_popular` **not** in `$browse_display_map` or `$listing_routes` (`myeventlane_theme.theme` L1756–1805) |
| **Navigation sources** | Footer (`PublicFooterNavigationBuilder` L49), static menu links (`myeventlane_core.links.menu.yml` L52–56), main menu (View menu config L2696–2704) |
| **Not linked from** | Homepage section CTAs, discovery filter chips, browse shell quicklinks |

---

### `/events/this-weekend` — `view.upcoming_events.page_this_weekend`

| Layer | Owner |
|-------|-------|
| **Route / display** | `upcoming_events:page_this_weekend` |
| **View filter** | `field_event_start` between `saturday this week` and `sunday this week 23:59:59` L2880–2882 |
| **Page template** | Generic `page.html.twig` |
| **View template** | `views-view--upcoming-events.html.twig` + `mel-events-discovery-filters.html.twig` |
| **Preprocess** | In `$browse_display_map` and chip/category preprocess (L1799, L3284) |
| **Navigation sources** | Footer, filter chips, browse shell quicklinks, empty-state CTAs, homepage tonight secondary CTA |

---

### `/events/today` — `view.upcoming_events.page_today`

| Layer | Owner |
|-------|-------|
| **Route / display** | `upcoming_events:page_today` |
| **View filter** | `field_event_start` `between today` and `tomorrow` L3186–3190; also `>= now` L3204–3231 |
| **Page template** | Generic `page.html.twig` |
| **View template** | `views-view--upcoming-events.html.twig` + filter chips |
| **AJAX reuse** | `EventFilterController` type `now` → `page_today` display L87–91 |
| **Navigation sources** | Footer, filter chips, browse shell, homepage "Happening tonight" → `page_today` L68 |

---

### `/events/nearby` — **no route**

| Layer | Owner |
|-------|-------|
| **Route** | **I cannot confirm this from the repository.** |
| **Closest data surface** | `mel_home_events:near_you` block display (not geo-filtered; no block placement) |
| **Hardcoded link sources** | `page--front.html.twig` L127; `views-view--upcoming-events--page-events.html.twig` L49; `views-view--upcoming-events--page-category.html.twig` L84; default empty copy in `mel-view-empty-events.html.twig` L11 |
| **Expected runtime** | No matching route in sync config → likely **404** unless path alias or taxonomy slug collision (not evidenced in config) |

---

### `/events/online` — **no route**

| Layer | Owner |
|-------|-------|
| **Route** | **I cannot confirm this from the repository.** |
| **Data model gap** | `field_event_type` allowed values: `rsvp`, `paid`, `both`, `external` only (`config/sync/field.storage.node.field_event_type.yml`) — no `online` value |
| **Hardcoded link sources** | `page--front.html.twig` L141; demo menu URI in `myeventlane_core.install` L451 |
| **Expected runtime** | No matching route → likely **404** |

---

### `/events/free` — `view.upcoming_events.page_free`

| Layer | Owner |
|-------|-------|
| **Route / display** | `upcoming_events:page_free` |
| **View filter** | `field_product_target` empty L2220–2230 + `PublicEventDiscoveryQueryAlter::applyFreeRsvpExclusion` |
| **Page template** | Generic `page.html.twig` |
| **View template** | `views-view--upcoming-events.html.twig`; chip label "Free & RSVP" |
| **AJAX reuse** | `EventFilterController` type `free` L99–103 |
| **Navigation sources** | Footer, filter chips, browse shell, homepage free section |

---

### `/events/category/{slug}` — `view.upcoming_events.page_category`

| Layer | Owner |
|-------|-------|
| **Route / display** | `upcoming_events:page_category` |
| **URL builder** | `EventCategoryUrlService::getCategoryUrl()` |
| **Page template** | `page--events--category.html.twig` |
| **View template** | `views-view--upcoming-events--page-category.html.twig` (chip bar + AJAX) |
| **AJAX controller** | `myeventlane_core.event_filter` → `/mel/filter-events` |
| **Navigation sources** | Footer category links, hero category chips, homepage discover, search fallback categories |

---

## Supporting routes (adjacent discovery)

### `/search` — `mel_search.view`

| Layer | Owner |
|-------|-------|
| **Controller** | `Drupal\myeventlane_search\Controller\SearchController::build` |
| **Template** | `myeventlane-search-results.html.twig` |
| **Page template** | `page--search.html.twig` |
| **Backend** | Search API index (referenced in module; not re-audited here) |

### `/calendar` — `view.events_calendar.page_1`

| Layer | Owner |
|-------|-------|
| **View** | `config/sync/views.view.events_calendar.yml` |
| **Page template** | `page--calendar.html.twig` |
| **Navigation** | Calendar hero CTA → `page_events` |

---

## Homepage rail → route mapping

| Homepage section | Visibility gate | Primary CTA route |
|--------------------|-----------------|-------------------|
| Community spotlight | `mel_home_show_featured` | `page_events` |
| Discover events | `mel_home_show_discover` | `page_events` |
| Happening tonight | `mel_home_show_tonight` | `page_today` |
| Easy ways to join in | `mel_home_show_free_rsvp` | `page_free` |
| New this week | `page.homepage_latest` truthy | `page_events` |
| Picked for you | `mel_home_show_recommended` | `page_events` |
| Nearby events | `page.homepage_nearby` truthy (no block placed) | hardcoded `/events/nearby` |
| Online events | `page.homepage_online` truthy (no block placed) | hardcoded `/events/online` |

Visibility logic: `myeventlane_theme.theme` L1931–1959 via `HomepageSectionVisibility` service.

---

## Template coverage summary

| Display | Dedicated page shell | Dedicated view template | Shared discovery chips |
|---------|---------------------|-------------------------|------------------------|
| `page_events` | ✅ browse shell | ✅ | ✅ (in view template) |
| `page_category` | ✅ category page | ✅ | ✅ (chip bar, different UX) |
| `page_today` | ❌ generic page | shared wrapper | ✅ |
| `page_this_weekend` | ❌ generic page | shared wrapper | ✅ |
| `page_free` | ❌ generic page | shared wrapper | ✅ |
| `page_popular` | ❌ generic page | shared wrapper | ✅ (but not in browse shell quicklinks) |
| `/events/nearby` | N/A | N/A | N/A |
| `/events/online` | N/A | N/A | N/A |

---

## Navigation builder ownership

| Builder | Role |
|---------|------|
| `PublicFooterNavigationBuilder` | Single source for public footer Discover column (`web/modules/custom/myeventlane_front/src/Service/PublicFooterNavigationBuilder.php`) |
| View menu config | Main menu entries for `page_events` and `page_popular` only |
| `myeventlane_core.links.menu.yml` | Static deployable footer-find menu links (duplicate of footer builder intent) |
| Twig hardcoded paths | `/events/nearby`, `/events/online`, `/events/this-weekend` in several templates (bypass route system) |
