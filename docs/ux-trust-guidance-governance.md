# UX Trust + Guidance Governance — MyEventLane

**Branch:** `feature/ux-trust-guidance-harmonisation`  
**Companion to:** `docs/ux-trust-guidance-audit.md`  
**Layered on:** `docs/product-language-governance.md`, `docs/product-language-inventory.md`, `docs/platform-canonical-surface-map.md`, `docs/platform-governance-audit.md`

This document records the **trust and guidance principles** the platform applies after the harmonisation pass, the **standards future copy must follow**, and **what intentionally stayed operational or hidden**. It is **prescriptive for new copy**; legacy strings are not bulk-rewritten outside owned surfaces.

---

## 1. Trust language principles

1. **Calm, credible, gender-neutral.** No urgency tactics, no emojis on financial moments, no fear-based "blockers".
2. **Verb-first CTAs**, one primary action per region: "Submit support request", "Create event", "Pay now".
3. **Plain English, Australian spelling.** Match the canonical product language: organiser, ticket holder, support, support request, Event Editor, Event Manager, Promote event.
4. **Tell the user what happens next.** Empty / error / waiting states must give a next step.
5. **Reuse existing systems.** No new shells, no parallel guidance frameworks, no new modal systems. Edit at the canonical source (`MelReadinessHelper`, `AccountLinksService`, vendor shell builder, etc.).
6. **Translation-safe.** All visible strings must use `t()` / `TranslatableMarkup`. Plain literal strings in PHP are forbidden for visible UI.
7. **Cache-safe.** No new render contexts; copy edits never widen cacheability scope.

---

## 2. Organiser guidance standards

- Use **organiser** for all customer- and organiser-visible language. Internal entity / permission / route names remain "vendor".
- Disabled sidebar items must distinguish **event-scoped disabled** ("Open this from an event") from **future / placeholder** ("Available soon"). Done in `templates/includes/sidebar.html.twig` using `item.key`.
- Onboarding copy avoids "blockers" / "fix early" framing; prefer "What to do next" / "Small setup steps now help publishing and payouts go smoothly."
- Stripe wording: never expose the raw `status` enum to organisers; show a calm next step and link to the Stripe-hosted dashboard.
- Save feedback shows draft state when not yet published ("Saved as draft.") so organisers know the listing is not public.

---

## 3. Checkout trust standards

- Buyer details copy is short and reassuring ("We'll email your order confirmation and tickets here.", "Optional — we'll only use this if we need to reach you about this booking.").
- Ticket-holder pane uses **canonical "ticket holder"** in headings and SR-only status; intro line uses "Questions from the organiser" when organiser questions exist.
- Express checkout copy says "Pay faster with Apple Pay, Google Pay, or Link when your browser offers them."
- "Express checkout isn't available — this event needs a few details for each ticket first." replaces older mixed-vocabulary copy.
- **Do not** modify legal consent wording, validation rules, GST / tax-invoice phrasing, refund-window timelines, or Stripe security disclosures. These are owned by legal / finance / processor compliance.

---

## 4. Support guidance standards

- The customer escalation portal uses **support / support request** in all copy. Internal route, entity, and permission names keep "escalation".
- Issue-type select labels read in plain English; "Vendor Dispute" → "Issue with an organiser", "Customer Complaint" → "Other concern" (machine values unchanged).
- Submission confirmation: "Your support request has been received. We'll reply by email — please keep an eye on your inbox." No SLA hours quoted.
- Customer-facing escalation status banner remains SLA-free, badge-free, and timestamp-free per `CustomerEscalationController::buildCustomerStatusBanner`.
- Escalation level numbers, breach flags, internal queue state must never appear on customer- or organiser-visible surfaces.

---

## 5. Empty state standards

- Replace Drupal-default `empty: { }` with a `text_custom` area whenever the surface is reached by customers or organisers.
- Empty markup includes a `role="status"` container, an `<h2>` heading, and a short body sentence with a next-step verb.
- Wording examples used in this pass:
  - "No events yet — create an event to add details, RSVPs, or tickets."
  - "No RSVPs yet — they'll appear here as people respond."
  - "No articles match yet — try the search at the top of the page, or browse the Help Centre for more topics."
- **Do not** edit admin Views (`mel_admin_review_queue`, `mel_help_*_admin`, `watchdog`, etc.) — those serve staff and may carry operational meaning.

---

## 6. Success state standards

- Booking complete copy reassures the user that tickets and a receipt are coming by email; mentions checking spam if applicable.
- Refund timing copy preserves the legally-aligned "2–5 business days" timeline; tone remains calm and informative.
- Email subjects use plain headlines without emoji on financial documents (receipts, refunds, tax invoices).

---

## 7. Error state standards

- Error messages name the user-visible failure ("Check-in couldn't be completed.") and offer a recovery path ("Try again or use manual check-in.").
- **Never** include raw stack traces, error codes, or third-party error strings in customer- or organiser-visible messages. Log internally; surface a friendly reference (e.g. order number).
- Refund-failed buyer email exposes the **order number** as the reference, not the raw `error_message`.
- Stripe Connect controller flash messages now say "We couldn't find your organiser profile.", "Your organiser account isn't ready yet.", "Something didn't match with your Stripe account." — never raw status enums.

---

## 8. AI guidance standards

- Customer- and organiser-facing fallback copy uses the same voice ("I'm not sure from the Help Centre. For a personal answer, open a support request: …"). Robotic phrasings ("I cannot confirm…") are removed.
- The vendor AI prompt fallback (`config/sync/myeventlane_ai.prompt.vendor_ai_answer_v1.yml`) matches the same voice.
- **Do not** widen retrieval scope, change audience filtering, change grounding rules, or remove the JSON schema constraints in `HelpAssistantService::buildPromptDefinition`. Wording inside `system` instructions stays only when it does not weaken the safety rules.
- The Help Assistant must not display raw model `confidence` to users without translation; the existing query form is acceptable today as a developer/debug surface and may be replaced by user-friendly mapped phrases in a later pass.
- Staff prompts (`escalation_*`, `playbook_*`) are out of scope for this pass.

---

## 9. Event publishing guidance rules

- Publish CTA card has a calm body line: "After publishing, your public page can show RSVPs or tickets you've turned on."
- Wizard saves communicate draft state ("Saved as draft.") when the underlying node remains unpublished.
- Moderation hold copy reads "Publishing is paused while our team finishes reviewing this listing." — does not expose the word "moderation".
- Connect Stripe gate copy reads "Connect Stripe to accept card payments for paid tickets." — non-blame framing.

---

## 10. Day-of-event guidance rules

- Door scanner placeholder: "Paste ticket code or QR text" (replaces "payload").
- Check-in failure copy: "Check-in couldn't be completed. Try again or use manual check-in."
- **Do not** alter scanning logic, ticket validation, or QR security. Copy is presentational only.

---

## 11. Accessibility guidance rules

- Every visible empty state markup includes `role="status"` so assistive tech is informed when content arrives or is absent.
- Disabled sidebar links keep `aria-disabled="true"` and a `title` attribute that is now context-aware (event-scoped vs future).
- The aria-label on the vendor shell sidebar is now "Organiser navigation" matching the inner nav and the canonical chrome.
- Visually-hidden status messages (e.g. ticket-holder paragraph SR text) are shortened but always preserved — never removed.
- Decorative icons keep `aria-hidden="true"`; text content carries the meaning.
- All translated copy continues to flow through `t()` / `TranslatableMarkup`.

---

## 12. Future UX copy governance

- **No mass search/replace.** Edit copy at the surface that owns it (controller, form, plugin, theme builder, Twig template).
- **No new copy services.** When wording recurs (e.g. organiser dashboard slots), edit `MelReadinessHelper` slots — do not duplicate strings into Twig.
- **Do not rename** modules, services, routes, permissions, or entities for copy reasons alone.
- **Preserve access checks.** Copy lives in templates and forms; routing and `_custom_access` stay authoritative.
- **Document drift.** When adding a new customer- or organiser-facing string, link the surface back to one of: `AccountLinksService::buildNavigationItems`, `_myeventlane_vendor_theme_build_full_vendor_shell_nav_items`, `MelReadinessHelper`, `CustomerEscalationController`.
- **Translation-safe.** Visible strings always use `t()` / `TranslatableMarkup`.
- **Test by audience.** New customer copy must be reviewed against this document plus `docs/product-language-governance.md` before merging.

---

## 13. What changed in this pass

- **Empty states**: `views.view.mel_vendor_events.yml`, `views.view.myeventlane_vendor_rsvps.yml`, `views.view.mel_help_attendee_help.yml`, `views.view.mel_help_organiser_help.yml` — added calm `text_custom` empty areas with `role="status"` markup.
- **Checkout**: `BuyerDetailsPane.php` (email + mobile descriptions), `TicketHolderParagraphPane.php` (intro title + body, SR status, fallback question label), `myeventlane_checkout_flow.module` (express checkout intro, multi-ticket notice, unavailable notice), `MelReadinessHelper.php` (ticket-holder canonical wording, Stripe label, moderation hold sentence).
- **Receipts / messaging**: `myeventlane_messaging.template.order_receipt.yml` (de-emoji subject, "View your tickets", donation thank-you, footer reply line), `myeventlane_messaging.template.refund_failed_buyer.yml` (order-number reference instead of raw error), `myeventlane_messaging.template.cart_abandoned.yml` (calmer subject + body).
- **Organiser shell**: `myeventlane_vendor_theme/templates/layout/page.html.twig` (aria-label), `templates/dashboard/dashboard.html.twig` (eyebrow, fallback H1, attention panel), `templates/includes/sidebar.html.twig` (event-scoped disabled differentiation).
- **Stripe Connect**: `StripeConnectController.php` flash messages (organiser wording, no raw `@status` leak).
- **Event Editor**: `EventStudioBaseForm.php` (draft-aware save message), `EventStudioPublishForm.php` (publish helper line).
- **Ticket holders**: `VendorAttendeeController.php` (page title, group headings, empty state).
- **Support**: `CustomerEscalationForm.php` (issue-type display labels, submission success copy), `CustomerEscalationController.php::add` (intro).
- **AI**: `HelpAssistantService.php` (disabled / AI-disabled / fallback / escalation strings), `myeventlane_ai.prompt.vendor_ai_answer_v1.yml` (fallback wording aligned).
- **Day-of-event**: `TicketScanController.php` (manual entry placeholder), `TicketCheckinService.php` (failure message).
- **Vendor events bulk form**: `VendorEventsBulkActionsForm.php` (empty CTA copy).

All changes are presentation-only. No routes, services, permissions, schemas, payment flows, AI grounding rules, or staff isolation contracts were modified.

---

## 14. What intentionally stayed operational

- All machine names: modules (`myeventlane_vendor`, `myeventlane_escalations*`, `myeventlane_boost`, …), permissions, routes (`/vendor/*`, `/my/support/escalations/*`), entity types, fields, config keys, commerce SKUs.
- Stripe technical wording where attribution / compliance demands it.
- Tax invoice / GST labels and refund-window timing — finance / legal owns these.
- Customer-facing escalation status banner copy — already SLA-free per the file's own contract.
- Help Assistant grounding rules in the system prompt and the JSON schema enforcement.
- Help Centre attendee retention per `docs/product-language-governance.md`.

---

## 15. What must never be exposed publicly

- Staff playbook content, internal SLA breach messaging, raw escalation level numbers, raw breach flags.
- Internal route names, module IDs, machine names in user-visible copy.
- Raw Stripe API status enums, `@status` strings, raw error messages.
- Refund failure raw `error_message` — use order-number reference and log the rest.
- Drupal admin queue language, watchdog messages, advanced queue internals.
- Any unsanctioned PII in support, AI, or transactional copy.
