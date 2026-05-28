# Event page style and colour theme — Phase 0 audit

**Date:** 2026-05-18  
**Scope:** Audit only (no implementation).  
**Task:** Vendor-selectable MEL Classic vs MEL Immersive page style + preset colour themes on public event full pages, configured from Event Studio.

---

## Phase 0 gate — STOP before implementation

| Check | Result |
|-------|--------|
| `git status --short` | **Dirty** — 11 modified files (extras/book/event-full work in progress) |
| Merge in progress | No |
| Rebase in progress | No |
| Current branch | `feature/event-studio-extras-editor` |
| Recent commits | Extras editor, cart toasts, booking extras UI (not page-style work) |

**Recommendation:** Stash or commit the in-flight extras/event-full changes before starting Phases 1–9 on a dedicated branch (e.g. `feature/event-page-style-theme`). Implementation must not be mixed with uncommitted extras UI unless intentionally combined.

---

## 1. Current event full page rendering architecture

### Templates (single path — no Classic/Immersive split today)

| Asset | Role |
|-------|------|
| `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig` | Canonical full event page markup |
| `web/themes/custom/myeventlane_theme/templates/node--event.html.twig` | Wrapper; attaches `myeventlane_event_full` library |
| `web/themes/custom/myeventlane_theme/templates/commerce/myeventlane-event-book.html.twig` | Extends module book template only (out of scope for style variants unless book should inherit — **not in this slice**) |

**Wrapper classes today (Twig):**

```twig
<article{{ attributes.addClass('mel-event', 'mel-event--v2') }}>
```

- No `mel-event-page--classic` / `mel-event-page--immersive` / colour modifier classes yet.
- `.mel-event-page` is referenced in `_event-cards-festival.scss` but is **not** applied on `node--event--full.html.twig`.

### Preprocess

| Hook | File | Notes |
|------|------|-------|
| `myeventlane_theme_preprocess_node()` | `myeventlane_theme.theme` ~L2452 | General node vars (cards, listings) |
| `myeventlane_theme_preprocess_node__event()` | `myeventlane_theme.theme` ~L3804 | Hero `image_src`, `mel_address`, `mel_category_*`, organiser, related events, operational addon teaser ~L4131, booking sidebar vars |

**Gap:** No read of page style / theme fields; no `attributes` class injection for style/colour variants in preprocess (recommended in Phase 4 via `hook_preprocess_node__event` or dedicated helper called from there).

### Libraries and build

| Piece | Location |
|-------|----------|
| Page library | `myeventlane_theme.libraries.yml` → `myeventlane_event_full` (JS: `event-full-trust-rotator.js`; CSS via Vite `global-styling` / `main.css`) |
| SCSS entry | `src/scss/main.scss` → `@use 'components/event-full'` |
| Event full styles | `src/scss/components/_event-full.scss` (primary), `_event-hero.scss`, `pages/_event.scss`, `_event-node.scss` |
| Build | Root `npm run mel:build` → theme `npm ci && vite build`; lint `npm run mel:lint` (hero check + stylelint CSS) |

### Visual baseline (“Mockup 1 / Classic”)

Current production direction on full pages already matches **warm MEL Classic**:

- `$mel-color-bg: #fff9f5`, primary coral `$mel-color-primary: #f26d5b`
- Featured hero (`mel-event-hero--featured-style`), white surface cards, sticky booking sidebar
- Recent in-branch layout work: main column order (What to expect → Extras → Event Information → Share), icon-only share chips, compact fav save in sidebar

**Immersive (Mockup 3)** is **not** implemented; must be additive SCSS under wrapper modifiers only.

---

## 2. Event Studio branding / settings architecture

### Section registry

- **Discovery:** `plugin.manager.myeventlane_event_studio_section` → `EventStudioSectionManager`
- **Definition:** PHP 8 `#[EventStudioSection(...)]` on classes under `Plugin/EventStudioSection/`
- **Rendering:** `EventStudioSectionRenderer` + workspace shell twig `mel-event-studio-workspace.html.twig`

**Relevant sections:**

| ID | Route | Form / render target |
|----|-------|---------------------|
| `branding` | `myeventlane_event_studio.workspace_branding` | `EventBrandingForm` (`renderTarget: form:...EventBrandingForm`) |
| `settings` | `myeventlane_event_studio.workspace_settings` | `EventSettingsForm` extends publish form; `renderTarget: settings_with_readiness` |

### Branding form today

- **Class:** `EventBrandingForm` extends `EventStudioBaseForm`
- **Persists:** Hero via `EventStudioSaveService::saveBrandingHero()` + `mel` form fragment (not entity fields except image widget)
- **Form display:** `core.entity_form_display.node.event.studio_branding` — **only** `field_event_image` (image_widget_crop + `event_hero` crop)
- **UI:** Custom shell `mel-es-field-group--branding`, focal presets JS (`mel-branding-hero-tools.js`)

### Settings form today

- Publish/readiness-focused; not the right primary home for visual page style unless product wants it under “Manage Event” instead of Branding.

### Access pattern (reuse)

- `EventStudioBaseForm::assertVendorEvent()` → `myeventlane_vendor.event_access_checker` (`EventVendorAccessChecker`)
- Route access: `EventStudioAccess` + `CurrentVendorResolver`

### Recommended Event Studio placement

**Primary: extend `EventBrandingForm`** with a second field group, e.g. `mel-es-field-group--event-page-style`:

- Aligns with “what feeling should this event page have?” (Probe → Present)
- Same save/submit pattern as hero (`persistWizardMel` or direct field save on node — **prefer explicit field API** on node for `field_mel_page_style` / `field_mel_theme_colour` for config export and preprocess simplicity)
- Add fields to `studio_branding` form display **hidden from raw node edit** for other modes; vendor edits only via Studio

**Alternative:** Small card on Settings — only if Branding becomes overcrowded; Branding is the better UX fit.

**Do not** add a new top-level sidebar section unless product insists (adds navigation weight).

---

## 3. Existing Pro / subscription / boost / capability logic

### Services found (delegate, do not reimplement billing)

| Service ID | Class | Use for immersive gate |
|------------|-------|-------------------------|
| `myeventlane_pro.pro_access` | `ProAccessService` | User-level Pro: `mel_pro` role; `isPro()`, `canAccessFeature($account, $feature)` |
| N/A (vendor entity) | `VendorSubscriptionService` | Vendor-level: `myeventlane_vendor.is_pro` field + `hook_myeventlane_vendor_is_pro_vendor_alter` |
| Boost | `myeventlane_boost_entitlement` entity | **Event-scoped promotion**, not vendor Pro — **do not** use for immersive style |

**`ProAccessService` PRO_FEATURES today:** `advanced_analytics`, `audience_messaging`, `boost_priority` — immersive is **not** listed (non‑Pro users would pass `canAccessFeature` for unknown keys). New service must **not** rely on that accidentally.

**Recommended capability seam:**

```text
Service: myeventlane_event_studio.event_style_access
Class:   Drupal\myeventlane_event_studio\Service\EventStyleAccessManager
Method:  canUseImmersiveStyle(NodeInterface $event, AccountInterface $account): bool
```

**Delegation order (documented default):**

1. Users with `administer nodes` or dedicated permission (e.g. `administer event page style`) → allow (for support).
2. Else if `myeventlane_pro` enabled: `ProAccessService::isPro($account)` → allow.
3. Else optional: `VendorSubscriptionService::isProVendor($vendorId)` where `$vendorId` from `field_event_vendor` — **confirm product**: subscription is user-based today (`mel_pro` role), vendor flag may diverge; reconcile in implementation.
4. Else `FALSE`.
5. `@todo` hooks / future: subscription SKU, boost bundle, admin override entity — **no billing in this slice**.

**Do not** grant immersive to all vendors by default.

---

## 4. Existing event fields (config/sync)

### Page style / colour

- **`field_mel_page_style`:** does **not** exist
- **`field_mel_theme_colour`:** does **not** exist

### Naming convention on event bundle

Existing `field_mel_*` on event:

- `field_mel_extras_book_placement`
- `field_mel_op_capabilities`
- `field_mel_sup_*` (support-a-thon)

**Recommended new fields (per product spec):**

| Field | Storage type | Allowed values | Default |
|-------|--------------|----------------|---------|
| `field_mel_page_style` | `list_string` | `classic` → MEL Classic; `immersive` → MEL Immersive | `classic` |
| `field_mel_theme_colour` | `list_string` | `coral`, `purple`, `mint`, `gold`, `blue` | `coral` |

**Config location:** Follow `field_mel_extras_book_placement` pattern — YAML under `config/sync/` (and optional install in `myeventlane_schema` or `myeventlane_event_studio` if repo convention requires module-owned definitions). **Confirm during Phase 1** which module owns field config (likely `myeventlane_schema` or commerce/event_studio depending on team rule).

**Form displays:**

- Add to `node.event.studio_branding` (visible or via custom widgets in `EventBrandingForm`)
- Hide on default/wizard displays vendors should not use
- Optional minimal admin form display for support fallback

**No arbitrary colour picker** in this slice.

---

## 5. Frontend / SCSS strategy (recommended)

### Single template, class-driven variants

On `<article>` (via preprocess `attributes`):

```text
mel-event
mel-event--v2
mel-event-page--classic | mel-event-page--immersive
mel-event-page--colour-coral | … | mel-event-page--colour-blue
```

### CSS custom properties (wrapper-scoped)

On `.mel-event-page--colour-*` (and immersive overrides where needed):

```css
--mel-event-accent
--mel-event-accent-soft
--mel-event-accent-contrast
```

Map presets to token-safe values (contrast-checked). Reuse `tokens/_colors.scss` bases:

| Preset | Suggested accent source |
|--------|-------------------------|
| coral | `$mel-color-primary` |
| purple | `$mel-color-accent` |
| mint | derived mint (e.g. mix accent-alt) |
| gold | `$mel-color-orange` |
| blue | `$mel-color-accent` / navy pairing |

### SCSS file organisation

- Extend `_event-full.scss` with nested blocks:
  - `.mel-event-page--classic { … }` (explicit defaults / no-op where current styles already classic)
  - `.mel-event-page--immersive { … }` (dark section bg, hero overlay, card surfaces, extras emphasis)
  - `.mel-event-page--colour-*` (custom properties only)
- Keep hero overrides coordinated with `_event-hero.scss` under same modifiers
- `prefers-reduced-motion`: respect existing `tokens/_motion.scss` patterns

### Public render fallback rule (recommended)

When stored style is `immersive` but `EventStyleAccessManager::canUseImmersiveStyle()` is **false** at **view** time (anonymous/public):

- Render **`mel-event-page--classic`** (do not expose Pro layout without capability)
- Optionally log once at `notice` for vendor misconfiguration

When saving in Event Studio:

- Reject or downgrade to `classic` + validation message if immersive selected without capability (**no silent save**).

---

## 6. Implementation risks

| Risk | Mitigation |
|------|------------|
| Dirty working tree / mixed PRs | New branch; isolate page-style commits |
| `git checkout` accidentally reverted `node--event--full.html.twig` during recent work | Re-verify template matches intended layout before Phase 5 |
| Pro user vs Pro vendor mismatch | `EventStyleAccessManager` documents and tests both paths explicitly |
| Duplicating `ProAccessService` | Inject and delegate; add `immersive_event_page` feature key only if extending `PRO_FEATURES` is approved |
| Twig branching for Classic/Immersive | **Forbidden** — classes + SCSS only |
| Accessibility on Immersive dark cards | Define contrast pairs per colour preset; test WCAG AA on CTA and body text |
| Config drift | `ddev drush config:status` after Phase 1; only new field + form display YAML |
| Event Studio save path | Use same vendor assert + field save as other event fields; unit test unauthorized immersive |
| Book page / checkout | Out of scope — do not attach immersive classes to book route unless product asks |

---

## 7. Explicit non-goals (this initiative)

- Billing, Stripe, subscription purchase flows, or boost checkout for immersive unlock
- Separate Twig templates per style
- Raw node edit UI for vendors
- Arbitrary hex colour input
- Stock, warehouse, shipping, scanner, QR, entitlement, cart, checkout, or order mutation changes
- Browser E2E tests (unless existing pattern added later)
- Changing operational extras commerce logic

---

## 8. Phase-by-phase implementation checklist (for handoff)

| Phase | Deliverable |
|-------|-------------|
| 1 | `field_mel_page_style`, `field_mel_theme_colour` config + form displays |
| 2 | `EventStyleAccessManager` + service definition |
| 3 | `EventBrandingForm` UI (style cards + colour selector + Pro lock) |
| 4 | Preprocess class injection on event full |
| 5 | `_event-full.scss` (+ hero) variants |
| 6 | PHPUnit: access manager + form validation |
| 7 | `docs/event-page-style-and-colour-themes.md` |
| 8 | validate / lint / build / targeted tests |
| 9 | Final report |

---

## 9. Files likely touched (implementation preview)

| Area | Paths |
|------|--------|
| Config | `config/sync/field.storage.node.field_mel_page_style.yml`, `field.field.node.event.*`, `core.entity_form_display.node.event.studio_branding.yml` |
| Module | `myeventlane_event_studio/src/Service/EventStyleAccessManager.php`, `myeventlane_event_studio.services.yml`, `EventBrandingForm.php` |
| Theme | `myeventlane_theme.theme` (preprocess), `_event-full.scss`, possibly `_event-hero.scss` |
| Tests | `myeventlane_event_studio/tests/src/Unit/EventStyleAccessManagerTest.php`, kernel/form tests if present |
| Docs | `docs/event-page-style-and-colour-themes.md` |

---

## 10. Audit commands reference

```bash
git status --short
git branch --show-current
git log --oneline --decorate -10
# Pro / capability grep (sample)
grep -R "ProAccessService\|VendorSubscriptionService\|mel_pro" web/modules/custom/myeventlane_pro web/modules/custom/myeventlane_vendor -n
```

---

**Phase 0 complete.** No code changes except this document. Proceed to Phase 1 only after working tree is clean or branched, and product confirms Pro rule (user role vs vendor `is_pro` vs both).
