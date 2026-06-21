# MEL Theme Asset Ownership

**Date:** 2026-06-21  
**Theme:** `web/themes/custom/myeventlane_theme`  
**Method:** Filesystem inventory + repository reference search (Twig, PHP, SCSS).  
**Note:** No `assets/` subdirectory exists; all packaged assets are under `images/`.

---

## Ownership model

| Layer | Location | Managed by | Used for |
|---|---|---|---|
| **Content-managed** | `public://` via Drupal fields / Media | Editors, Event Studio, Page Visuals admin | Event images, blog heroes, category images, discovery heroes |
| **Theme-packaged** | `themes/custom/myeventlane_theme/images/` | Theme / design ops (git) | Logos, icons, fallbacks, marketing hero art when no CMS asset |
| **Vendor theme** | `themes/custom/myeventlane_vendor_theme/images/` | Vendor theme | Vendor console logo, event placeholder |
| **Other themes** | `myeventlane_admin_theme`, `mel_maintenance` | Respective themes | Admin / error logos |

**Rule:** Do not move theme files without proven consumer updates. Prefer uploading to the correct field (taxonomy, Page Visual) over adding new hardcoded paths.

---

## Committed assets — `myeventlane_theme/images/`

### Logo

| File | Class | Consumers | Status |
|---|---|---|---|
| `logo.svg` | logo | `mel-footer-brand-block.html.twig`, header patterns | **Active** |
| `logo-icon.svg` | logo | Favicon / compact references (brand audit) | **Active** |
| `mel-email-logo.png` | logo | `myeventlane_messaging` email base template; `mel-email-logo.png` resolver in module | **Active** |

### Icon (value proposition / UI)

| File | Class | Consumers | Status |
|---|---|---|---|
| `icon-accessibility.svg` | icon | Value cards / front values (per brand audit) | **Active** |
| `icon-community.svg` | icon | Value cards | **Active** |
| `icon-no-fees.svg` | icon | Value cards | **Active** |

### Illustration / hero (theme fallback)

| File | Class | Consumers | Status |
|---|---|---|---|
| `mel/hero/mel-hero-home-community.jpg` | illustration | `_myeventlane_front_home_hero_theme_urls()`, theme preprocess homepage fallback | **Active** |
| `mel/hero/mel-hero-home-abstract.svg` | decorative | `components/hero/hero.twig` fallback (`hero_fallback_src`) | **Active** |
| `mel/mel-mascot-round.png` | illustration | Guide/mascot reference (brand audit); limited Twig refs | **Present — evolve for Guide system** |

### Decorative / empty state

| File | Class | Consumers | Status |
|---|---|---|---|
| `mel-empty-cart.png` | decorative | `myeventlane_theme.theme` cart empty illustration helper | **Active** |
| `mel/placeholders/mel-placeholder-default.svg` | fallback image | `vendor-card.html.twig`, `OperationalExtraVisualPresenter` | **Active** |

### Documentation only

| File | Class | Notes |
|---|---|---|
| `mel/README.md` | — | Documents category, hero, and empty-state conventions |

---

## Documented slots (not committed or missing on disk)

From `images/mel/README.md`, brand audit, and theme preprocess — **consumers exist; files often absent**:

| Expected path | Class | Consumer | On disk |
|---|---|---|---|
| `mel/hero/mel-hero-home.png` | illustration | Homepage fallback candidate in PHP | **Missing** |
| `mel/hero/mel-hero-mobile.png` | illustration | Brand audit / legacy docs | **Missing** |
| `mel/hero/mel-hero-events.png` | illustration | Events listing hero fallback | **Missing** |
| `mel/hero/mel-hero-search.png` | illustration | Search hero fallback | **Missing** |
| `mel/hero/mel-hero-journey-desktop.png` | illustration | Category fallback chain in `myeventlane_theme.theme` | **Missing** |
| `mel/hero/mel-hero-journey-mobile.png` | illustration | Category fallback chain | **Missing** |
| `mel/categories/mel-category-{slug}.png` | illustration | Category preprocess when term has no `field_category_image` | **Dir/convention only** |
| `mel/empty/mel-empty-events.png` | fallback image | `views-view--upcoming-events--page-category.html.twig` (`onerror` hide) | **Missing** |
| `images/myeventlane-logo.png` | logo | Referenced in brand audit | **Missing** (superseded by `logo.svg`?) |

**Operational preference:** Upload category images on taxonomy terms and discovery heroes via **Structure → Page Visuals** rather than committing large PNGs to git.

---

## Vendor theme assets

| File | Class | Consumer |
|---|---|---|
| `myeventlane_vendor_theme/images/logo.svg` | logo | Vendor shell |
| `myeventlane_vendor_theme/images/mel-event-placeholder.svg` | fallback image | Vendor event cards without image |

---

## Duplicate / overlap risks

| Issue | Detail | Action |
|---|---|---|
| Dual homepage hero systems | `myeventlane-home-hero.html.twig` (block) vs `components/hero/hero.twig` (Probe) | Document ownership per route; do not add third hero |
| Same JPG for desktop + mobile | `_myeventlane_front_home_hero_theme_urls()` uses same community JPG | Acceptable; Page Visuals can supply distinct mobile Media |
| `field_vendor_logo` vs `field_logo_image` | Two logo fields on vendor entity | Image architecture does not merge — business decision required |
| Legacy `node--event.html.twig` | Competes with `node--event--full.html.twig` | Treat as legacy; SCSS comments confirm |

---

## Unused or low-reference assets

| File | Assessment |
|---|---|
| `mel-mascot-round.png` | Only character asset; not heavily wired in Twig — reserved for Guide rollout |
| `logo-icon.svg` | Committed; verify favicon theme settings if changing brand |
| Documented missing PNGs | Not unused — **expected slots** awaiting artwork or CMS upload |

No committed theme image files were found with **zero** repository references except possibly `logo-icon.svg` (indirect/favicon).

---

## Hardcoded path consumers (grep summary)

| Path pattern | Example consumer |
|---|---|
| `images/logo.svg` | Footer brand block |
| `images/mel/hero/*` | Front module, theme preprocess, hero component |
| `images/mel/categories/*` | Theme preprocess category heroes |
| `images/mel/placeholders/mel-placeholder-default.svg` | Vendor cards, commerce extras |
| `images/mel/empty/mel-empty-events.png` | Events/category empty view |
| `images/mel-empty-cart.png` | Cart empty state PHP helper |
| `images/mel-email-logo.png` | Transactional email |

---

## Classification summary

| Class | Count (committed) | Owner |
|---|---|---|
| logo | 3 | Theme + messaging module |
| icon | 3 | Theme value props |
| illustration | 2 (+ mascot) | Theme / brand |
| decorative | 1 (empty cart) | Theme commerce UX |
| fallback image | 1 (placeholder SVG) | Theme + commerce presenter |

---

## Rollback / change safety

- **Theme asset swap:** Replace file in place keeping path — no config export.
- **Path change:** Requires Twig/PHP/SCSS search for old path; run `npm run mel:build`.
- **Remove asset:** Confirm zero references via repo search first.

---

## Related

- [image-architecture-audit.md](./image-architecture-audit.md)
- [brand-rollout/brand-assets-audit.md](./brand-rollout/brand-assets-audit.md)
- `web/themes/custom/myeventlane_theme/images/mel/README.md`
