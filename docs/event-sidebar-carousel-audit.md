# Event sidebar carousel — Phase 1 audit

**Date:** 2026-05-19  
**Branch:** `feature/event-sidebar-carousel`  
**Scope:** Read-only audit before sidebar carousel implementation.

## Phase 0 — Safety gate

| Check | Finding |
|-------|---------|
| Working tree | **Dirty** — branding serialization fix (3 PHP files) + unrelated commerce twig. Exclude from carousel commit. |
| Merge/rebase | None |
| Branch | `feature/event-sidebar-carousel` (from `feature/event-media-cinematic-convergence`) |
| `drush config:status` | DB ≠ sync for event form displays + `crop.type.event_hero` (unchanged from prior phases). |

## Current sidebar structure

**Template:** `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig`

```
aside.mel-event-layout__sidebar
  └── div.mel-card.mel-card--sticky.mel-event__card
        ├── mel-card__header (CTA title)
        └── mel-card__body
              └── div.mel-booking-panel (single stacked column)
                    ├── mode chip, scarcity, signal, price, urgency
                    ├── product render
                    ├── CTA (partial--event-full-booking-cta)
                    ├── trust rotator + decision prompts
                    ├── calendar tabs
                    └── save for later (flag)
```

**No carousel today** — all conversion, trust, calendar, and save content in one scrollable card.

## Data sources (existing only)

| Slide intent | Variables / services |
|--------------|---------------------|
| Booking CTA | `mel_cta_text`, `cta_type`, `event_cta`, `event_ui`, `content.field_product_target`, partial booking CTA |
| Extras preview | `operational_addon_teaser` / `EventOperationalAddonTeaserBuilder::buildForEvent()` |
| Gallery preview | `mel_event_gallery` via `EventMediaPresenter::buildGalleryViewModel()` |
| Social proof | `event_viewer_count`, `event_save_count`, `event_interest` |
| Trust | `mel_show_booking_trust_copy`, trust rotator markup, decision prompts |
| Organiser | `mel_organiser` from `myeventlane_theme_preprocess_node()` |
| Reminders | `google_calendar_url`, `event_flag_event_save` |

## Libraries & JS

| Asset | Role |
|-------|------|
| `myeventlane_theme/myeventlane_event_full` | Trust rotator JS, page styles |
| `myeventlane_theme/mel_event_gallery` | `mel-media-lightbox.js` — dialog per `[data-mel-lightbox-root]` |
| Sticky | `.mel-card--sticky` in `_event-page-themes.scss` (lg+) |

## Mobile behavior

- ≤768px: sidebar card moves **above** main column (`display: contents` + flex order).
- Fixed `.mel-mobile-cta` duplicates primary CTA at viewport bottom.
- In-sidebar sticky CTA on small screens (convergence SCSS set `position: static` recently).

## Cacheability

- Node + per-media tags on gallery (`myeventlane_event_preprocess_node`).
- Viewer count: `max-age: 60` on markup render array.
- Save count: flagging_list tags.
- Extras teaser: product cache tags from commerce builder.

## SCSS hierarchy

1. `_event-full.scss` — booking panel, sticky card, meta bar
2. `_event-page-themes.scss` — Classic / Immersive sidebar surfaces
3. `_event-cinematic-convergence.scss` — column gap, immersive greys

## Gaps (Phase 2+ targets)

- Monolithic booking panel feels like stacked widgets.
- No swipe / keyboard carousel for secondary content.
- Gallery in main column only — sidebar does not tease media.
- Lightbox triggers must live inside same `[data-mel-lightbox-root]` as dialog (extend JS for sidebar → main gallery).
- Trust + social + calendar compete for vertical space with CTA.

## Implementation plan

1. `EventSidebarCarouselBuilder` — slide manifest from existing variables only.
2. Theme hook `mel_event_sidebar_carousel` + slide partials.
3. Refactor sidebar in `node--event--full.html.twig` (no duplicate sidebar).
4. `mel-event-sidebar-carousel.js` — vanilla swipe, arrows, keyboard, reduced motion.
5. Extend `mel-media-lightbox.js` — `data-mel-lightbox-root-ref` for sidebar triggers.
6. `_event-sidebar-carousel.scss` — Classic / Immersive surfaces.
