# Theme Architecture Audit

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)
**Audit date:** 2026-06-14
**Branch:** `feature/event-studio-consolidation`
**Method:** Evidence-based. Every claim references a repository path. Where evidence was not found, this is stated explicitly.

---

## 1. Installed themes (evidence)

Source: `config/sync/core.extension.yml` (`theme:` block) and `config/sync/system.theme.yml`.

```
theme:
  olivero, claro, gin, stable9,
  myeventlane_theme, myeventlane_vendor_theme,
  myeventlane_admin, radix, myeventlane_radix, mel_maintenance
```

`system.theme.yml`:
```
admin: gin
default: myeventlane_theme
```

| Theme | Base | Role | Build | Evidence |
|---|---|---|---|---|
| **myeventlane_theme** | `stable9` | **Public marketplace / discovery (default)** | Vite | `web/themes/custom/myeventlane_theme/myeventlane_theme.info.yml` |
| **myeventlane_vendor_theme** | `stable9` | **Vendor console (operational workspace)** | Vite | `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.info.yml` |
| **myeventlane_admin** | `gin` | Admin UX layer | Claro/Gin | `web/themes/custom/myeventlane_admin_theme/myeventlane_admin.info.yml` |
| **gin** | contrib | Active admin theme (`system.theme: admin: gin`) | — | `config/sync/system.theme.yml` |
| **myeventlane_radix** | `radix` (Bootstrap 5) | Experimental SDC migration target — **not default** | Vite | `web/themes/custom/myeventlane_radix/myeventlane_radix.info.yml` |
| **mel_maintenance** | none | Maintenance / branded 403-404 | standalone CSS | `web/themes/custom/mel_maintenance/mel_maintenance.info.yml` |

> **Note on `myeventlane_radix`:** installed but `system.theme.yml` default is `myeventlane_theme`. Its directory contains `ROLLOUT_STRATEGY.md`, `PURGE_STRATEGY.md`, `IMPLEMENTATION_CHECKLIST.md` — this is an in-progress Bootstrap-5/SDC migration track, not the live public theme. **Repository evidence not found** that any production route currently renders through `myeventlane_radix`. Treat as parallel/experimental for this brand rollout.

---

## 2. Theme negotiation (how a request picks a theme)

Source: `web/modules/custom/myeventlane_core/myeventlane_core.services.yml` and `src/Theme/*Negotiator.php`.

Three negotiators registered (highest priority wins):

| Priority | Negotiator | Logic | Evidence |
|---|---|---|---|
| 2100 | `AdminThemeNegotiator` | Admin routing / domain → Gin | `myeventlane_core/src/Theme/AdminThemeNegotiator.php` |
| 2000 | `VendorThemeNegotiator` | If `DomainDetector::isVendorDomain()` → `myeventlane_vendor_theme`; **except** public vendor routes which force `myeventlane_theme` | `myeventlane_core/src/Theme/VendorThemeNegotiator.php` |
| 1500 | `RouteThemeNegotiator` | If route has `options._theme`, use it (vendor onboarding on public domain) | `myeventlane_core/src/Theme/RouteThemeNegotiator.php` |

**Public vendor routes forced back to marketplace theme** (`VendorThemeNegotiator::PUBLIC_VENDOR_ROUTES`):
- `entity.myeventlane_vendor.canonical`
- `myeventlane_vendor.public_list`
- `myeventlane_vendor.organisers`

`VendorThemeNegotiator::determineActiveTheme()` also returns `NULL` (no override) for `_admin_route` routes, deferring to Gin.

> A duplicate `VendorThemeNegotiator` also exists at `myeventlane_vendor/src/Theme/VendorThemeNegotiator.php`. The active one is registered in `myeventlane_core.services.yml`. The duplicate should be confirmed dead before brand work touches negotiation (out of scope for audit — flagged).

**Architectural consequence for the brand rollout:** the public/vendor split is enforced at the *theme negotiation* layer by **domain**, not by URL path alone. "The Guide" living in public discovery vs. vendor workspace is therefore a clean, already-existing boundary (see `surface-boundary-audit.md`).

---

## 3. Public theme — `myeventlane_theme` (PRIMARY BRAND SURFACE)

### Purpose
Default theme for all public marketplace / discovery / event / checkout / help / onboarding surfaces on the public domain.

### Regions (info.yml)
Highly section-oriented homepage. Defined regions include a full homepage stack:
`homepage_hero`, `homepage_featured`, `homepage_categories`, `homepage_latest`, `homepage_tonight`, `homepage_free`, `homepage_nearby`, `homepage_online`, `homepage_host_cta`, `homepage_blog`, `homepage_newsletter`, plus `home_featured`, `home_discover`, `home_recommended`, `host_cta`, `bottom_cta`, and a structured footer (`footer_newsletter`, `footer_attendees`, `footer_organisers`, `footer_support_legal`, `footer_brand`, `footer_ack`, `footer_payments`, `footer_bottom`).

> This region map is the strongest single piece of evidence that the homepage is **already architected as a discovery surface** (featured / tonight / free+RSVP / nearby / online / recommended / categories). See `homepage-audit.md`.

### Design token source — **single source of truth**
`web/themes/custom/myeventlane_theme/src/scss/base/_tokens.scss` — one `:root` block defining the entire system as CSS custom properties.

Current brand palette (already bright/warm/optimistic):
```
--mel-color-bg:        #fff9f5   (warm cream)
--mel-color-surface:   #ffffff
--mel-color-primary:   #f26d5b   (coral)
--mel-color-accent:    #7c83fd   (periwinkle / indigo)
--mel-color-text:      #24303a   (slate ink)
--mel-gradient-hero:   coral→periwinkle wash
--mel-gradient-text:   coral→periwinkle
```
Plus full scales: radius (`--mel-radius-*`), spacing (`--mel-space-0..10`), shadows, motion/easing, glassmorphism (`--mel-glass-*`), section spacing, and semantic aliases (`--mel-ink`, `--mel-muted`, `--mel-peach`, `--mel-lilac`, `--mel-butter`, `--mel-sky`, `--mel-mint`).

> **Single most important reuse finding of the whole audit:** the entire visual system flows from **one `:root` token file**. A "Bright Edition" re-skin is overwhelmingly achievable by re-valuing these tokens, not by rewriting components. SCSS partials reference `colors.$mel-color-primary` (SCSS source) for compile-time uses (`src/scss/abstracts/_mixins.scss`), so the brand also has a **SCSS color abstract** that must be kept in sync with the `:root` block — confirm before re-skinning.

### SCSS structure
Organised under `src/scss/`:
`base/` (tokens, base), `abstracts/` (`_mixins.scss`, colors), `layout/` (regions, homepage-hero, page-home, navigation, header/footer), `components/` (~60 partials incl. `_event-card`, `_featured-carousel`, `_category-pills`, `_help-centre`, `_event-hero-festival`, `_rsvp`, `_cart`, `_value-cards`, `_empty-states` under utilities), `commerce/`, `front/`, `pages/`, `onboarding/`, `vendor/`, `utilities/`. Entry: `src/scss/main.scss` (+ `front.scss`, `auth-pages.scss`). A legacy `scss/` dir also exists (`scss/auth-pages.scss`, `scss/components/_event-hero.scss`) — minor, verify dead vs live before edits.

### JS structure
`src/js/` (Vite source: `skeleton.js`, `front-pie.js`) + legacy `js/` (`home-hero-rotator.js`, `home-hero-search.js`, `mel-cards.js`, `mel-chips.js`, `footer-accordion.js`). Behaviours attach via Drupal behaviors + `core/once`.

### Component source
SDC-style components under `web/themes/custom/myeventlane_theme/components/`:
`hero/`, `featured-events/`, `card-carousel/` (+ `carousel-nav`), `site-header/`, `mobile-drawer/`, `vibe-mixer/`, `browse/` (`mel-browse-filters`). Plus **223 Twig templates** under `templates/` (account, block, commerce, components, email, entity, event, field, …). Full inventory in `component-inventory.md`.

### Build
Vite. Assets injected from Vite manifest via `hook_library_info_alter()` (see `myeventlane_theme.libraries.yml` comments — `global-styling` and `myeventlane-global` pull from manifest). `vite.config.js`, `postcss.config.js`, `package.json` present.

### Library map (selected, `myeventlane_theme.libraries.yml`)
Discovery-relevant libraries already exist: `front`, `mel-events-discovery`, `mel-chips` (AJAX category chip bar), `mel-filters`, `mel-listing-header`, `home-hero-rotator`, `help_search`, `blog-landing`, `organiser-hub-landing`, `mel-card-behaviors`, `skeleton`.

---

## 4. Vendor theme — `myeventlane_vendor_theme` (OPERATIONAL WORKSPACE — keep separate)

### Purpose
Vendor console "dual-domain" experience (info.yml: *"Humanitix/Eventbrite quality UI"*). Activated by `VendorThemeNegotiator` on the vendor domain.

### Regions
Workspace shell, **not** a marketing surface: `sidebar` (shell nav), `vendor_header_left/center/right`, `content`, `sidebar_help`, `highlighted`, `vendor_footer_left/right/legal`.

### Design tokens — **deliberately different system**
`web/themes/custom/myeventlane_vendor_theme/src/scss/_root-tokens.scss`:
```
--mel-bg:     #F7F8FA   (cool grey — NOT the public cream)
--mel-surface:#ffffff
--mel-text:   #1A1A1A
--mel-focus:  #2563EB   (utility blue — NOT coral)
--mel-coral:  #FF8A8A   (softer/desaturated vs public #f26d5b)
--mel-accent: var(--mel-coral)
```
Tighter radii (`8/14/20px` vs public `12/16/24px`) and a flatter shadow scale. This is an intentional **operational-SaaS** aesthetic, distinct from the public brand.

### Structure
`src/scss/` with `layout/`, `components/` (`_account-summary`, `_best-event`, `_quick-actions`, `_vendor-alert`, `_onboarding-flow`, `_vendor-order-view`, `_notifications`, `_mel-header-footer`), `pages/`, `tokens/`, `base/`, `vendor/`. Own `src/js/`, `templates/`, `logo.svg`, Vite build.

> **Brand-rollout consequence:** "The Guide" is a *public discovery* persona. The vendor theme's token system, palette, and regions are correctly walled off. Per Phase 10 intent, the Bright Edition should re-skin **`myeventlane_theme`** and largely **leave `myeventlane_vendor_theme` untouched** beyond optional shared-logo/wordmark parity.

---

## 5. Admin theme — `myeventlane_admin` (Gin) — DO NOT TOUCH for brand

`base theme: gin`; libraries pull `claro/global-styling` + `gin/gin`. Minimal SCSS (`scss/admin.scss`, `scss/_platform-control-centre.scss`). `system.theme.yml` sets the active admin theme to **`gin`** directly. Admin is Drupal back-office; out of scope for the public brand territory.

---

## 6. Maintenance theme — `mel_maintenance`

`base theme: false`, standalone CSS, library `mel_maintenance/maintenance`. Sources the branded 403/404 styling that the public theme re-exposes via the `mel-error-pages` library (`css/errors.css`, which carries its own scoped `--mel-*` token block, e.g. `--mel-ink`, gradient backgrounds). Low-traffic but **brand-visible** (error pages) — include in Bright Edition pass.

---

## 7. Theme → route mapping summary

| Surface | Theme | Trigger |
|---|---|---|
| Public homepage, discovery, event detail, checkout, RSVP, help, public vendor profile | `myeventlane_theme` | default + forced public vendor routes |
| Vendor console / dashboard / studio | `myeventlane_vendor_theme` | vendor domain via `DomainDetector` |
| Vendor onboarding on public domain | per route `options._theme` | `RouteThemeNegotiator` |
| Admin (`_admin_route`) | `gin` | `AdminThemeNegotiator` / system.theme |
| 403 / 404 / maintenance | `mel_maintenance` assets via `myeventlane_theme/mel-error-pages` | core error handling |

---

## 8. What this means for the Bright Edition rollout

| Verdict | Item | Why |
|---|---|---|
| **REUSE (re-value)** | `myeventlane_theme/src/scss/base/_tokens.scss` `:root` block + SCSS color abstract | Single source of truth; whole UI re-skins from here |
| **REUSE** | Homepage region stack + discovery libraries (`mel-chips`, `mel-events-discovery`, `featured-events`, `card-carousel`) | Discovery surfaces already architected |
| **KEEP / DON'T TOUCH** | `myeventlane_vendor_theme` token system & regions | Operational workspace, correctly walled off |
| **KEEP / DON'T TOUCH** | `myeventlane_admin` / Gin | Back-office |
| **EVOLVE** | `mel_maintenance` error-page token block | Separate `--mel-*` scope, brand-visible |
| **DECIDE** | `myeventlane_radix` (Bootstrap 5 migration) | Not live; do not invest Bright Edition work here unless migration is being adopted — **confirm with team** |
| **VERIFY** | duplicate `VendorThemeNegotiator`, legacy `scss/` dir | Dead-code risk before any negotiation/SCSS edits |

**Open questions requiring team validation (not resolvable from repo):**
1. Is `myeventlane_radix` the intended future public theme, or abandoned? Determines whether Bright Edition targets stable9 theme or Radix.
2. Must the public `:root` palette and the SCSS `colors` abstract be edited together? (Confirm build pipeline.)
