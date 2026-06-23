# Event Trust & Conversion Audit

**Date:** 2026-06-23  
**Scope:** Public event journey — discovery card → event page → RSVP/ticket book → checkout → confirmation → ticket retrieval.  
**Method:** Repository inspection (Twig, PHP services, SCSS). No invented fields, routes, or social proof.  
**Out of scope:** Checkout architecture, Commerce entities, stock logic, Stripe, routes, permissions.

---

## Executive summary

MEL’s public event UX is architecturally sound: **one CTA partial** (`partial--event-full-booking-cta.html.twig`) feeds both sidebar and mobile sticky bar; **`BookingFlowResolver`** owns booking mode, availability, pricing, and primary CTA; **`event_ui`** normalises labels for cards and full page.

Remaining friction is mostly **copy and empty-state clarity** (sold out, cancelled), **organiser trust surfacing** (redundant copy, missing event count), and **one wiring gap** where RSVP waitlist CTAs on the event full page never receive a URL from `BookingFlowResolver` (legacy `EventModeManager` had this; templates already expect it).

Recommended approach: small Twig/copy passes, wire RSVP waitlist into `BookingFlowResolver`, enrich organiser card from existing `VendorCardBuilder` data — no checkout or Commerce changes.

---

## Journey map

| Stage | Primary templates / routes | Key services |
|-------|---------------------------|--------------|
| Discovery card | `mel-event-card.html.twig`, view modes `compact_commerce`, `editorial_magazine`, `list_card` | `EventCardViewModel`, `EventMerchandisingPresenter`, `event_ui` |
| Event full page | `node--event--full.html.twig`, `partial--event-full-booking-panel.html.twig`, `partial--event-full-booking-cta.html.twig` | `BookingFlowResolver`, `event_ui`, `MelReadinessHelper` (GST note) |
| RSVP book | `myeventlane-event-book.html.twig`, `form--myeventlane-rsvp-public-form.html.twig` | `RsvpPublicForm`, route `myeventlane_commerce.event_book` |
| Ticket book | `myeventlane-event-book.html.twig`, `form--myeventlane-ticket-selection-form.html.twig` | `TicketSelectionForm`, `TicketAvailabilityService` |
| Checkout | `commerce-checkout-form.html.twig`, `mel-checkout-order-summary-grouped.html.twig` | `MelReadinessHelper`, `OrderPricingBreakdownBuilder` |
| Confirmation (paid) | `commerce-checkout-completion.html.twig` | `MelCustomerContinuityPresenter::buildCheckoutCompletionPresentation()` |
| Confirmation (RSVP) | `mel-rsvp-thankyou.html.twig` | `MelCustomerContinuityPresenter::buildRsvpThankYouPresentation()` |
| Ticket retrieval | `myeventlane-my-tickets.html.twig`, `mel-account-ticket-card.html.twig` | `MyTicketsController`, route `myeventlane_checkout_flow.my_tickets` |

---

## 1. Event page hierarchy

### Confirmed (working)

| Element | Status | Source |
|---------|--------|--------|
| Hero image | Visible via `field_event_image` or category/placeholder fallback | `node--event--full.html.twig`, `preprocess_node__event` |
| Title | Single `<h1>` in hero | `mel-event-hero__title` |
| Category | Hero chip when no discovery badge / sold-out pill | `mel_category_label` or `content.field_category` |
| Date/time | Hero meta list with calendar + clock icons | Node `field_event_start` / `field_event_end` |
| Location | Venue name or venue entity in hero meta | `field_venue_name`, `field_venue` |
| Price | Sidebar `mel_sidebar_pricing` from `BookingFlowResolver::getDisplayPricing()` | “From $X”, “Free RSVP”, “External” |
| Availability | Scarcity badge, urgency lines, sold-out pill | `event_ui`, `event_domain_state` |
| Primary CTA | Sidebar + mobile sticky share one partial | `partial--event-full-booking-cta.html.twig` |
| Organiser | `mel_organiser` card from `field_event_vendor` | `preprocess_node__event` |
| Support links | Footer zone: Help Centre, Support, Privacy, Terms | Bottom of `node--event--full.html.twig` |
| Refund info | Policies card when `mel_refund_policy_text` or cancellation field set | Main column |
| Related events | `mel_related_events` (max 3, `compact_commerce`) | `EventRecommendationService` |

### Issues

| Issue | Severity | Detail |
|-------|----------|--------|
| Long scroll before action (mobile) | Medium | Hero + content column scroll before sticky CTA; sticky bar mitigates but first paint requires hero scroll on long descriptions |
| Duplicate organiser copy | Low | “Presented by” + “Hosted by @name” repeats the same name |
| Cancelled empty state thin | Medium | Banner is one line; no guidance on refunds or next steps |
| Sold out empty state thin | Medium | Disabled “Sold out” button; waitlist branch in CTA partial never fires (see §7) |
| `user_has_ticket` never set | Low | “You're going ✓” CTA state in partial never renders (`event_ui.user_has_ticket` not populated in PHP) |
| External inventory blind spot | Info | External events always `AVAILABILITY_AVAILABLE`; MEL cannot reflect third-party sold out |

### CTA duplication

- **No duplicate primary buttons** in sidebar vs mobile — shared partial is correct.
- **Save for later** (flag) is secondary; acceptable.
- **Share chips** are tertiary; below fold.

---

## 2. Organiser trust

### Data available (repository-confirmed)

| Signal | Available | Shown on event full |
|--------|-----------|---------------------|
| Organiser name | `field_event_vendor` → vendor label | Yes |
| Logo/avatar | `VendorCardBuilder::buildLogoUrl()` | Yes |
| Public profile URL | `entity.myeventlane_vendor.canonical` (access-checked) | Yes (name link) |
| Tagline / summary | `field_tagline` or truncated `field_summary` | Yes |
| Upcoming event count | `VendorCardBuilder::countUpcomingEvents()` | **No** (not passed to `mel_organiser`) |
| New organiser (< 30 days) | `VendorCardBuilder::isNewOrganiser()` | **No** |
| Follower count | `VendorFollowService` | **No** (appropriate — avoid vanity metrics on event page) |
| Ratings / reviews | — | **Not in repository** — do not add |
| “Verified organiser” | Legacy `event-organiser.html.twig` only | **Not on live full template** (correct — would be fabricated) |

### Assessment

Organisers feel **real** (name + logo + optional tagline) but not **established** — no event count or profile CTA beyond the name link. Legacy template with “Verified organiser” is orphaned.

---

## 3. Pricing trust

| Question | Finding |
|----------|---------|
| Is total cost clear on event page? | **Partially** — “From $X” or “Free RSVP”; GST note when applicable (`mel_show_price_includes_gst_note`) |
| Are fees surprising? | **Risk at checkout** — full fee/GST breakdown via `OrderPricingBreakdownBuilder` on checkout/confirmation; book page shows “Clear pricing · No hidden fees” reassurance |
| Does user understand next step? | **Yes** for active booking — CTA → `/event/{node}/book` → ticket matrix or RSVP form → cart/checkout |
| RSVP messaging | “Free — no payment required” under CTA; book page trust bar repeats |
| Sold out | Hero pill + disabled CTA; limited “what next” copy |
| Capacity | “Only @c spots left”, “Limited spots remaining” when `rsvp_state === limited` |

---

## 4. CTA review

### Dominant action pattern

| Mode | Primary label (resolver) | Display label (theme override) |
|------|--------------------------|--------------------------------|
| Paid | `Get your tickets` | `Get your tickets` (panel heading) |
| RSVP | `RSVP free` | `Book free RSVP` |
| External | `View details` | + “On organiser site” hint in partial |
| Sold out | `Sold out` (disabled) | Should be `Join waitlist` when RSVP waitlist enabled |
| Scheduled | `Sales open on …` | Disabled button |

### Issues

| Issue | Detail |
|-------|--------|
| Waitlist dead branch | Twig lines 24–27 in CTA partial expect `event_cta.url` when sold out; resolver returns empty URL |
| External “View details” | Vague vs brand preference “Get tickets” for transactional external |
| Card CTAs | Decorative chip inside whole-card link — acceptable; `aria-label` on card carries CTA text |
| Cards use “View details” for scheduled paid | Intentional — defers purchase decision to event page |

---

## 5. Mobile review (390px baseline)

### Confirmed

| Check | Status |
|-------|--------|
| Hero scaling | `mel-event-hero--featured-style` — mobile-first in `_event-full.scss` |
| Sticky CTA | `.mel-mobile-cta` fixed bottom, safe-area padding |
| Button min height | `min-height: 44px` on `.mel-mobile-cta__button` |
| Share chips | 44×44 touch targets |
| Bottom nav stacking | `_mobile-bottom-nav.scss` references `.mel-mobile-cta` — verify z-index in QA |

### Issues

| Issue | Detail |
|-------|--------|
| Scroll depth | User may read hero + intro before noticing sticky bar — sticky bar is always visible when booking CTA shown |
| Legacy SCSS | `_event-mobile-cta.scss` uses `.mel-event-cta-mobile-sticky` — different class from live `.mel-mobile-cta`; likely dead CSS |
| Sold out mobile | Eyebrow shows “Sold out”; no waitlist sub-line unless URL present |

---

## 6. Empty states

| State | What happens | Why | What to do next | Gap |
|-------|--------------|-----|-----------------|-----|
| **Sold out** | Disabled CTA / muted text | Capacity reached | Waitlist (RSVP) or explore related events | Waitlist URL not wired; copy thin |
| **No tickets configured** | `MODE_UNAVAILABLE`, empty CTA | Event not bookable | Contact organiser / support | OK |
| **Waitlist (RSVP)** | Route `myeventlane_event_attendees.waitlist_signup` | Event full | Join waitlist form | Not linked from event full CTA |
| **Tier waitlist (paid)** | `TicketSelectionForm` tier waitlist UI | Per-tier sold out | Join tier waitlist on book page | Book page shows sold-out alert when fully sold out before tier waitlist UI |
| **Cancelled** | Warning banner; booking panel suppressed | Organiser cancelled | Refund/update from organiser | No next-step copy |
| **Ended** | “Event ended” disabled CTA | Past `field_event_end` | Browse related events | OK |
| **Scheduled** | “Sales open on …” disabled | Before sales window | Return later | OK |
| **Browse empty** | `mel-browse-empty-recovery.html.twig` | No matching events | Recovery links | OK |

Copy guideline reference (`docs/brand/copy-guidelines.md`):

> **Sold out:** “This event is sold out. Join the waitlist or explore similar events nearby.”

---

## 7. RSVP flow

| Step | Trust signals |
|------|---------------|
| Book page | Trust bar: no payment, email confirmation, admin-only data |
| Form | 4-step onboarding cards, fixed bottom CTA |
| Capacity | `mel_signal_remaining_spots` urgency on hero |
| Thank-you | `MelCustomerContinuityPresenter` — calendar, my tickets, help links |

**Risk:** None identified for trust copy; form logic unchanged.

---

## 8. Ticket purchase & checkout

| Step | Trust signals |
|------|---------------|
| Book page | “Choose your tickets”, reassurance row, mobile booking bar |
| Cart | Grouped by event, governed empty states |
| Checkout | `mel_checkout_presentation` hero, trust chips, sticky CTA labels |
| Complete | Organiser trust block (`mcc.organiser_trust`), pricing breakdown partial |

**Risk:** Fee visibility depends on checkout step — not regressed in this audit.

---

## 9. Confirmation & ticket retrieval

| Surface | Content |
|---------|---------|
| Checkout complete | Event summary card, ticket rows, calendar export, continuity actions |
| RSVP thank-you | Headline, meta, donation bands, continuity actions |
| My Tickets | Empty state via `mel_my_tickets_empty`; populated order cards |

---

## Phase 2 changes (this pass)

| Change | File | Risk |
|--------|------|------|
| Wire RSVP waitlist URL when sold out + waitlist enabled | `BookingFlowResolver.php`, `myeventlane_event.services.yml` | Low — existing route, no stock change |
| Enrich `mel_organiser` with event count + new organiser flag | `myeventlane_theme.theme` | Low — existing `VendorCardBuilder` |
| Stronger organiser card copy/structure | `node--event--full.html.twig` | Low — Twig only |
| Cancelled + sold out next-step copy | `node--event--full.html.twig`, booking partials | Low — copy only |
| External CTA label → “Get tickets” | `BookingFlowResolver.php` | Low — label only |
| Organiser meta + profile link styles | `_event-full.scss` | Low — scoped SCSS |

### Not changed (requires broader review)

- Populate `event_ui.user_has_ticket` (needs attendance/order lookup service)
- Paid tier waitlist from event full when fully sold out (BookController + `TicketSelectionForm` early return)
- Remove dead `_event-mobile-cta.scss` (verify build bundle first)

---

## Validation checklist

```bash
git status --short
ddev drush cr
composer validate --check-lock
# PHP lint on changed files
ddev exec php -l web/modules/custom/myeventlane_event/src/Service/BookingFlowResolver.php
```

Manual QA:

- [ ] Event full — paid, active
- [ ] Event full — free RSVP
- [ ] Event full — sold out RSVP with waitlist
- [ ] Event full — cancelled
- [ ] Event full — external
- [ ] Mobile 390px — sticky CTA + bottom nav
- [ ] Book → checkout → complete
- [ ] RSVP → thank-you
- [ ] My Tickets empty + populated

---

## Residual risks

| Risk | Notes |
|------|-------|
| Waitlist only for RSVP sold out | Paid tier waitlist still book-page only |
| `user_has_ticket` | Dead UI branch until service wires it |
| External sold out | Cannot reflect off-platform inventory |
| Theme build | SCSS change requires `npm run mel:build` before visual QA |

**Safe to commit:** Yes, after cache rebuild and lint pass — changes are scoped to CTA resolution, Twig copy, theme preprocess, and organiser SCSS.
