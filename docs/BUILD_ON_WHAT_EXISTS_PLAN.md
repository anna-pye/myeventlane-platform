# Build-On-What-Exists Plan — MyEventLane v2

**Date:** 2026-02-02  
**Source:** Advanced Ticketing Foundations Audit (2026-02-02)  
**Scope:** Architectural planning only. No implementation, UX, migrations, or timelines.

---

## Feature 1 — Checkout Duplicate Content / Stripe Hardening

### Existing Components Reused (file + reason)

| Component | File | Reason |
|-----------|------|--------|
| commerce-checkout-form.html.twig | `web/themes/custom/myeventlane_theme/templates/commerce/commerce-checkout-form.html.twig` | Single form render; no duplicate content. Leave as-is. |
| Commerce Stripe behaviors | contrib `commerce_stripe` | `commerceStripeForm`, `commerceStripePaymentElement` remain the single init path. |
| myeventlane_commerce_checkout_form_submit_safety_check | `web/modules/custom/myeventlane_commerce/myeventlane_commerce.module` | Form submit safety; do not touch. |

### Extensions Required (file + reason)

| Component | File | Reason |
|-----------|------|--------|
| Stripe attachment logic | `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` | Consolidate: remove direct html_head script injection (lines 281–291); keep only `commerce_stripe/stripe` library. Single attach point. |
| stripe-fallback.js | `web/themes/custom/myeventlane_theme/js/stripe-fallback.js` | Reduce scope: fallback only when library fails; do not re-run behaviors if Stripe already loaded. |

### New Components Required (isolated only)

| Component | Reason |
|-----------|--------|
| None | Consolidation only; no new primitives. |

### Explicit Non-Goals (what will NOT be changed)

- Commerce checkout flow config (`mel_event_checkout.yml`)
- Commerce Stripe contrib module
- Checkout pane structure (ticket_holder_paragraph, payment_information, etc.)
- Form alter logic in myeventlane_commerce.module

### No-Refactor Guarantee

- **Must NOT touch:** Commerce checkout form structure, Commerce Stripe behaviors, payment_information pane.
- **Single source of truth:** `commerce_stripe/stripe` library for Stripe.js load; Commerce Stripe behaviors for init.

### Dependency Map

- **Depends on:** Nothing. First in build order.
- **Blocks:** Who-Pays-Fees (checkout stability), Traffic Spike (checkout protection).

### Risk Level

**Low**

### Why This Order Is Safe

Checkout is the highest-traffic, highest-risk surface. Hardening Stripe loading first reduces failure modes before adding fee logic or rate limiting. No schema changes; no new services; consolidation only.

---

## Feature 2 — Who-Pays-Fees Configuration

### Existing Components Reused (file + reason)

| Component | File | Reason |
|-----------|------|--------|
| PlatformFeeOrderProcessor | `web/modules/custom/myeventlane_commerce/src/OrderProcessor/PlatformFeeOrderProcessor.php` | Single source of truth for order-level platform fee. Reuse as-is. |
| StripeConnectPaymentService | `web/modules/custom/myeventlane_commerce/src/Service/StripeConnectPaymentService.php` | Single source of truth for Stripe Connect params. Reuse. |
| StripeService::calculateApplicationFee | `web/modules/custom/myeventlane_core/src/Service/StripeService.php` | Fee formula. Reuse; parameters become configurable. |
| FeeTransparencyPane | `web/modules/custom/myeventlane_checkout_flow/src/Plugin/Commerce/CheckoutPane/FeeTransparencyPane.php` | Fee display. Reuse. |
| myeventlane_core.settings | `web/modules/custom/myeventlane_core/config/install/myeventlane_core.settings.yml` | Config pattern. Reuse. |
| GeneralSettingsForm | `web/modules/custom/myeventlane_core/src/Form/GeneralSettingsForm.php` | Admin config UI. Extend. |

### Extensions Required (file + reason)

| Component | File | Reason |
|-----------|------|--------|
| StripeConnectPaymentService::calculateApplicationFee | `web/modules/custom/myeventlane_commerce/src/Service/StripeConnectPaymentService.php` | Read fee_percentage, fixed_fee_cents from config instead of hardcoded 0.03, 30. |
| myeventlane_core.settings schema | `web/modules/custom/myeventlane_core/config/schema/myeventlane_core.schema.yml` | Add stripe_fee_percent, stripe_fee_fixed_cents, fee_payer (buyer/organizer/split). |
| GeneralSettingsForm | `web/modules/custom/myeventlane_core/src/Form/GeneralSettingsForm.php` | Add form elements for Stripe fee config and fee payer. |
| PlatformFeeOrderProcessor | `web/modules/custom/myeventlane_commerce/src/OrderProcessor/PlatformFeeOrderProcessor.php` | Add conditional logic: if organizer absorbs, skip or reduce platform fee adjustment. |
| FeeTransparencyPane | `web/modules/custom/myeventlane_checkout_flow/src/Plugin/Commerce/CheckoutPane/FeeTransparencyPane.php` | Display absorption/split when applicable. |

### New Components Required (isolated only)

| Component | Reason |
|-----------|--------|
| None | All extensions to existing services and config. |

### Explicit Non-Goals (what will NOT be changed)

- Stripe Connect destination charge structure
- Donation exclusion from fee base (ticket revenue only)
- Commerce order adjustment types
- Checkout flow pane order

### No-Refactor Guarantee

- **Must NOT touch:** Stripe Connect transfer_data logic, donation exclusion, order placement flow.
- **Single source of truth:** `StripeConnectPaymentService::getConnectPaymentIntentParams()` for Stripe params; `PlatformFeeOrderProcessor` for order adjustments.

### Dependency Map

- **Depends on:** Checkout Hardening (stable Stripe init before fee display changes).
- **Blocks:** Nothing.

### Risk Level

**Low**

### Why This Order Is Safe

Fee config is additive. Existing flows remain; new config keys and conditional branches only. Checkout Hardening first ensures Stripe is stable before fee display changes.

---

## Feature 3 — Assign Tickets Later (Buyer UX + Tokens)

### Existing Components Reused (file + reason)

| Component | File | Reason |
|-----------|------|--------|
| Ticket entity | `web/modules/custom/myeventlane_tickets/src/Entity/Ticket.php` | STATUS_ISSUED_UNASSIGNED, holder_name, holder_email. Reuse as-is. |
| TicketIssuer | `web/modules/custom/myeventlane_tickets/src/Ticket/TicketIssuer.php` | Creates unassigned tickets. Reuse as-is. |
| TicketPdfGenerator | `web/modules/custom/myeventlane_tickets/src/Ticket/TicketPdfGenerator.php` | Enforces holder before PDF. Reuse as-is. |
| CheckInTokenService | `web/modules/custom/myeventlane_checkout_paragraph/src/Service/CheckInTokenService.php` | HMAC pattern, expiry. Reuse pattern; extend scope. |
| MessagingManager | `web/modules/custom/myeventlane_messaging/src/Service/MessagingManager.php` | Queue-based email. Reuse as-is. |
| MyTicketsController | `web/modules/custom/myeventlane_checkout_flow/src/Controller/MyTicketsController.php` | Buyer order/ticket view. Extend or add link. |

### Extensions Required (file + reason)

| Component | File | Reason |
|-----------|------|--------|
| CheckInTokenService (or new TicketAssignmentTokenService) | `myeventlane_checkout_paragraph` or `myeventlane_tickets` | Add ticket-scoped token: generateToken(ticket_id), validateToken(token) → ticket_id. Same HMAC pattern; different entity scope. |
| Ticket entity | `web/modules/custom/myeventlane_tickets/src/Entity/Ticket.php` | Add method or subscriber to transition STATUS_ISSUED_UNASSIGNED → STATUS_ASSIGNED when holder_name/email set. |
| TicketIssuer | `web/modules/custom/myeventlane_tickets/src/Ticket/TicketIssuer.php` | Optional: map paragraph holder data to Ticket when available at checkout; or leave unassigned for "assign later" path. |
| MessagingManager TRANSACTIONAL_TEMPLATES | `web/modules/custom/myeventlane_messaging/src/Service/MessagingManager.php` | Add template ID for assign-tickets email. |

### New Components Required (isolated only)

| Component | Reason |
|-----------|--------|
| AssignTicketForm | Buyer-facing form; token-validated; updates Ticket holder_name, holder_email; transitions status. Isolated in myeventlane_tickets. |
| AssignTicketController | Route handler; validates token; renders form. Isolated. |
| Messaging template | `assign_tickets_buyer` (or equivalent). Config entity; install via hook_install. |
| Route | `/ticket/assign/{token}`. Isolated. |

### Explicit Non-Goals (what will NOT be changed)

- TicketHolderParagraphPane (checkout flow for assign-at-checkout remains)
- OrderCompletedSubscriber (EventAttendee creation from paragraphs)
- VendorCheckInController (check-in flow)
- Ticket entity schema (holder fields already exist)

### No-Refactor Guarantee

- **Must NOT touch:** OrderCompletedSubscriber, TicketHolderParagraphPane, EventAttendee creation, check-in token flow.
- **Single source of truth:** Ticket entity for ticket lifecycle; CheckInTokenService pattern for token validation.

### Dependency Map

- **Depends on:** Checkout Hardening, Who-Pays-Fees (checkout stable).
- **Blocks:** Nothing.

### Risk Level

**Medium**

### Why This Order Is Safe

Checkout and fee config are stable first. Assign Tickets Later adds new buyer route and form; does not alter checkout or order placement. Ticket entity and token pattern exist; extension only. Ticket vs EventAttendee/paragraph flows can coexist: Ticket path for PDF/download; paragraph path for check-in/attendees.

---

## Feature 4 — Traffic Spike & Checkout Protection

### Existing Components Reused (file + reason)

| Component | File | Reason |
|-----------|------|--------|
| RateLimiterService | `web/modules/custom/myeventlane_api/src/Service/RateLimiterService.php` | checkLimit(), myeventlane_api_rate_limit table. Reuse as-is. |
| Lock service | Drupal core `@lock` | Concurrency. Reuse as-is. |
| CommerceCapacityEnforcementSubscriber | `web/modules/custom/myeventlane_capacity/src/EventSubscriber/CommerceCapacityEnforcementSubscriber.php` | Order placement gate. Reuse as-is. |
| EventProductManager lock usage | `web/modules/custom/myeventlane_event/src/Service/EventProductManager.php` | Pattern for lock acquisition. Reuse pattern. |

### Extensions Required (file + reason)

| Component | File | Reason |
|-----------|------|--------|
| RateLimiterService usage | New subscriber or middleware | Apply checkLimit() to checkout routes; identifier = IP or session. Use existing table; new identifier prefix if needed. |
| Commerce order placement | `commerce_order.place.pre_transition` or payment flow | Add lock acquisition around order placement or payment confirmation; release on completion/failure. Pattern from EventProductManager. |
| MessagingManager idempotency pattern | Reference only | Apply similar duplicate-skip logic for payment idempotency key if added. |

### New Components Required (isolated only)

| Component | Reason |
|-----------|--------|
| CheckoutRateLimitSubscriber (or equivalent) | Event subscriber; checks RateLimiterService on checkout routes; returns 429 if exceeded. Isolated. |
| OrderPlacementLockService (or inline lock in existing subscriber) | Acquires lock per order/cart; prevents duplicate placement. Isolated or extends CommerceCapacityEnforcementSubscriber. |
| Idempotency key support (optional) | If payment retries are added: store idempotency_key → order_id; skip duplicate. New table or state; isolated. |

### Explicit Non-Goals (what will NOT be changed)

- Commerce payment flow (synchronous)
- CommerceCapacityEnforcementSubscriber capacity logic
- API rate limiting (VendorExportApiController, etc.)
- Vendor comms rate limiting

### No-Refactor Guarantee

- **Must NOT touch:** Commerce payment gateway plugins, Stripe Connect flow, capacity enforcement algorithm.
- **Single source of truth:** RateLimiterService for rate limits; Lock service for concurrency; CommerceCapacityEnforcementSubscriber for capacity.

### Dependency Map

- **Depends on:** Checkout Hardening (stable Stripe), Who-Pays-Fees (stable fee display), Assign Tickets Later (optional; checkout protection is independent).
- **Blocks:** Nothing.

### Risk Level

**Medium**

### Why This Order Is Safe

Rate limiting and locking are additive. Existing capacity enforcement remains. RateLimiterService and Lock are proven in production (API, EventProductManager). Checkout Hardening and Who-Pays-Fees first ensure checkout is stable before adding protection layers.

---

## Feature 5 — Recurring Events (Series Model)

### Existing Components Reused (file + reason)

| Component | File | Reason |
|-----------|------|--------|
| rrule.js | `web/libraries/rrule/2.6.8/rrule.min.js` | Parse/generate RRULE. Reuse as-is. |
| Event node | `node.type.event` | Base content type. Reuse. |
| Event date fields | `field_event_start`, `field_event_end`, etc. | Per-instance dates. Reuse. |
| EventModeManager | `web/modules/custom/myeventlane_event/src/Service/EventModeManager.php` | Event mode resolution. Extend if series affects mode. |
| Views | Existing event views | Extend filters for series vs instance. |

### Extensions Required (file + reason)

| Component | File | Reason |
|-----------|------|--------|
| Event node | `node.type.event` | Add field_event_series (entity ref) or field_parent_event (self-ref) for child instances. |
| Event node | `node.type.event` | Add field_recurrence_rule (text/RRULE) or field_recurrence_config (structured) for series template. |
| Views | Event list, vendor events, etc. | Add filter: series template vs instance; exclude generated instances from some displays. |

### New Components Required (isolated only)

| Component | Reason |
|-----------|--------|
| Event series entity (optional) | If series is first-class: entity for template; events reference it. Isolated. |
| RecurrenceGeneratorService | Cron/scheduler; reads RRULE; creates child event nodes from template. Isolated. |
| RecurrenceGeneratorCommands | Drush command for manual generation. Isolated. |
| Series-aware capacity | If capacity is per-instance vs series-total: extend EventCapacityService. Isolated extension. |

### Explicit Non-Goals (what will NOT be changed)

- Single-instance event flow (remains default)
- Order placement flow
- Ticket entity
- Check-in flow
- EventAttendee model

### No-Refactor Guarantee

- **Must NOT touch:** OrderCompletedSubscriber, TicketIssuer, EventAttendee, Commerce checkout, capacity enforcement for single events.
- **Single source of truth:** Event node remains; series adds optional fields and generator. Existing events unaffected.

### Dependency Map

- **Depends on:** All prior features (checkout, fees, assign tickets, traffic). Recurring is highest risk; build last.
- **Blocks:** Nothing.

### Risk Level

**High**

### Why This Order Is Safe

Recurring Events is isolated. New entity/fields, scheduler, views. Single-instance events remain unchanged. Build after all checkout-adjacent work is done. Blast radius is limited to series-specific code paths.

---

## Implementation Readiness Verdict

**MyEventLane v2 is ready to begin implementation of Features 1–4 (Checkout Hardening, Who-Pays-Fees, Assign Tickets Later, Traffic Spike Protection) without refactor; Feature 5 (Recurring Events) requires isolated new architecture.**

---

*End of Plan*
