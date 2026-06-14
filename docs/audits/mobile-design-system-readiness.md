# Mobile Design System Readiness

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-14  
**Inputs:** `canonical-design-system.md`, `component-system-inventory.md`, `mobile-breakpoint-inventory.md`, `css-ownership-map.md`  
**Status:** Readiness assessment only — no implementation

---

## Branch verification

| Check | Expected | Actual |
|-------|----------|--------|
| Branch | `feature/mobile-foundation` | `feature/brand-rollout-phase-1a-discovery-copy` |
| Working tree | Clean | Dirty |

---

## Readiness categories

| Status | Meaning |
|--------|---------|
| **Ready** | Repository evidence supports implementation with a single canonical owner and acceptable mobile baseline |
| **Needs Consolidation** | Component exists but conflicting patterns, duplicate owners, or token mismatch block safe mobile work |
| **Blocked** | Missing repository evidence, unresolved ownership, or high Commerce/vendor risk |

---

## Ready

Components with sufficient evidence and a clear canonical path for Phase 1 mobile work (visual/breakpoint alignment only):

| Component | Canonical owner | Evidence | Mobile notes |
|-----------|-----------------|----------|--------------|
| **Public container / layout spacing** | `layout/_container.scss` | Uses `mel-break(md)`, `mel-break(lg)` | Token-aligned |
| **Governed form states** | `_mel-form-states.scss` | Part of `.mel-form-system` | Touch targets documented in `_mel-buttons.scss` for form actions |
| **Governed modals / overlays** | `_mel-modals.scss`, `_mel-overlays.scss` | Single BEM system | Drawer patterns exist |
| **Governance states / readiness** | `_mel-states.scss`, `_mel-readiness.scss` | Studio/vendor governed UI | Not primary mobile funnel |
| **Trust / policy banners** | `_mel-trust-policy.scss`, `_mel-reassurance.scss` | Checkout/cart trust copy patterns | Used in cart/checkout polish |
| **RSVP thank-you** | `myeventlane_rsvp/css/rsvp-thankyou.css` | Low overlap risk per css-ownership-map | Small surface |
| **Checkout completion** | `commerce-checkout-completion.html.twig` | Post-purchase; visual-only scope | Lower risk than checkout form |
| **Public mobile CTA bar (event)** | `_event-mobile-cta.scss` | Imported in `main.scss` | Exists; may need breakpoint alignment only |
| **Klaro consent** | `_klaro-consent.scss` | Cross-theme intentional include | Mobile overlay styling present |

**Caveat:** "Ready" means **safe to touch for breakpoint/token alignment**, not that the component is fully mobile-optimized at 390px.

---

## Needs Consolidation

Components with repository-confirmed duplication or conflicting patterns — mobile implementation requires consolidation first or strict scoped diffs:

| Component | Conflicting patterns | Owners | Blocker for mobile |
|-----------|---------------------|--------|-------------------|
| **Breakpoint system** | Public `sm: 480px`; vendor `sm: 640px`; hardcoded 900/767/479px | `_breakpoints.scss` (both themes); 470+ `@media` in theme SCSS | Inconsistent collapse points across funnel |
| **Buttons `.mel-btn`** | Variants: primary, secondary, ghost, destructive, sm, lg, touch, coral | Public + vendor `_buttons.scss`; `mel-event-studio.css` | Touch size inconsistency; gradient vs flat |
| **Cards `.mel-card`** | `--static`, `--governed`, event extensions | Public + vendor + `_event-card.scss` | Different surface tokens |
| **Forms (vendor Event Studio)** | `.mel-form-system` vs heavy overrides | `_mel-forms.scss` vs vendor `_event-form.scss` (94× `!important`) | Field layout unpredictable on narrow screens |
| **Tables** | Stack at 767px in multiple files | `_tables.scss`, `_mel-tables.scss`, vendor `_event-table.scss` | No single mobile card-stack pattern for orders |
| **Tabs** | Console, wizard, simple-tabs | Vendor `_tabs.scss`, `simple-tabs.css`, `_wizard.scss` | Three sources |
| **Public navigation** | Overlay nav + drawer | `_site-header.scss` (900px) vs `header.js` (768px) | State mismatch 768–900px |
| **Empty states** | `.mel-empty` vs `.mel-empty-state` | `_mel-empty-states.scss`, vendor `_empty-states.scss`, `_mel-browse.scss` | Markup/class drift |
| **Category chips / pills** | `.mel-chip`, `.mel-category-chip`, `.mel-pill`, `.mel-filter-chip` | Hero, browse, event-full, category-pills | Partially fixed in Task 9; naming still fragmented |
| **Toasts / alerts** | `.mel-toast` vs vendor alert | `_toasts.scss`, `_vendor-alert.scss` | Duplicate stacks |
| **Badges / status** | `.mel-badge`, `.mel-status`, `.mel-status-badge` | Vendor `_badges.scss`, `_sla-badge.scss` | Naming duplication |
| **Event page layout** | 29 `@media` in `_event-full.scss`; gallery 900px cuts | Theme SCSS + `event-builder-preview.css` | Large surface; incremental only |
| **Checkout layout** | 20 `@media`; module `mel-operational-checkout.css` | `_checkout.scss` + module | Sidebar + pane density |
| **Cart layout** | Active `commerce/_commerce.scss` vs orphan `_cart.scss` | Theme | Confusion risk |
| **Event Studio shell** | Module grid + theme polish + vendor builder | `mel-event-studio-shell.css`, `_event-studio.scss`, `_mel-builder.scss` | **Primary consolidation target** before mobile layout |
| **Event Studio navigation** | Section nav CSS split | `mel-event-studio-nav.css`, `_event-studio.scss` | Drawer pattern proposed, not implemented |
| **Vendor KPI / metrics** | `.mel-kpi-card` vs `_mel-metrics.scss` | Vendor theme | 479px hardcoded stack |
| **Vendor theme public SCSS recompile** | `@mel-theme/components/*` in vendor `main.scss` | Structural duplication | Changes to public tokens affect vendor twice |

---

## Blocked

Components or system claims that **cannot** be implemented safely without additional evidence, product sign-off, or prerequisite work:

| Item | Reason | Evidence |
|------|--------|----------|
| **390px enforced baseline** | No 390px token in theme SCSS | `mobile-breakpoint-inventory.md`: "Evidence not found" |
| **Drupal responsive image breakpoints** | No `*.breakpoints.yml` in repo | `mobile-breakpoint-inventory.md` |
| **Single design system (implemented)** | Acceptance criteria in `canonical-design-system.md` §8 not met | Multiple button/card/breakpoint owners |
| **Event Studio mobile sidebar → drawer** | Module owns layout grid; 280px fixed columns | `mel-event-studio-shell.css` lines 19–42 |
| **Vendor orders mobile table → cards** | No canonical responsive table component; table-first UI score 3/10 | `mobile-route-priority-map.md`; `_event-table.scss` |
| **Vendor form `!important` reduction** | Requires Drupal/Gin markup alignment | 94× in `_event-form.scss` |
| **Commerce checkout logic / pane changes** | High Commerce/Stripe risk | Project rules; checkout flow config |
| **`myeventlane_core/css/studio-layout.css` role** | Relationship to Event Studio shell not traced | `css-ownership-map.md` |
| **Dead SCSS import removal** | `vendor-studio-editor`, `studio-inspector` — usage not verified in this audit | Vendor `main.scss` 92–93 |
| **Unified sm token (480 vs 640)** | Requires product sign-off per `canonical-design-system.md` | Conflicting today |
| **Legacy radix components** | Parallel breakpoints (700px, 1100px) | `myeventlane_radix` — active theme status not re-verified in this audit |

---

## Component readiness matrix

| Component | Status | Phase 1 touch? |
|-----------|--------|----------------|
| Breakpoint tokens | **Needs Consolidation** | **Yes** — prerequisite |
| `.mel-btn` | **Needs Consolidation** | Scoped public-only OK |
| `.mel-card` | **Needs Consolidation** | Scoped public-only OK |
| `.mel-form-system` | **Needs Consolidation** (vendor) | Public checkout panes only |
| Public header nav | **Needs Consolidation** | **Yes** — align 900→768 |
| Event mobile CTA | **Ready** | **Yes** |
| Checkout SCSS | **Needs Consolidation** | Visual-only **Yes** |
| Event full layout | **Needs Consolidation** | Incremental only |
| Event Studio shell | **Blocked** | **No** — Phase 2+ |
| Vendor orders tables | **Blocked** | **No** — needs pattern |
| Empty states | **Needs Consolidation** | Low priority |
| Modals / overlays | **Ready** | As needed |
| RSVP thank-you | **Ready** | Low priority |

---

## Design system acceptance gap

From `canonical-design-system.md` §8, MEL cannot claim a single implemented design system until:

1. One breakpoint map in both themes — **not met** (480 vs 640 sm)
2. One `.mel-btn` definition — **not met**
3. One `.mel-card` base — **not met**
4. Event Studio 390px without horizontal scroll — **not verified** (manual QA required)
5. Reduced `!important` in `_event-form.scss` and `_mel-builder.scss` — **not met**

---

## Recommended consolidation sequence (documentation)

| Step | Component | Unblocks |
|------|-----------|----------|
| 1 | Breakpoint token map + deprecate 900px header cut | Public nav, event gallery, checkout |
| 2 | Public `.mel-btn` touch sizes documented as canonical | Book, checkout CTAs |
| 3 | Remove dead vendor SCSS imports (after grep verification) | Build clarity |
| 4 | Event Studio shell mobile (module-led) | Vendor P0 routes |
| 5 | Table → card stack pattern in `_mel-tables.scss` | Orders, attendees |
| 6 | Vendor button/card dedup | Vendor dashboard |

---

**Design system readiness review complete. No implementation.**
