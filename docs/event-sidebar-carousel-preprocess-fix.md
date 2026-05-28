# Event sidebar carousel preprocess fix

**Date:** 2026-05-21  
**Branch:** `fix/event-full-sidebar-carousel-preprocess`

## Problem

`mel_sidebar_carousel` was built in `myeventlane_theme_preprocess_node__event()` but event canonical pages render with theme hook suggestion `node__event__full` (`node--event--full.html.twig`).

Drupal’s theme registry attaches bundle-specific preprocess only to the matching hook depth:

| Theme hook | `myeventlane_theme_preprocess_node__event` registered? |
|------------|--------------------------------------------------------|
| `node__event` | Yes |
| `node__event__full` | **No** (only `myeventlane_theme_preprocess_node`) |

Verified via `drush php:eval` on `theme.registry` → `node__event__full['preprocess functions']`.

Result: `mel_sidebar_carousel` was never set on full pages; `node--event--full.html.twig` fell through to the static sidebar `{% else %}` branch.

## Where carousel is built

- **PHP:** `_myeventlane_theme_attach_event_sidebar_carousel()` in `myeventlane_theme.theme`
- **Service:** `myeventlane_event.sidebar_carousel_builder` → `EventSidebarCarouselBuilder::buildFromEventPageVariables()`
- **Render:** `#theme` => `mel_event_sidebar_carousel` (`mel-event-sidebar-carousel.html.twig`)

## Where carousel is rendered

- `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig` (sidebar `{% if mel_sidebar_carousel %}`)

## Fallback when carousel missing

Static `mel-card mel-card--sticky` booking panel in the same template (`{% else %}`), including trust rotator, calendar tabs, and save-for-later — Phase 1 monolithic sidebar.

## Trust / CTA duplication

Template already branches:

```twig
{% if mel_sidebar_carousel %}
  {{ mel_sidebar_carousel }}
{% else %}
  … static booking + trust …
{% endif %}
```

When carousel is present, static trust/CTA block is skipped. Mobile sticky CTA (`partial--event-full-booking-cta`, slot `mobile`) remains separate by design (Phase 1). **No Twig change required.**

## Fix

1. Extract carousel attach logic to `_myeventlane_theme_attach_event_sidebar_carousel()`.
2. Call from `myeventlane_theme_preprocess_node__event()` (non-full event templates / `node__event` hook).
3. Add `myeventlane_theme_preprocess_node__event__full()` for full pages.

Cache metadata from the builder and module preprocess (`#cache` on child render arrays, media tags) is unchanged; the helper does not strip or overwrite `#cache`.

## Residual notes (out of scope)

Other variables only set in `preprocess_node__event()` (e.g. `mel_organiser`, `hero_datetime`, calendar URLs) are still absent on `node__event__full` until a broader preprocess alignment is done. Carousel still renders (booking slide minimum); organiser/trust slides in the manifest may be fewer without those vars.
