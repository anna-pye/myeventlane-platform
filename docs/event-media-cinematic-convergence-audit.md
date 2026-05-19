# Event media cinematic convergence — Phase 4 audit

**Date:** 2026-05-19  
**Branch:** `feature/event-media-cinematic-convergence`  
**Scope:** Read-only visual audit before Phase 4 SCSS/Twig refinement.

## Phase 0 — Safety gate

| Check | Finding |
|-------|---------|
| Working tree | **Dirty** — `mel-operational-order-item-line.html.twig` (unrelated commerce); `BrandingHeroFocalAugmenter` + `EventBrandingForm` + `EventStudioBaseForm` (Media Library serialization fix, in progress). **Exclude commerce twig from Phase 4 commits.** |
| Merge/rebase | None active |
| Branch | `feature/event-media-cinematic-convergence` (from `feature/event-media-editorial-storytelling`) |
| `drush config:status` | **Different** for `core.entity_form_display.node.event.default`, `studio_branding`, `core.entity_view_display.node.event.full`, `crop.type.event_hero` — DB vs `config/sync`; repo YAML matches HEAD. Benign for SCSS work. |

## Page section map (top → bottom)

| # | Region | Markup / classes | Style owner |
|---|--------|------------------|-------------|
| 1 | Hero | `.mel-event-hero--featured-style` | `_event-hero.scss`, `_event-page-themes.scss` |
| 2 | Meta strip | `.mel-event-meta-bar` | `_event-full.scss`, themes |
| 3 | Gallery | `.mel-event-gallery` (presenter-driven) | `_event-gallery.scss`, themes |
| 4 | Cancelled alert | `.mel-alert` | base |
| 5 | Main + sidebar | `.mel-event-layout` | `_event-full.scss`, themes |
| 5a | About | `.mel-card--surface` | themes + full |
| 5b | Highlights | `.mel-card--highlights` | full tinted; themes override Classic/Immersive |
| 5c | What to expect | `.mel-card--expect` | full tinted; themes override |
| 5d | Extras | `.mel-event-section--extras` + `.mel-addon-teaser` | commerce twig + full + themes |
| 5e | Event information | `.mel-card` + `.mel-info-row` | full |
| 5f | Share | `.mel-event-section--share` | full |
| 5g | Accessibility | `.mel-card` | full |
| 5h | Organiser | `.mel-organiser-card` | full |
| 5i | Related | `.mel-return-block` | full |
| 5j | Support links | `.mel-event-support-zone` | full |
| 5k | Policies / contact | `.mel-card` | full |
| 6 | Sidebar booking | `.mel-card--sticky` | full + themes |
| 7 | Mobile CTA | `.mel-mobile-cta` | full + themes |

**Preserved:** `EventMediaPresenter`, gallery field schema, image styles, lightbox JS, hero architecture.

## Disconnected surfaces & abrupt transitions

1. **Hero → meta → gallery** — Three separate “objects” (hero block, floating meta card, gallery band) with no shared atmospheric layer; `margin-block: mel-space(7)` on gallery adds a white/peach “air gap” after meta on Classic.
2. **Gallery → layout** — Gallery ends with large vertical margin; main column cards restart with `mel-mt-5` utility spacing (inconsistent with theme `--mel-immersive-section-gap`).
3. **Immersive canvas vs cards** — Body gradient is strong; individual `.mel-card--surface` panels still read as equal-weight boxes despite theme hierarchy (About = raised, Highlights = quiet).
4. **Meta strip on Immersive** — Sits between hero and gallery without bridging gradient; feels like a third plugin between two cinematic regions.

## Duplicated borders & shadow language

- `_event-full.scss` applies `border: 1px solid rgba(0,0,0,0.05)` + `shadows.$mel-shadow-md` on meta bar globally; themes re-layer borders on Classic/Immersive — **double vocabulary**.
- Every main-column block uses `.mel-card--surface` + `mel-mt-5` → repeated outline + shadow at same elevation.
- Extras: outer section card + inner `.mel-addon-teaser.mel-card--surface` (inner header hidden in CSS but DOM still nested) → **commerce widget** cue.
- `.mel-info-row` top borders inside Event Information recreate list dividers inside an already-bordered card.

## Inconsistent spacing

- Gallery: `mel-space(7)` block margins vs layout `mel-space(5)` vs Immersive `--mel-immersive-section-gap` (6).
- `mel-mt-5` on sequential cards ignores theme tokens.
- Mobile: gallery horizontal scroll padding vs card padding not aligned to main column rhythm.

## Widget / plugin feeling areas

| Area | Why it breaks immersion |
|------|-------------------------|
| `.mel-addon-teaser` | Commerce module markup (`mel-card`, secondary CTAs) visually distinct from event narrative cards |
| `.mel-info-row` grid | Admin-style label/value rows in Event Information |
| `.mel-booking-panel` trust rotator / decision prompts | Dense micro-copy blocks; acceptable for conversion but visually noisy next to editorial body |
| Share chip row | Icon-only circles read as social plugin strip |

## Typography rhythm issues

- `_event-full.scss` hardcodes `#24303a`, `#7c8791` on booking sidebar — **fights** Immersive token overrides (partially patched in `_event-page-themes.scss`).
- Section titles: gallery `mel-font-size(xl)` vs card `mel-card__title` vs immersive `--mel-immersive-heading-size` — three scales.
- `.mel-text--muted` / `.mel-event-section__lede` contrast borderline on Immersive purple preset (`--mel-immersive-text-muted` at 0.72 alpha).

## Classic vs Immersive architecture (keep)

- **Classic:** warm `#fff8f4` shell, white cards, meta overlap on hero foot, accent-driven CTAs — do not darken or add cinematic overlays.
- **Immersive:** dark body gradient, tonal surfaces, minimal borders, accent glow on CTAs — remove remaining light-grey box cues from `_event-full.scss` where they leak through.

## Mobile

- Gallery scroll widths (92% / 78% / 72%) good; gap before first main card still desktop-compressed.
- Sidebar reorder (`display: contents`) works; sticky in-sidebar CTA on ≤768px can fight `.mel-mobile-cta` fixed bar.
- Touch targets: share chips, gallery triggers, CTAs meet 44px in most paths; addon row thumbs at 56px OK.

## Accessibility & performance (preserve)

- Gallery: lazy images, alt on triggers, lightbox ESC/focus return, `prefers-reduced-motion` on hover transforms.
- Cache: presenter + media tags unchanged.
- **Audit note:** Immersive gallery lede at `rgba(255,255,255,0.76)` on dark — pass AA for large text; verify purple preset accent-on-dark for CTA.

## Phase 4 implementation plan (this branch)

1. Add `_event-cinematic-convergence.scss` — editorial rhythm tokens, main-column gap, hero→gallery bridge, typography normalization, extras embedding, mobile tuning.
2. Refine `_event-gallery.scss` — narrative spacing, Classic/Immersive atmospheric continuity.
3. Twig: `mel-addon-teaser--narrative` modifier on embedded teaser (hide duplicate chrome).
4. No new fields, services, or gallery pipeline changes.
