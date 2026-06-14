# Mobile Experience Audit

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)
**Audit date:** 2026-06-14
**Method:** Evidence-based. MEL principle (CLAUDE.md/AGENTS.md): *"Use mobile-first responsive layouts."* Builds on existing `docs/audits/mobile-breakpoint-inventory.md` and `breakpoint-unification-plan.md`.

---

## 1. Responsive foundation (evidence)

| Aspect | Evidence | State |
|---|---|---|
| Mobile-first mandate | CLAUDE.md §Theme | Policy |
| **Canonical breakpoints** | `src/scss/tokens/_breakpoints.scss` — `$mel-breakpoints` (xs 0, sm 480, md 768, lg 1024, xl 1280) + `mel-break()` mixin | ✅ Unified (Phase 2B) |
| Vendor breakpoints | `myeventlane_vendor_theme/.../tokens/_breakpoints.scss` (`respond-to`/`respond-down`) | Consumes canonical (2B) |
| **Ad-hoc media queries** | ~120 SCSS partials use hardcoded `@media` (479–959px), many distinct max-widths (720/640/500/440/420…) | ⚠️ **Fragmented — flagged "Phase 2C" debt** |
| Touch targets | `min-width: 44px` (13+ uses) | ✅ Touch-friendly |
| Modern CSS | `@media (width >= 1200px)` range syntax | ✅ |
| Reduced motion | `@media (prefers-reduced-motion: reduce)` (`_commerce.scss:82`) | ✅ Accessible |
| Responsive images | **No `*.breakpoints.yml`** found (per breakpoint plan) | Gap |

---

## 2. Per-surface mobile behaviour

| Surface | Mobile pattern (evidence) | State |
|---|---|---|
| **Homepage** | Region-driven sections stack vertically into scrollable **discovery rails** (`page--front.html.twig` + `mel-section-shell`); hero rotator + search; `home-hero-rotator.js` | ✅ Naturally mobile (vertical rails) |
| **Discovery / browse** | Card grids reflow; category **chips** AJAX bar (`mel-chips.js`); skeleton→content (`skeleton.js`); `mel-events-discovery` library | ✅ Good |
| **Event page** | Sidebar "sticky on desktop, collapsible on mobile" (`event-sidebar.html.twig`); **sticky booking panel** `.mel-card--sticky .mel-booking-panel`; cinematic carousel slides (`_event-cinematic-convergence.scss`) | ✅ Strong; carousel is mobile-friendly |
| **Checkout** | **Sticky action bar** + sticky aside that collapses on mobile (`_commerce.scss:491,655,1060`) | ✅ Good |
| **Cart** | Sticky summary (`_cart.scss:262`) | ✅ |
| **RSVP** | `_rsvp.scss` responsive; thank-you flow | ✅ |
| **Help Centre** | Chip-filtered search (`_help-search.scss`), assistant page | ✅ |
| **Mobile nav** | Mobile drawer SDC (`components/mobile-drawer/`) with transform transitions; `mobile_nav` region | ✅ |

---

## 3. Where new Guide discovery surfaces fit — without harming mobile UX

| Guide surface | Mobile fit | Risk | Recommendation |
|---|---|---|---|
| Guide rails (Hidden Gems / Recommended) on homepage | Append as more vertical rails — same pattern as existing sections | Low | Reuse `mel-section-shell` + card-carousel; lazy-load below fold |
| **Vibe Mixer** on homepage/discovery | Chip + slider UI; chips already mobile-proven (`mel-chips`) | Low–med | Ensure sliders are touch-friendly (44px); collapse into chips-first on small screens |
| Post-login Guide card | Single stacked card (`mel-post-login-hub`) | Low | Reuse existing hub layout |
| "Ask the Guide" entry | Help assistant page already responsive | Low | Reuse |
| Related-events / "more gems" on event page | Horizontal carousel below detail grid | **Medium** | **Must not collide with the existing sticky booking panel** — place above the sticky region or in document flow, test z-index/scroll |
| A mobile "Guide" persistent/bottom element | — | **High** | ⚠️ **Avoid a new fixed bottom bar** — event page (sticky booking), cart, and checkout already use sticky/fixed bars; a Guide bottom bar would **collide/stack**. If desired, gate it off transactional surfaces only |

---

## 4. Mobile risks the brand rollout must respect

| Risk | Evidence | Mitigation |
|---|---|---|
| Sticky-element collision | Multiple sticky bars (booking, cart, checkout) | No new global fixed/bottom Guide bar; scope any sticky Guide UI away from booking/checkout |
| Breakpoint fragmentation | ~120 ad-hoc `@media` (Phase 2C debt) | Token **re-skin is safe** (colors only); any new *layout* should use `mel-break()`, not new hardcoded values |
| Image weight on mobile | No `*.breakpoints.yml`; hero PNGs (`mel-hero-home.png`, `mel-hero-mobile.png`) | New Bright Edition art must ship mobile variants (mobile hero slot already exists) + lazy-load |
| Touch target regressions on chips/sliders | 44px standard in place | Keep ≥44px on Vibe Mixer controls |

---

## 5. Verdicts

| Verdict | Item |
|---|---|
| **SAFE TO REUSE** | Canonical `mel-break()` tokens, mobile drawer, sticky booking/cart/checkout, chips/skeleton JS, vertical-rail homepage, mobile hero slot |
| **NEEDS EVOLUTION** | Token re-skin (safe); ensure Vibe Mixer sliders are touch-sized; ship mobile Bright Edition hero art |
| **ADD (low risk)** | Guide rails as additional homepage sections; post-login Guide card; related "gems" carousel on event page (in-flow, not colliding with sticky CTA) |
| **AVOID (high risk)** | New global fixed/bottom Guide bar; new hardcoded breakpoints; un-optimised mobile hero imagery |
| **PRE-WORK (optional)** | Phase 2C breakpoint consolidation reduces long-term risk but is **not a blocker** for a token re-skin |

**Bottom line:** Mobile is in good shape and **already discovery-oriented** (vertical rails, chips, sticky CTAs). New Guide surfaces fit naturally as **more rails and a post-login card**. The only real mobile hazard is **sticky-element collision** on transactional pages — so the Guide should be in-flow content, not a new fixed bar. A token re-skin carries near-zero mobile-layout risk.
