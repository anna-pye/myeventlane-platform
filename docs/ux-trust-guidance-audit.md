# UX Trust + Guidance Audit — MyEventLane

**Branch:** `feature/ux-trust-guidance-harmonisation`  
**Builds on:**
- `docs/platform-governance-audit.md`
- `docs/platform-canonical-surface-map.md`
- `docs/product-language-governance.md`
- `docs/product-language-inventory.md`

**Method:** Direct inspection of customer- and organiser-facing controllers, forms, Twig templates, Views YAML, AI prompt config, and transactional email templates. No assumptions about routes, permissions, services, or schemas were made — only presentation strings were considered for change.

**Goal:** Identify presentation/copy improvements that increase trust and reduce support burden, **without** logic, route, access, payment, AI grounding, or staff isolation changes.

---

## Scope summary

| Area | Real surfaces inspected |
|------|------------------------|
| Booking, cart, checkout, RSVP, receipts | `myeventlane_commerce`, `myeventlane_checkout_flow`, `myeventlane_checkout_paragraph`, `myeventlane_rsvp`, `myeventlane_core/MelReadinessHelper`, theme `commerce/*`, transactional email config |
| Organiser onboarding, Stripe, publishing, attendees, check-in | `myeventlane_vendor`, `myeventlane_vendor_settings`, `myeventlane_vendor_theme`, `myeventlane_event_studio`, `myeventlane_event`, `myeventlane_event_attendees`, `myeventlane_boost`, `myeventlane_tickets` |
| Support and Help | `myeventlane_escalations_portal`, `myeventlane_help_assistant`, `myeventlane_help_centre` (presentation only), `config/sync/myeventlane_ai.prompt.help_centre_answer_v1.yml`, `config/sync/myeventlane_ai.prompt.vendor_ai_answer_v1.yml` |
| Empty states (Views + Twig) | `config/sync/views.view.*.yml` (customer + organiser), theme empty markup |

Anything explicitly **staff-only** (PCC, Drupal admin, escalation operations, staff prompts) is out of scope and listed in the **DO NOT TOUCH** section at the bottom.

---

## Section A — Booking, RSVP, checkout, receipts

| Surface | Existing guidance (verbatim or near-verbatim) | Risk | Improvement (presentation only) |
|---------|-----------------------------------------------|------|--------------------------------|
| Sold-out alert (`web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`) | "This event is sold out." | Abrupt; no next step. | Calmer single sentence with clear next step (e.g. join the waitlist from the event page if available). |
| Empty inventory same form | "No tickets available for this event." | Cold; ambiguous between sold out and not yet on sale. | "There are no tickets to buy here right now." with optional contextual hint. |
| Access code description same form | "If you have an organiser code, enter it here to reveal hidden or invite-only tickets for this browser session." | "Browser session" is technical. | Plainer wording; mention how the code is remembered without exposing storage detail. |
| Form load error in `myeventlane-event-book.html.twig` | "We couldn't load the booking form. Please refresh the page." | OK but no support fallback. | Add fallback: "If it keeps happening, contact Support." |
| Buyer details email field description (`myeventlane_checkout_flow/src/Plugin/Commerce/CheckoutPane/BuyerDetailsPane.php`) | "We'll send your order confirmation and tickets to this address." | OK; can be tightened. | "We'll email your order confirmation and tickets here." |
| Express checkout block intro (`myeventlane_checkout_flow.module`) | "Use Stripe Link, Apple Pay, or Google Pay when available for this device." | "This device" is technical. | "Pay faster with Apple Pay, Google Pay, or Link when your browser offers them." |
| Express checkout unavailable copy | "Fast checkout isn't available for this event because additional attendee details are required." | Uses "attendee" (legacy in customer voice). | "Express checkout isn't available — this event needs a few details for each ticket first." (uses "ticket holder" / neutral voice per governance doc.) |
| Ticket holder paragraph pane intro (`myeventlane_checkout_paragraph/src/Plugin/Commerce/CheckoutPane/TicketHolderParagraphPane.php`) | "Attendee questions" + "Add per-ticket details and answer organiser questions before continuing." | Mixes "attendee" (customer-facing) with canonical "ticket holder" used elsewhere in the same flow. | "Questions from the organiser" + "Answer these for each ticket before you pay." |
| Ticket holder pane SR status | "This ticket holder entry is incomplete. Required fields or organiser questions still need answers." | Long for screen readers. | "Incomplete — required fields or organiser questions are missing." |
| Readiness checkout warning (`myeventlane_core/src/MelReadinessHelper.php`) | "Attendee details are required before payment." | Terminology drift; canonical is "ticket holder". | "Ticket holder details are required before payment." |
| Readiness Step 2 intro | Mixes "ticket holders" eyebrow with "Add each attendee once..." body. | Same drift in one block. | Use "ticket holder" consistently. |
| Cart empty slot copy | "Your cart is empty" + paragraph about items being queued and "may clear if pricing or timing changes". | Honest but worry-inducing in the most common state (just empty). | Calmer: "Your cart is empty" + "Pick an event and add tickets when you're ready." |
| Payment-unavailable slots | "Payments are paused right now" / "trusted payment route" | Slightly dramatic. | "Checkout is temporarily unavailable" + "Your card has not been charged." |
| RSVP cancel confirm form (`myeventlane_rsvp/src/Form/RsvpCancelConfirmForm.php`) | "Are you sure you want to cancel this RSVP?" / "Your RSVP has been cancelled." | **Untranslated** (no `t()`); abrupt. | Wrap in `t()`; soften and offer recovery: "Cancel your RSVP for this event?" / "Your RSVP has been cancelled. You can RSVP again from the event page if spots are still available." |
| Order receipt email subject (`config/sync/myeventlane_messaging.template.order_receipt.yml`) | H1: "🎉 Thank you for your order!" | Emoji in financial document; mismatched gravity. | Plain "Thank you for your order." |
| Order receipt CTA | "View My Tickets" | Title-case inconsistency vs other emails. | "View your tickets" |
| Refund failed buyer email (`config/sync/myeventlane_messaging.template.refund_failed_buyer.yml`) | "Reference: {{ error_message }}" | **May expose internal error strings** to buyers. | Provide a friendly reference (e.g. order number) and keep raw `error_message` in logs only. |
| Cart abandonment subject (`config/sync/myeventlane_messaging.template.cart_abandoned.yml`) | "Forgot something? Your tickets are waiting" | Light guilt framing. | "Still interested? Your cart is waiting" |
| Booking complete page subtitle (Readiness `completion_summary_*`) | "Your tickets and receipt are on their way to your inbox." | Strong, but no "if not received" guidance. | Optional add: "If you don't see it in a few minutes, check your spam folder." |
| Refund-ineligible label (`web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-order-detail.html.twig`) | "Support note:" prefix | "Support note" reads as internal/staff. | "Refund note:" or "Why refunds aren't available:" |
| Customer ticket guests label same template | "Guests:" | Mixed with canonical "ticket holders". | Optional align with ticket holder language. |

---

## Section B — Organiser onboarding, Stripe, settings, publishing

| Surface | Existing guidance | Risk | Improvement |
|---------|-------------------|------|-------------|
| Vendor shell `<aside aria-label="Vendor navigation">` (`myeventlane_vendor_theme/templates/layout/page.html.twig`) | "Vendor navigation" | Drift vs canonical "Organiser" chrome. | "Organiser navigation" |
| Disabled top-level nav badge + title (`myeventlane_vendor_theme/templates/includes/sidebar.html.twig`) | title `Coming soon`; badge `Soon` | **Misleading** for items that are disabled because no event context exists (Orders, Check-in) — they look like dead features. | Differentiate: event-scoped disabled → "Open this from an event" / "Choose an event first"; reserve "Coming soon" for genuine placeholders. |
| Vendor dashboard hero fallback (`myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig`) | Eyebrow "Vendor Console", H1 fallback "Welcome to your Vendor Console" | "Vendor" branding on canonical Organiser surface (per governance). | "Organiser console" / "Welcome to your organiser dashboard" |
| Dashboard attention panel | "What needs attention now" / "Fix blockers early so launches stay smooth." | "Blockers" feels alarmist on first-time use. | "What to do next" / "Small setup steps now help publishing and payouts go smoothly." |
| Stripe readiness label (`myeventlane_core/src/MelReadinessHelper.php`) | "Stripe payouts" / "Stripe connection is required before you can charge for tickets." | Slightly terse and "required" can feel abrupt. | "Stripe account" or "Payments (Stripe)"; "Connect Stripe to accept card payments for paid tickets." |
| Moderation hold line (Readiness) | "Publishing is paused until moderation clears this event." | Exposes internal "moderation" word. | "Publishing is paused while our team finishes reviewing this listing." |
| Events index empty inline form (`myeventlane_vendor/src/Form/VendorEventsBulkActionsForm.php`) | "No events yet" / "Create your first event and start selling tickets." | Sales-heavy for RSVP-first organisers. | "Create an event to add details, RSVPs, or tickets." |
| Stripe Connect controller flash messages (`myeventlane_vendor/src/Controller/StripeConnectController.php`) | "No vendor profile found for your account." / "Your vendor store is not ready yet…" / raw env-debug Stripe key paragraph / "Stripe account status: @status." | Uses "vendor" (legacy) in **customer-facing** error; raw status leaks ops detail. | Use "organiser" or "your account"; replace raw status with plain-language next step + Support link. Keep machine names in logs only. |
| Vendor settings descriptions (`myeventlane_vendor_settings/src/Form/VendorSettingsForm.php`) | Multiple references to "public vendor page", "vendor profile", journey chip "Boost". | Terminology drift on surface labelled "Organiser settings". | Replace visible "vendor" with "organiser/profile"; chip "Promote event". |
| Vendor help panel suggestions (`myeventlane_vendor/src/Plugin/Block/VendorHelpPanelBlock.php`) | "...help with **escalation** questions in the vendor portal." | "Escalation" is staff-internal per governance. | "Support-related questions" / "organiser portal". |
| Event Editor first-event banner (`myeventlane_event_studio/templates/mel-event-studio.html.twig`) | "✨ You're creating your first event" | OK; emoji optional. | Calmer plain text or keep emoji; not a security issue. |
| Event Editor Operational Tone panel (same template) | Title "Operational tone"; empty fallback "Standard **vendor** workspace tone."; lines may include "Escalation-aware messaging is active." | Exposes staff vocabulary on organiser UI. | Rename to "Editing mode" / "Editing guidance"; fallback "Standard editing guidance."; rewrite policy hints in `EventStudioGovernanceBuilder` to user-facing wording. |
| Event Studio publish form (`myeventlane_event_studio/src/Form/EventStudioPublishForm.php`) | Helper missing on what publishing exposes. | OK but could reduce uncertainty. | Add short helper under publish: "After publishing, your public page can show RSVPs or tickets you've turned on." |
| Event Studio gating + ticket helper (`myeventlane_event_studio/src/Form/EventStudioForm.php`) | "Default off. Simpler events get more bookings — only ask what you truly need." / "Pick from venues under your **organizer** account." | Slightly fear-based; US spelling. | Calmer: "Fewer questions usually mean faster checkout."; AU spelling "organiser". |
| Save feedback (`EventStudioBaseForm.php`) | "Saved." | Doesn't clarify draft vs published. | "Saved as draft." when unpublished. |
| Wizard publish form (`myeventlane_event/src/Form/EventWizardPublishForm.php`) | "✨ Publish Event"; long contribution tangent post-publish. | Tone inconsistent with calm money flows. | "Publish event"; tighten contribution sentence. |
| Boost / Promote event surfaces (`myeventlane_boost/**`, `EventIntelligenceService.php`, theme `event/analytics.html.twig`, `event/mel-event-overview-page.html.twig`, `components/mel-boost-status-panel.html.twig`) | Multiple visible mentions of "Boost" / "boosted". | Drift vs canonical "Promote event" (governance doc) — internal module names stay, but **visible labels** should harmonise. | Replace visible labels with "Promote event"; aria-label "Promotion status"; CTA "Promote my event". |
| Vendor support panel (`myeventlane_vendor_theme/templates/mel-support-panel.html.twig`) | Emoji-heavy headings ("👀", "⚡", "🔥"); "Boost" branding; performance claims. | Tone clashes with "calm, credible for money"; product-name drift. | Plain titles; "Promote event"; soften urgency claims. |
| Payouts empty (`myeventlane_vendor_theme/templates/vendor/payouts.html.twig`) | "No transactions yet" | OK; could add expectation. | Add: "Transactions appear after your first paid orders." |
| Ticket holders page title (`myeventlane_event_attendees/src/Controller/VendorAttendeeController.php`) | Title "Attendees for @event"; group headings "RSVP Attendees" / "Ticket Attendees" | Sidebar nav says "Ticket holders" but page says "Attendees" — inconsistent. | "Ticket holders for @event"; group headings "RSVP responses" / "Ticket holders". |
| Door scan UI (`myeventlane_tickets/src/Controller/TicketScanController.php`) | "Paste ticket code or payload"; "Queued: 0" | "Payload" is technical. | "Paste ticket code or QR text" |
| Check-in failure (`myeventlane_tickets/src/Service/TicketCheckinService.php`) | "A server error occurred while checking in this ticket." | Sounds severe. | "Check-in couldn't be completed. Try again or use manual check-in." |

---

## Section C — Support and AI guidance

| Surface | Existing guidance | Risk | Improvement |
|---------|-------------------|------|-------------|
| Customer support list intro (`myeventlane_escalations_portal/src/Controller/CustomerEscalationController.php::list`) | Title "Support"; intro "View and manage your support requests." | OK; could reassure new users with no requests yet. | Optional: "View your past support requests and start a new one any time." |
| Customer support empty state same controller | "You have no support requests yet. When you contact us, your conversations will appear here." | Already calm and aligned. | Keep. |
| Customer support add page intro (`add()`) | "Describe your issue and we'll get back to you as soon as possible." | "as soon as possible" is vague but OK; pairs well with reassurance line. | Optional shorten: "Tell us what's going on — we'll reply by email." |
| Customer issue type select (`CustomerEscalationForm.php::buildForm`) | Includes options "Vendor Dispute" and "Customer Complaint". | "**Vendor Dispute**" exposes legacy vocabulary in customer-facing select per governance doc; "Customer Complaint" reads odd to the customer ("Customer" = themselves). | "Issue with an organiser" instead of "Vendor Dispute"; drop "Customer Complaint" or rename to "Other concern" (does **not** rename machine values — display labels only). |
| Customer escalation submit success same form | "Your support request has been submitted. We will get back to you as soon as possible." | OK; could be slightly warmer. | "Your support request has been received. We'll reply by email — please keep an eye on your inbox." |
| Help Assistant fallback (`myeventlane_help_assistant/src/Service/HelpAssistantService.php`) | "Sorry, the Help Assistant is unavailable right now." (disabled) / "AI help is currently unavailable. You can still browse help articles below." (AI disabled) | Disabled message has no next step. | "The Help Assistant is unavailable right now. You can browse the Help Centre or contact Support." |
| Help Assistant low-confidence fallback (`buildFallbackPayload`) | "We couldn't find a perfect answer in the Help Centre yet. Try rephrasing your question or open one of the guides below when they appear." | Long; second clause assumes guides will appear. | "We couldn't find a clear answer in the Help Centre. Try rephrasing your question, or browse the guides below." |
| Help Assistant escalation fallback text (`buildEscalationText`) | "I cannot confirm this from the Help Centre. For a personalised answer, open a support request at @url." | Robotic "I cannot"; "personalised" is fine. | "I'm not sure from the Help Centre. For a personal answer, open a support request: @url." |
| Help Assistant header card (`templates/help-assistant.html.twig`) | "I'll answer using MyEventLane help content and guide you to the next step." | OK; aligned. | Keep. |
| Help Assistant query form intro (`HelpAssistantQueryForm.php`) | Confidence printout `Confidence: @confidence`. | Exposes raw model confidence label to user. | Either hide confidence or translate to plain words ("Strong match" / "Possible match" / "I'm not sure" mapped from low/medium/high). |
| Help Assistant query placeholder (`templates/help-assistant.html.twig`) | "Ask anything about your event, tickets, or payouts…" | Already organiser-leaning; OK. | Keep. |
| Help Assistant "Still stuck?" card | "Still stuck?" / "Our support team can help you directly." | OK. | Keep. |
| `myeventlane_ai.prompt.help_centre_answer_v1.yml` system instructions | Instructs Australian English, calm tone, fallback constant. | Aligned with governance. | Keep. |
| `myeventlane_ai.prompt.vendor_ai_answer_v1.yml` system instructions | "You help event organisers understand policies and next steps." / fallback "I can't confirm from guidance. Please contact MyEventLane support for details." | Aligned; uses "support" canonical word. | Keep wording. Consider matching fallback voice to Help Assistant: "I'm not sure from the guidance. Please contact MyEventLane support for details." |

**Customer-facing escalation status banner labels** (`CustomerEscalationController::buildCustomerStatusBanner`) are already calm and SLA-free per the file's own contract — keep as-is.

---

## Section D — Empty states (Views, Twig, controllers)

| Surface | Existing guidance | Risk | Improvement |
|---------|-------------------|------|-------------|
| `config/sync/views.view.mel_vendor_events.yml` | `empty: { }` (Drupal default) | Cold default on the **canonical organiser events list** (`/vendor/events`). | Add a Views `text_custom` empty area with "No events yet — create your first event to get started." |
| `config/sync/views.view.myeventlane_vendor_rsvps.yml` | No empty handler at all. | Cold default on organiser RSVP list (`dashboard/rsvps`). | Add empty area: "No RSVPs yet — they'll appear here as people respond." |
| `config/sync/views.view.commerce_subscription_orders_customer.yml` | `empty: { }` (line 685) | Cold default on a customer-facing subscription orders list. | Add empty area: "No subscription orders yet." (only if surface is reachable to customers; verify before edit). |
| `config/sync/views.view.mel_help_attendee_help.yml` | No empty handler. | Cold default on Help Centre attendee landing if filters return nothing. | Add empty area: "No help articles match yet — try the search above or browse the Help Centre." |
| `config/sync/views.view.mel_help_organiser_help.yml` | No empty handler. | Same as above for organiser help. | Same calm fallback. |
| `config/sync/views.view.mel_help_faq.yml` | No empty handler. | Same. | Same. |
| `config/sync/views.view.mel_saved_events.yml` | Already has calm empty markup ("No saved events yet"). | OK. | Keep. |
| `config/sync/views.view.mel_help_zero_results.yml` | `empty: { }` | Admin view (target audience = staff). | **Do not touch.** |

**Twig and controller empty/disabled copy already covered above** in Sections A–C.

---

## Section E — Day-of-event confidence

| Surface | Existing guidance | Risk | Improvement |
|---------|-------------------|------|-------------|
| Door scanner empty/instructions (`myeventlane_tickets/src/Controller/TicketScanController.php`) | "Ready to scan"; "Paste ticket code or payload"; "Queued: 0" | "Payload" technical. | "Paste ticket code or QR text" |
| Check-in failure path (`myeventlane_tickets/src/Service/TicketCheckinService.php`) | "A server error occurred while checking in this ticket." | Severe wording at door. | "Check-in couldn't be completed. Try again or use manual check-in." |
| Mel-door-checkin / vendor-checkin templates | "Loading..." inline copy | Minimal but not branded. | Optional theme-level label "Working..." / "Checking…" — low priority for this pass. |

QR security, ticket validation logic, scanning logic — **NO CHANGES**.

---

## Section F — Accessibility & trust review

The pass should preserve every existing aria-label and screen-reader-only string. Specific points to keep in mind during edits:

- `CustomerEscalationController::buildCustomerStatusBanner` already uses an `aria-hidden="true"` icon span with a textual message — preserve this when changing wording.
- `myeventlane_help_assistant` form has `<label class="visually-hidden" for="...">Your question</label>` — keep.
- `myeventlane_vendor_theme/templates/includes/sidebar.html.twig` — disabled-link `title` attributes provide context to assistive tech; harmonise wording but **do not remove the attribute**.
- `myeventlane_checkout_paragraph` ticket-holder paragraph SR status string — shorten but keep meaning.
- `commerce-checkout-form.html.twig` payment-trust icon row — ensure visible decorative icons have a meaningful `visually-hidden` companion or `aria-label`.

---

## DO NOT TOUCH (must be preserved exactly)

- **Legal copy**: `LegalConsentPane` checkbox text, links, `LegalSettingsService::getCollectionNoticeCheckout()`, terms / privacy / refund link labels, the binding agreement validation message.
- **Tax invoice / GST labelling**: `mel-tax-invoice-section.html.twig`, receipt + invoice templates, "This document is a tax invoice for GST purposes.", "All prices include GST" — finance/legal owns these.
- **Refund-window timelines** in buyer refund emails (e.g. "2–5 business days") — change only with finance/legal alignment.
- **Stripe attribution and security disclosures** required by processor / compliance (e.g. "Secure payment via Stripe", Stripe Link / Apple Pay / Google Pay phrasing where mandated).
- **Conditional refund availability hints** that honestly reflect organiser policy (`trust_footer_refund_hint`, conditional trust chips). Do not imply refunds where none exist.
- **Anti-abuse / rate-limit messages** that double as security signals (e.g. "Too many requests", invalid waitlist link copy). Edit for tone only when the system meaning stays intact.
- **Customer-facing escalation status banner** wording in `CustomerEscalationController::buildCustomerStatusBanner` — already SLA-free and approved.
- **Help Assistant grounding rules** in `HelpAssistantService::buildPromptDefinition` (system message instructions: "Only answer using the provided help content", "Do not invent…", JSON schema rules). **Wording governs the model's behaviour, not just presentation.**
- **AI prompt YAML for staff prompts** — `escalation_*`, `playbook_*` config files. Out of scope.
- **Permission machine names**, route machine names, entity / bundle / field machine names, module IDs, config keys (e.g. `myeventlane_vendor`, `escalation`, `boost_upgrade`).
- **Admin Views and PCC labels**: `mel_admin_review_queue.yml`, `mel_help_feedback_admin.yml`, `mel_help_zero_results.yml`, `mel_help_analytics_admin.yml`, `mel_help_internal_procedures.yml`, watchdog, advanced queue, commerce subscription **admin** orders.
- **Staff playbooks**, internal SLA breach copy, escalation level numbers, raw `error_message` from refund failures, raw Stripe `@status` strings — must stay out of customer / organiser surfaces. Where today's copy leaks them, replacement should anonymise (not remove the safety of having an internal log).
- **Drupal core templates** we do not override.
- **Help Centre attendee retention**: per `docs/product-language-governance.md`, Help articles may keep "Attendee" sections — do not bulk-edit.
- **Form validation rules** and submit handler logic — copy of validation **messages** may be softened, but logic and conditions stay.

---

## Output — what changes in this pass

The fixes below are bounded to the items above and split per phase:

- **Phase 3** — Empty states: `views.view.mel_vendor_events.yml`, `views.view.myeventlane_vendor_rsvps.yml`, plus three Help Centre audience views; calm empty area markup, no logic.
- **Phase 4** — Checkout trust: `BuyerDetailsPane.php` description, `TicketHolderParagraphPane.php` intro + SR status, `myeventlane_checkout_flow.module` express-checkout copy, `MelReadinessHelper.php` strings flagged in Section A, `myeventlane_messaging.template.order_receipt.yml` subject + CTA, `myeventlane_messaging.template.refund_failed_buyer.yml` reference field, `myeventlane_messaging.template.cart_abandoned.yml` subject.
- **Phase 5** — Organiser onboarding: `myeventlane_vendor_theme/page.html.twig` aria-label, `dashboard.html.twig` hero fallback + attention panel, `MelReadinessHelper.php` Stripe + moderation lines, `VendorEventsBulkActionsForm.php` empty CTA, `StripeConnectController.php` flash messages, `VendorSettingsForm.php` descriptions, `VendorHelpPanelBlock.php` suggestion copy, `EventStudioForm.php`/`EventStudioBaseForm.php` save + helper text, `EventStudioGovernanceBuilder.php` policy hint wording, `VendorAttendeeController.php` page title + group headings.
- **Phase 6** — Support confidence: `CustomerEscalationForm.php` issue-type display labels + submit success, `HelpAssistantService.php` fallback strings, `vendor_ai_answer_v1.yml` fallback voice (wording only).
- **Phase 7** — Publishing guidance: `EventStudioPublishForm.php` helper sentence, `EventStudioBaseForm.php` "Saved as draft" wording.
- **Phase 8** — Day-of-event: `TicketScanController.php` field label, `TicketCheckinService.php` failure copy.
- **Phase 9** — AI consistency: covered together with Phase 6.
- **Phase 10** — Accessibility: validate aria/SR strings still present after each edit; no removals.
- **Phase 12** — Governance doc: `docs/ux-trust-guidance-governance.md`.

Total touched surfaces are presentational and translation-safe (`t()` / `TranslatableMarkup`) and cache-safe (no new render contexts).
