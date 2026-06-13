# Mobile Route Priority Map — Phase 2A

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** Priority vendor/public surfaces for mobile-first work. Scores are **audit estimates** from repository evidence (SCSS `@media` density, table usage, layout structure, JS mobile hooks) — not user testing.

**Scale:** 10 = likely acceptable at 390px today; 1 = high risk / dense desktop-first layout.

---

## Scoring summary

| Area | Route / surface (evidence) | Mobile score /10 | Primary risks |
|------|---------------------------|------------------|---------------|
| **Homepage** | Public front (`_homepage.scss`, `_front-page.scss`, `_home-hero.scss`, featured carousel) | **7** | Carousel + hero; some responsive rules; public header mobile nav exists (`header.js`) |
| **Event page (public)** | Event full / book (`_event-full.scss` 29 `@media`, `_booking-page.scss`, `event-builder-preview.css`) | **6** | Multi-column hero/gallery; mobile CTA bar (`_event-mobile-cta.scss`); booking JS mobile bar |
| **Checkout** | Commerce checkout (`_checkout.scss` 20 `@media`, `mel-operational-checkout.css`, `mel-checkout.js`) | **5** | Multi-step density; buyer mobile fields; table-like order review |
| **Vendor dashboard** | `/vendor/dashboard` (`pages/_mel-dashboard.scss`, `_live-operations.scss`, `_mel-builder.scss`) | **4** | KPI grids, live ops panels, 31× `!important` in live-ops |
| **Event Studio** | `/vendor/events/{node}/studio/*` (`mel-event-studio-shell.css` 23 `@media`, fixed 280px sidebar grid) | **4** | Desktop grid `280px + 1fr`; builder sidebar; vendor `_mel-builder.scss` 21 `@media` |
| **Orders** | `/vendor/events/{event}/orders` (`_event-table.scss`, `vendor-order-view`, commerce module CSS) | **3** | Table-heavy; horizontal scroll patterns |
| **Attendees** | Attendee ops / check-in (`_mel-attendee-operations.scss`, waitlist templates, checkin CSS) | **4** | Mixed tables + cards; some 640/768 rules |
| **Messaging** | Vendor promotion / comms (`vendor-branding.css`, workspace messaging sections) | **5** | Form-heavy; fewer table patterns in grep sample |
| **Analytics** | `/vendor/events/{event}/analytics` (`pages/_analytics.scss`, `analytics.css`, charts) | **4** | Charts + wide metrics; KPI cards stack at 479/640px |

---

## Detailed evidence by area

### Homepage — 7/10

| Factor | Evidence |
|--------|----------|
| Density | Hero + sections; `_homepage.scss` 6 `@media`, `_front-page.scss` 9 |
| Navigation | Public mobile nav implemented (`header.js`, `_site-header.scss` 900px gates) |
| Tables | Low on homepage templates |
| CTA hierarchy | Hero CTAs; featured carousel (`_featured-carousel.scss`) |
| Responsiveness risk | **Medium** — 900px header breakpoint ≠ 768 md token |

### Event page (public) — 6/10

| Factor | Evidence |
|--------|----------|
| Density | `_event-full.scss` (29 media queries), gallery (`_event-gallery.scss` 15+ `@media` at 900px) |
| Navigation | Public header mobile OK; in-page sticky booking (`mel-booking-summary.js` mobile bar) |
| Tables | Low on canonical event view; ticket selector in preview CSS |
| CTA hierarchy | `_event-mobile-cta.scss`, book panel |
| Responsiveness risk | **Medium-high** — many hardcoded 900px/767px splits |

### Checkout — 5/10

| Factor | Evidence |
|--------|----------|
| Density | `_checkout.scss` 20 `@media`; operational checkout module |
| Navigation | Checkout flow steps; limited mobile-specific JS beyond field classes |
| Tables | Order summary tables likely |
| CTA hierarchy | Pay / continue actions in checkout partials |
| Responsiveness risk | **High** — commerce multi-pane layout |

### Vendor dashboard — 4/10

| Factor | Evidence |
|--------|----------|
| Density | `_mel-dashboard.scss`, `_live-operations.scss` (17 `@media`, 31× `!important`) |
| Navigation | Vendor shell sidebar + help panel |
| Tables | Event lists mix grid + table patterns |
| CTA hierarchy | Quick actions, boost cards |
| Responsiveness risk | **High** — dashboard grids assume width |

### Event Studio — 4/10

| Factor | Evidence |
|--------|----------|
| Density | Shell CSS: `grid-template-columns: 280px 1fr`; onboarding variant `200px 280px 1fr` |
| Navigation | Section sidebar + topbar (`mel-event-studio-topbar.html.twig`) |
| Tables | Section-dependent (tickets, orders embed tables) |
| CTA hierarchy | Topbar publish/autosave; section CTAs |
| Responsiveness risk | **High** — module shell is desktop-grid-first; 23 responsive rules but sidebar width fixed in base layout |

### Orders — 3/10

| Factor | Evidence |
|--------|----------|
| Density | Vendor `_event-table.scss` (768px breakpoints), order view SCSS |
| Navigation | Event workspace tabs (legacy mission control) |
| Tables | **Primary UI** — orders listing |
| CTA hierarchy | Row actions, filters |
| Responsiveness risk | **Very high** — table-first |

### Attendees — 4/10

| Factor | Evidence |
|--------|----------|
| Density | `_mel-attendee-operations.scss`, waitlist management template |
| Navigation | Event workspace / studio attendees section |
| Tables | Attendee lists, export CSV links |
| CTA hierarchy | Check-in, export, door ops |
| Responsiveness risk | **High** — ops tooling |

### Messaging — 5/10

| Factor | Evidence |
|--------|----------|
| Density | Workspace messaging section; vendor comms forms |
| Navigation | Studio section nav |
| Tables | Lower than orders |
| CTA hierarchy | Send / branding CTAs |
| Responsiveness risk | **Medium** |

### Analytics — 4/10

| Factor | Evidence |
|--------|----------|
| Density | `pages/_analytics.scss`, `analytics.css`, chart components |
| Navigation | Vendor shell |
| Tables | Metrics tables + charts |
| CTA hierarchy | Boost / export secondary |
| Responsiveness risk | **High** — wide chart layouts |

---

## Priority ranking for Phase 2B+ (mobile implementation)

| Priority | Area | Score | Rationale |
|----------|------|-------|-----------|
| P0 | Event Studio | 4 | Canonical vendor workflow post-consolidation; shell grid is desktop-first |
| P0 | Orders | 3 | Table-heavy; revenue-critical |
| P1 | Vendor dashboard | 4 | Entry hub; live ops density |
| P1 | Checkout | 5 | Conversion-critical |
| P2 | Event page (public) | 6 | Discovery → book funnel |
| P2 | Attendees / Analytics | 4 | Operational sub-surfaces |
| P3 | Homepage | 7 | Better baseline; still unify breakpoints |
| P3 | Messaging | 5 | Lower traffic complexity |

**Phase 2A does not implement fixes.**
