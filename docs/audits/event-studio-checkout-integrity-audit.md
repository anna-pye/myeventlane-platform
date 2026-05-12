# Event Studio Checkout Integrity Audit

Status: in progress, paid checkout browser pass completed on DDEV  
Scope: Event Studio attendee questions against Drupal Commerce checkout flows

## Disposable DDEV Fixtures

- Paid browser fixture: event `1567` ("Experience Anna Live"), product `90`, ticket types `88` and `89`, variations `4121` and `4122`.
- RSVP browser fixture from prior audit: event `1540`; fallback published RSVP events `1381` and `1544`.
- Checkout audit user: `mel_checkout_audit` (`uid 76`).
- Event `1567` was given disposable attendee question templates for this audit:
  - `mel_required_meal_choice`: active required select, per-ticket, options `Chicken` / `Vegetarian`.
  - `mel_optional_accessibility_note`: active optional textarea, per-ticket.
  - `mel_vip_shirt_size`: active required radios, scoped to ticket type `88`, options `S` / `M` / `L`.
  - `mel_archived_legacy_code`: archived textfield, per-ticket.

## Scenarios To Verify

- Single ticket purchase: covered indirectly by order-item quantity `1` per selected ticket type in order `471`.
- Multiple quantities of one ticket type: pending.
- Multiple ticket types in one cart: passed in browser with order `471`.
- Ticket plus add-on: pending fixture; no add-on path confirmed in this pass.
- Ticket plus merchandise: pending fixture; no merchandise path confirmed in this pass.
- Ticket plus donation: deferred for this event; booking donation UI is gated by `field_enable_donations`, which is not present on event `1567` in this DDEV bundle.
- Per-ticket questions: passed in browser with `mel_required_meal_choice` and `mel_optional_accessibility_note`.
- Per-ticket-type questions: passed in browser; `mel_vip_shirt_size` rendered only for ticket type `88`.
- Optional and required questions: passed in browser.
- Required enforcement: passed in browser; incomplete submission stayed on checkout and did not create holder paragraphs.
- Invalid number handling: pending.
- Select/radio option validation: first browser pass saw newline-separated stored options render as one combined option; after cache rebuild, the current `TicketHolderParagraphPane` newline parser rendered separate options and the flow passed.
- Archived question exclusion from new checkout renders: passed in browser; `mel_archived_legacy_code` did not render.
- Historical archived-answer rendering in readonly reporting.
- Ticket issuance after attendee answers save: passed for order `471`; two `myeventlane_ticket` entities (`179`, `180`) issued.
- Mixed carts without duplicate attendee holders or duplicated order item metadata: passed for multiple ticket types in one Commerce order; non-ticket mixed carts remain pending/deferred pending fixtures.

## Integrity Boundaries

- Checkout must continue using `TicketHolderParagraphPane`.
- Question selection must continue through `CheckoutAttendeeSchemaService`.
- Answers must continue writing through the existing `attendee_answer` child paragraphs and `field_attendee_extra_field`.
- Studio autosave must never mutate checkout answers.
- Reporting views must be readonly projections and must not mutate attendee/order data.

## Failures Found

- Full non-ticket mixed-cart browser verification remains pending for add-on, merchandise, and donation fixtures.
- Browser smoke previously confirmed the Event Studio checkout questions section renders on DDEV for event `1599`, but no purchase scenario was completed in that pass.
- First paid checkout browser pass saw newline-separated `field_question_options` values render as one combined option. Select/radio validation became hard to satisfy, and server validation reset the combined choice. After cache rebuild, the current checkout pane rendered separate options.

## Fixes Applied

- Autosave replay now normalizes `entity_autocomplete` values from Form API element metadata instead of field-name lists.
- Server-side autosave now rejects non-writable, readonly, deferred, coming-soon, unknown, and autosave-unsupported sections.
- Question mutation governance now blocks semantic changes after historical answers exist.
- Question option values now use deterministic normalized hashes for immutability checks.
- Readonly sections now render projection DTOs for summary data.
- Checkout attendee question option rendering splits newline-separated stored option values before building select, radio, and checkboxes elements; this was confirmed in-browser after cache rebuild.

## Browser Verification Notes

- Browser path: `/event/1567/book` -> `/cart` -> `/checkout/471/order_information` -> `/checkout/471/review` -> `/checkout/471/complete`.
- The cart contained two ticket types in one order: order items `701` and `702`.
- Validation failure recovery: submitting missing last names and required question answers kept the order in draft, preserved the checkout page, marked required fields invalid, and left order items with zero `field_ticket_holder` references.
- Successful retry created exactly two holder paragraphs, one per order item: holder `667` for order item `701`, holder `670` for order item `702`.
- Holder `667` contains three answer snapshots: required meal choice `Chicken`, optional accessibility note `Needs aisle access`, and ticket-type question `M`.
- Holder `670` contains two answer snapshots: required meal choice `Vegetarian` and optional accessibility note empty. It did not receive the ticket-type-only `mel_vip_shirt_size` question.
- Completed order `471` has state `completed`, total `101.38 AUD`, and exactly two issued `myeventlane_ticket` rows.

## Unresolved Risks

- Full add-on, merchandise, donation, RSVP-only, mobile, browser refresh, back-button, and multiple-quantity browser verification is still required before sign-off.
- Historical answer detection depends on cloned question metadata until a future answer/version snapshot model exists.
- Per-order questions remain deferred because answer storage is not enabled for that boundary.
