# Discovery Hero Ownership Report — Phase 3A Stabilisation

**Date:** 2026-06-19 (Phase 3B update)  
**Branch context:** `feature/unify-discovery-heroes`  
**Scope:** Single Page Visuals chain for all public discovery surfaces.

---

## Phase 3D — route separation (2026-06-19)

**Branch:** `feature/discovery-route-separation`

### Route ownership matrix

| Route | Page template | Shell | Hero source | Search | Category nav | Date chips | Content |
|-------|---------------|-------|-------------|--------|--------------|------------|---------|
| `/` | `page--front.html.twig` | `page.html.twig` (homepage) | HomeHeroBlock / Page Visual `home_hero` | Home hero block | HomeHeroBlock | — | Region blocks |
| `/events` | `page--events.html.twig` → `mel-browse-events-page-shell` | `mel-discovery-page-shell` | Page Visual `events_hero` | Discovery hero (events) | — | Shell (date chips) | View `page_events` + sidebar filters |
| `/events/category/*` | `page--events--category.html.twig` | `mel-discovery-page-shell` | `field_category_image` → Page Visual fallback | Discovery hero (events) | Discovery hero bar | — | View `page_category` cards |
| `/events/popular` | `page--view--upcoming-events--page-popular.html.twig` | `mel-discovery-page-shell` | Page Visual `community_hero` | Discovery hero (events) | — | Shell (date chips) | View `page_popular` cards |
| `/events/hidden-gems` | `page--view--upcoming-events--page-hidden-gems.html.twig` | `mel-discovery-page-shell` | Page Visual `hidden_gems_hero` | Discovery hero (events) | — | Shell (date chips) | View `page_hidden_gems` cards |
| `/calendar` | `page--calendar.html.twig` | `mel-discovery-page-shell` | Page Visual `calendar_hero` | — | — | — | `mel-calendar-page-content` + FullCalendar view |
| `/search` | `page--search.html.twig` | `mel-discovery-page-shell` | Page Visual `search_page_hero` | Discovery hero (site `/search?q=`) | — | — | `myeventlane-search-results` |
| `/help` | `help/page--help.html.twig` | `mel-discovery-page-shell` | Page Visual `help_hero` | Help content search only | — | — | `help-centre-home.html.twig` |
| `/vendors` | `page--vendors.html.twig` | `mel-discovery-page-shell` | Page Visual `vendors_hero` | — | — | — | `myeventlane-vendor-list` |

### Contamination root cause

`_myeventlane_theme_discovery_shows_context_filters()` used a **denylist** (help + vendors only). Search, calendar, and category routes still received date chips. `$show_search` only excluded help — vendors and calendar inherited event search with Near me field.

### Phase 3D fix

- **Allowlist** date chips: `_myeventlane_theme_discovery_date_filter_routes()` — events, popular, hidden-gems, today, weekend, free only.
- **Allowlist** hero search: `_myeventlane_theme_discovery_shows_hero_search()` — site search on `/search`; events search on browse/category chip routes; off on help, vendors, calendar.
- Shell Twig default: `mel_discovery_show_context_filters|default(false)` (fail closed).

### Consumer counts (repository)

| Asset | Owner | Consumers |
|-------|-------|-----------|
| `discovery-hero.html.twig` | `mel_discovery_hero` theme hook | `mel-discovery-page-shell` (12 discovery routes) |
| `mel-discovery-page-shell.html.twig` | Theme include | 9 page templates + `mel-browse-events-page-shell` |
| Category pills in hero | `discovery-hero.html.twig` | Category routes only (`variant == category`) |
| `mel-events-discovery-filters.html.twig` | Shell + view fallback | Shell (allowlisted routes); view when shell flag unset |
| Event sidebar filters | `views-view--upcoming-events--page-events.html.twig` | `/events` only |
| Help search | `help-centre-home.html.twig` | `/help` only |
| Site search form | `_discovery-hero-search.html.twig` | `/search` hero + event browse heroes |

---

## Phase 3E — Calendar ownership audit (2026-06-19)

**Branch:** `feature/discovery-route-separation`  
**Scope:** Audit only — prove where `/calendar` FullCalendar output is owned and whether the discovery shell drops it.

### Ownership matrix

| Item | Owner |
|------|-------|
| **Route** | `view.events_calendar.page_calendar` → path `/calendar` |
| **Controller** | `Drupal\views\Routing\ViewPageController::handle` |
| **View** | `events_calendar` (`config/sync/views.view.events_calendar.yml`) |
| **Display ID** | `page_calendar` (plugin: `page`) |
| **Style plugin** | `fullcalendar_view_display` (module: `fullcalendar_view`) |
| **Page template** | `page--calendar.html.twig` (suggested via `myeventlane_theme_theme_suggestions_page_alter()`) |
| **Shell include chain** | `page--calendar.html.twig` → `mel-discovery-page-shell.html.twig` → `mel-calendar-page-content.html.twig` |
| **Region** | Drupal `content` region → Twig `page.content` |
| **FullCalendar theme hook** | `views_view_fullcalendar` → contrib `views-view-fullcalendar.html.twig` |
| **JS mount selector** | `.js-drupal-fullcalendar` (grid injected by `fullcalendar_view.js`) |
| **Event data (runtime)** | `drupalSettings.fullCalendarView[0].calendar_options` (41 events on staging) |
| **Parallel preprocess data** | `calendar_events` in `preprocess_page__calendar()` — separate entity query; not the view source |

### Render array path

```text
ViewPageController → #type=view (#name=events_calendar, #display_id=page_calendar)
  → fullcalendar_view_display → views_view_fullcalendar → .js-drupal-fullcalendar
page preprocess → mel_discovery_hero, page_visual
preprocess_page__calendar → calendar_events, mel_calendar_this_week_rail
preprocess_views_view (events_calendar) → unset title only
preprocess_views_view_fullcalendar → MEL colours in drupalSettings
Twig: page--calendar → mel-discovery-page-shell:37 include → mel-calendar-page-content:19 {{ page.content }}
```

### Finding: render array is not lost

| Check | Result |
|-------|--------|
| HTML source | View markup present inside `.mel-content--calendar` |
| Server HTML `.fc-view` | Absent (expected — client-side hydration) |
| Browser after JS | `fcView: true`, 41 events in drupalSettings |
| Shell drop risk | Line 37 uses `{% include discovery_content_template %}` — correct |

**False “empty calendar” causes:** JS mount div empty in source HTML; screenshot/snapshot before JS init.

### CSS / presentation (not render loss)

FullCalendar SCSS in `_calendar.scss` is scoped to `.mel-calendar-page`; discovery shell uses `.mel-page--discovery`. Styling may be incomplete; calendar still renders after JS.

### Twig empty-state gate risk

`mel-calendar-page-content.html.twig` shows empty state when `calendar_events is empty` — bypasses `page.content` even if view has results. Not active on staging (67 `calendar_events`).

**Stop:** Ownership proven. No shell/template line drops the render array. Next work targets JS hydration visibility, CSS scope, and/or empty-state gate logic — not shell removal.


All listed surfaces resolve hero images via **Page Visuals Manager** only:

| Surface | Route | Page Visual entity |
|---------|-------|-------------------|
| Home | `system.front_page` / `view.frontpage.page_1` | `home_hero` |
| Events | `view.upcoming_events.page_events` | `events_hero` |
| Calendar | `view.events_calendar.page_calendar` | `calendar_hero` |
| Search | `mel_search.view` | `search_page_hero` |
| Community | `view.upcoming_events.page_popular` | `community_hero` |
| Hidden Gems | `view.upcoming_events.page_hidden_gems` | `hidden_gems_hero` |
| Help | `myeventlane_help_centre.home` | `help_hero` |
| Vendors | `myeventlane_vendor.public_list` | `vendors_hero` |

**Global fallback:** `default_hero` (`route_name: default`) when no route-specific visual exists.

**Removed runtime fallback chains:**
- Theme PNG paths (`mel-hero-events.png`, `mel-category-*.png`, etc.)
- HomeHeroBlock theme JPG fallback and featured-events rotator as image source
- Hero Settings form (deprecated in UI; not used at runtime)

**Single exception (content-owned, not admin duplicate):** `field_category_image` on category taxonomy terms overrides Page Visual on `/events/category/*`.

**Central resolver:** `_myeventlane_theme_apply_page_visual_hero()` in `myeventlane_theme.theme`.

---

## Executive summary

| Question | Answer |
|----------|--------|
| **Canonical hero image system (recommended)** | **Page Visuals Manager** (`myeventlane_page_visuals`) |
| **Hero Settings status** | Admin UI exists; **not wired to runtime** — do not remove until Phase 3B migration |
| **Discovery layout system** | Unified `mel-home-hero` via `mel_discovery_hero` theme hook + `mel-discovery-page-shell.html.twig` |
| **Category-specific images** | Taxonomy `field_category_image` → theme PNG → Page Visual (category route only) |

---

## 1. Hero image systems inventory

### 1.1 Page Visuals Manager — **recommended canonical**

| Item | Detail |
|------|--------|
| **Module** | `web/modules/custom/myeventlane_page_visuals/` |
| **Entity** | `myeventlane_page_visual` (config entity) |
| **Resolver** | `PageVisualResolver::resolveForRoute()` |
| **Admin** | Page Visuals collection (Structure → Page Visuals) |
| **Injection** | `myeventlane_page_visuals_preprocess_page()` → `$variables['page_visual']` |
| **Consumers** | Discovery preprocess (`myeventlane_theme.theme`), `HomeHeroBlock` |
| **Features** | Route-scoped, Media-first (desktop + mobile), alt text, hide-on-mobile, global `default` fallback |
| **Staging entities** | Homepage, Events, Search, Calendar (4 enabled) |

**Resolution order (resolver):**

1. Enabled visual matching exact `route_name`
2. Section fallback (not implemented)
3. Enabled visual with `route_name = default`
4. `null`

### 1.2 Hero Settings — **legacy / not active at runtime**

| Item | Detail |
|------|--------|
| **Module** | `web/modules/custom/myeventlane_theme_settings/` |
| **Admin** | `/admin/appearance/myeventlane/hero-settings` (`HeroSettingsForm`) |
| **Config** | `myeventlane_theme_settings.settings` |
| **Keys** | `hero_default`, `hero_events`, `hero_calendar`, `hero_category`, `hero_search` (file fids) |
| **Runtime wiring** | **None** — no `.module` preprocess, no theme consumption confirmed in repo |
| **Staging state** | Only `hero_search=101` set; others empty |
| **Also in module** | `CategoryVisualsCommands` (Drush pill colour sync — unrelated to hero images) |

**Verdict:** Hero Settings is a parallel admin surface created for discovery heroes but never connected to `myeventlane_theme.theme` or discovery builder. **Do not delete until Phase 3B** maps any uploaded fids into Page Visuals or documents explicit deprecation.

### 1.3 Theme static assets (fallback)

| Item | Detail |
|------|--------|
| **Homepage** | `_myeventlane_front_home_hero_theme_urls()` → `images/mel/hero/mel-hero-home-community.jpg`, `mel-hero-home.png` |
| **Discovery listing** | `_myeventlane_theme_get_listing_hero_url()` → `images/mel/hero/mel-hero-events.png`, `mel-hero-search.png`, `images/mel/categories/mel-category-{slug}.png` |
| **Repo state** | Homepage community JPG exists; category PNG pack **not present** in theme tree |

### 1.4 Taxonomy category images

| Item | Detail |
|------|--------|
| **Field** | `field.field.taxonomy_term.categories.field_category_image` |
| **Resolver** | `_myeventlane_theme_get_listing_hero_url()` on `view.upcoming_events.page_category` |
| **Order** | Term image (file must exist on disk) → theme PNG → Page Visual |
| **Note** | Staging DB references files that may 404 on disk; resolver now skips missing files |

### 1.5 Homepage featured-events rotator (homepage only)

| Item | Detail |
|------|--------|
| **Block** | `HomeHeroBlock` (`myeventlane_front`) |
| **Fallback** | When no Page Visual and no theme asset: `FeaturedEventsRenderBuilder::buildHeroRotator()` |
| **Scope** | Front page block only — not discovery routes |

---

## 2. Hero layout / presentation systems (not image ownership)

These render heroes but should not own image configuration long-term:

| System | Template / hook | Status | Notes |
|--------|---------------|--------|-------|
| **Homepage hero block** | `myeventlane-home-hero.html.twig` | Active (homepage) | Uses Page Visuals + theme fallback + rotator |
| **Discovery hero** | `discovery-hero.html.twig` + `mel_discovery_hero` | Active (discovery routes) | Reuses `mel-home-hero` classes |
| **Legacy page header** | `mel-page-header.html.twig` | Slimmed; default variant only | Still present — do not delete (Phase 3A) |
| **Legacy calendar hero** | `mel-calendar-hero.html.twig` | **Deleted in discovery unification** | Replaced by discovery hero |
| **Taxonomy term page** | `taxonomy-term.html.twig` | May still reference old header patterns | Out of discovery shell scope |

---

## 3. Page Visuals vs Hero Settings — overlap matrix

| Route / context | Page Visuals | Hero Settings key | Theme PNG fallback | Category term image |
|-----------------|-------------|-------------------|--------------------|---------------------|
| `/` (homepage) | ✅ `view.frontpage.page_1` | `hero_default` (unused) | ✅ community JPG | — |
| `/events` | ✅ configured | `hero_events` (unused) | `mel-hero-events.png` | — |
| `/calendar` | ✅ configured | `hero_calendar` (unused) | — | — |
| `/search` | ✅ configured | `hero_search` (fid 101, **unused**) | `mel-hero-search.png` | — |
| `/events/category/*` | ❌ not configured | `hero_category` (unused) | `mel-category-{slug}.png` | ✅ primary |
| `/events/popular` | ❌ not configured | — | — | — |
| `/events/hidden-gems` | ❌ not configured | — | — | — |
| `/vendors` | ❌ not in rollout | — | — | — |
| `/help` | ❌ not in rollout | — | — | — |

**Duplication risk:** Editors could upload the same hero twice (Page Visual Media entity + Hero Settings managed file) with only Page Visuals taking effect today.

---

## 4. Recommended canonical system

### Primary: **Page Visuals Manager**

**Why:**

- Already powers homepage (`HomeHeroBlock`) and discovery preprocess
- Media-first (aligned with MEL architecture)
- Route-scoped with explicit admin UX and cache tags
- Supports mobile variant and hide-on-mobile
- Has `default` global fallback entity type

### Secondary fallbacks (keep, do not admin-duplicate):

1. **Category term** `field_category_image` (category routes only — content-owned)
2. **Theme-packaged PNG/JPG** (developer fallback when no CMS config)
3. **Homepage event rotator** (homepage-only dynamic fallback)

### Deprecate (Phase 3B+, after migration proof):

- **Hero Settings** admin form — migrate any fids to Page Visual entities, then mark read-only, then remove in a later phase
- **Parallel theme PNG paths** — keep as code fallback but document as dev-only

### Do not delete in Phase 3A:

- `HeroSettingsForm`, config schema, menu link
- `mel-page-header.html.twig`, `_mel-page-header.scss`
- `_myeventlane_theme_get_listing_hero_url()` (category + fallback chain)
- `HomeHeroBlock`, `myeventlane-home-hero.html.twig`

---

## 5. Discovery hero rollout plan (Phase 3B+)

### Phase 3A — live (this pass)

| Route | Route name | Shell | Category nav in hero |
|-------|-----------|-------|----------------------|
| `/events` | `view.upcoming_events.page_events` | ✅ | ✅ |
| `/events/category/*` | `view.upcoming_events.page_category` | ✅ | ❌ hidden |
| `/events/popular` | `view.upcoming_events.page_popular` | ✅ | ✅ |
| `/events/hidden-gems` | `view.upcoming_events.page_hidden_gems` | ✅ | ✅ |
| `/search` | `mel_search.view` | ✅ | ✅ |
| `/calendar` | `view.events_calendar.page_calendar` | ✅ | ✅ |

### Phase 3B — planned additions

| Route | Route name | Page Visual slot | Notes |
|-------|-----------|------------------|-------|
| **`/vendors`** | `myeventlane_vendor.public_list` | Add Page Visual entity | Curated route added to `PageVisualForm` |
| **`/help`** | `myeventlane_help_centre.home` | Add Page Visual entity | Help Centre landing; sub-routes optional later |
| `/help/index` | `myeventlane_help_centre.public_index` | Optional alias visual | Same creative as `/help` or section fallback |
| `/events/popular` | `view.upcoming_events.page_popular` | Create Page Visual | Currently placeholder hero |
| `/events/hidden-gems` | `view.upcoming_events.page_hidden_gems` | Create Page Visual | Currently placeholder hero |

### Phase 3B implementation checklist (not started)

1. Add routes to `_myeventlane_theme_discovery_routes()` for `/vendors` and `/help`
2. Create page templates or route-based shell inclusion
3. Add discovery copy in `_myeventlane_theme_discovery_copy_for_route()`
4. Create Page Visual entities in staging/production
5. Wire Hero Settings fids → Page Visuals migration script (optional Drush command)
6. Remove Hero Settings admin after 30-day editor confirmation period

---

## 6. Phase 3A fixes applied

### 6.1 Calendar rendering regression

**Cause:** `page--calendar.html.twig` passed `include(...)` output as a string variable; Twig auto-escaped it in the shell (`{{ page_content }}`), printing raw HTML.

**Fix:** Pass template path `discovery_content_template` to shell; shell uses `{% include discovery_content_template %}` so render arrays and FullCalendar markup execute correctly.

**Files:** `page--calendar.html.twig`, `mel-discovery-page-shell.html.twig`

### 6.2 Category navigation on category pages

**Cause:** Success criteria require no category pill bar when user is already on `/events/category/*`. Drupal omits `FALSE` theme variables; Twig `show_categories|default(true)` re-enabled the bar.

**Fix:**

- PHP: `#show_categories => FALSE` for `view.upcoming_events.page_category`
- Twig: `show_category_nav` gated on `variant != 'category'`

**Files:** `myeventlane_theme.theme`, `discovery-hero.html.twig`

---

## 7. Validation

```bash
ddev drush cr
curl -sk https://myeventlane.ddev.site/calendar | rg 'fullcalendar|fc-view'
curl -sk https://myeventlane.ddev.site/events/category/music | rg 'mel-home-hero__categories-bar'  # expect 0 matches
curl -sk https://myeventlane.ddev.site/events | rg 'mel-home-hero__categories-bar'  # expect matches
```

---

## 8. Residual risk

| Risk | Mitigation |
|------|------------|
| Hero Settings confusion for editors | Document in admin form description; Phase 3B migration |
| Missing category image files on disk | Re-upload term images or add category Page Visual |
| Popular/hidden-gems placeholder heroes | Create Page Visual entities |
| Calendar `<title>` empty prefix | Separate metatag issue — not Phase 3A |
| `/vendors`, `/help` not yet on discovery shell | Phase 3B rollout |

---

## 9. File reference map

| Purpose | Path |
|---------|------|
| Discovery hero Twig | `web/themes/custom/myeventlane_theme/templates/components/discovery-hero/discovery-hero.html.twig` |
| Discovery shell | `web/themes/custom/myeventlane_theme/templates/includes/mel-discovery-page-shell.html.twig` |
| Calendar body | `web/themes/custom/myeventlane_theme/templates/includes/mel-calendar-page-content.html.twig` |
| Discovery builder | `myeventlane_theme.theme` → `_myeventlane_theme_build_discovery_hero()` |
| Image resolver (listing) | `myeventlane_theme.theme` → `_myeventlane_theme_get_listing_hero_url()` |
| Page Visuals resolver | `web/modules/custom/myeventlane_page_visuals/src/Service/PageVisualResolver.php` |
| Page Visuals preprocess | `web/modules/custom/myeventlane_page_visuals/myeventlane_page_visuals.module` |
| Hero Settings form | `web/modules/custom/myeventlane_theme_settings/src/Form/HeroSettingsForm.php` |
| Homepage hero block | `web/modules/custom/myeventlane_front/src/Plugin/Block/HomeHeroBlock.php` |
| Page Visual curated routes | `web/modules/custom/myeventlane_page_visuals/src/Form/PageVisualForm.php` |
