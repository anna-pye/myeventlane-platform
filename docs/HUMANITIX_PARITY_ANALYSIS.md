# Humanitix parity analysis

Structured comparison of MyEventLane’s flows and capabilities to Humanitix (events, ticketing, fees, RSVP, check-in, refunds, etc.) to produce a parity checklist and gap list.

**Scope:** Feature-level comparison only. No product or pricing recommendations. Accuracy is based on Humanitix public materials and the current MyEventLane codebase.

---

## 1. Humanitix capability summary (reference)

*Sources: humanitix.com/us/features, help.humanitix.com, humanitix.com pricing.*

### 1.1 Event creation & formats


| Capability                                           | Humanitix |
| ---------------------------------------------------- | --------- |
| Single events                                        | ✓         |
| Recurring events                                     | ✓         |
| Online/virtual events                                | ✓         |
| Public vs private discoverability                    | ✓         |
| Location via Google Maps                             | ✓         |
| Custom refund policy (timeframes, pre-set or custom) | ✓         |
| Event branding / Canva design tools                  | ✓         |


### 1.2 Tickets & capacity


| Capability                                                                  | Humanitix            |
| --------------------------------------------------------------------------- | -------------------- |
| Free events (no fees)                                                       | ✓                    |
| Paid tickets                                                                | ✓                    |
| Multiple ticket types / complex combinations                                | ✓ (simplified tools) |
| Capacity management                                                         | ✓                    |
| Seat maps (tables, auditoriums, market stalls)                              | ✓                    |
| Merchandise add-ons (books, hoodies, cups, etc.)                            | ✓                    |
| 1-Click Ticket Manager™ (guests update details without account)             | ✓                    |
| Assign tickets later (bulk buy, assign attendees later; corporate packages) | ✓                    |


### 1.3 Waitlist & sold-out behaviour


| Capability                                          | Humanitix |
| --------------------------------------------------- | --------- |
| Waitlist when sold out or ticket type full          | ✓         |
| Auto-notify waitlisted guests when tickets released | ✓         |
| Manual vs automatic release of waitlist spots       | ✓         |


### 1.4 Check-in & event day


| Capability                                                          | Humanitix |
| ------------------------------------------------------------------- | --------- |
| Free “Humanitix for Hosts” scanning app (iOS/Android)               | ✓         |
| QR code scan check-in                                               | ✓         |
| Manual name search check-in                                         | ✓         |
| Offline scan with sync when reconnected                             | ✓         |
| Live check-in and sales figures                                     | ✓         |
| VIP management (scanning messages, e.g. green room, special access) | ✓         |
| Multiple staff/team members on door                                 | ✓         |


### 1.5 Payments & fees


| Capability                                                  | Humanitix |
| ----------------------------------------------------------- | --------- |
| Credit card (Humanitix Payments or Stripe)                  | ✓         |
| Free events: no fees                                        | ✓         |
| Paid: booking fee % + fixed per ticket (varies by currency) | ✓         |
| NFP/charity/school discounted fees                          | ✓         |
| Choose who pays (buyer, organizer, split)                   | ✓         |
| Invoice payment for corporate clients                       | ✓         |
| Gift cards                                                  | ✓         |
| Funds within 7 days of event; “funds on-demand” option      | ✓         |
| No subscription / admin / sign-up fees                      | ✓         |


### 1.6 Promotions & marketing


| Capability                                               | Humanitix |
| -------------------------------------------------------- | --------- |
| Promotional codes                                        | ✓         |
| Affiliate codes (share, track, evangelize)               | ✓         |
| Email tools (announcements, reminders, sponsor messages) | ✓         |
| Events on Humanitix marketplace (1M+ ticket buyers/year) | ✓         |
| Embedded widgets (sell on own site)                      | ✓         |


### 1.7 Guest & refund management


| Capability                                                           | Humanitix |
| -------------------------------------------------------------------- | --------- |
| Simple guest management                                              | ✓         |
| Host-initiated refunds (any time at host discretion)                 | ✓         |
| Buyer self-service refund from confirmation email (within policy)    | ✓         |
| Refund policy: pre-set timeframes (e.g. 7 days prior) or custom      | ✓         |
| Policy cannot be made more restrictive after publish; can be relaxed | ✓         |
| Refunds 2–5 business days to card                                    | ✓         |
| Teams (give event access to others with Humanitix account)           | ✓         |


### 1.8 Accessibility & impact


| Capability                                                              | Humanitix   |
| ----------------------------------------------------------------------- | ----------- |
| “World-first” accessibility features for guests with special needs      | ✓ (claimed) |
| 100% profits to charity (healthcare, education, food, stability)        | ✓           |
| Impact reporting / Promotional hub (share impact, social, on the night) | ✓           |


---

## 2. MyEventLane capability summary (from codebase)

*Sources: web/modules/custom/*, config, and docs. Reflects current implementation.*

### 2.1 Event creation & formats


| Capability                        | MyEventLane | Notes                                                                         |
| --------------------------------- | ----------- | ----------------------------------------------------------------------------- |
| Single events                     | ✓           | Node type `event`, event wizard                                               |
| Recurring events                  | ✗           | No recurring/series entity or flow found                                      |
| Online/virtual events             | ✓           | Event type; location optional / linkable                                      |
| Public vs private discoverability | Partial     | Publish/unpublish; no explicit “private event” product flag                   |
| Location (address, venue)         | ✓           | `field_location`, `field_venue_name`, myeventlane_location, myeventlane_venue |
| Refund policy (event-level)       | ✓           | `field_refund_policy` on event; LegalConsentPane references it                |
| Event branding                    | Partial     | Theme/site branding; no Canva integration                                     |


### 2.2 Tickets & capacity


| Capability                                    | MyEventLane | Notes                                                            |
| --------------------------------------------- | ----------- | ---------------------------------------------------------------- |
| Free events (RSVP, no fee)                    | ✓           | `field_event_type` rsvp; RSVP flow; free events free on platform |
| Paid tickets                                  | ✓           | Commerce product/variations, TicketSelectionForm, cart, checkout |
| RSVP + paid (“both”)                          | ✓           | EventModeManager MODE_BOTH                                       |
| External link (no MEL ticketing)              | ✓           | `field_event_type` external + URL                                |
| Multiple ticket types                         | ✓           | Product variations, ticket-type paragraphs                       |
| Capacity management                           | ✓           | myeventlane_capacity, EventCapacityService, sold-out state       |
| Seat maps                                     | ✗           | No seat-map / table / stall mapping                              |
| Merchandise add-ons                           | ✗           | No add-on products in event checkout flow                        |
| 1-Click–style guest update without account    | ✗           | “My Tickets” is account-based; no token-based guest edit         |
| Assign tickets later (bulk buy, assign later) | ✗           | Not found                                                        |


### 2.3 Waitlist & sold-out behaviour


| Capability                             | MyEventLane | Notes                                                                      |
| -------------------------------------- | ----------- | -------------------------------------------------------------------------- |
| Waitlist when sold out                 | ✓           | EventCtaResolver “Join Waitlist”; WaitlistSignupForm                       |
| Waitlist signup (name/email)           | ✓           | myeventlane_event_attendees WaitlistSignupForm                             |
| Notify waitlisted when spots free      | ✓           | WaitlistPromotionWorker, WaitlistInviteWorker, WaitlistNotificationService |
| Priority/first-come for released spots | Partial     | Automation exists; explicit “priority queue” UI/config not verified        |


### 2.4 Check-in & event day


| Capability                                   | MyEventLane | Notes                                                                                       |
| -------------------------------------------- | ----------- | ------------------------------------------------------------------------------------------- |
| Web-based check-in UI                        | ✓           | myeventlane_checkin (page, list, search, toggle); VendorCheckInController                   |
| QR scan check-in                             | ✓           | myeventlane_checkin scan template; myeventlane_rsvp QrCheckinController (validate endpoint) |
| Manual search/toggle by attendee             | ✓           | CheckInController::search, ::toggle; list view                                              |
| Dedicated native mobile app                  | ✗           | Web only; no “Humanitix for Hosts”–style app                                                |
| Offline scan + sync                          | ✗           | Requires connectivity                                                                       |
| Live check-in/sales stats on check-in screen | Partial     | Stats in CheckInController (checked_in/total); no live sales feed in same UI                |
| VIP / scanning messages                      | ✗           | No VIP flags or custom scan messages                                                        |


### 2.5 Payments & fees


| Capability                                     | MyEventLane | Notes                                                                       |
| ---------------------------------------------- | ----------- | --------------------------------------------------------------------------- |
| Credit card (Stripe)                           | ✓           | Stripe Connect, Commerce Payment                                            |
| Free events: no fees                           | ✓           | Platform fee on ticket subtotal only                                        |
| Platform fee (% on tickets)                    | ✓           | PlatformFeeOrderProcessor, myeventlane_core.settings `platform_fee_percent` |
| Fee transparency in checkout                   | ✓           | FeeTransparencyPane (subtotal, donation, fees, tax, total)                  |
| Stripe Connect application fee (vendor payout) | ✓           | StripeConnectPaymentService, fee on ticket revenue only                     |
| Who pays (buyer vs organizer)                  | Partial     | Fee shown to buyer; organizer-absorb/split not confirmed in config          |
| Invoice payment                                | ✗           | Not found                                                                   |
| Gift cards                                     | ✗           | Not found                                                                   |
| Funds timing / “on-demand”                     | Partial     | Stripe Connect; actual payout timing is Stripe/bank, not configured in MEL  |


### 2.6 Promotions & marketing


| Capability                             | MyEventLane | Notes                                                                                |
| -------------------------------------- | ----------- | ------------------------------------------------------------------------------------ |
| Promotional codes                      | Partial     | Commerce Promotion in checkout flow deps; no MEL-specific promo UI documented        |
| Affiliate codes                        | ✗           | Not found                                                                            |
| Email (confirmations, reminders, etc.) | ✓           | myeventlane_messaging, OrderPlacedSubscriber, templates, queues                      |
| Public event discovery (marketplace)   | ✓           | Public event listing/views/search                                                    |
| Embedded widgets / external sell       | ✓           | PurchaseSurface (popup, embedded_checkout, collection); VendorEventWidgetsController |


### 2.7 Guest & refund management


| Capability                                | MyEventLane | Notes                                                                     |
| ----------------------------------------- | ----------- | ------------------------------------------------------------------------- |
| Vendor view of attendees/orders           | ✓           | VendorEventOrdersController, VendorCheckInController, attendee lists      |
| Vendor-initiated refunds                  | ✓           | myeventlane_refunds, RefundProcessor, vendor flows                        |
| Buyer self-service refund from email/link | ✗           | Refunds appear vendor-initiated; no “refund from confirmation” flow found |
| Refund policy (event-level, displayed)    | ✓           | field_refund_policy; checkout consent references refund policy            |
| Teams / multi-user access to event        | Partial     | Vendor roles; no “Teams” product like Humanitix                           |


### 2.8 Accessibility & impact


| Capability                           | MyEventLane | Notes                                                                           |
| ------------------------------------ | ----------- | ------------------------------------------------------------------------------- |
| Accessibility metadata (venue/event) | ✓           | Vocabulary “accessibility”, field_accessibility*, accessibility_icons component |
| Accessibility needs (attendee)       | ✓           | EventAttendee accessibility_needs; RsvpBookingForm/OrderCompletedSubscriber     |
| Charity/social-impact model          | ✗           | Platform fee is revenue, not “100% profits to charity”                          |


---

## 3. Parity checklist (feature-level)

Legend: **Parity** = MEL has equivalent capability; **Partial** = MEL has some but not all of Humanitix behaviour; **Gap** = MEL does not have it.

### 3.1 Event creation & formats


| #   | Feature                           | Parity / Partial / Gap |
| --- | --------------------------------- | ---------------------- |
| 1   | Single events                     | **Parity**             |
| 2   | Recurring events                  | **Gap**                |
| 3   | Online/virtual events             | **Parity**             |
| 4   | Public vs private discoverability | **Partial**            |
| 5   | Location / venue                  | **Parity**             |
| 6   | Refund policy (event-level)       | **Parity**             |
| 7   | Event branding (e.g. Canva)       | **Partial**            |


### 3.2 Tickets & capacity


| #   | Feature                                                 | Parity / Partial / Gap |
| --- | ------------------------------------------------------- | ---------------------- |
| 8   | Free RSVP events                                        | **Parity**             |
| 9   | Paid tickets                                            | **Parity**             |
| 10  | RSVP + paid (“both”)                                    | **Parity**             |
| 11  | External link                                           | **Parity**             |
| 12  | Multiple ticket types                                   | **Parity**             |
| 13  | Capacity and sold-out                                   | **Parity**             |
| 14  | Seat maps                                               | **Gap**                |
| 15  | Merchandise add-ons                                     | **Gap**                |
| 16  | Guest update details without account (1-Click style)    | **Gap**                |
| 17  | Assign tickets later (bulk buy, assign attendees later) | **Gap**                |


### 3.3 Waitlist


| #   | Feature                              | Parity / Partial / Gap |
| --- | ------------------------------------ | ---------------------- |
| 18  | Waitlist when sold out               | **Parity**             |
| 19  | Notify when spots free               | **Parity**             |
| 20  | Priority/automatic release behaviour | **Partial**            |


### 3.4 Check-in & event day


| #   | Feature                       | Parity / Partial / Gap |
| --- | ----------------------------- | ---------------------- |
| 21  | Check-in UI (web)             | **Parity**             |
| 22  | QR scan check-in              | **Parity**             |
| 23  | Manual search/toggle          | **Parity**             |
| 24  | Dedicated native check-in app | **Gap**                |
| 25  | Offline scan + sync           | **Gap**                |
| 26  | VIP / custom scan messages    | **Gap**                |


### 3.5 Payments & fees


| #   | Feature                              | Parity / Partial / Gap |
| --- | ------------------------------------ | ---------------------- |
| 27  | Card payment (Stripe)                | **Parity**             |
| 28  | Free events no fee                   | **Parity**             |
| 29  | Platform/booking fee on paid tickets | **Parity**             |
| 30  | Fee transparency at checkout         | **Parity**             |
| 31  | Who-pays (buyer/organizer/split)     | **Partial**            |
| 32  | Invoice payment                      | **Gap**                |
| 33  | Gift cards                           | **Gap**                |
| 34  | Funds-on-demand / payout timing      | **Partial**            |


### 3.6 Promotions & marketing


| #   | Feature                          | Parity / Partial / Gap                                                    |
| --- | -------------------------------- | ------------------------------------------------------------------------- |
| 35  | Promo codes                      | **Partial** (Commerce Promotion in stack; MEL-specific UX not documented) |
| 36  | Affiliate codes                  | **Gap**                                                                   |
| 37  | Email (confirmations, reminders) | **Parity**                                                                |
| 38  | Embedded widgets                 | **Parity**                                                                |
| 39  | Public discovery                 | **Parity**                                                                |


### 3.7 Guest & refund management


| #   | Feature                                       | Parity / Partial / Gap          |
| --- | --------------------------------------------- | ------------------------------- |
| 40  | Vendor attendee/order management              | **Parity**                      |
| 41  | Vendor-initiated refunds                      | **Parity**                      |
| 42  | Buyer self-service refund (from confirmation) | **Gap**                         |
| 43  | Refund policy display/consent                 | **Parity**                      |
| 44  | Teams (multi-user event access)               | **Partial** (vendor roles only) |


### 3.8 Accessibility & impact


| #   | Feature                              | Parity / Partial / Gap              |
| --- | ------------------------------------ | ----------------------------------- |
| 45  | Accessibility metadata (event/venue) | **Parity**                          |
| 46  | Attendee accessibility needs         | **Parity**                          |
| 47  | Charity/social-impact model          | **Gap** (different product posture) |


---

## 4. Gap list (concise)

Items where MyEventLane has **no** or **insufficient** equivalent to Humanitix.

### 4.1 High impact (core ticketing / event ops)

- **Recurring events** — No recurring/series model; each event is single-date.
- **Seat maps** — No table/auditorium/stall mapping or seat selection.
- **Merchandise add-ons** — No add-on products in event checkout.
- **Assign tickets later** — No “buy N tickets, assign attendees later” (e.g. corporate).
- **Buyer self-service refund** — No refund-from-confirmation-email within policy; refunds are vendor-initiated.
- **Invoice payment** — No invoice/pay-later for B2B.
- **Gift cards** — No gift card product or redemption.

### 4.2 Check-in & event day

- **Native check-in app** — Web-based only; no “Humanitix for Hosts”–style app.
- **Offline scan + sync** — Requires connectivity.
- **VIP / custom scan messages** — No VIP flag or staff-facing messages on scan.

### 4.3 Promotions & marketing

- **Affiliate codes** — No dedicated affiliate/referral code system.
- **Promo codes** — Commerce Promotion is in the stack; MEL-specific promo UX and docs are not confirmed.

### 4.4 Guest experience

- **1-Click–style guest update** — No token-based “update your details without account”; “My Tickets” is account-based.

### 4.5 Product model (non-feature)

- **Charity/social-impact** — Humanitix gives 100% profits to charity; MEL is a commercial platform. Not a feature gap but a product difference.

---

## 5. Partial-parity list (short)

Areas where MyEventLane has **some** of the Humanitix behaviour but not all.

- **Who pays fees** — Fees shown to buyer; full “organizer absorbs / split” configuration not confirmed.
- **Recurring** — Only via “repeat” wording in some code paths; no true recurring events.
- **Public vs private** — Publish/unpublish only; no “private event” product.
- **Event branding** — Theming only; no Canva or hosted design tool.
- **Waitlist priority** — Automation to notify when spots free; priority-order and auto-release semantics not fully confirmed.
- **Promo codes** — Commerce Promotion present; MEL wrappers and UX need review.
- **Teams** — Vendor roles exist; no Humanitix-style “invite teammate to this event” workflow.
- **Funds timing** — Stripe Connect in use; explicit “funds on demand” or 7-day rules not in MEL config.

---

## 6. Summary counts


| Category                  | Parity | Partial | Gap    |
| ------------------------- | ------ | ------- | ------ |
| Event creation & formats  | 3      | 2       | 1      |
| Tickets & capacity        | 5      | 0       | 4      |
| Waitlist                  | 2      | 1       | 0      |
| Check-in & event day      | 3      | 0       | 3      |
| Payments & fees           | 4      | 2       | 2      |
| Promotions & marketing    | 3      | 1       | 1      |
| Guest & refund management | 3      | 1       | 1      |
| Accessibility & impact    | 2      | 0       | 1      |
| **Total**                 | **25** | **7**   | **13** |


---

## 7. Doc metadata

- **Created:** 2026-01-27  
- **Humanitix reference:** humanitix.com (US/features, pricing, help centre).  
- **MyEventLane reference:** repo `/Users/anna/myeventlane` (web/modules/custom, config).  
- **Reviewed modules (sample):** myeventlane_event, myeventlane_commerce, myeventlane_checkout_flow, myeventlane_capacity, myeventlane_event_attendees, myeventlane_checkin, myeventlane_rsvp, myeventlane_refunds, myeventlane_tickets, myeventlane_donations, myeventlane_messaging, myeventlane_core, myeventlane_vendor, myeventlane_schema.

