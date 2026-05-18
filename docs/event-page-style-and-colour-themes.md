# Event page style and colour themes

## Product decision

- **Mockup 1 — Warm & Energetic** (`classic` + `coral`) is the default public event page for everyone.
- **Non-Pro vendors** always publish and render `classic` / `coral` (stored Pro-only values do not leak to the public page).
- **Pro organisers** (or admin) may choose **Bold & Immersive** (`immersive`) and any curated colour mood preset.
- Vendors choose from **MEL-designed presets only** (no custom hex picker).
- **Clean & Modern** (Mockup 2) is deferred to a future slice.
- Billing, checkout, and subscription purchase are **out of scope**; capability is gated via `EventStyleAccessManager` + `ProAccessService`.

## Vendor-facing labels

| Machine | Label |
|---------|--------|
| `classic` | Warm & Energetic |
| `immersive` | Bold & Immersive |
| `coral` | Coral Pop |
| `purple` | Violet Pulse |
| `mint` | Mint Night |
| `gold` | Golden Hour |
| `blue` | Ocean Blue |

## Classic vs Immersive

| | Warm & Energetic (`classic`) | Bold & Immersive (`immersive`) |
|---|-------------|----------------|
| Public render | All vendors (default) | Pro (`event_immersive_style`) or `administer nodes` |
| Feel | Warm, community-first, bright cards | Dark cinematic hero, bold contrast |
| Best for | Workshops, markets, community | Music, nightlife, launches |

## Fields (config/sync)

| Field | Machine name | Default |
|-------|----------------|---------|
| Event page style | `field_mel_page_style` | `classic` |
| Event theme colour | `field_mel_theme_colour` | `coral` |

Allowed style values: `classic`, `immersive`.  
Allowed colours: `coral`, `purple`, `mint`, `gold`, `blue`.

## Capability service

- **Service ID:** `myeventlane_event_studio.event_style_access`
- **Class:** `Drupal\myeventlane_event_studio\Service\EventStyleAccessManager`
- **Methods:** `canCustomizeEventPage()`, `canUseStyle()`, `canUseColour()`, `canUseImmersiveStyle()`
- **Pro feature key:** `event_immersive_style` (listed in `ProAccessService` gated features)
- **Admin bypass:** `administer nodes`
- Non-Pro: `classic` + `coral` only.
- Future: subscription state, boost purchase, or admin override can extend this service without changing Event Studio save paths.

## Public render resolver

- **Service ID:** `myeventlane_event_studio.event_page_style_resolver`
- **Class:** `Drupal\myeventlane_event_studio\Service\EventPageStyleResolver`
- Sanitizes stored values; builds modifier classes for Twig.
- **Public render:** uses event owner capability; non-Pro owners always resolve to `classic` / `coral` regardless of stored values.
- **Studio save:** validation errors for Pro-only style/colour; `resolveForPersistence()` downgrades defense-in-depth.

## Event Studio UX

- **Form:** `EventBrandingForm` (Branding workspace)
- **Section:** “Choose your event page style”
- **Present:** Warm & Energetic and Bold & Immersive option cards (`#mel_option_cards`)
- **Listen:** Selected style + colour radios reflect current node values
- **Ask:** Save writes `field_mel_page_style` and `field_mel_theme_colour` via `EventStudioSaveService::saveBrandingHero()`
- **Invite:** Non-Pro vendors see Immersive disabled with upgrade copy (no billing link in this slice)
- **Validation:** Unauthorized Immersive submission returns a form error (not silent downgrade)

## Public CSS / classes

Applied on the full event `<article>` (single template: `node--event--full.html.twig`):

- `mel-event-page--classic` | `mel-event-page--immersive`
- `mel-event-page--colour-{coral|purple|mint|gold|blue}`

CSS variables per colour:

- `--mel-event-accent`
- `--mel-event-accent-soft`
- `--mel-event-accent-contrast`

SCSS: `web/themes/custom/myeventlane_theme/src/scss/components/_event-page-themes.scss`

## Accessibility

- WCAG-minded contrast on Immersive cards and CTAs
- Minimum **44px** tap targets on primary actions
- `prefers-reduced-motion: reduce` respected (no motion-heavy effects)

## QA checklist

- [ ] Vendor without Pro: public page classic/coral; Immersive and non-coral moods locked; tampered POST rejected
- [ ] Pro vendor: Immersive saves and public page shows Immersive classes
- [ ] Admin: Immersive available
- [ ] Invalid style/colour values sanitize to classic/coral on public render
- [ ] Each colour preset updates CTA/accent on Classic and Immersive
- [ ] `npm run mel:lint` and `npm run mel:build` pass
- [ ] `config:status` clean after `drush cim`

## Non-goals (this slice)

- No duplicate event Twig templates per style
- No custom colour picker / arbitrary hex
- No billing or checkout for Pro upgrade
- No stock, warehouse, shipping, scanner, QR, entitlement, cart, or order changes

## Config notes (pre-PR audit)

### `field_mel_extras_book_placement`

**Classification: B — local-only drift (not required on `main` or this branch).**

| Check | Result |
|-------|--------|
| `config/sync` YAML | Not present on `main` or `feature/event-page-style-themes` |
| Custom module / theme code references | None on current branch |
| Active Drupal config | Does not exist (removed when `drush cim` aligned DB with sync during style-themes verification) |

The field appeared only on unmerged work (`85125b64` on `feature/event-studio-extras-editor`, not an ancestor of `main`) with `EventExtrasBookPlacementResolver` and booking-placement UI. That commit is **not** in `main` or PR #403’s merged tree. A copy existed in the local database only (“Only in DB” before `cim`).

**Action for this PR:** Do not recreate the field. Restoring it belongs in a separate PR if/when extras book-placement code and config are merged to `main`.

### `core.entity_form_display.node.event.studio_branding` drift

**Classification: benign ordering only.** Active vs sync differ only in YAML key order under `hidden:` (`field_mel_theme_colour` position). Both hide the same fields including `field_mel_page_style` and `field_mel_theme_colour`. No missing or extra fields.

### `crop.type.event_hero` drift

**Classification: pre-existing, unrelated.** Active vs sync differ only in key order (`id` / `label`). Not introduced by Event Page Style Themes.

## Future work

- Persist event-level entitlement when subscription/boost ends and downgrade stored style
- Cross-link: prior audit notes in `docs/event-page-style-and-colour-audit.md` when present on branch
