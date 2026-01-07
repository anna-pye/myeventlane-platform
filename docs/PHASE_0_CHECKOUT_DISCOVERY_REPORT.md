# Phase 0: MyEventLane Checkout Discovery Report

**Date:** 2024-12-27  
**Purpose:** Map existing checkout architecture before implementing Humanitix-level upgrade

---

## 1. Commerce Checkout Flow Configuration

### Current State
- **No custom checkout flow plugin found** — system likely uses Commerce default flow
- **Checkout flow config:** Not found in custom modules (may be in database/config export)
- **Default flow ID:** Likely `default` (Commerce standard)

### Checkout Panes Found

#### A. `myeventlane_checkout_paragraph` module
- **Module:** `web/modules/custom/myeventlane_checkout_paragraph/`
- **Status:** Active (lifecycle: stable)
- **Panes:**
  1. **`ticket_holder_paragraph`** (`TicketHolderParagraphPane.php`)
     - Plugin ID: `ticket_holder_paragraph`
     - Default step: `order_information`
     - Stores attendee data as Paragraph entities
     - Field: `field_ticket_holder` on order items (entity reference to paragraphs)
     - Paragraph type: `attendee_answer`
     - Handles extra questions via `field_attendee_questions` (nested paragraphs)
  
  2. **`grouped_order_summary`** (`GroupedSummaryPane.php`)
     - Plugin ID: `grouped_order_summary`
     - Default step: `_sidebar`
     - Uses Views: `ticket_order_summary` display `embed_1`
     - Read-only summary grouped by Event and Ticket Type

#### B. `myeventlane_commerce` module
- **Module:** `web/modules/custom/myeventlane_commerce/`
- **Pane:**
  1. **`myeventlane_attendee_info_per_ticket`** (`AttendeeInfoPerTicket.php`)
     - Plugin ID: `myeventlane_attendee_info_per_ticket`
     - Default step: `order_information`
     - Visible only if event has `field_collect_per_ticket` = TRUE
     - Stores data in `field_attendee_data` (JSON field on order items)
     - Uses `ParagraphQuestionMapper` service for vendor-defined questions
     - Includes accessibility needs checkboxes

#### C. Legacy module (deprecated)
- **Module:** `web/modules/custom/myeventlane_checkout/`
- **Status:** Deprecated (lifecycle: deprecated)
- **Note:** Marked as legacy; use `myeventlane_checkout_paragraph` instead

---

## 2. MEL Ticketing/Cart Customizations

### Ticket Selection
- **File:** `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`
- **Form ID:** `myeventlane_ticket_selection_form`
- **Purpose:** Multi-variation ticket selection matrix (replaces TicketMatrixForm)
- **Storage:** Adds items to Commerce cart via `CartManagerInterface`
- **Note:** No `TicketMatrixForm.php` found — likely replaced by `TicketSelectionForm`

### Cart Twig Overrides
- **Not found in discovery** — may exist in theme or Commerce contrib overrides
- **Location to check:** `web/themes/custom/myeventlane_theme/templates/commerce/`

---

## 3. Stock Enforcement Logic

### Capacity Module
- **Module:** `web/modules/custom/myeventlane_capacity/`
- **Service:** `EventCapacityService` (`web/modules/custom/myeventlane_capacity/src/Service/EventCapacityService.php`)
- **Interface:** `EventCapacityServiceInterface`
- **Methods:**
  - `getCapacityTotal(NodeInterface $event): ?int` — reads `field_event_capacity_total` or `field_capacity`
  - `getSoldCount(NodeInterface $event): int` — counts RSVPs + paid tickets
  - `getRemaining(NodeInterface $event): ?int`
  - `isSoldOut(NodeInterface $event): bool`
  - `assertCanBook(NodeInterface $event, int $requested = 1): void` — throws `CapacityExceededException`

### Current Enforcement Points
- **Event-level capacity** (not per-variation)
- **No Commerce Stock module** detected
- **No per-variation stock table** found
- **Gap:** No enforcement at cart add/refresh/order place transitions

### Exception
- **File:** `web/modules/custom/myeventlane_capacity/src/Exception/CapacityExceededException.php`

---

## 4. Stripe Integration

### Payment Gateway
- **Plugin:** `StripeConnect` (`web/modules/custom/myeventlane_commerce/src/Plugin/Commerce/PaymentGateway/StripeConnect.php`)
- **Plugin ID:** `stripe_connect`
- **Extends:** `StripePaymentElement` (Commerce Stripe contrib)
- **Purpose:** Stripe Connect destination charges for vendor payments
- **Payment method types:** Credit card only

### Services
- **StripeConnectPaymentService** (`web/modules/custom/myeventlane_commerce/src/Service/StripeConnectPaymentService.php`)
  - `getStripeAccountIdForOrder(OrderInterface $order): ?string`
  - `validateOrderForConnect(OrderInterface $order): array`
  - `calculateApplicationFee(OrderInterface $order, float $feePercentage = 0.03, int $fixedFeeCents = 30): int`
  - `getConnectPaymentIntentParams(OrderInterface $order): array`
  - Reads `field_stripe_account_id` from Commerce Store entity

### Core Stripe Service
- **StripeService** (`web/modules/custom/myeventlane_core/src/Service/StripeService.php`)
- **Used by:** `StripeConnectPaymentService`

### Event Subscribers
- **StripeConnectValidationSubscriber** (`web/modules/custom/myeventlane_commerce/src/EventSubscriber/StripeConnectValidationSubscriber.php`)
- **Purpose:** Validates orders before payment

### Store Fields
- `field_stripe_account_id` (text)
- `field_stripe_connected` (boolean)
- `field_stripe_charges_enabled` (boolean)
- `field_stripe_payouts_enabled` (boolean)
- `field_stripe_onboard_url` (text)
- `field_stripe_dashboard_url` (text)

### Webhook Handlers
- **Not found in discovery** — may be in Commerce Stripe contrib or separate module

---

## 5. Attendee Storage Approach

### Current Implementation (Dual System)

#### System A: Paragraph-based (`myeventlane_checkout_paragraph`)
- **Field:** `field_ticket_holder` on `commerce_order_item`
- **Type:** Entity reference to Paragraph entities
- **Paragraph type:** `attendee_answer`
- **Fields on paragraph:**
  - `field_first_name` (text)
  - `field_last_name` (text)
  - `field_email` (email)
  - `field_phone` (tel, optional)
  - `field_attendee_questions` (entity reference to nested paragraphs for extra questions)
- **Extra questions:** Stored as child paragraphs with `field_attendee_extra_field` (text/JSON)

#### System B: JSON field (`myeventlane_commerce`)
- **Field:** `field_attendee_data` on `commerce_order_item`
- **Type:** JSON field
- **Structure:** `{ticket_1: {name, email, accessibility_needs, ...}, ticket_2: {...}}`
- **Used by:** `AttendeeInfoPerTicket` pane (when `field_collect_per_ticket` = TRUE)

### Access Control
- **LockAttendeeOnOrderPlaced** subscriber (`web/modules/custom/myeventlane_commerce/src/EventSubscriber/LockAttendeeOnOrderPlaced.php`)
  - Currently empty implementation (placeholder)
- **No explicit `hook_entity_access()` found** for attendee paragraphs
- **Gap:** No vendor isolation enforcement for attendee data access

### Order Item Fields Summary
- `field_ticket_holder` (entity reference, paragraphs)
- `field_attendee_data` (JSON)
- `field_target_event` (entity reference to event node, used for capacity counting)

---

## 6. ICS/Calendar Generator Services

### ICS Download Routes
- **Route:** `myeventlane_rsvp.ics_download`
  - Path: `/event/{node}/ics`
  - Controller: `Drupal\myeventlane_rsvp\Controller\IcsController::download`
- **Route:** `myeventlane_rsvp.ics_bundle`
  - Path: `/my-profile/download-rsvps.ics`
  - Controller: `Drupal\myeventlane_rsvp\Controller\RsvpIcsBundleController::download`

### Calendar Button Builder
- **Service:** `CalendarButtonBuilder` (`web/modules/custom/myeventlane_rsvp/src/Service/CalendarButtonBuilder.php`)
- **Methods:**
  - `build(NodeInterface $event): array` — returns Google, Outlook, Apple Calendar links
  - Generates URLs for Google Calendar, Outlook, and internal ICS download

### ICS Generation
- **Controllers found:** `IcsController`, `RsvpIcsBundleController`
- **Files:** Not read in discovery (assumed to exist in `myeventlane_rsvp` module)

### Email Integration
- **Template:** `myeventlane_automation/config/install/myeventlane_messaging.template.export_ready_ics.yml`
- **Purpose:** Email template for ICS export (likely for RSVPs)

---

## 7. Vendor Export (CSV)

### Existing CSV Export
- **Controller:** `AttendeeCsvController` (`web/modules/custom/myeventlane_views/src/Controller/AttendeeCsvController.php`)
- **Route:** Uses Views `attendee_answer` with `page_1` display
- **Export format:** First name, Last name, Email, Question, Answer
- **Source:** Paragraph-based attendee answers (`attendee_answer` paragraphs)

### Finance CSV Export
- **Service:** `BasCsvExportService` (`web/modules/custom/myeventlane_finance/src/Service/BasCsvExportService.php`)
- **Purpose:** BAS (Business Activity Statement) exports for admin/vendors
- **Not for attendee data**

### Vendor Dashboard
- **Module:** `myeventlane_vendor_dashboard` — **NOT FOUND**
- **Note:** User mentioned `CsvExportController` in `myeventlane_vendor_dashboard`, but module doesn't exist
- **Possible locations:**
  - May be in `myeventlane_vendor` module
  - May be in `myeventlane_views` (AttendeeCsvController)

---

## 8. Donation Integration

### Donation Module
- **Module:** `web/modules/custom/myeventlane_donations/`
- **Services:**
  - `RsvpDonationService` (uses `field_attendee_data`)
  - `PlatformDonationService` (uses `field_attendee_data`)
- **Checkout pane:** **NOT FOUND** — donations may be handled outside checkout or in a separate flow

---

## 9. Key Entity Types and Fields

### Event Node (node:event bundle)
- `field_event_capacity_total` (integer) — primary capacity field
- `field_capacity` (integer) — fallback capacity field
- `field_event_type` (list) — 'rsvp', 'paid', 'both'
- `field_collect_per_ticket` (boolean) — enables per-ticket attendee capture
- `field_attendee_questions` (entity reference) — template paragraphs for extra questions
- `field_event_start` (datetime)
- `field_event_end` (datetime)
- `field_location` (text)

### Commerce Order Item (commerce_order_item)
- `field_ticket_holder` (entity reference to paragraphs)
- `field_attendee_data` (JSON)
- `field_target_event` (entity reference to event node)

### Commerce Store (commerce_store)
- `field_stripe_account_id` (text)
- `field_stripe_connected` (boolean)
- `field_stripe_charges_enabled` (boolean)
- `field_stripe_payouts_enabled` (boolean)
- `field_stripe_onboard_url` (text)
- `field_stripe_dashboard_url` (text)

### Paragraph Types
- `attendee_answer` — stores per-ticket attendee info
  - `field_first_name`
  - `field_last_name`
  - `field_email`
  - `field_phone`
  - `field_attendee_questions` (nested paragraphs)

---

## 10. Gaps and Unknowns

### Critical Gaps
1. **No custom checkout flow plugin** — using Commerce default (multi-step)
2. **No per-variation stock enforcement** — only event-level capacity
3. **No stock enforcement at Commerce layer** (cart add/refresh/order place)
4. **No vendor access control** for attendee data (no `hook_entity_access()`)
5. **Dual attendee storage** — paragraphs vs JSON (inconsistent)
6. **No donation checkout pane** found
7. **No fee transparency pane** found
8. **No legal/consent pane** found
9. **No buyer details pane** (email-first) found
10. **Vendor dashboard CSV export location unclear** (`myeventlane_vendor_dashboard` module not found)

### Unknowns (Require Clarification)
1. **Checkout flow configuration** — which flow is active? What steps/panes are configured?
2. **TicketMatrixForm** — user mentioned it, but only `TicketSelectionForm` found. Are they the same?
3. **Donation order item type** — what is the machine name? Does it exist?
4. **Commerce Stock module** — is it installed? If not, need to implement minimal stock table
5. **Vendor dashboard module** — exact module name and CSV export controller path
6. **Confirmation page** — where is it? Custom route or Commerce default?
7. **Receipt email** — which module sends it? Is it branded?
8. **Order item bundle types** — what are the machine names? (e.g., 'ticket', 'boost', 'donation')

---

## 11. File Paths Summary

### Checkout Panes
- `web/modules/custom/myeventlane_checkout_paragraph/src/Plugin/Commerce/CheckoutPane/TicketHolderParagraphPane.php`
- `web/modules/custom/myeventlane_checkout_paragraph/src/Plugin/Commerce/CheckoutPane/GroupedSummaryPane.php`
- `web/modules/custom/myeventlane_commerce/src/Plugin/Commerce/CheckoutPane/AttendeeInfoPerTicket.php`

### Services
- `web/modules/custom/myeventlane_capacity/src/Service/EventCapacityService.php`
- `web/modules/custom/myeventlane_commerce/src/Service/StripeConnectPaymentService.php`
- `web/modules/custom/myeventlane_rsvp/src/Service/CalendarButtonBuilder.php`

### Payment Gateway
- `web/modules/custom/myeventlane_commerce/src/Plugin/Commerce/PaymentGateway/StripeConnect.php`

### Forms
- `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`

### Event Subscribers
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/LockAttendeeOnOrderPlaced.php`
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/StripeConnectValidationSubscriber.php`

### Controllers
- `web/modules/custom/myeventlane_views/src/Controller/AttendeeCsvController.php`

---

## 12. Next Steps (Phase 1+)

### Questions to Answer Before Coding
1. What is the active checkout flow plugin ID and its step configuration?
2. What are the order item bundle machine names? (ticket, boost, donation, etc.)
3. Where is the vendor dashboard CSV export controller? (`myeventlane_vendor_dashboard` module path)
4. Is Commerce Stock module installed? If not, should we create a minimal stock table?
5. What is the donation order item bundle machine name?
6. Where is the confirmation page route/controller?
7. Which module sends receipt emails? Is it branded?

### Implementation Priorities
1. **Phase 1:** Create custom checkout flow plugin (`MelEventCheckoutFlow`)
2. **Phase 2:** Implement stock enforcement EventSubscriber
3. **Phase 2:** Harden attendee storage and access control
4. **Phase 3:** Theme updates for single-page feel
5. **Phase 4:** Confirmation page and email enhancements

---

**END OF PHASE 0 REPORT**

