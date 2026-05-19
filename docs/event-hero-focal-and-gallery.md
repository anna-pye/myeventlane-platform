# Event hero focal point and future gallery

## Current hero stack (do not replace)

| Layer | Role |
|-------|------|
| `field_event_image` | Single cover file + alt on the event node |
| **Event Studio branding** | `image_widget_crop` + crop type `event_hero` (1200×630) — vendor sets a fixed crop in Studio |
| **Public event + book hero** | Image style `mel_event_hero_featured` (1600×900) via `focal_point_scale_and_crop` on crop type `focal_point` |
| **Focal point storage** | `focal_point` module persists focus on the file crop entity; branding save copies `focal_point` from the mel widget into the field item |

Focal shortcuts in Branding (`mel-branding-hero-tools.js`) set the `focal_point` form value. They do **not** move the `event_hero` crop box — that remains the Crop API widget. Public framing follows **focal_point**, not the event_hero crop rectangle.

## Branding UX (this slice)

- `BrandingHeroFocalAugmenter` injects `focal_point` + indicator into the crop widget delta so focal_point JS and shortcuts work together.
- Active shortcut state, `aria-pressed`, status copy, and 16:9 framing preview reflect the same focal values used on the public page.
- Framing preview uses the **original file URL** (`drupalSettings.myeventlane_event_studio.brandingHero.sourceUrl`) with CSS `object-fit: cover` + `object-position` — closer to `mel_event_hero_featured` than the crop-widget thumbnail.
- The crop widget still edits **event_hero** (1200×630) for listings/social; public heroes use **focal_point** at 16:9 — vendors should trust the framing preview and focal shortcuts for event/book pages, not the crop box aspect ratio alone.

## Public rendering (parity)

| Surface | Image style | Focal |
|---------|-------------|-------|
| Event full (`node.event.full`) | `mel_event_hero_featured` | Baked via `focal_point_scale_and_crop` |
| Book page (`myeventlane-event-book`) | Same field + style via render array | Same |
| Classic / Immersive | Same hero markup (`mel-event-hero--featured-style`); theme SCSS differs overlay/layout only | Same |

Do not add a second `object-position` layer on public heroes — focal is applied at image generation time.

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

### Mobile

- Hero uses full-width `object-fit: cover` in `.mel-event-hero--featured-style`; immersive may adjust min-height and content placement in `_event-page-themes.scss` but not a separate image pipeline.
- Gallery (future) should use responsive image styles per card context; lightbox/carousel is a presentation layer on top of a multi-value field, not a new storage model.
