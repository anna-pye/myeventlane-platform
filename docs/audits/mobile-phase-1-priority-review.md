# Mobile Phase 1 Priority Review

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-14  
**Inputs:** `mobile-route-priority-map.md`, `event-route-reference-map.md`, `mel-v2-current-build-audit.md`, routing YAML, SCSS/JS evidence  
**Status:** Audit only — no implementation

---

## Branch verification (required preflight)

| Check | Expected | Actual |
|-------|----------|--------|
| Branch | `feature/mobile-foundation` | **`feature/brand-rollout-phase-1a-discovery-copy`** |
| Working tree | Clean | **Dirty** — modified Twig on homepage/discovery |

**Note:** Audit documents from Phase 2A exist on disk; this review was produced from repository evidence despite branch mismatch. Switch to `feature/mobile-foundation` with a clean tree before implementation work.

---

## Priority scale

| Tier | Definition |
|------|------------|
| **P0** | Critical mobile routes — primary conversion or vendor revenue operations; poor mobile score or funnel-blocking friction |
| **P1** | High-value routes — significant traffic or revenue adjacency; moderate mobile risk |
| **P2** | Secondary routes — operational or supporting surfaces; can follow P0/P1 |
| **P3** | Defer — acceptable baseline today or low traffic/complexity ratio |

Scores reference `mobile-route-priority-map.md` (10 = likely OK at 390px; 1 = high risk).

---

## Public conversion funnel

### P0 — Homepage

| Field | Detail |
|-------|--------|
| **Route / path** | `view.frontpage.page_1` → `/home` (`config/sync/system.site.yml`: `front: /home`) |
| **Mobile score** | **7/10** (`mobile-route-priority-map.md`) |
| **Why P0** | Primary entry point for discovery and organiser CTAs; hero, featured carousel, category chips |
| **User impact** | First impression; mobile nav (`header.js`, `_site-header.scss`); category chip touch targets |
| **Revenue impact** | Indirect — routes users to events and `/create-event` gateway |
| **Risk** | **Medium UX** — 900px header breakpoint vs 768px md token and `header.js` matchMedia at 768px (`_site-header.scss`, `header.js` line 221) |
| **Effort** | **Low–medium** — better baseline than vendor surfaces; breakpoint alignment + hero/carousel polish |

**Evidence:** `_homepage.scss` (6 `@media`), `_front-page.scss` (9), `_home-hero.scss`, `_featured-carousel.scss`, `myeventlane-home-hero.html.twig`.

---

### P0 — Discovery (events listing)

| Field | Detail |
|-------|--------|
| **Routes / paths** | `view.upcoming_events.page_events` → `/events`; `page_category`, `page_today`, `page_this_weekend`, `page_free`; `myeventlane_search` → `/search` |
| **Mobile score** | **Not scored separately** in Phase 2A map; grouped with homepage funnel |
| **Why P0** | Core browse path between homepage and event detail; filter chips and event cards |
| **User impact** | Horizontal category strips, filter chips, card grid density |
| **Revenue impact** | Direct — drives event page and book/checkout entry |
| **Risk** | **Medium UX** — chip/pill class fragmentation (`.mel-chip`, `.mel-category-chip`, `.mel-pill`, `.mel-filter-chip` per `component-system-inventory.md`); 44px touch targets partially addressed in Task 9 audit |
| **Effort** | **Medium** — multiple Views templates + `_event-card.scss`, `_mel-browse.scss`, `_category-pills.scss` |

**Evidence:** `mel-discovery-event-page-polish-audit.md`; templates under `views-view--upcoming-events--*`, `mel-page-header.html.twig`.

---

### P0 — Event detail (public full page)

| Field | Detail |
|-------|--------|
| **Route / path** | `entity.node.canonical` (event bundle); path alias typical |
| **Mobile score** | **6/10** |
| **Why P0** | Decision point before book/RSVP; sticky mobile CTA bar exists |
| **User impact** | Multi-column hero/gallery; booking sidebar order on mobile; share/CTA hierarchy |
| **Revenue impact** | **High** — primary CTA to `/event/{node}/book` |
| **Risk** | **Medium–high UX** — `_event-full.scss` (29 `@media`), `_event-gallery.scss` (900px/767px splits), `_event-mobile-cta.scss` |
| **Effort** | **High** — large SCSS surface; JS mobile bar (`mel-booking-summary.js`) |

**Evidence:** `node--event--full.html.twig`, `mel-event-full-page-polish-audit.md`, `_event-full.scss`.

---

### P0 — Book / RSVP entry

| Field | Detail |
|-------|--------|
| **Routes / paths** | `myeventlane_commerce.event_book` → `/event/{node}/book`; `myeventlane_rsvp.public_rsvp_form` → `/event/{event}/rsvp` (redirects to unified booking per `RsvpRedirectController`) |
| **Mobile score** | **Inherits checkout/booking** (~5–6/10) |
| **Why P0** | Unified paid + RSVP conversion hinge; ticket selection form → cart |
| **User impact** | Ticket selector density; mobile booking summary panel |
| **Revenue impact** | **Critical** — cart creation via `TicketSelectionForm` / `RsvpPublicForm` |
| **Risk** | **Medium technical** — Commerce form markup; `event-builder-preview.css` overlap; tier data gaps (events without `field_ticket_types` per booking verification audit) |
| **Effort** | **Medium–high** — `myeventlane-event-book.html.twig`, `_booking-page.scss`, module CSS |

**Evidence:** `myeventlane_commerce.routing.yml`, `myeventlane_rsvp.routing.yml`, `mel-booking-checkout-verification.md`.

---

### P0 — Checkout

| Field | Detail |
|-------|--------|
| **Routes / paths** | `commerce_checkout.checkout` → `/checkout`; `commerce_checkout.form` → `/checkout/{commerce_order}/{step}`; flow `mel_event_checkout` |
| **Mobile score** | **5/10** |
| **Why P0** | Revenue completion; multi-pane buyer + attendee + payment |
| **User impact** | Sidebar order summary on narrow viewports; pane density; table-like order review |
| **Revenue impact** | **Critical** — payment capture |
| **Risk** | **High technical + Commerce** — `_checkout.scss` (20 `@media`), `mel-operational-checkout.css`, checkout pane order/weights; **do not change payment logic** |
| **Effort** | **High** — Twig + SCSS + Commerce pane layout; visual-only scope safest |

**Evidence:** `config/sync/commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml`, `mel-cart-checkout-visual-trust-polish.md`, `_checkout.scss`.

---

### P1 — Cart

| Field | Detail |
|-------|--------|
| **Route / path** | Commerce cart routes (e.g. `/cart`) |
| **Mobile score** | Not in Phase 2A map |
| **Why P1** | Pre-checkout step; trust copy and event grouping |
| **User impact** | Cart table layout; remove control touch targets |
| **Revenue impact** | Medium — abandonment risk |
| **Risk** | **Medium** — duplicate `_cart.scss` vs `commerce/_commerce.scss` (legacy, not imported per cart audit) |
| **Effort** | **Low–medium** — mostly visual |

**Evidence:** `mel-cart-checkout-visual-trust-polish.md`.

---

### P1 — Post-purchase / confirmation

| Field | Detail |
|-------|--------|
| **Routes / paths** | Checkout complete step; `myeventlane_checkout_flow.my_tickets` → `/my-tickets`; `myeventlane_rsvp.thankyou` → `/event/{event}/rsvp/thank-you` |
| **Mobile score** | Not scored |
| **Why P1** | Retention and ticket access; lower friction than checkout |
| **User impact** | Confirmation layout, my-tickets list |
| **Revenue impact** | Indirect — repeat attendance, trust |
| **Risk** | **Low–medium** |
| **Effort** | **Low** |

**Evidence:** `commerce-checkout-completion.html.twig`, `rsvp-thankyou.css`.

---

## Vendor / operations surfaces

### P0 — Event Studio (vendor)

| Field | Detail |
|-------|--------|
| **Routes / paths** | `myeventlane_event_studio.*` → `/vendor/events/{node}/studio/*` (sections: information, branding, content, tickets, etc.) |
| **Mobile score** | **4/10** |
| **Why P0 (vendor)** | Canonical post-consolidation vendor workflow; referenced across dashboard, onboarding, gateway |
| **User impact** | Fixed 280px sidebar grid; section nav; topbar publish/autosave |
| **Revenue impact** | **High (vendor)** — event creation/publish gates ticket sales |
| **Risk** | **Very high technical** — `mel-event-studio-shell.css` (~5.4k lines, 23 `@media`, `grid-template-columns: 280px 1fr`); module + theme + vendor `_mel-builder.scss` (30× `!important`) ownership conflict |
| **Effort** | **Very high** — defer layout to Phase 2+ after token unification |

**Evidence:** `mel-event-studio-shell.css` lines 19–34; `css-ownership-map.md`; `event-route-reference-map.md` §B.

---

### P0 — Vendor orders

| Field | Detail |
|-------|--------|
| **Route / path** | `myeventlane_vendor.console.event_orders` → `/vendor/event/{event}/orders` (and Studio orders sections) |
| **Mobile score** | **3/10** |
| **Why P0 (vendor)** | Table-primary UI; revenue reconciliation |
| **User impact** | Horizontal scroll or unreadable columns on 390px |
| **Revenue impact** | **High (vendor ops)** |
| **Risk** | **High** — `_event-table.scss`, commerce module order CSS; table→card pattern not established |
| **Effort** | **High** — needs responsive table pattern first |

**Evidence:** `mobile-route-priority-map.md`; vendor `_event-table.scss`.

---

### P1 — Vendor dashboard

| Field | Detail |
|-------|--------|
| **Route / path** | `/vendor/dashboard` |
| **Mobile score** | **4/10** |
| **Why P1** | Vendor entry hub; KPI grids, live ops |
| **User impact** | Dashboard density; quick actions |
| **Revenue impact** | Medium (vendor productivity) |
| **Risk** | **High** — `_live-operations.scss` (31× `!important`) |
| **Effort** | **High** |

---

### P2 — Attendees / check-in

| Field | Detail |
|-------|--------|
| **Routes / paths** | Attendee ops, RSVP check-in (`myeventlane_rsvp.checkin_*`, vendor event operations) |
| **Mobile score** | **4/10** |
| **Why P2** | Door ops often tablet; mixed tables + cards |
| **User impact** | Check-in scan flows may need mobile |
| **Revenue impact** | Operational |
| **Risk** | **Medium–high** — `_mel-attendee-operations.scss` |
| **Effort** | **Medium–high** |

---

### P2 — Vendor analytics

| Field | Detail |
|-------|--------|
| **Route / path** | `myeventlane_event_studio.workspace_analytics`, `/vendor/events/{event}/analytics` |
| **Mobile score** | **4/10** |
| **Why P2** | Charts + wide metrics; KPI cards stack at 479/640px |
| **User impact** | Read-only insights; less conversion-critical |
| **Revenue impact** | Low direct |
| **Risk** | **Medium** — `analytics.css`, `pages/_analytics.scss` |
| **Effort** | **Medium** |

---

### P3 — Vendor messaging / promotion

| Field | Detail |
|-------|--------|
| **Route / path** | Studio messaging/promotions sections |
| **Mobile score** | **5/10** |
| **Why P3** | Form-heavy; fewer tables; lower traffic complexity per Phase 2A |
| **User impact** | Secondary vendor task |
| **Revenue impact** | Low–medium |
| **Risk** | **Low–medium** |
| **Effort** | **Medium** |

---

### P3 — Help / trust / blog / organiser hub

| Field | Detail |
|-------|--------|
| **Routes / paths** | Help centre, `/trust`, blog landing, organiser hub |
| **Mobile score** | Not in Phase 2A map |
| **Why P3** | Supporting content; not primary conversion path |
| **User impact** | Lower |
| **Revenue impact** | Indirect |
| **Risk** | **Low** |
| **Effort** | **Low–medium** |

**Evidence:** Route grep; not scored in mobile-route-priority-map.

---

## Priority matrix (summary)

| Priority | Route / surface | Score | Primary driver |
|----------|---------------|-------|----------------|
| **P0** | Homepage `/home` | 7 | Funnel entry |
| **P0** | Discovery `/events`, `/search` | — | Browse → detail |
| **P0** | Event detail | 6 | CTA → book |
| **P0** | Book / RSVP `/event/{node}/book` | ~5–6 | Cart creation |
| **P0** | Checkout `/checkout` | 5 | Payment |
| **P0** | Event Studio `/vendor/events/{node}/studio/*` | 4 | Vendor workflow (high tech risk) |
| **P0** | Vendor orders | 3 | Vendor revenue ops (high UX risk) |
| **P1** | Cart, post-purchase, vendor dashboard | 4–5 | Adjacent conversion / hub |
| **P2** | Attendees, analytics, public event polish gaps | 4–6 | Operational / secondary |
| **P3** | Messaging, help, homepage breakpoint-only | 5–7 | Defer or token-only |

---

## Reconciliation with Phase 2A map

Phase 2A ranked **Event Studio** and **Orders** as P0 for **vendor mobile implementation**, and **Homepage** as P3 (better baseline).

This review **splits public conversion (P0)** from **vendor ops (P0 with defer recommendation for implementation)** because:

1. Public P0 routes have **repository-confirmed revenue paths** (`BookController`, `mel_event_checkout`, RSVP redirect).
2. Vendor P0 routes have **lower mobile scores** but **highest CSS ownership conflict** — implementing them before token/component consolidation increases regression risk (`css-ownership-map.md`).

**Recommended implementation order:** public conversion foundation first (tokens + book + checkout), vendor Event Studio/orders in Phase 2+ after consolidation.

---

## What not to implement yet

| Item | Reason |
|------|--------|
| Event Studio shell sidebar → drawer | Module owns grid; 280px fixed columns; 30+ `!important` in builder SCSS |
| Orders table → card stack | No canonical mobile table pattern; P0 UX but blocked on design system |
| Vendor `_event-form.scss` dedup | 94× `!important`; needs markup alignment |
| Commerce/checkout logic changes | High Stripe/Commerce risk per project rules |
| 390px token enforcement | **Evidence not found** for 390px in theme SCSS (`mobile-breakpoint-inventory.md`); requires product sign-off |
| Dead vendor SCSS import removal | Document only until verified unused (`vendor-studio-editor`, `studio-inspector` in vendor `main.scss` lines 92–93) |

---

**Phase 1 priority review complete. No code changes.**
