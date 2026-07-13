# Account Dashboard Bookings Architecture (ACE Phase 3)

## Purpose

Document the customer Account Dashboard bookings experience after ACE Phase 3.
This phase improves the **existing** `/my-account` hub. It does not add a second
dashboard, a second My Bookings route, or parallel booking/ticket presenters.

## Current architecture (before Phase 3)

| Purpose | Owner | Route | Template | Responsibilities |
|---------|-------|-------|----------|------------------|
| Customer home | `MyAccountController::dashboard` | `myeventlane_account.dashboard` `/my-account` | `myeventlane-my-account-dashboard.html.twig` | Welcome stats; split Tickets / RSVPs / Past |
| Participation data | `CustomerHubDataBuilder` | — | — | Tickets, RSVPs, past rows from attendees + completed orders |
| Account hero (unwired) | `CustomerAccountHeroBuilder` | — | `mel-account-hero.html.twig` | Copy/CTAs registered but not rendered |
| Hub cards | Theme | — | `mel-account-event-card.html.twig` | Variant-based badges/CTAs in Twig |
| My Bookings (orders) | `MyTicketsController` | `myeventlane_checkout_flow.my_tickets` `/my-tickets` | `myeventlane-my-tickets.html.twig` | Order-centric ticket list |
| Booking detail | `MyTicketsController::orderDetail` | `myeventlane_checkout_flow.order_detail` | `myeventlane-order-detail.html.twig` | Passes, QR, PDF |
| Parallel list | `CustomerDashboardController` | `myeventlane_dashboard.customer` `/my-events` | `myeventlane-customer-dashboard.html.twig` | Unified upcoming/past (same data builder) |
| Empty states | `MelReadinessHelper` + `GovernedOperationalTemplates` | — | `mel_empty_state` | Per-section empties |
| Nav | `AccountLinksService` | — | `page--account.html.twig` | Sidebar + quick links |

### Known duplication (documented, not expanded)

- `/my-events` remains a **live parallel surface** sharing `CustomerHubDataBuilder`.
  Phase 3 does **not** grow `/my-events`. Consolidation/redirect is a future task.
- Hub cards (event-centric) and My Tickets cards (order-centric) remain distinct
  on purpose: different product questions.

## Final architecture (after Phase 3)

```
MyAccountController::dashboard
  ├─ CustomerHubDataBuilder::buildParticipationLists()
  │    ├─ next_booking (earliest upcoming)
  │    ├─ upcoming_bookings (remaining)
  │    ├─ past_events
  │    └─ enrichBookingPresentation() → status_label + primary/secondary CTAs
  ├─ CustomerAccountHeroBuilder::buildDashboardHero()
  │    └─ welcome + mode (next_booking | summary | empty)
  ├─ GovernedOperationalTemplates::accountDashboardBookingsEmpty()
  └─ theme myeventlane_my_account_dashboard
       ├─ mel-account-hero
       ├─ Next booking → mel-account-event-card--next
       ├─ Upcoming bookings list
       ├─ Notifications (existing)
       ├─ Past bookings
       └─ Helpful links (AccountLinksService quick items)
```

### Ownership map

| Concern | Canonical owner | Do not duplicate |
|---------|-----------------|------------------|
| Participation rows | `CustomerHubDataBuilder` | New booking query services |
| Status language | `MelReadinessHelper::customerHubBookingStatusLabel()` | Hardcoded Commerce states in Twig |
| Empty “Nothing booked yet” | `MelReadinessHelper` + `GovernedOperationalTemplates` | Inline empty tables |
| Dashboard hero copy | `CustomerAccountHeroBuilder` | Parallel welcome bands with different copy owners |
| Card markup | `mel-account-event-card.html.twig` | New card component types |
| Ticket PDF / QR / order access | `myeventlane_tickets` + `MyTicketsController` | Re-implement ticket logic in account |
| Sidebar IA | `AccountLinksService` | Ad-hoc nav in templates |

## Reuse decisions

| Need | Decision | Why |
|------|----------|-----|
| Next booking hero visual | Extend `mel-account-event-card` with `--next` | Same row shape; one card system |
| Welcome / empty CTAs | Wire existing `CustomerAccountHeroBuilder` | Already owned hero; was unwired |
| Status copy | Extend `MelReadinessHelper` | ACE vocabulary lives with readiness |
| Empty bookings | New readiness slot + governed template | Matches empty-state governance |
| Primary CTAs | Enrich in `CustomerHubDataBuilder` | Single calculation site; Twig only renders |
| Order/ticket deep links | Existing `ticket_url` / `pdf_url` | Access already enforced on target routes |

## Components extended

- `CustomerHubDataBuilder` — `next_booking`, `upcoming_bookings`, presentation enrichment
- `CustomerAccountHeroBuilder` — welcome + next-booking mode; no `/my-events` CTA
- `MyAccountController` — wires hero + unified bookings sections
- `MelReadinessHelper` — bookings empty + hub status/CTA labels
- `GovernedOperationalTemplates::accountDashboardBookingsEmpty()`
- `AccountLinksService` — “Dashboard” / “My bookings” labels
- `mel-account-event-card.html.twig` — status_label + CTA fields
- `mel-account-hero.html.twig` — welcome line
- `_account-cards.scss`, `_account-hero.scss`, `_my-account.scss` — next card + helpful links

## Components intentionally not duplicated

- No new dashboard controller or route
- No new My Bookings page (reuse `/my-tickets` as bookings list authority)
- No `AttendeeContinuity*` / `BookingPresentation*` presenter modules
- No fork of discovery `EventCardViewModel` for hub
- No new empty-state framework
- No expansion of `CustomerDashboardController` / `/my-events`
- No invented “Before You Go”, “Directions”, or “Contact organiser” CTAs without existing routes

## Status language (ACE)

| Key | Label | Source signal |
|-----|-------|---------------|
| `confirmed` | Booking confirmed | Upcoming confirmed participation |
| `rsvp` | Booking confirmed | Confirmed RSVP row |
| `ticket_ready` | Ticket ready | Ticket code / PDF available |
| `today` / `tomorrow` | Today / Tomorrow | Start date relative to request time (overlay, not Commerce state) |
| `completed` | Completed | Past event end/start |
| `cancelled` | Cancelled | Reserved for existing cancelled signals (not newly queried) |
| `payment_pending` | Booking received | Reserved; hub still loads confirmed/completed rows only |

Never expose raw Commerce labels such as “Order Complete” on the hub.

## Customer-facing terminology (IA)

```
Public entry point
↓
Home  →  /  (<front>)

Authenticated customer workspace
↓
Dashboard  →  /my-account
My bookings → /my-tickets
```

| Audience | Label | Destination | Owner |
|----------|-------|-------------|-------|
| Public / global | Home | `/` | Logo aria-label, mobile bottom nav `MobileBottomNavigationBuilder` |
| Authenticated customer | Dashboard | `/my-account` | `AccountLinksService`, page H1, header shortcut (`region--header.html.twig`, radix header) |
| Authenticated customer | My bookings | `/my-tickets` | `AccountLinksService`, shell H1 |

Prefer: **Dashboard**, **My bookings**, **Booking**.  
Avoid customer-facing: **Order**, **Customer Home**, dual **Home** for `/my-account`.

Organiser console may still say Home for its own workspace; that is not the customer IA.

## User journey (attendee)

Confirmation page → confirmation email (ACE Phase 2) → **Dashboard** (`/my-account`) →
booking card CTA → My bookings / order detail → ticket PDF → event.

Route `/my-account` is unchanged. Only customer-facing labels use Dashboard.

## Future extension points

- Redirect or alias `/my-events` → `/my-account` after IA sign-off
- Surface payment-pending bookings only when a shared loader already exists
- Add Directions / Contact organiser only when stable public URLs exist on the row
- Optional kernel coverage for full `buildParticipationLists` with fixtures

## Validation

See Phase 3 delivery notes for commands run (`composer validate`, `ddev drush cr`,
`npm run mel:lint`, `npm run mel:build`, unit tests).
