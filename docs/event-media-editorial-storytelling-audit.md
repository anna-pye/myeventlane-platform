# Event media editorial storytelling — Phase 3 audit

**Date:** 2026-05-19  
**Branch base:** `feature/event-page-style-themes` (Phase 2 gallery committed)  
**Scope:** Read-only audit before Phase 3 edits.

## Phase 0 — Safety gate

| Check | Finding |
|-------|---------|
| Working tree | **Dirty** — unrelated `mel-operational-order-item-line.html.twig` (commerce). Exclude from Phase 3 commits. |
| Merge/rebase | None active |
| Branch | Created `feature/event-media-editorial-storytelling` |
| `drush config:status` | **Different** (active ≠ sync) for: `core.entity_form_display.node.event.default`, `studio_branding`, `core.entity_view_display.node.event.full`, `crop.type.event_hero`. Git `config/sync` matches HEAD; drift is **DB vs exported YAML** (post-`cim` not `cex`). Benign for code work; run `ddev drush cex` before deploy if needed. |

## Architecture map

```
field_mel_event_gallery (node)
  → myeventlane_event_preprocess_node (full view)
  → EventMediaPresenter::buildGalleryViewModel()
  → mel_event_gallery theme (mel-event-gallery.html.twig)
  → myeventlane_theme/mel_event_gallery (SCSS in _event-gallery.scss + mel-media-lightbox.js)
```

Hero path **unchanged**: `field_event_image` → view display / theme preprocess → `mel-event-hero--featured-style`.

## EventMediaPresenter (current)

- **Input:** Ordered `field_mel_event_gallery` media (`image` bundle).
- **Output:** Flat `items[]` with identical shape: `index`, `mid`, `alt`, `urls`, `srcset`, `sizes`, dimensions.
- **Image styles:** `mel_event_gallery_card` (960×640 focal crop), `mel_event_gallery_lightbox` (1600w scale).
- **Gap:** No `role`, `emphasis`, or `layout_class` — all cards equal weight.

## Preprocess (`myeventlane_event_preprocess_node`)

- Sets `mel_event_media`, `mel_event_gallery` on `view_mode === full`.
- Merges media entity cache tags into `#cache['tags']`.
- Does not alter hero or layout variables.

## Twig (`mel-event-gallery.html.twig`)

- Single `<ul class="mel-event-gallery__grid">` with uniform `<li class="mel-event-gallery__item">`.
- Style modifier: `mel-event-gallery--classic|immersive` from `event_page_style`.
- JSON payload for lightbox in `<script type="application/json">`.
- **Gap:** No composition regions (lead / support / detail).

## SCSS (`_event-gallery.scss`)

| Breakpoint | Layout |
|------------|--------|
| Default | 1-column grid |
| ≥560px | 2 equal columns |
| ≥900px | 3 equal columns |
| ≤559px | Horizontal scroll, 86% item width (equal) |

**Classic:** light border on triggers, muted lede.  
**Immersive:** inverse title, heavier shadow on triggers.  
**Gap:** Equal card rhythm; no lead bleed, no asymmetry, no editorial grid areas.

## Lightbox (`mel-media-lightbox.js`)

- Native `<dialog>`, ESC/cancel, overlay click, prev/next, keyboard arrows.
- Focus return to trigger on close.
- Reads JSON from DOM (cacheable with page; items are URLs only).
- **Accessible:** aria-labels on triggers; caption from alt text.

## Mobile (current)

- All items same scroll width (86%).
- No sequencing emphasis for image 1 vs 2–3 vs 4+.
- Touch targets ≥44px on triggers and lightbox controls.

## Render / cache dependencies

- Node cache tags + per-media tags on gallery field.
- No `#lazy_builder`; images use `loading="lazy"` in markup.
- Image derivatives via Drupal image styles (cacheable URLs).

## Layout collision points

- Gallery inserted in `node--event--full.html.twig` after meta bar, before cancelled banner / main layout.
- Immersive page canvas from `mel-event-page--*` on `<article>` — gallery SCSS scoped under those selectors.
- Risk: equal grid can feel “widget block” vs meta strip / `mel-event-layout` cards — Phase 3 addresses via spacing and surface alignment only in gallery partial.

## Phase 3 intent (no schema changes)

- Add deterministic composition metadata in **presenter only**.
- Restructure **Twig** classes (not new theme hooks).
- Replace equal-grid **SCSS** with lead / support / detail rhythm + mobile narrative scroll.
- Preserve lightbox, image styles, field, and Event Studio branding UX.
