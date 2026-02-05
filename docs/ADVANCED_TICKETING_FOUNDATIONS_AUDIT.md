# Advanced Ticketing Foundations Audit — MyEventLane v2

**Date:** 2026-02-02  
**Scope:** Audit only. No implementation, refactor, or design proposals.  
**Method:** Codebase search with exact file/class/method citations.

---

## 1. Executive Summary

MyEventLane v2 has **partial foundations** for Assign Tickets Later (unassigned ticket state and token infrastructure exist; buyer assignment flow does not), **strong foundations** for Who-Pays-Fees (configurable platform fee; Stripe application fee hardcoded), **remediated** Checkout Duplicate Content (template fixed per CHECKOUT_ISSUES_REPORT; Stripe still loaded from multiple sources), **no foundations** for Recurring Events (rrule.js library present but unused; events are single-instance), and **limited foundations** for Traffic Spike Protection (capacity enforcement and EventProductManager lock exist; no checkout rate limiting or payment idempotency). Implementation of these five features will require new work in all areas; architectural refactor is not mandatory but extension and consolidation are.

---

## 2. Feature-by-Feature Foundations Audit

### 1️⃣ Assign Tickets Later (Buyer UX + Tokens)

#### Existing Foundations (with evidence)

| Component | Location | Evidence |
|-----------|----------|----------|
| Unassigned ticket state | `web/modules/custom/myeventlane_tickets/src/Entity/Ticket.php` | `STATUS_ISSUED_UNASSIGNED`, `STATUS_ASSIGNED` (lines 51–52, 124–126); default `STATUS_ISSUED_UNASSIGNED` (line 131) |
| Ticket holder fields | `web/modules/custom/myeventlane_tickets/src/Entity/Ticket.php` | `holder_name`, `holder_email` (lines 111–118), both optional |
| Ticket creation as unassigned | `web/modules/custom/myeventlane_tickets/src/Ticket/TicketIssuer.php` | `'status' => Ticket::STATUS_ISSUED_UNASSIGNED` (line 70) |
| Secure token service (HMAC) | `web/modules/custom/myeventlane_checkout_paragraph/src/Service/CheckInTokenService.php` | `generateToken(ParagraphInterface)`, `validateToken(string)`; HMAC over `paragraph_id:timestamp`; 24h expiry (lines 29–106) |
| Token-based check-in route | `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.routing.yml` | `myeventlane_checkout_flow.vendor_checkin_scan` path `/vendor/check-in/scan/{token}` |
| Ticket PDF blocks until assigned | `web/modules/custom/myeventlane_tickets/src/Ticket/TicketPdfGenerator.php` | `generatePdfForTicket()` throws if `holder_name` or `holder_email` empty (lines 116–121) |

#### Partial Implementations (with evidence)

| Component | Location | Evidence |
|-----------|----------|----------|
| Ticket entity vs paragraph flow | Two parallel flows | `TicketIssuer` (OrderPaidSubscriber) creates `myeventlane_ticket` entities; `OrderCompletedSubscriber` creates `EventAttendee` from `field_ticket_holder` paragraphs. No code maps paragraph holder data into Ticket entity `holder_name`/`holder_email`. |
| CheckInTokenService scope | Paragraph-based | Token binds to `paragraph_id`. Not ticket_id. Reusable pattern but different entity. |

#### Missing Components

- Buyer-facing controller or form for ticket assignment after purchase
- Token generation/validation for ticket (or ticket_code) — CheckInTokenService is paragraph-scoped
- Route for buyer to assign tickets via secure link (e.g. `/ticket/assign/{token}`)
- Messaging template for “assign your tickets” email
- Logic to transition Ticket from `STATUS_ISSUED_UNASSIGNED` to `STATUS_ASSIGNED`

#### Reusable Components

| Component | File / Service | Notes |
|-----------|----------------|-------|
| CheckInTokenService | `myeventlane_checkout_paragraph/src/Service/CheckInTokenService.php` | Extendable: same HMAC pattern for ticket_id or ticket_code |
| Ticket entity | `myeventlane_tickets/src/Entity/Ticket.php` | As-is: status and holder fields exist |
| TicketPdfGenerator | `myeventlane_tickets/src/Ticket/TicketPdfGenerator.php` | As-is: already enforces holder assignment before PDF |

#### Explicit Answers

- **Do unassigned tickets exist in the data model?** Yes. `Ticket::STATUS_ISSUED_UNASSIGNED`, `holder_name`/`holder_email` optional, `TicketIssuer` creates unassigned tickets.
- **Is there any token infrastructure reusable?** Yes. `CheckInTokenService` (HMAC, expiry) is reusable; scope is paragraph, not ticket.
- **Is there any buyer-facing controller or form related to ticket holders?** No. `TicketHolderParagraphPane` collects holders at checkout. `MyTicketsController` displays orders; no assignment form. `TicketDownloadController` downloads by order_item or ticket_code; no assignment UI.

---

### 2️⃣ Who-Pays-Fees Configuration

#### Existing Foundations (with evidence)

| Component | Location | Evidence |
|-----------|----------|----------|
| Platform fee (order-level, buyer-facing) | `web/modules/custom/myeventlane_commerce/src/OrderProcessor/PlatformFeeOrderProcessor.php` | `platform_fee_percent` from `myeventlane_core.settings` (lines 53–54); configurable via GeneralSettingsForm |
| Platform fee config | `web/modules/custom/myeventlane_core/config/install/myeventlane_core.settings.yml` | `platform_fee_percent: 5` |
| Application fee (Stripe Connect) | `web/modules/custom/myeventlane_commerce/src/Service/StripeConnectPaymentService.php` | `calculateApplicationFee()` (lines 253–258); `getConnectPaymentIntentParams()` (lines 281–322) |
| Fee calculation formula | `web/modules/custom/myeventlane_core/src/Service/StripeService.php` | `calculateApplicationFee(int $amount, float $feePercentage = 0.03, int $fixedFeeCents = 30)` (lines 539–541) |
| Fee transparency pane | `web/modules/custom/myeventlane_checkout_flow/src/Plugin/Commerce/CheckoutPane/FeeTransparencyPane.php` | Displays subtotal, donation, fees, tax (lines 79–120) |

#### Partial Implementations (with evidence)

| Component | Location | Evidence |
|-----------|----------|----------|
| Stripe application fee | Hardcoded | `StripeConnectPaymentService::calculateApplicationFee()` passes `0.03`, `30` to `StripeService::calculateApplicationFee()`; no config entity |
| Fee exclusion logic | `PlatformFeeOrderProcessor` | Excludes donations, boost (EXCLUDED_BUNDLES); fee on ticket subtotal only |

#### Missing Components

- Config for Stripe application fee (percentage, fixed cents)
- Config or field for “who pays” (buyer vs organizer absorption)
- Conditional logic for organizer-absorb or split
- Event-level or store-level fee override

#### Reusable Components

| Component | File / Service | Notes |
|-----------|----------------|-------|
| PlatformFeeOrderProcessor | `myeventlane_commerce/src/OrderProcessor/PlatformFeeOrderProcessor.php` | Extendable: add absorption/split logic |
| StripeConnectPaymentService | `myeventlane_commerce/src/Service/StripeConnectPaymentService.php` | Extendable: read fee from config |
| FeeTransparencyPane | `myeventlane_checkout_flow/src/Plugin/Commerce/CheckoutPane/FeeTransparencyPane.php` | Extendable: display absorption/split |

#### Explicit Answers

- **Where are fees calculated?** Platform fee: `PlatformFeeOrderProcessor::process()`. Stripe application fee: `StripeConnectPaymentService::calculateApplicationFee()` → `StripeService::calculateApplicationFee()`.
- **Is the buyer always paying today?** Yes. Platform fee is an order adjustment (buyer pays). Stripe application fee is deducted from vendor payout (vendor pays platform).
- **Is there any conditional logic already present?** No. No “organizer absorbs” or “split” logic. HUMANITIX_PARITY_ANALYSIS.md (line 190): “organizer-absorb/split not confirmed in config”.

---

### 3️⃣ Checkout Duplicate Content & Stripe Hardening

#### Existing Foundations (with evidence)

| Component | Location | Evidence |
|-----------|----------|----------|
| Checkout template (remediated) | `web/themes/custom/myeventlane_theme/templates/commerce/commerce-checkout-form.html.twig` | Single form render; no duplicate blocks (45 lines total) |
| Checkout flow config | `web/modules/custom/myeventlane_checkout_flow/config/install/commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml` | Panes: mel_buyer_details, ticket_holder_paragraph, mel_donation, mel_legal_consent, payment_information, grouped_order_summary, coupon_redemption, mel_fee_transparency, order_summary |
| Form submit safety | `web/modules/custom/myeventlane_commerce/myeventlane_commerce.module` | `myeventlane_commerce_checkout_form_submit_safety_check` (lines 463–484); ensures `#payment_options` exists |

#### Partial Implementations (with evidence)

| Component | Location | Evidence |
|-----------|----------|----------|
| Stripe loading | Multiple sources | (1) `commerce_stripe/stripe` library (myeventlane_theme.theme line 273); (2) Direct html_head script injection (lines 281–291); (3) `myeventlane_theme_stripe_js_forced`; (4) `stripe-fallback.js` re-runs `commerceStripeForm` / `commerceStripePaymentElement` |
| Stripe init path | Commerce Stripe + theme | Commerce Stripe provides behaviors; theme attaches library + direct script on `commerce_checkout` routes |

#### Missing Components

- Single canonical Stripe init path (currently library + direct injection + fallback)
- Verification that checkout panes are not rendered twice (template is clean; no evidence of pane duplication)

#### Reusable Components

| Component | File / Service | Notes |
|-----------|----------------|-------|
| commerce-checkout-form.html.twig | `myeventlane_theme/templates/commerce/commerce-checkout-form.html.twig` | As-is: no duplicate content |
| Commerce Stripe behaviors | contrib `commerce_stripe` | As-is: `commerceStripeForm`, `commerceStripePaymentElement` |

#### Explicit Answers

- **Where duplication originates?** Per CHECKOUT_ISSUES_REPORT.md, duplication was in template (form rendered twice) and Stripe scripts (two init blocks). Template is now fixed. Stripe: library + direct injection + fallback = multiple load paths.
- **Is the Stripe init path centralized?** No. Theme attaches `commerce_stripe/stripe` and a direct script; `stripe-fallback.js` can load Stripe and re-run behaviors.
- **Are checkout panes rendered twice?** No evidence. Template renders `form|without(...)` and `form.sidebar`, `form.actions` once. Commerce checkout flow renders panes per step; no duplicate pane config.

---

### 4️⃣ Recurring Events (Series Model)

#### Existing Foundations (with evidence)

| Component | Location | Evidence |
|-----------|----------|----------|
| rrule.js library | `web/libraries/rrule/2.6.8/rrule.min.js` | Present; recurrence rules for calendar dates |
| Event date fields | `web/modules/custom/myeventlane_schema`, EVENT_NODE_DISCOVERY_REPORT | `field_event_start`, `field_event_end`, `field_sales_start`, `field_sales_end` — single datetime each |
| Placeholder for series | `web/modules/custom/myeventlane_vendor/src/Controller/VendorEventRsvpController.php` | Line 64: “Daily series no longer available - use empty array”; `$series = []` |

#### Partial Implementations (with evidence)

| Component | Location | Evidence |
|-----------|----------|----------|
| Time-series (analytics) | `myeventlane_vendor`, `myeventlane_analytics` | “Time series” = chart data (sales over time), not event recurrence |

#### Missing Components

- Event series entity or parent_event reference
- RRULE or recurrence rule field on event
- Scheduler/cron to generate child events from series
- Views or queries for event groups/series
- Parent/child event relationship

#### Reusable Components

| Component | File / Service | Notes |
|-----------|----------------|-------|
| rrule.js | `web/libraries/rrule/2.6.8/rrule.min.js` | As-is: can parse/generate RRULE |
| Event node | `node.type.event` | Extendable: add `field_event_series` or `field_parent_event` |

#### Explicit Answers

- **Is there any existing series or recurrence logic?** No. VendorEventRsvpController uses empty `$series`. rrule.js is present but not used for events.
- **Are events strictly single-instance today?** Yes. One `field_event_start`/`field_event_end` per event; no series or recurrence fields.
- **Is there any groundwork we can reuse?** rrule.js library and event node type; no series/recurrence implementation.

---

### 5️⃣ Traffic Spike & Checkout Protection

#### Existing Foundations (with evidence)

| Component | Location | Evidence |
|-----------|----------|----------|
| Capacity enforcement | `web/modules/custom/myeventlane_capacity/src/EventSubscriber/CommerceCapacityEnforcementSubscriber.php` | `onCartEntityAdd`, `onOrderPlacePreTransition`; `assertCanBook()` blocks oversell |
| Order placement gate | `docs/phase-3-capacity-enforcement.md` | Add-to-cart, cart validation, order placement pre-transition |
| Lock (concurrency) | `web/modules/custom/myeventlane_event/myeventlane_event.services.yml` | `EventProductManager` uses `@lock` (line 30) |
| Lock usage | `web/modules/custom/myeventlane_event/src/Service/EventProductManager.php` | Prevents concurrent product sync (EVENT_WIZARD_FIX_COMPLETE.md) |
| Messaging idempotency | `web/modules/custom/myeventlane_messaging/src/Service/MessagingManager.php` | “Single entry point; idempotent” (line 16); duplicate skip (line 129) |
| Messaging rate limit | `web/modules/custom/myeventlane_messaging/src/Plugin/QueueWorker/MessagingQueueWorker.php` | `getMaxMessagesPerRun()` caps messages per cron (lines 114–124) |
| API rate limiting | `web/modules/custom/myeventlane_api/src/Service/RateLimiterService.php` | `checkLimit()`; table `myeventlane_api_rate_limit`; used by VendorExportApiController, VendorEventApiController, VendorAttendeeApiController, PublicEventApiController |
| Vendor comms rate limit | `web/modules/custom/myeventlane_vendor_comms/src/Service/CommsRateLimiter.php` | `checkRateLimit()` per event/vendor; hourly/daily limits |

#### Partial Implementations (with evidence)

| Component | Location | Evidence |
|-----------|----------|----------|
| Refund queue | `myeventlane_refunds` | VendorRefundWorker processes refunds asynchronously; payment itself is synchronous |
| Payment processing | Commerce + Stripe | Synchronous; no queue between checkout submit and Stripe |

#### Missing Components

- Rate limiting on checkout or payment endpoints
- Idempotency for payment/order placement (e.g. idempotency key)
- Lock around order placement or payment
- Fail-safe retry logic for payment
- Edge/cache protection for checkout

#### Reusable Components

| Component | File / Service | Notes |
|-----------|----------------|-------|
| RateLimiterService | `myeventlane_api/src/Service/RateLimiterService.php` | Extendable: apply to checkout routes |
| Lock service | Drupal core `@lock` | As-is: used by EventProductManager |
| CommerceCapacityEnforcementSubscriber | `myeventlane_capacity` | As-is: final gate before order placement |
| MessagingQueueWorker rate limit | `myeventlane_messaging` | Pattern only: per-run cap |

#### Explicit Answers

- **What protects checkout today?** Capacity enforcement (add-to-cart, order placement); no rate limiting or payment idempotency on checkout.
- **Are queues involved in payment processing?** No. Payment is synchronous. Refunds use VendorRefundWorker; messaging uses queue.
- **Is there any rate limiting or request throttling?** Yes for API (`RateLimiterService`) and vendor comms (`CommsRateLimiter`). Not for checkout.

---

## 3. Reuse Map (Critical)

| Feature | Reusable Component | File / Service | Notes |
|---------|--------------------|----------------|-------|
| Assign Tickets Later | Ticket entity | `myeventlane_tickets/src/Entity/Ticket.php` | STATUS_ISSUED_UNASSIGNED, holder_name, holder_email |
| Assign Tickets Later | CheckInTokenService | `myeventlane_checkout_paragraph/src/Service/CheckInTokenService.php` | HMAC pattern; extend for ticket scope |
| Assign Tickets Later | TicketPdfGenerator | `myeventlane_tickets/src/Ticket/TicketPdfGenerator.php` | Enforces holder before PDF |
| Who-Pays-Fees | PlatformFeeOrderProcessor | `myeventlane_commerce/src/OrderProcessor/PlatformFeeOrderProcessor.php` | Configurable platform_fee_percent |
| Who-Pays-Fees | StripeConnectPaymentService | `myeventlane_commerce/src/Service/StripeConnectPaymentService.php` | calculateApplicationFee, getConnectPaymentIntentParams |
| Who-Pays-Fees | FeeTransparencyPane | `myeventlane_checkout_flow/src/Plugin/Commerce/CheckoutPane/FeeTransparencyPane.php` | Fee display |
| Checkout Hardening | commerce-checkout-form.html.twig | `myeventlane_theme/templates/commerce/commerce-checkout-form.html.twig` | No duplicate content |
| Checkout Hardening | Commerce Stripe | contrib | commerceStripeForm, commerceStripePaymentElement |
| Recurring Events | rrule.js | `web/libraries/rrule/2.6.8/rrule.min.js` | Recurrence rules |
| Recurring Events | Event node | `node.type.event` | Add series/parent fields |
| Traffic Spike | RateLimiterService | `myeventlane_api/src/Service/RateLimiterService.php` | Extend to checkout |
| Traffic Spike | Lock service | Drupal `@lock` | Concurrency |
| Traffic Spike | CommerceCapacityEnforcementSubscriber | `myeventlane_capacity` | Order placement gate |

---

## 4. Risk & Complexity Snapshot

| Feature | Technical Risk | Architectural Impact | Dependency Risk |
|----------|----------------|----------------------|-----------------|
| Assign Tickets Later | Medium | Medium: reconcile Ticket entity vs EventAttendee/paragraph flows; new buyer routes | Low |
| Who-Pays-Fees | Low | Low: config + conditional logic in existing services | Low |
| Checkout Hardening | Low | Low: consolidate Stripe loading; no structural change | Low (Commerce Stripe) |
| Recurring Events | High | High: new entity/fields, scheduler, views | Medium (rrule.js) |
| Traffic Spike | Medium | Medium: rate limit + idempotency on checkout; lock around payment | Low |

---

## 5. Final Verdict

**MyEventLane v2 has sufficient foundations to begin implementation of four of the five features via extension and consolidation; Recurring Events requires new architecture but not refactor of existing flows.**

---

*End of Audit*
