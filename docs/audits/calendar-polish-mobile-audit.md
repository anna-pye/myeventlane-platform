# Calendar Polish & Mobile Validation — Phase 4 Audit

**Date:** 2026-06-19  
**Branch:** `feature/calendar-polish-mobile` (from `feature/discovery-route-separation`)  
**Scope:** Calendar presentation polish, CSS ownership, empty-state safety, mobile QA, discovery route visual consistency.

---

## Config status (pre-change)

```text
ddev drush cst
```

| Config | State |
|--------|-------|
| `myeventlane_page_visuals.page_visual.calendar_hero` | Different (active vs sync) |
| `myeventlane_page_visuals.page_visual.search_page_hero` | Different (active vs sync) |

**Phase 4 requires no config export.** Changes are theme Twig + preprocess only.

---

## Repository evidence gate

| Class / hook | Usage count (theme + docs) | Route / consumer |
|--------------|---------------------------|------------------|
| `.mel-calendar-page` | 1 Twig owner, 12 SCSS rules | `/calendar` only (`mel-calendar-page-content.html.twig`) |
| `.mel-calendar-card` | 1 Twig, 2 SCSS (calendar + discovery margin) | `/calendar` only |
| `.mel-content--calendar` | 1 Twig, 1 SCSS | `/calendar` FullCalendar mount |
| `calendar_events` | **Removed** (was 1 Twig gate + preprocess) | Was parallel entity query; not view source |
| `views_view_fullcalendar` | 1 preprocess hook | `events_calendar` view only |
| `fullcalendar_view` | View style plugin + module enablement | `events_calendar.page_calendar` |

**Multi-consumer check:** No shared template/class has conflicting consumers. `.mel-calendar` (add-to-calendar on event nodes) is a separate component (`components/_mel-calendar.scss`).

---

## Ownership matrix

| Item | Owner | Consumer | Route | Purpose |
|------|-------|----------|-------|---------|
| Calendar page template | `page--calendar.html.twig` | Drupal page suggestion | `view.events_calendar.page_calendar` | Discovery shell entry |
| Discovery shell | `mel-discovery-page-shell.html.twig` | 9 discovery page templates | All discovery routes | Hero → context filters → content |
| Calendar body | `mel-calendar-page-content.html.twig` | `page--calendar.html.twig` | `/calendar` | Card + `page.content` + optional rail |
| Calendar hero | Page Visual `calendar_hero` via `mel_discovery_hero` | Discovery shell | `/calendar` | Full-bleed hero (no search/chips) |
| FullCalendar output | `events_calendar` view + `fullcalendar_view_display` | `{{ page.content }}` | `/calendar` | Canonical event data |
| FullCalendar colours | `myeventlane_theme_preprocess_views_view_fullcalendar()` | JS drupalSettings | `/calendar` | Category palette |
| “Upcoming this week” rail | `_myeventlane_theme_build_calendar_this_week_rail()` | `mel-calendar-page-content.html.twig` | `/calendar` | Secondary embed view |
| Calendar SCSS | `src/scss/pages/_calendar.scss` | Imported in `main.scss` | `/calendar` (scoped `.mel-calendar-page`) | Toolbar, cells, events, rail |

---

## Phase A — Calendar styling ownership

### Finding

After discovery shell rollout, runtime DOM was:

```text
.mel-page.mel-page--discovery
  └── .mel-discovery-content
        └── .mel-calendar-card   ← no .mel-calendar-page ancestor
```

FullCalendar presentation rules in `_calendar.scss` are scoped to `.mel-calendar-page`. Without that wrapper, toolbar buttons, today cell, event bars, and overflow guards did not apply.

### Fix (smallest diff)

Wrap calendar body content in `.mel-calendar-page` in `mel-calendar-page-content.html.twig`. Reuses existing SCSS; no duplicate rules.

### Post-fix verification

| Check | Result |
|-------|--------|
| `.mel-calendar-page` in HTML | Present on `/calendar` |
| `.fc-toolbar` styled (44px targets, MEL today pill) | Pass (desktop + 390px browser QA) |
| `.mel-page--discovery` retained | Pass — shell unchanged |

---

## Phase B — Empty state safety

### Finding

`mel-calendar-page-content.html.twig` gated rendering on `calendar_events is empty`, sourced from a **separate entity query** in `preprocess_page__calendar()`. Canonical calendar data is the `events_calendar` view via `page.content`.

Risk: empty-state branch could hide a valid FullCalendar render array if the parallel query returned no rows while the view still had results (different filters: view excludes ended/cancelled/archived; preprocess did not).

### Fix

1. Always render `{{ page.content }}` inside `.mel-calendar-card`.
2. Remove `calendar_events` entity query from `preprocess_page__calendar()` — eliminates duplicate query.
3. Retain `mel_calendar_this_week_rail` (uses existing `upcoming_events:embed_calendar_this_week` view).

Empty-state Twig (`.mel-empty--calendar`) and SCSS remain in repo for future view-empty integration; no longer used as a page-level gate.

---

## Phase C & D — Mobile / discovery route QA

**Environment:** `https://myeventlane.ddev.site` (DDEV). Browser QA run as authenticated admin (admin toolbar present — noted in caveats).

**Screenshots:** `docs/audits/calendar-polish-mobile/screenshots/`

| Route | Viewport | Pass/Fail | Issue | Owner |
|-------|----------|-----------|-------|-------|
| `/calendar` | 390px | Pass | None in `.mel-main` / calendar card | `_calendar.scss` + content template |
| `/calendar` | 768px | Pass | FC toolbar wraps; no card overflow | `_calendar.scss` |
| `/calendar` | 1024px | Pass | — | — |
| `/calendar` | 1440px | Pass | Hero + FC toolbar + list view visible | Discovery shell + view |
| `/events` | 390px | Pass | Date chips + hero search; no discovery overflow | Shell allowlist |
| `/events/category/music` | HTML | Pass | Empty context filters; category nav in hero | Phase 3D allowlist |
| `/events/popular` | HTML | Pass | Date chips only | Shell allowlist |
| `/events/hidden-gems` | HTML | Pass | Date chips only | Shell allowlist |
| `/search` | HTML | Pass | Empty context filters; hero search in discovery hero | `_myeventlane_theme_discovery_shows_hero_search()` |
| `/help` | HTML | Pass | Empty context filters; help search in content template | `help-centre-home.html.twig` |
| `/vendors` | HTML | Pass | Empty context filters; no event search/chips | Phase 3D allowlist |

### Discovery layout consistency (Phase D)

| Route | Observed stack | Expected | Match |
|-------|----------------|----------|-------|
| `/events` | Hero → date chips → sidebar filters → cards | Hero → Date chips → Filters → Cards | Pass |
| `/events/category/music` | Hero (category nav) → cards | Hero → Category nav → Cards | Pass |
| `/events/popular` | Hero → date chips → cards | Hero → Date chips → Cards | Pass |
| `/search` | Hero (search) → results | Hero → Results | Pass |
| `/help` | Hero → help search → content | Hero → Help search → Content | Pass |
| `/vendors` | Hero → directory | Hero → Directory | Pass |
| `/calendar` | Hero → calendar card | Hero → Calendar | Pass |

No cross-route control contamination detected (calendar/help/vendors/search have empty `.mel-discovery-context-filters`).

---

## Validation commands

| Command | Result |
|---------|--------|
| `git status` | 2 theme files modified |
| `ddev drush cr` | Success |
| `ddev drush cst` | 2 pre-existing Page Visual diffs (unchanged by this phase) |
| `npm run build` | Success |
| `npm run mel:lint` | Success |
| `vendor/bin/phpstan` | Pre-existing: memory limit 128M crash |
| `vendor/bin/phpcs` | Pre-existing: 73 errors / 111 warnings on `.theme` file |

---

## Files changed

| File | Change |
|------|--------|
| `templates/includes/mel-calendar-page-content.html.twig` | Add `.mel-calendar-page` wrapper; always render view via `page.content`; remove `calendar_events` empty gate |
| `myeventlane_theme.theme` | Remove duplicate `calendar_events` entity query from `preprocess_page__calendar()` |

---

## Residual risk

- **Config drift:** Active `calendar_hero` / `search_page_hero` Page Visuals differ from sync — unrelated to this phase; export separately if intentional.
- **Anonymous QA:** Browser screenshots include admin toolbar; re-run QA logged out for production sign-off.
- **Empty UX:** Custom “No events scheduled yet” CTA removed with unsafe gate; FullCalendar empty grid is canonical until view-empty template is wired.
