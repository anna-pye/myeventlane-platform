# MEL Responsive Image Recommendations

**Date:** 2026-06-21  
**Status:** Recommendations only — **no config or module changes** in this audit.  
**Prerequisite audit:** [image-architecture-audit.md](./image-architecture-audit.md)

---

## Current state

### Modules

| Module | Status | Notes |
|---|---|---|
| `responsive_image` | **Enabled** (core) | Required dependency satisfied |
| `focal_point` | **Enabled** | Used by MEL card/gallery styles |
| `drupal/webp` | **Not installed** | Not needed — core `image_convert_avif` → WebP on several styles |
| `drupal/avif` | **Not installed** | Document only; evaluate after measuring CDN/browser support |

### Existing responsive image styles

| Style ID | Breakpoint group | Fallback | Mapped image styles | Consumer |
|---|---|---|---|---|
| `narrow` | `responsive_image` (viewport_sizing) | `max_325x325` | 325, 650, 1300 | `node.event.event` display only |
| `wide` | `responsive_image` (viewport_sizing) | `max_325x325` | 325, 650, 1300, 2600 | `node.event.event_card` display only |

These are **Drupal core defaults** scaled with WebP derivatives. They are **not** aligned to MEL card dimensions (400×500 / 800×450) or event hero (16:9).

### Non-module responsive patterns (already in production)

| Pattern | Location | Mechanism |
|---|---|---|
| Homepage / discovery heroes | `myeventlane-home-hero.html.twig`, `discovery-hero.html.twig`, `hero.twig` | Manual `<picture>` + `srcset` from separate desktop/mobile URLs |
| Page Visuals | `PageVisualResolver` | Optional `media_uuid_desktop` + `media_uuid_mobile` |
| Event cards | `EventCardViewModel` | Single styled URL per view mode — **no srcset** |

---

## Gaps

1. **Primary discovery cards** do not use Responsive Image — one JPEG/WebP URL per card from `mel_event_card_*`.
2. **Event full hero** uses single `mel_event_hero_featured` URL (1600×900 max) — no `srcset` for 390px mobile.
3. **Page Visual heroes** bypass image styles entirely — full-resolution Media file URLs.
4. **Blog article hero** uses `large` (480×480) — not responsive; teasers correctly use `mel_blog_card`.
5. **Legacy view modes** `event` and `event_card` still reference `narrow`/`wide` — may be dead paths if Views use `EventCardViewModel` exclusively.

---

## Recommendations (prioritised)

### P1 — Page Visual hero derivatives (highest impact, bounded scope)

**Problem:** Discovery heroes download original uploaded dimensions.

**Recommendation:**

1. Add image styles (names aligned to existing convention, **not** generic prompt names):
   - `mel_hero_desktop` — e.g. 1920×1080 focal crop or scale (match design tokens)
   - `mel_hero_mobile` — e.g. 768×1024 or 800×600 for mobile art direction
2. Update `PageVisualResolver::getImageUrlFromMedia()` to build URLs via `ImageStyle::buildUrl()` instead of raw `file_url`.
3. Preserve cache tags on image style + file + media.

**Do not** enable new contrib modules. Reuse Focal Point crop type `focal_point`.

**Risk:** Hero admin previews must still look correct; test all discovery routes in [discovery-hero-ownership-report.md](./discovery-hero-ownership-report.md).

---

### P2 — MEL responsive style for event cards

**Problem:** Cards serve one width; retina and narrow viewports get same asset.

**Recommendation:**

1. Create `responsive_image.styles.mel_event_card` mapping:
   - 400w → `mel_event_card_standard` (or dedicated 400w variant)
   - 800w → `mel_event_card_featured` or scaled variant for retina
   - `sizes`: `(min-width: 768px) 50vw, 100vw` (validate against `.mel-event-card` CSS)
2. Wire **only** where formatter is used — prefer extending `EventCardViewModel` to emit `srcset`/`sizes` attributes in Twig rather than duplicating formatter logic in PHP.

**Alternative (smaller diff):** Keep single URL but ensure WebP derivative exists on `mel_event_card_*` styles (add `image_convert_avif` effect like `mel_blog_card`).

---

### P3 — Event full page hero

**Problem:** Mobile loads up to 1600px hero.

**Recommendation:**

1. Create `responsive_image.styles.mel_event_hero` with:
   - Mobile: 800×450 (or 768w scale from `mel_event_hero_featured` pipeline)
   - Desktop: `mel_event_hero_featured`
2. Switch `core.entity_view_display.node.event.full` `field_event_image` formatter from `image` to `responsive_image` **only after** template `node--event--full.html.twig` is confirmed compatible (it currently prints `{{ content.field_event_image }}` — compatible).

**Risk:** Event Studio `event_hero` crop must remain source of truth; test immersive/classic branding.

---

### P4 — Blog full hero

**Recommendation:** Add `mel_blog_hero` (e.g. 1200×675) and assign to `media.image.default` or a dedicated `media.image.hero` view mode. Keep `mel_blog_card` on `media.image.thumbnail` for listings.

---

### P5 — Vendor logo

**Recommendation:** Add `mel_vendor_logo` (square, e.g. 200×200 @1x) and replace `medium` on `field_vendor_logo` in `myeventlane_vendor.full` display. Maps to prompt `organiser_avatar` intent.

---

### P6 — Retire or remap legacy `narrow` / `wide` on event displays

**Recommendation:** Audit whether `node.event.event` and `node.event.event_card` view modes are still rendered anywhere. If unused, hide fields or remap to MEL styles to avoid two responsive systems.

```bash
# Suggested audit command (manual)
ddev drush sql:query "SELECT DISTINCT type FROM config WHERE name LIKE '%view_mode%'" 
# Plus grep Views config for view_mode: event_card / event
```

---

## WebP / AVIF policy

| Approach | Verdict |
|---|---|
| Core `image_convert_avif` with `extension: webp` | **Already in use** on `mel_blog_card`, `large`, `medium`, `max_*` — extend to `mel_event_card_*` and hero styles if needed |
| Contrib `drupal/webp` | **Do not install** — duplicates core |
| AVIF delivery | **Document only** — enable core AVIF when platform image toolkit and browser metrics support it; no module install now |

---

## Implementation constraints (from programme rules)

- One concern per PR.
- Export only changed config: `ddev drush cex -y`.
- No checkout, Commerce, RSVP, or Event Studio workflow changes in responsive-image PRs unless hero formatter is explicitly in scope.
- Validate:

```bash
ddev drush cr
ddev drush cim --preview
ddev drush config:status
npm run mel:lint
npm run mel:build
```

---

## Suggested breakpoint alignment

Align new `sizes` attributes with existing SCSS breakpoints (see `docs/audits/breakpoint-unification-plan.md`):

| Viewport | Target |
|---|---|
| 390px | Mobile-first card width ~100vw |
| 768px | Tablet — ~50vw in two-column grids |
| 1280px | Desktop — max card column widths per `mel-card` system |

---

## Rollback

Each responsive style is independent config:

```bash
ddev drush config:delete responsive_image.styles.mel_event_card  # example
ddev drush config:delete image.style.mel_hero_desktop            # example
ddev drush cr
```

Revert display mode formatter changes via `git checkout -- config/sync/core.entity_view_display.*.yml`.

---

## Decision log

| Decision | Rationale |
|---|---|
| Do not auto-enable contrib WebP/AVIF | Core effect already present |
| Do not create `event_card` / `hero_mobile` generic names | MEL `mel_*` namespace already established |
| Manual `<picture>` for Page Visuals remains valid | Separate mobile Media UUID is an intentional art-direction feature |
| Stop before P1 implementation | Awaiting explicit approval per implementation gate |
