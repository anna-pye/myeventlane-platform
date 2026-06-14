# Component Inventory

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)
**Audit date:** 2026-06-14
**Method:** Evidence-based. Classifications: **SAFE TO REUSE** · **NEEDS EVOLUTION** · **REPLACE**.

> **Builds on** the pre-existing `docs/audits/component-system-inventory.md` (Phase 2A, 2026-06-13), which already catalogues buttons/cards/forms/tables/tabs/nav/badges/chips/modals/toasts/empty-states and their duplicate ownership. This document does **not** re-derive that; it re-classifies the inventory **for the Bright Edition brand territory** and adds discovery/recommendation/CTA components that brand work depends on.

---

## A. How components are sourced (evidence)

Three component layers (per Phase 2A + `theme-architecture.md`):

1. **SDC-style components** — `web/themes/custom/myeventlane_theme/components/<name>/<name>.twig` (+ optional `.scss`).
2. **Twig template overrides** — `web/themes/custom/myeventlane_theme/templates/**` (223 templates).
3. **SCSS partials** — `src/scss/components/*.scss` (~60) governed by the single `:root` token file.

All visual styling flows from `src/scss/base/_tokens.scss`. **Most "evolution" below is a token re-value, not a component rewrite.**

---

## B. Discovery / brand-critical components (the ones "The Guide" needs)

| Component | Location | Twig | SCSS | Brand verdict | Notes |
|---|---|---|---|---|---|
| **Hero (SDC)** | `components/hero/hero.twig` | ✅ | via theme | **NEEDS EVOLUTION** | Re-skin + new headline voice ("What can I discover this weekend?"). Structure reusable. |
| **Front hero (rotator)** | `templates/components/front/_front-hero.html.twig` + `js/home-hero-rotator.js`, `home-hero-search.js` | ✅ | `layout/_homepage-hero.scss` | **NEEDS EVOLUTION** | Rotating hero + search already exists. CLAUDE.md flags "locked hero variants" — confirm before touching. |
| **Vibe Mixer** | `components/vibe-mixer/vibe-mixer.twig` | ✅ | theme | **SAFE TO REUSE** (high value) | Chip selector + energy/budget sliders (`['Chill','Loud','Cute','Artsy','Family','Outdoors']`). **Already a "Guide" discovery interaction** — strongest existing asset for the new territory. |
| **Featured Events (SDC)** | `components/featured-events/featured-events.twig` | ✅ | `components/_featured-carousel.scss` | **SAFE TO REUSE** | Carries `curator_line` defaulting to **"Curated by MyEventLane"** — editorial/Guide voice already baked in. Links to `view.upcoming_events.page_events`. |
| **Card Carousel (SDC)** | `components/card-carousel/card-carousel.twig` + `carousel-nav.twig` | ✅ | `_featured-carousel.scss`, `_event-sidebar-carousel.scss` | **SAFE TO REUSE** | Reusable rail for any "rail of gems". |
| **Recommended-for-you rail** | `templates/views/views-view-unformatted--front-recommended-events--block-1.html.twig` | ✅ | theme | **SAFE TO REUSE** | A **`front-recommended-events` View block already exists**. Recommendation surface present today. |
| **Post-login hub** | `templates/mel-post-login-hub.html.twig` | ✅ | theme | **NEEDS EVOLUTION** | Personalised landing referencing "recommend"; prime Guide-moment surface. |
| **Category pills / type pill** | `templates/components/event-type-pill.html.twig`, `_front-pills.html.twig`; SCSS `_category-pills.scss` + `_mel-events-filters.scss` | ✅ | ✅ | **NEEDS EVOLUTION** | Duplicate naming (pills vs chips) noted in 2A. Brand: unify + re-skin as discovery chips. |
| **Browse filters (SDC)** | `components/browse/mel-browse-filters.twig` | ✅ | theme | **SAFE TO REUSE** | Exposed-filter UI for discovery. |
| **Front pie / values / calendar / search** | `templates/components/front/_front-pie.html.twig`, `_front-values.html.twig`, `_front-calendar.html.twig`, `_front-search.html.twig` + `js/front-pie.js`, `mel-calendar-hero.html.twig` | ✅ | `_front-pie.scss` | **NEEDS EVOLUTION** | Homepage discovery widgets. Re-skin to Bright Edition; "values" block is a brand-voice slot. |
| **Event card** | `components/event-card/mel-event-card.html.twig`, `event/event-card.html.twig`; SCSS `_event-card.scss` | ✅ | ✅ | **NEEDS EVOLUTION** | Core discovery unit. Re-skin (imagery, "gem" badge potential). `js/mel-cards.js` handles image-brightness contrast classes — keep. |
| **Category follow CTA** | `templates/components/mel-category-follow-cta.html.twig` | ✅ | theme | **SAFE TO REUSE** | "Follow this category" = built-in re-engagement / Guide hook. |
| **Mobile drawer (SDC)** | `components/mobile-drawer/mobile-drawer.twig` + `.scss` | ✅ | ✅ | **SAFE TO REUSE** | Mobile nav. Re-skin only. |
| **Site header (SDC)** | `components/site-header/site-header.twig` + `.scss` | ✅ | ✅ | **NEEDS EVOLUTION** | Logo/wordmark swap + nav voice. Canonical public header (per 2A). |

---

## C. General UI primitives (from Phase 2A — brand verdicts)

| Family | Canonical owner (per 2A) | Brand verdict | Brand note |
|---|---|---|---|
| Buttons `.mel-btn` (+`--primary/cta/coral/ghost…`) | public `_buttons.scss` + `_mel-buttons.scss` | **NEEDS EVOLUTION** | Token re-value. Consolidate vendor duplicate (don't reskin vendor). |
| Cards `.mel-card` | public `_cards.scss` + `_mel-cards.scss` | **NEEDS EVOLUTION** | Token re-value only. |
| Forms `.mel-form-system` | `_mel-forms.scss` / `_mel-form-states.scss` | **SAFE TO REUSE** | Mostly vendor/checkout; minimal brand surface. |
| Tables `.mel-table` | `_mel-tables.scss` | **SAFE TO REUSE** | Operational, not a public brand surface. |
| Badges / status | `_mel-states.scss`, `_sla-badge.scss`, vendor `_badges.scss` | **SAFE TO REUSE** | Add a *new* "Hidden Gem" badge variant rather than replace. |
| Chips / pills | `_help-centre.scss` chips, `_category-pills.scss` | **NEEDS EVOLUTION** | Unify duplicate vocabulary; central to discovery. |
| Modals / overlays / drawers | `_mel-modals.scss`, `_mel-overlays.scss`, `_mel-disclosure.scss` | **SAFE TO REUSE** | Re-skin only. |
| Alerts / toasts | `_toasts.scss`, vendor `_vendor-alert.scss` | **SAFE TO REUSE** | Re-skin only. |
| Empty states | `_mel-empty-states.scss` / `utilities/_empty-states.scss` (split vocab) | **NEEDS EVOLUTION** | High-value Guide-moment surface ("nothing here yet — let the Guide find you something"). Consolidate `.mel-empty` vs `.mel-empty-state` first. |
| Search / filters | `_search-form.scss`, `_mel-events-filters.scss` | **NEEDS EVOLUTION** | Discovery-critical; re-skin + copy. |
| Value cards / feature blocks | `_value-cards.scss`, `_feature-block.scss` | **NEEDS EVOLUTION** | Brand-voice slots. |
| Help centre / support | `_help-centre.scss`, `_help-search.scss`, `_support.scss`, `mel-support-layer.html.twig`, `contextual-help-card.html.twig` | **NEEDS EVOLUTION** | Foundation for "Ask the Guide" (see `help-centre-audit.md`). |
| Wizard / onboarding | `mel-wizard.html.twig`, `_event-wizard.scss`, `_wizard-step-card.scss`, `onboarding/` | **NEEDS EVOLUTION** (vendor side) | Guide-moments in onboarding (see `onboarding-audit.md`). |

---

## D. Vendor console components — **DO NOT RE-SKIN for Bright Edition**

Per `theme-architecture.md`, the vendor theme uses a separate token system (`_root-tokens.scss`, cool grey/blue). Vendor-scoped duplicates (`myeventlane_vendor_theme/src/scss/components/*`: `_account-summary`, `_best-event`, `_quick-actions`, `_vendor-alert`, `_vendor-order-view`, `_notifications`, KPI cards, vendor tables/forms/tabs) are **operational workspace** components.

| Verdict | Action |
|---|---|
| **KEEP / DON'T TOUCH** | Vendor dashboard widgets, KPI cards, vendor order/event tables, Event Studio shell CSS (`mel-event-studio-*.css`) |
| Optional | Shared logo/wordmark parity only |

---

## E. Components to REPLACE / retire

| Item | Evidence | Why |
|---|---|---|
| Duplicate empty-state vocab (`.mel-empty` vs `.mel-empty-state` vs `--listing`) | Phase 2A "Duplicate / conflicting patterns" | Consolidate to one before brand copy lands. Not a visual replace — a vocabulary replace. |
| Duplicate button/card owners (public vs vendor vs `mel-*`) | Phase 2A | Resolve ownership before re-skin to avoid drift. |
| Legacy `scss/` dir (`scss/auth-pages.scss`, `scss/components/_event-hero.scss`) | `theme-architecture.md §3` | Confirm dead vs live; retire if dead. |

> **Repository evidence not found** for: dedicated "carousel/rail" beyond card-carousel SDC (rails are View-driven); a standalone "drawer" beyond `mobile-drawer`; a generic "banner" component (banners are achieved via hero/value-cards/CTA panels).

---

## F. Brand-rollout classification summary

| Verdict | Count emphasis | Representative components |
|---|---|---|
| **SAFE TO REUSE** | Largest bucket | Vibe Mixer, Featured Events (curator line), Card Carousel, Recommended-for-you rail, Browse filters, Mobile drawer, modals/toasts/tables/forms, Category follow CTA |
| **NEEDS EVOLUTION** | Token re-value + copy | Hero, front hero rotator, event card, category pills/chips, site header, empty states, search/filters, value cards, help/support, post-login hub |
| **REPLACE / RETIRE** | Small | Duplicate vocab consolidation (empty states, button/card ownership), dead legacy `scss/` |
| **KEEP / DON'T TOUCH** | Vendor + admin | Vendor console widgets, Event Studio shell, Gin admin |

**Headline finding:** the brand territory already has its anchor components — **Vibe Mixer**, **"Curated by MyEventLane" Featured Events**, a **Recommended-for-you View block**, **Category follow CTA**, and **Browse filters**. The Guide does not require new component architecture; it requires (1) a token re-skin, (2) copy/voice, and (3) wiring these existing surfaces into a coherent narrative.
