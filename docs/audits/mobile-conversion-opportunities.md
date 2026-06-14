# Mobile Conversion Opportunities

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-14  
**Scope:** Public conversion funnel friction — homepage, discovery, event page, checkout, RSVP  
**Method:** Repository evidence only (Twig, SCSS, JS, routing, prior audits). No code proposals.

---

## Branch verification

| Check | Expected | Actual |
|-------|----------|--------|
| Branch | `feature/mobile-foundation` | `feature/brand-rollout-phase-1a-discovery-copy` |
| Working tree | Clean | Dirty |

**Browser testing:** Not executed in this audit. Findings from static code review and prior audit documents.

---

## Summary — highest conversion opportunity

| Rank | Surface | Opportunity | Revenue lever |
|------|---------|-------------|---------------|
| 1 | **Book / checkout** | Reduce multi-step density and align mobile summary/CTA | Direct ticket revenue |
| 2 | **Event detail → book** | Mobile CTA visibility and booking panel order | Funnel conversion |
| 3 | **Discovery filters** | Touch targets and active state clarity | Browse → click-through |
| 4 | **Homepage hero** | Category chip discoverability + search | Top-of-funnel |
| 5 | **RSVP path reliability** | Tier data completeness on book page | Free event conversion |

---

## Homepage friction

### O1 — Header breakpoint mismatch (768 vs 900px)

| Field | Detail |
|-------|--------|
| **Evidence** | `_site-header.scss`: desktop nav hidden `@media (width <= 900px)` (lines 58, 117, 177, 253); `header.js` line 221: `matchMedia('(min-width: 768px)')` |
| **User impact** | Between 768–899px, JS and CSS may disagree on mobile vs desktop nav state — confusing nav, hidden CTAs, or double menus |
| **Implementation risk** | **Low–medium** — SCSS + JS alignment only; no routing changes |
| **Estimated effort** | **S** (1–2 days) — token decision + test header drawer |

### O2 — Hero category chip styling gap (partially addressed)

| Field | Detail |
|-------|--------|
| **Evidence** | `mel-discovery-event-page-polish-audit.md` P1: hero used `mel-category-chip` while `_hero.scss` styled `.mel-chip`; Task 9 added unified rules |
| **User impact** | Category shortcuts below fold on mobile reduce discovery depth |
| **Implementation risk** | **Low** — SCSS/Twig only |
| **Estimated effort** | **S** — verify Task 9 merged; retest `/home` |

### O3 — Featured carousel + hero density

| Field | Detail |
|-------|--------|
| **Evidence** | `mobile-route-priority-map.md`: homepage 7/10; `_featured-carousel.scss`, `_home-hero.scss` |
| **User impact** | Carousel swipe/scroll competition with primary "Find events" CTA |
| **Implementation risk** | **Low** — locked hero variants rule applies; layout polish only |
| **Estimated effort** | **M** — hero is high-visibility; careful diff |

### O4 — Create-event CTA prominence on mobile

| Field | Detail |
|-------|--------|
| **Evidence** | `myeventlane-home-hero.html.twig`, `region--header.html.twig`, `mobile-drawer.twig` reference `myeventlane_vendor.create_event_gateway` |
| **User impact** | Vendor acquisition secondary to attendee discovery on mobile drawer |
| **Implementation risk** | **Low** |
| **Estimated effort** | **S** — IA/copy decision, not Commerce |

---

## Discovery friction

### O5 — Category pill / chip class fragmentation

| Field | Detail |
|-------|--------|
| **Evidence** | `component-system-inventory.md`: `.mel-chip`, `.mel-category-chip`, `.mel-pill`, `.mel-filter-chip`; templates: `mel-page-header.html.twig`, `hero.html.twig`, `mel-events-discovery-filters.html.twig` |
| **User impact** | Inconsistent tap targets and active states across `/events`, category pages, homepage |
| **Implementation risk** | **Low–medium** — multiple SCSS files; regression on listing pages |
| **Estimated effort** | **M** — consolidate naming in docs first; incremental SCSS |

### O6 — Horizontal category strip overflow

| Field | Detail |
|-------|--------|
| **Evidence** | `mel-page-header.html.twig` — `.mel-category-strip` horizontal pills; `_mel-browse.scss` strip rules |
| **User impact** | Horizontal scroll without clear affordance on 390px; categories off-screen |
| **Implementation risk** | **Low** |
| **Estimated effort** | **S–M** — scroll snap / fade edge pattern |

### O7 — Event card touch and motion

| Field | Detail |
|-------|--------|
| **Evidence** | Task 9 added `prefers-reduced-motion` on `_event-card.scss`; card hover lift/zoom |
| **User impact** | Cards are primary click target to event detail |
| **Implementation risk** | **Low** |
| **Estimated effort** | **S** — verify 44px min touch on card meta links |

### O8 — Search route integration

| Field | Detail |
|-------|--------|
| **Evidence** | `myeventlane_search.routing.yml`: `/search`, `/search/autocomplete` |
| **User impact** | Mobile search UX not scored in Phase 2A map |
| **Implementation risk** | **Unknown** — autocomplete mobile keyboard behavior not audited in depth |
| **Estimated effort** | **M** — needs dedicated pass; **repository evidence not found** for mobile-specific search SCSS |

---

## Event page friction

### O9 — Booking sidebar below long main column (addressed in Task 10)

| Field | Detail |
|-------|--------|
| **Evidence** | `mel-event-full-page-polish-audit.md` P1: mobile flex order — sidebar `order: 1`, main `order: 2` |
| **User impact** | Users scroll past content before seeing book CTA |
| **Implementation risk** | **Low** if fix present on branch |
| **Estimated effort** | **S** — verify CSS deployed |

### O10 — Multi-column hero / gallery breakpoint splits

| Field | Detail |
|-------|--------|
| **Evidence** | `_event-full.scss` (29 `@media`); `_event-gallery.scss` (900px, 767px, 899px) |
| **User impact** | Layout shifts, cropped images, horizontal scroll on gallery |
| **Implementation risk** | **Medium** — large SCSS file |
| **Estimated effort** | **L** — phased breakpoint alignment |

### O11 — Mobile sticky CTA bar vs in-page booking panel

| Field | Detail |
|-------|--------|
| **Evidence** | `_event-mobile-cta.scss`; `node--event--full.html.twig` mobile bar; `mel-booking-summary.js` |
| **User impact** | Duplicate CTAs or obscured content if bar overlaps footer |
| **Implementation risk** | **Medium** — JS + CSS coordination |
| **Estimated effort** | **M** |

### O12 — CTA label consistency (partially addressed)

| Field | Detail |
|-------|--------|
| **Evidence** | Task 10: `mel_booking_action_label`, "Book free RSVP" copy; `BookController` CTA resolver |
| **User impact** | Wrong label reduces trust on RSVP vs paid |
| **Implementation risk** | **Low** |
| **Estimated effort** | **S** |

### O13 — Share control touch targets (partially addressed)

| Field | Detail |
|-------|--------|
| **Evidence** | Task 10: `.mel-social` 40px → 44px |
| **User impact** | Secondary to conversion but affects viral loop |
| **Implementation risk** | **Low** |
| **Estimated effort** | **S** |

---

## Checkout friction

### O14 — Multi-pane density on single column

| Field | Detail |
|-------|--------|
| **Evidence** | `_checkout.scss` (20 `@media`); checkout flow panes: `mel_buyer_details`, `ticket_holder_paragraph`, `mel_donation`, `mel_legal_consent`, `payment_information`, sidebar `order_summary` (`mel-booking-checkout-verification.md`) |
| **User impact** | Long scroll before payment; abandonment on mobile |
| **Implementation risk** | **High** if pane order/visibility touched — Commerce config |
| **Estimated effort** | **L** — visual-only reorder already done in cart audit Twig |

### O15 — Sidebar order summary on narrow viewports

| Field | Detail |
|-------|--------|
| **Evidence** | `commerce-checkout-form--with-sidebar.html.twig`; `mel-operational-checkout.css` |
| **User impact** | Summary below fold or squeezed — users lack price anchor while filling forms |
| **Implementation risk** | **Medium** — layout only |
| **Estimated effort** | **M** — sticky summary pattern |

### O16 — Table-like order review

| Field | Detail |
|-------|--------|
| **Evidence** | `views-view-table--commerce-checkout-order-summary.html.twig`; `mobile-route-priority-map.md` checkout "table-like order review" |
| **User impact** | Horizontal scroll on line items |
| **Implementation risk** | **Medium** |
| **Estimated effort** | **M** |

### O17 — Trust copy repetition (addressed Task 11)

| Field | Detail |
|-------|--------|
| **Evidence** | `mel-cart-checkout-visual-trust-polish.md`: reduced to two compact trust strings |
| **User impact** | Scroll fatigue before pay button |
| **Implementation risk** | **Low** |
| **Estimated effort** | **S** — verify on branch |

### O18 — Cart → checkout handoff clarity

| Field | Detail |
|-------|--------|
| **Evidence** | Task 11: `mel-cart-event-overview` chips vs misleading grouped table |
| **User impact** | Users unsure which event tickets belong to |
| **Implementation risk** | **Low** |
| **Estimated effort** | **S** |

---

## RSVP friction

### O19 — RSVP redirect to unified book page

| Field | Detail |
|-------|--------|
| **Evidence** | `myeventlane_rsvp.routing.yml`: `/event/{event}/rsvp` → `RsvpRedirectController::redirectToBooking`; book builds `RsvpPublicForm` when RSVP tiers exist |
| **User impact** | Extra redirect hop; URL expectations (`/rsvp` vs `/book`) |
| **Implementation risk** | **Low** for UX copy; **medium** if route changed |
| **Estimated effort** | **S** (copy/labels) / **M** (route IA) |

### O20 — Missing ticket tiers blocks RSVP

| Field | Detail |
|-------|--------|
| **Evidence** | `mel-booking-checkout-verification.md`: events 1375/1377 show RSVP CTA but `field_ticket_types` count = 0 → "RSVP is not yet available" |
| **User impact** | **High** — dead-end after marketing RSVP event |
| **Implementation risk** | **High** — data model / Event Studio publish pipeline, not CSS |
| **Estimated effort** | **L** — vendor tooling + validation; outside mobile SCSS scope |

### O21 — RSVP thank-you mobile layout

| Field | Detail |
|-------|--------|
| **Evidence** | `rsvp-thankyou.css`; route `/event/{event}/rsvp/thank-you` |
| **User impact** | Post-conversion calendar/add-to-wallet CTAs |
| **Implementation risk** | **Low** |
| **Estimated effort** | **S** |

### O22 — Paid vs RSVP book form density

| Field | Detail |
|-------|--------|
| **Evidence** | `myeventlane-event-book.html.twig` — `mel-booking-summary` mobile panel; `_booking-page.scss` |
| **User impact** | Ticket selection + summary visibility drives add-to-cart |
| **Implementation risk** | **Medium** — Commerce form |
| **Estimated effort** | **M** |

---

## Opportunity priority for Phase 1

| ID | Opportunity | User impact | Risk | Effort | Phase 1? |
|----|-------------|-------------|------|--------|----------|
| O1 | Header 768/900 alignment | High | Low–med | S | **Yes** |
| O14–O16 | Checkout mobile density | **Critical** | Med–high | L | **Yes** (visual scope) |
| O22 | Book page summary panel | **Critical** | Med | M | **Yes** |
| O9–O11 | Event detail CTA order/bar | High | Med | M | **Yes** |
| O5–O6 | Discovery chips/strip | High | Low–med | M | Partial |
| O20 | RSVP tier data gaps | High | **High** | L | **No** — data/vendor |
| O10 | Event gallery breakpoints | Med | Med | L | Phase 2 |

---

## What not to treat as mobile conversion work

| Item | Reason |
|------|--------|
| Stripe / payment gateway changes | Commerce safety rules |
| Checkout pane enable/disable in config | Config export + Commerce risk |
| Event Studio publish logic | Vendor backend |
| Vendor orders table redesign | Blocked on design system (`mobile-design-system-readiness.md`) |

---

**Mobile conversion audit complete. No code proposed.**
