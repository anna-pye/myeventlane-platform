# Event Studio Checkout Integrity Audit

Status: in progress  
Scope: Event Studio attendee questions against Drupal Commerce checkout flows

## Scenarios To Verify

- Single ticket purchase.
- Multiple quantities of one ticket type.
- Multiple ticket types in one cart.
- Ticket plus add-on.
- Ticket plus merchandise.
- Ticket plus donation.
- Per-ticket questions.
- Per-ticket-type questions.
- Optional and required questions.
- Required enforcement.
- Invalid number handling.
- Select/radio option validation.
- Archived question exclusion from new checkout renders.
- Historical archived-answer rendering in readonly reporting.
- Ticket issuance after attendee answers save.
- Mixed carts without duplicate attendee holders or duplicated order item metadata.

## Integrity Boundaries

- Checkout must continue using `TicketHolderParagraphPane`.
- Question selection must continue through `CheckoutAttendeeSchemaService`.
- Answers must continue writing through the existing `attendee_answer` child paragraphs and `field_attendee_extra_field`.
- Studio autosave must never mutate checkout answers.
- Reporting views must be readonly projections and must not mutate attendee/order data.

## Failures Found

- Full checkout purchase and mixed-cart browser verification remains pending.
- Browser smoke confirmed the Event Studio checkout questions section renders on DDEV for event `1599`, but no purchase scenario was completed in this pass.

## Fixes Applied

- Autosave replay now normalizes `entity_autocomplete` values from Form API element metadata instead of field-name lists.
- Server-side autosave now rejects non-writable, readonly, deferred, coming-soon, unknown, and autosave-unsupported sections.
- Question mutation governance now blocks semantic changes after historical answers exist.
- Question option values now use deterministic normalized hashes for immutability checks.
- Readonly sections now render projection DTOs for summary data.

## Unresolved Risks

- Full mixed-cart browser verification is still required before sign-off.
- Historical answer detection depends on cloned question metadata until a future answer/version snapshot model exists.
- Per-order questions remain deferred because answer storage is not enabled for that boundary.
