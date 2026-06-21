# MEL Image Architecture Audit

**Date:** 2026-06-21  
**Method:** Repository-first audit (config, custom modules, theme Twig/SCSS). No implementation in this phase.  
**Scope:** Media, image styles, responsive delivery, content vs theme asset boundaries, Twig consumers.

---

## Executive summary

MEL already has a **production image architecture**. Custom `mel_*` image styles, Focal Point, Event Studio hero crops, event card pipeline, blog card styling, and gallery presenters are **implemented and wired**. The prompt’s recommended style names (`event_card`, `event_feature`, `blog_card`, etc.) **map to existing config** — creating parallel styles would duplicate the system.

**Implementation gate:** **CLOSED** for new image styles and contrib modules. Proceed only with **targeted follow-ups** documented in [Gap analysis](#gap-analysis) (each needs explicit approval and a single concern per PR).

---

## Phase 1 — Media audit

### Media types (`config/sync/media.type.*.yml`)

| ID | Label | Owner | Notes |
|---|---|---|---|
| `image` | Image | Core Media | Standard `field_media_image`; used by blog hero, Page Visuals, event gallery |
| `document` | Document | Core Media | Non-image |
| `audio` | Audio | Core Media | Non-image |
| `video` | Video | Core Media | Non-image |
| `remote_video` | Remote video | Core Media | Non-image |

No custom media bundles beyond core patterns were found in `config/sync`.

### Image fields (content-managed)

| Field | Entity | Bundle | Type | Storage path / handler | Primary consumers |
|---|---|---|---|---|---|
| `field_event_image` | `node` | `event` | **Image (file)** | `events/[date:custom:Y]-[date:custom:m]` | Event full page, cards, Event Studio branding, booking surfaces |
| `field_mel_event_gallery` | `node` | `event` | **Entity reference → media** (`image`) | Media library | `EventMediaPresenter`, `mel_event_gallery` theme hook, Event Studio branding form |
| `field_blog_hero_image` | `node` | `blog_post` | **Entity reference → media** (`image`) | Media library | `BlogArticlePresentationService`, blog teaser via `media.image.thumbnail` |
| `field_image` | `node` | `article` | Image (file) | Core article | Legacy/core article displays |
| `field_category_image` | `taxonomy_term` | `categories` | Image (file) | `categories/[date:custom:Y]-[date:custom:m]` | Category discovery heroes; theme PNG fallback |
| `field_vendor_logo` | `myeventlane_vendor` | `myeventlane_vendor` | Image (file) | `vendor_logos` | Public vendor profile (`full` display, `medium` style) |
| `field_logo_image` | `myeventlane_vendor` | `myeventlane_vendor` | Image (file) | `[date:custom:Y]-[date:custom:m]` | Vendor entity (form visible; full display hidden) |
| `field_msg_logo` | `myeventlane_vendor` | `myeventlane_vendor` | Image (file) | — | Email header override |
| `field_banner_image` | `myeventlane_vendor` | `myeventlane_vendor` | Image (file) | — | Vendor profile (hidden on default/full in sync config) |

**Boundary:** Event hero uses a **direct image field** (not Media). Blog and Page Visuals use **Media**. Do not collapse these without an explicit migration plan.

### Image styles (`config/sync/image.style.*.yml`)

#### MEL-owned styles (do not duplicate)

| Config name | Label | Dimensions / behaviour | Crop | Owner module / service | Consumers |
|---|---|---|---|---|---|
| `mel_event_card_standard` | MEL event card (standard) | 400×500 focal crop | `focal_point` | `myeventlane_event` / `EventCardViewModel` | `teaser`, `list_card`, `compact_commerce`, `teaser_tonight`, default card pipeline |
| `mel_event_card_featured` | MEL event card (featured) | 800×450 focal crop | `focal_point` | `EventCardViewModel` | `teaser_featured`, `editorial_magazine`, editorial/spotlight variants |
| `mel_event_hero_featured` | MEL event hero (featured) | 16:9 crop + scale 1600×900 | `event_hero` | Event full display config | `node.event.full` → `node--event--full.html.twig` via `content.field_event_image` |
| `mel_crop_event_hero` | MEL crop — event hero (widget) | Crop only (16:9) | `event_hero` | Event Studio branding form | `studio_branding` form widget preview |
| `mel_crop_focal_point` | MEL crop — focal point (widget) | 1200×630 focal crop | `focal_point` | Focal Point widget | General focal preview |
| `mel_blog_card` | MEL blog card | 600×338 focal crop + WebP derivative | `focal_point` | `media.image.thumbnail` display | Blog teasers, blog card grids |
| `mel_event_gallery_card` | MEL event gallery (card) | 960×640 focal crop | `focal_point` | `EventMediaPresenter` | Event gallery carousel cards |
| `mel_event_gallery_lightbox` | MEL event gallery (lightbox) | Scale max 1600w | — | `EventMediaPresenter` | Gallery lightbox |
| `mel_extra_square` | MEL Event extra (square) | 400×400 focal crop | `focal_point` | `OperationalExtraVisualPresenter` | Commerce operational extras |

#### Core / shared styles (also used)

| Name | Role |
|---|---|
| `large`, `medium`, `thumbnail`, `wide` | Blog hero (`media.image.default` → `large`), vendor logo (`medium`), core defaults |
| `max_325x325`, `max_650x650`, `max_1300x1300`, `max_2600x2600` | Responsive Image `narrow` / `wide` breakpoints |
| `crop_thumbnail`, `media_library` | Admin / Media Library |

#### Recommended-name mapping (prompt § Phase 3)

| Prompt name | MEL equivalent | Action |
|---|---|---|
| `event_card` | `mel_event_card_standard` | **Reuse** |
| `event_feature` | `mel_event_card_featured` | **Reuse** |
| `blog_card` | `mel_blog_card` | **Reuse** |
| `hero_desktop` / `hero_mobile` | Not image styles — Page Visual desktop/mobile Media UUIDs + `<picture>` in Twig | **Do not create** |
| `organiser_avatar` | **No dedicated style** — `field_vendor_logo` uses core `medium` (480×480) | Gap — see below |

### Responsive image styles

| ID | Config | Image styles used | Wired to |
|---|---|---|---|
| `narrow` | `responsive_image.styles.narrow.yml` | max_325/650/1300 | `node.event.event` display (legacy) |
| `wide` | `responsive_image.styles.wide.yml` | max_325/650/1300/2600 | `node.event.event_card` display (legacy) |

**Primary card pipeline** (`EventCardViewModel::resolveCardImage`) builds **single URL** from `mel_event_card_*` — not Responsive Image formatter.

### Focal Point / crop

| Item | Path | Status |
|---|---|---|
| Module `focal_point` | `composer.json` `drupal/focal_point: ^2.1` | Enabled (2.1.2) |
| Module `image_widget_crop` | Core extension | Enabled |
| Crop type `focal_point` | `config/sync/crop.type.focal_point.yml` | Active |
| Crop type `event_hero` | `config/sync/crop.type.event_hero.yml` | 16:9 — Event Studio + `mel_event_hero_featured` |
| Settings | `config/sync/focal_point.settings.yml` | Present |
| Event Studio augmenter | `BrandingHeroFocalAugmenter` | Reads/writes focal on hero file |

### WebP / AVIF

- **No** `drupal/webp` or `drupal/avif` in `composer.json`.
- Drupal 11 core effect `image_convert_avif` with `extension: webp` is present on several styles including `mel_blog_card`, `large`, `medium`, and responsive `max_*` styles.
- This is **core toolkit behaviour**, not a contrib optimisation module. No install recommended.

---

## Phase 1 — Theme assets (`web/themes/custom/myeventlane_theme/images/`)

No `themes/custom/myeventlane_theme/assets/` directory exists. All theme-packaged raster/SVG assets live under `images/`.

See [theme-asset-ownership.md](./theme-asset-ownership.md) for full classification.

**Hardcoded hero paths (theme fallback layer):**

| Surface | Resolver | Fallback asset |
|---|---|---|
| Homepage | `HomeHeroBlock` → Page Visuals; `_myeventlane_front_home_hero_theme_urls()` | `mel/hero/mel-hero-home-community.jpg` |
| Homepage (Probe component) | `preprocess_page` → `components/hero/hero.twig` | `mel/hero/mel-hero-home-abstract.svg` |
| Category pages | `myeventlane_theme.theme` preprocess | `mel/categories/mel-category-{slug}.png` (often missing — DB `field_category_image` preferred) |
| Discovery heroes | `PageVisualResolver` + `discovery-hero.html.twig` | Page Visual Media; `<picture>` for mobile |

**Documented but uncommitted slots** (`.gitkeep` or README only): `mel-hero-events.png`, `mel-hero-search.png`, `mel-category-*.png`, `mel-empty-events.png`.

---

## Phase 1 — Twig / PHP image usage

| Pattern | Location | Owner | Notes |
|---|---|---|---|
| `file_url()` | `node--event.html.twig` (legacy) | Theme | **Bypasses** `mel_event_hero_featured`; superseded by `node--event--full.html.twig` |
| `file_url()` | `myeventlane-venue-page.html.twig` | `myeventlane_venue` | Raw venue image |
| `image_style` theme hook | `EventWizardBasicsForm` | `myeventlane_event` | Wizard preview |
| `ImageStyle::buildUrl()` | `EventCardViewModel` | `myeventlane_event` | **Canonical card image URLs** |
| `content.field_event_image` | `node--event--full.html.twig` | Theme + display config | Respects `mel_event_hero_featured` |
| `<picture>` | `myeventlane-home-hero.html.twig`, `hero.twig`, `discovery-hero.html.twig` | `myeventlane_front` / theme | Manual responsive srcset (not Responsive Image module) |
| Hardcoded `base_path ~ directory ~ '/images/...'` | Footer, empty states, vendor card placeholder, hero fallback | Theme | Theme-owned decorative/fallback assets |
| `PageVisualResolver::getImageUrlFromMedia()` | `myeventlane_page_visuals` | Custom | **Raw file URL** — no image style applied |

`responsive_image()` Twig function: **not used** in custom Twig (formatter applied via entity view displays only on legacy modes).

---

## Phase 1 — Content surface ownership

### Event (`node.event`)

| Surface | Field | Display mode | Image delivery |
|---|---|---|---|
| Public full page | `field_event_image` | `full` | `mel_event_hero_featured` via formatter → `node--event--full.html.twig` |
| Discovery cards | `field_event_image` | `teaser`, `list_card`, etc. | Config formatter **or** `EventCardViewModel` URL (primary for Views) |
| Editorial / spotlight | `field_event_image` | `teaser_featured`, `editorial_magazine` | `mel_event_card_featured` |
| Event gallery | `field_mel_event_gallery` | N/A (preprocess) | `EventMediaPresenter` → `mel_event_gallery_*` |
| Event Studio branding | `field_event_image` | `studio_branding` form | `image_widget_crop` + `event_hero` crop type |
| Booking / commerce extras | — | — | `mel_extra_square` via `OperationalExtraVisualPresenter` |

### Blog (`node.blog_post`)

| Surface | Field | Display mode | Image delivery |
|---|---|---|---|
| Article hero | `field_blog_hero_image` (media) | `default` media view | `media.image.default` → **`large`** (480×480 scale) |
| Card / teaser | `field_blog_hero_image` | `teaser` → media `thumbnail` | **`mel_blog_card`** (600×338) |

**Inconsistency:** Full article hero uses `large`; cards use `mel_blog_card`. Intentional separation possible but worth a follow-up if hero should be 16:9.

### Organiser / vendor profile

| Field | Public consumer | Style |
|---|---|---|
| `field_vendor_logo` | `myeventlane_vendor.full` | `medium` (480×480) |
| `field_logo_image` | Hidden on full display | — |

No `organiser_avatar` style exists.

### Homepage hero

| Layer | Owner | Mechanism |
|---|---|---|
| Primary | `myeventlane_page_visuals` | Config entity `myeventlane_page_visual` — desktop + mobile Media UUIDs per route |
| Block | `myeventlane_front` `HomeHeroBlock` | Injects URLs into `myeventlane-home-hero.html.twig` |
| Theme fallback | `myeventlane_front.module` `_myeventlane_front_home_hero_theme_urls()` | Theme JPG when no Page Visual |
| Alternate UI | `components/hero/hero.twig` | Probe/marketing hero; abstract SVG fallback |
| Deprecated | `myeventlane_theme_settings` HeroSettingsForm | Marked deprecated — not runtime |

Page Visual config entities are **database-stored** (not in `config/sync`); only schema in `myeventlane_page_visuals`.

### Community spotlight / promotional

- Homepage hero rotator slot exists in `myeventlane-home-hero.html.twig` (`featured_events`) but `HomeHeroBlock` currently passes `#featured_events => NULL`.
- Popular / Hidden Gems routes use Page Visual heroes (`community_hero`, `hidden_gems_hero`) per [discovery-hero-ownership-report.md](./discovery-hero-ownership-report.md).

---

## Phase 2 — Gap analysis

### Existing (keep)

- Full `mel_*` image style set for cards, hero, gallery, blog cards, commerce extras.
- Focal Point + `event_hero` 16:9 crop for Event Studio.
- `EventCardViewModel` as single card image authority.
- Page Visuals for route-based discovery heroes (content-managed Media).
- Theme fallback convention documented in `images/mel/README.md`.
- Core WebP derivatives via `image_convert_avif` on selected styles.

### Missing (documented only — not implemented here)

| Gap | Risk if rushed | Suggested follow-up |
|---|---|---|
| `organiser_avatar` / vendor logo style | Wrong dimensions on vendor cards | Add `mel_vendor_logo` (e.g. 200×200) **only after** design token sign-off; wire `field_vendor_logo` display |
| Page Visuals serve **original** file URLs | Large hero payloads, no WebP | Route Page Visual URLs through dedicated `mel_hero_desktop` / `mel_hero_mobile` styles in `PageVisualResolver` |
| Blog full hero uses `large` not `mel_blog_card` | Aspect mismatch on article pages | Add `mel_blog_hero` style or reuse hero style; update `media.image.default` |
| MEL-specific **responsive image styles** for cards/hero | Duplicate `narrow`/`wide` semantics | See [responsive-image-recommendations.md](./responsive-image-recommendations.md) |
| Legacy `node--event.html.twig` raw `file_url()` | Full-size images if template ever wins suggestion order | Confirm template retirement or align with `full` display |
| Uncommitted theme hero/empty slots | Broken `<img>` onerror fallbacks | Content ops: upload Page Visuals / term images; or commit artwork to documented paths |

### Duplicates (do not create)

- `event_card`, `event_feature`, `blog_card` as new machine names.
- Parallel Media types for events (already image field + gallery media).
- Second hero management system (Page Visuals replaced deprecated theme settings).
- Contrib WebP/AVIF modules (core effect already present).

### Risks

| Risk | Area | Mitigation |
|---|---|---|
| Event Studio crop regression | `field_event_image` widget, `event_hero` crop | No form/display changes without Studio QA |
| Card image cache invalidation | `EventCardViewModel` style URLs | Preserve cache tags on image styles |
| Commerce checkout | Product imagery | **Out of scope** — do not touch checkout panes |
| Homepage hero cache | Page Visual + block cache tags | Any resolver change must preserve `_cache` metadata |
| Config drift | Page Visuals in DB not sync | Document export/import process for staging |

---

## Module audit (Phase 4A)

```
ddev drush pml --status=enabled | grep -E "media|image|responsive_image|focal_point|webp|avif"
```

| Module | Status |
|---|---|
| `image` | Enabled (core) |
| `media` | Enabled (core) |
| `media_library` | Enabled (core) |
| `responsive_image` | Enabled (core) |
| `focal_point` | Enabled (2.1.2) |
| `image_widget_crop` | Enabled |
| `webp` / `avif` contrib | **Not installed** |

**Action:** No module installation required. Focal Point already satisfies crop/focus requirements.

---

## Implementation gate checklist

| Criterion | Status |
|---|---|
| Ownership proven | Yes |
| No duplicate system | Yes — creating prompt-named styles would duplicate |
| Config location known | Yes — `config/sync/image.style.mel_*.yml` |
| Route consumers known | Yes — see tables above |
| Commerce / Event Studio safe path | Yes — audit-only; no changes |

**Gate result:** **STOP** before creating new image styles or modules. Next work = approved follow-ups from Gap analysis, one concern per PR.

---

## Validation commands (when implementation resumes)

```bash
ddev drush cr
ddev drush cim --preview
ddev drush config:status
php -l <affected_php_files>
npm run mel:lint
npm run mel:build
```

---

## Manual QA surfaces (when implementation resumes)

Homepage, `/events`, category, event full page, blog article + listing, Event Studio branding, checkout, RSVP, vendor dashboard — at 390px, 768px, 1280px.

---

## Related audits

- [theme-asset-ownership.md](./theme-asset-ownership.md)
- [responsive-image-recommendations.md](./responsive-image-recommendations.md)
- [brand-rollout/brand-assets-audit.md](./brand-rollout/brand-assets-audit.md)
- [discovery-hero-ownership-report.md](./discovery-hero-ownership-report.md)
