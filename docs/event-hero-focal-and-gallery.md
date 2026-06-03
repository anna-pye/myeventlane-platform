# Event hero focal point and future gallery

## Current hero stack (do not replace)

| Layer | Role |
|-------|------|
| `field_event_image` | Single cover file + alt on the event node |
| **Event Studio branding** | `image_widget_crop` + crop type `event_hero` (16:9, 1600×900) — vendor sets the hero crop in Studio |
| **Public event + book hero** | Image style `mel_event_hero_featured` (1600×900) via `crop_crop` on crop type `event_hero` |
| **Focal point storage** | `focal_point` module persists focus on the file crop entity; branding save temporarily syncs `focal_point` from the `event_hero` crop centre |

Focal shortcuts in Branding (`mel-branding-hero-tools.js`) set the `focal_point` form value. Public heroes follow the **`event_hero` crop rectangle** via `mel_event_hero_featured`. The temporary `event_hero` → `focal_point` sync on save remains until a later cleanup branch.

## Branding UX (this slice)

- `BrandingHeroFocalAugmenter` injects `focal_point` + indicator into the crop widget delta so focal_point JS and shortcuts work together.
- Active shortcut state, `aria-pressed`, status copy, and 16:9 framing preview reflect the same crop used on the public page.
- Framing preview uses the **original file URL** (`drupalSettings.myeventlane_event_studio.brandingHero.sourceUrl`) with CSS `object-fit: cover` + `object-position` — closer to `mel_event_hero_featured` than the crop-widget thumbnail.
- The crop widget edits **event_hero** (16:9) for branding, preview, public event pages, and book pages — one crop model end to end.

## Public rendering (parity)

| Surface | Image style | Crop |
|---------|-------------|------|
| Event full (`node.event.full`) | `mel_event_hero_featured` | Baked via `crop_crop(event_hero)` |
| Book page (`myeventlane-event-book`) | Same field + style via render array | Same |
| Classic / Immersive | Same hero markup (`mel-event-hero--featured-style`); theme SCSS differs overlay/layout only | Same |

Do not add a second `object-position` layer on public heroes — framing is applied at image generation time.

## Event gallery (Phase 2 — media layer)

| Piece | Role |
|-------|------|
| `field_mel_event_gallery` | Multi-value `entity_reference` → `media` (`image` bundle only), event nodes only |
| `EventMediaPresenter` | Builds role-based gallery view models (`mel_event_gallery` theme); does **not** render hero |
| Image styles | `mel_event_gallery_card` (960×640, focal_point), `mel_event_gallery_lightbox` (scale 1600w) |
| Public render | `mel-event-gallery.html.twig` + `mel-media-lightbox.js` (native `<dialog>`) |
| Vendor UX | Event Studio → Branding tab, below hero tools (media library widget) |

**Keep** `field_event_image` as the hero. Gallery is optional storytelling media.

Hero path unchanged: templates still use `field_event_image` / `mel_event_hero_featured` for the hero region.

Avoid a parallel hero system or custom image entity.

## Editorial gallery composition (Phase 3)

Automatic hierarchy — **no vendor layout fields**. `EventMediaPresenter` assigns per-image metadata:

| Index | Role | Emphasis | Layout class |
|-------|------|----------|--------------|
| 1 | `lead` | `primary` | `mel-event-gallery__item--lead` |
| 2–3 | `support` | `secondary` | `mel-event-gallery__item--support` |
| 4+ | `detail` | `tertiary` | `mel-event-gallery__item--detail` |

**Composition** (grid modifier on `mel-event-gallery__grid--*`):

| Count | Composition | Desktop rhythm |
|-------|-------------|----------------|
| 1 | `single` | Full-width lead (16:9) |
| 2 | `duo` | Lead + support (≈5:3) |
| 3 | `trio` | Lead row, supporting pair below |
| 4+ | `editorial` | Lead block + support stack + detail grid |

**Classic:** warm canvas, soft card borders, magazine spacing.
**Immersive:** subtle bleed, atmospheric gradient (no neon/glow), quieter support surfaces.

### Mobile storytelling

- Horizontal scroll with **sequenced widths**: lead 92%, support 78%, detail 72%.
- `scroll-snap` for native editorial pacing.
- Same lightbox and alt text; role-specific `sizes` for responsive delivery.

### Accessibility guarantees

- Alt text from media field (fallback: media label).
- Keyboard: lightbox open/close, arrows, focus return to trigger.
- `prefers-reduced-motion`: hover lift disabled on gallery triggers.
- Lazy-loaded card images; lightbox loads full derivative on demand.

### Cache and performance

- Presenter uses Drupal image styles only (no raw file URLs in storage).
- Preprocess merges media entity cache tags.
- JSON lightbox payload is URL metadata only (built at render time).

### Future extension (do not bypass presenter)

- Optional caption field on media → add to presenter item payload, not Twig field render.
- Venue/performer galleries → separate fields, same presenter patterns.
- Carousel: extend lightbox JS only; do not add parallel gallery markup.

### Mobile (hero)

- Hero uses full-width `object-fit: cover` in `.mel-event-hero--featured-style`; immersive layout in `_event-page-themes.scss` only.
