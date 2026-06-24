# Frontend Performance & Maintainability Audit

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-24  
**Scope:** Public theme (`myeventlane_theme`), vendor theme (`myeventlane_vendor_theme`)  
**Type:** Audit + low-risk maintenance only — no visual redesign, no build-system migration.

---

## Prompt self-review (pre-execution)

| Risk class | Assessment |
|------------|------------|
| **Drupal** | Library paths are rewritten at runtime via `hook_library_info_alter()`; removing assets or changing entries without updating PHP/libraries breaks pages. Commerce/checkout JS must not be tree-shaken or double-loaded. |
| **Build** | `dist/` is gitignored; CI and pre-commit run `npm run mel:build`. Auditing requires a local build; sizes are post-build, not committed. |
| **Deployment** | Missing `dist/` on deploy → manifest errors logged (`MEL ERROR: Vite manifest missing`). Both themes must build in CI before artifact packaging. |
| **Maintenance** | Monolithic public `main.css` (~1.1 MB) is architectural, not a build bug. Vendor theme compiles duplicate `@mel-theme` partials under `.mel-vendor`. |

**Execution plan:** Measure builds → map CSS ownership → verify dead assets against libraries/Twig/Vite → document dependency health → apply only browserslist DB update (Phase 6).

---

## Phase 1 — Build audit

Commands run:

```bash
npm run mel:build
npm run mel:lint
composer validate --check-lock
ddev drush cr
```

### Public theme (`myeventlane_theme`)

| Asset | Size (raw) | Gzip (Vite report) | Notes |
|-------|------------|--------------------|-------|
| `dist/assets/main.*.css` | **1,114.59 KB** | 153.39 KB | Single bundle from `src/js/main.js` → imports `main.scss` |
| `dist/assets/main.*.js` | 94.19 KB | 28.33 kB | Swiper + theme behaviors |
| `dist/assets/front.*.css` | 9.74 KB | 2.46 KB | Separate entry `src/scss/front.scss` |
| `dist/assets/account-dropdown.*.js` | 1.88 KB | 0.67 KB | Legacy library; manifest-rewritten |
| `dist/assets/front-pie.*.js` | 1.68 KB | 0.59 KB | **Built but not attached** (see Phase 3) |
| `dist/.vite/manifest.json` | 0.68 KB | — | Used by `myeventlane_theme_library_info_alter()` |

**Vite config:** `sourcemap: false` (production). Hashed filenames under `dist/assets/`.

**Build warnings:** None from Vite. npm reports `Unknown env config "devdir"` (environment/npmrc; not theme code).

### Vendor theme (`myeventlane_vendor_theme`)

| Asset | Size (raw) | Gzip (Vite report) | Notes |
|-------|------------|--------------------|-------|
| `dist/main.css` | **538.54 KB** | 71.04 KB | Scoped `.mel-vendor` + `@mel-theme` imports |
| `dist/workspace.css` | 5.11 KB | 1.35 KB | Event Studio shell |
| `dist/vendor-wizard.css` | 2.61 KB | 0.77 KB | Wizard-only surface |
| `dist/main.js` | 9.17 KB | 3.04 KB | Drupal behaviors |
| `dist/auto.js` | **202.29 KB** | 69.31 KB | Chart.js lazy chunk (`import('chart.js/auto')`) |
| `dist/main.js.map` | 26.55 KB | — | Source maps enabled |
| `dist/auto.js.map` | **792.23 KB** | — | Largest map file |
| `dist/.vite/manifest.json` | 0.93 KB | — | Stable filenames (no hash) |

**Vite config:** `sourcemap: true` (production). CSS code-split across four entries.

### Duplicated bundles / build paths

| Issue | Evidence |
|-------|----------|
| **Vendor wizard double compile** | `package.json` runs `vite build` (emits `vendor-wizard.css`) then `build:vendor-wizard` (`sass … --style=compressed`), overwriting the Vite output. Same filename, redundant work. |
| **Public `@mel-theme` in vendor** | Vendor `main.scss` `@include meta.load-css('@mel-theme/…')` for 10+ public partials — second compiled copy under `.mel-vendor`, not a duplicate HTTP request on public pages. |
| **Legacy `js/` vs Vite `src/js/`** | Public theme: route-specific scripts in `js/` (libraries.yml) plus Vite bundle in `src/js/main.js`. Intentional split documented in `docs/audits/frontend-build-ownership.md`. |

### Source maps

| Theme | Production source maps | Impact |
|-------|------------------------|--------|
| Public | Off | No map files in `dist/` |
| Vendor | On | ~819 KB maps (`auto.js.map` dominates); not served to browsers unless devtools fetch them |

---

## Phase 2 — CSS ownership

See also: `docs/audits/css-ownership-map.md`, `docs/audits/frontend-build-ownership.md`, `docs/audits/event-studio-css-ownership.md`.

### Boundaries (repository-confirmed)

| Surface | Owner | Entry | Built output |
|---------|-------|-------|--------------|
| Public site | `myeventlane_theme` | `src/scss/main.scss`, `src/scss/front.scss` | `main.*.css`, `front.*.css` |
| Vendor console | `myeventlane_vendor_theme` | `src/scss/main.scss`, `workspace.scss`, `vendor-wizard.scss` | `main.css`, `workspace.css`, `vendor-wizard.css` |
| Event Studio layout | `myeventlane_event_studio` module CSS | Static CSS files | Loaded via module libraries |
| Feature routes | Custom modules | `*.libraries.yml` + `css/` | Attached per route |

**Do not merge themes.** Vendor intentionally imports public SCSS via Vite alias `@mel-theme` → `../myeventlane_theme/src/scss`.

### Duplicate SCSS basenames (28 files)

Shared partial names in both themes (separate files, separate token namespaces):  
`_buttons.scss`, `_cards.scss`, `_forms.scss`, `_navigation.scss`, `_support-panel.scss`, `_wizard.scss`, etc.

Vendor also loads public copies of: `mel-components`, `mel-cards`, `mel-navigation`, `live-operations`, `event-studio`, `help-centre`, `klaro-consent`, …

### Duplicate token systems

| System | Location |
|--------|----------|
| Public canonical | `src/scss/base/_tokens.scss`, `src/scss/tokens/*` |
| Vendor scoped | `src/scss/tokens/*`, `root-tokens.scss` (CSS vars on `.mel-vendor`) |
| Bridge | `@mel-theme` alias for shared component rules only |

### Entry-level orphans (not in `main.scss` / `front.scss` import graph)

Verified with import-graph script + manual review:

| File | Lines | Status |
|------|-------|--------|
| `components/_cart.scss` | ~515 | **Superseded** — cart rules live in `commerce/_commerce.scss` (also imported) |
| `components/_event-wizard.scss` | ~577 | **Superseded** — module ships `myeventlane_event/css/event-wizard.css` |
| `layout/_footer-layout.scss` | 1 | **Placeholder** — comment only |
| `layout/_homepage-hero.scss` | ~120 | **Superseded** — `.homepage-hero` in `layout/_homepage.scss` (imported) |
| `components/_event-cards-festival.scss` | — | Not imported; festival variants imported individually |

Account partials (`account-nav`, `account-cards`, …) are **not** orphans — pulled via `pages/_account.scss` → `@use 'pages/account'` in `main.scss`.

Staff-shell imports (`vendor-studio-editor`, `studio-inspector`) were **removed in Phase 2B** per `docs/audits/event-studio-css-ownership.md`. Prior audit docs may still mention them.

---

## Phase 3 — Dead assets

Verification method: grep for `attach_library`, `*.libraries.yml`, Vite entries, Twig `{# include #}` / `embed`.

### Confirmed dead (safe to remove in a follow-up PR)

| Asset | Evidence |
|-------|----------|
| `src/js/category-colors.js` | Exported helper; zero imports in repo |
| `src/js/hero.js` | Placeholder export; zero imports |
| `src/js/homepage-toggle.js` | Defines `Drupal.behaviors.homepageEventToggle`; **not** in any library or Vite entry |
| `layout/_footer-layout.scss` | Single-line placeholder |
| `components/_cart.scss` | Not imported; duplicated in `commerce/_commerce.scss` |
| `templates/components/front/_front-pie.html.twig` | No PHP/Twig include references |
| `dist/assets/front-pie.*.js` (build artefact) | Vite entry exists; **no library attachment** in `myeventlane_theme.libraries.yml` or `hook_library_info_alter()` |

### Unattached but potentially intentional (do not remove without product sign-off)

| Asset | Evidence |
|-------|----------|
| `js/stripe-fallback.js` | Documented in legal/build plans; **not** in `libraries.yml` today — payment safety net, not wired |
| `src/scss/components/_event-wizard.scss` | Large partial; module CSS is active path — keep until module/theme consolidation |

### Live assets (not dead)

| Asset | Attachment |
|-------|------------|
| `src/js/account-dropdown.js` | Vite entry + `account-dropdown` library + manifest alter |
| `js/*.js` (17 files) | Individual libraries in `myeventlane_theme.libraries.yml` |
| `src/js/skeleton.js` | `skeleton` library |
| Vendor `js/form-protection.js`, `js/footer-accordion.js`, etc. | Vendor `libraries.yml` |

---

## Phase 4 — Dependency health

### npm audit (after build, 2026-06-24)

**Public theme** (`vite@7.3.2`): 3 vulnerabilities — esbuild (low), js-yaml (moderate), vite (high, dev-server/Windows paths).

**Vendor theme** (`vite@8.0.5`): 2 vulnerabilities — js-yaml (moderate), vite (high).

**Root** (`package.json`): husky/stylelint only; OpenAI + Swiper runtime deps unused by theme builds.

### Deprecated packages (vendor `npm ci` warnings)

- `eslint@8.57.1` — EOL; vendor uses `eslint@^8.54.0`
- Transitive: `inflight`, `rimraf@3`, `glob@7`, `@humanwhocodes/*`

### eslint / stylelint version split

| Package | Public theme | Vendor theme |
|---------|--------------|--------------|
| stylelint | ^17.6.0 | ^15.11.0 |
| stylelint config | standard | standard-scss |
| eslint | — | ^8.54.0 (EOL) |

Public `mel:lint` only runs scoped stylelint on ~12 partials, not full SCSS tree.

### Browserslist

Vendor build previously warned: *caniuse-lite is 6 months old*. **Fixed in this audit:** `npx update-browserslist-db@latest` in vendor theme (`package-lock.json` only). Rebuild: warning cleared.

### Upgrade recommendations

| Change | Risk | Recommendation |
|--------|------|----------------|
| `npm audit fix` (patch) | Low–medium | Run per-theme in a dedicated PR; re-run `mel:build` |
| eslint 9.x | **High** | Requires flat config migration; defer |
| stylelint 17.x on vendor | Medium | Align with public theme in dedicated PR |
| vite 7 → 8 on public | **High** | Major; test Commerce + HMR on DDEV |
| chart.js major bump | Medium | Test all `[data-chart-id]` dashboards |

---

## Phase 5 — Performance findings

### Why public `main.css` exceeds 1 MB

Not a duplicate-import bug — **monolithic entry by design**. `main.scss` imports **~170+ partials** (~58,857 lines SCSS source).

Largest contributors (source lines):

| Partial | Lines |
|---------|-------|
| `_event-full.scss` | 3,506 |
| `_checkout.scss` | 3,341 |
| `_event-card.scss` | 2,307 |
| `_help-centre.scss` | 1,861 |
| `_live-operations.scss` | 1,817 |
| `_event-book.scss` | 1,741 |
| `commerce/_commerce.scss` | 1,734 |
| `_event-page-themes.scss` | 1,550 |

The 58 `components/_mel-*.scss` governance partials total **~2,618 lines** — not the primary driver.

** gzip:** 153 KB over the wire — acceptable for HTTP/2 single bundle, but every public page loads full CSS regardless of route.

### Why vendor `main.css` exceeds 500 KB

| Factor | Detail |
|--------|--------|
| 84 local SCSS files (~24,044 lines) | Dashboard, forms, builder, analytics |
| `@mel-theme` recompilation | ~10 public partials embedded under `.mel-vendor` |
| Parallel vendor components | `_mel-builder.scss` (1,268 lines), `_event-form.scss` (718), `_dashboard-live-ops.scss` (2,498) |
| Dual button/card systems | Local `_buttons.scss` + `@mel-theme/components/mel-components` |

### Large source maps (vendor)

`auto.js.map` at **792 KB** — Chart.js chunk. Disable production sourcemaps or use `build.sourcemap: 'hidden'` in a future PR if maps are not needed in CI artefacts.

### Repeated imports

Sass `@use` deduplicates within a compilation unit. Vendor `@mel-theme` imports are **intentional duplication across themes**, not accidental double `@use` in one entry.

**Optimisation candidates (future phases, not executed here):**

1. Route-level CSS splitting for public theme (high effort; needs library + attach audit).
2. Trim vendor `@mel-theme` imports to only what Event Studio/dashboard need.
3. Remove vendor production sourcemaps.
4. Drop redundant `build:vendor-wizard` sass step if Vite output is sufficient.

---

## Phase 6 — Changes made (this audit)

| Change | File | Rationale |
|--------|------|-----------|
| Updated caniuse-lite / browserslist DB | `web/themes/custom/myeventlane_vendor_theme/package-lock.json` | Clears build warning; no target browser changes |
| Audit documentation | `docs/audits/frontend-performance-audit.md` | This file |

**Not changed (by design):** no SCSS/JS deletions, no vite config edits, no dependency major upgrades, no visual changes.

---

## Validation results

| Command | Result |
|---------|--------|
| `npm run mel:build` | **Pass** — both themes built |
| `npm run mel:lint` | **Pass** — hero check + scoped stylelint |
| `composer validate --check-lock` | **Pass** |
| `ddev drush cr` | **Pass** |
| Manual page smoke | **Not run in this session** — recommend: homepage, `/events`, event node, checkout, `/vendor`, Event Studio, `/my-account` |

---

## Residual risks

1. **Monolithic CSS** — public pages pay ~153 KB gzip for unused route styles; acceptable short-term, limits Core Web Vitals headroom on mobile.
2. **Vendor Chart.js chunk** — 202 KB raw / 69 KB gzip loaded on first chart render; correct lazy pattern, but heavy on analytics dashboards.
3. **Dead Vite entry `front-pie`** — wastes build time; pie UI template appears unused.
4. **npm audit** — vite/esbuild findings affect **dev server**, not production static assets; still worth patching.
5. **`stripe-fallback.js` unwired** — documented safety net not attached; checkout relies on Commerce Stripe library only.

---

## Safe to commit

**Yes**, with a focused commit:

- `docs/audits/frontend-performance-audit.md` (new)
- `web/themes/custom/myeventlane_vendor_theme/package-lock.json` (browserslist DB)

No theme SCSS/JS/Twig/Drupal config changes. Re-run `npm run mel:build` in CI before merge.

---

## Recommended follow-up PRs (ordered, low risk → high)

1. Remove confirmed dead source files listed in Phase 3 (no UX change if templates truly unused).
2. Remove Vite `front-pie` entry or attach via library if product still wants pie widget.
3. Vendor: remove duplicate `build:vendor-wizard` sass step after diffing CSS output.
4. Vendor: `sourcemap: false` or `'hidden'` for production.
5. `npm audit fix` per theme with CI rebuild.
6. Public CSS route-splitting spike (plan only — touches many `attach_library` call sites).
