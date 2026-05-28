# MEL Product Reset Phase 1 Source of Truth

## Purpose

This document preserves the product reasoning, market lessons, implementation process, and non-negotiable rules for:

**MEL Product Reset Phase 1: Discovery, Event Studio, and Booking Confidence**

This exists so future work does not drift, duplicate logic, or turn MEL back into a Drupal-feeling admin product.

## Product Position

MyEventLane should become:

**A community-first event platform for simple RSVPs, trusted local ticketing, and low-friction organiser tools.**

MEL is not trying to copy Eventbrite, Humanitix, Luma, DICE, Ticket Tailor, or TryBooking.

MEL should learn from the market, then apply those lessons through its own Drupal 11, Commerce, Stripe Connect, RSVP, ticketing, vendor, and community-first architecture.

## Market Lessons

### Humanitix

Humanitix shows the value of clear trust positioning, free-event clarity, low paid-ticket fees, charity/NFP messaging, and a visible ethical reason to choose the platform.

Relevant lessons for MEL:

- Make free RSVP events feel genuinely supported.
- Explain fees clearly.
- Show why MEL exists.
- Make community trust visible before checkout.
- Keep NFP/community organisers in mind.

### Ticket Tailor

Ticket Tailor shows the value of simple setup, simple pricing, free-event support, donations, merchandise/add-ons, and check-in confidence.

Relevant lessons for MEL:

- Ticket setup must feel simple.
- Donations and add-ons should feel connected, not bolted on.
- Check-in and QR tools should be easy to understand.
- Organisers should feel operationally ready.

### Luma

Luma shows the value of fast event creation, beautiful event pages, invites, reminders, guest confidence, and low-friction RSVP/ticket flows.

Relevant lessons for MEL:

- Event Studio should feel like a guided creator, not a Drupal form.
- Event pages should feel polished and shareable.
- The organiser should know what happens next.
- The attendee should know what they will receive.

### DICE

DICE shows the value of mobile-first ticket confidence, QR tickets, waitlists, secure ticket handling, and clear sold-out demand signals.

Relevant lessons for MEL:

- Mobile booking must feel confident.
- QR tickets must feel reliable.
- Sold-out, waitlist, and unavailable states must be clear.
- The ticket holder experience matters as much as the organiser experience.

### Eventbrite

Eventbrite shows the value of discovery, marketing tools, organiser reporting, and marketplace confidence.

Relevant lessons for MEL:

- Discovery must be stronger.
- Search, categories, date filters, and location awareness matter.
- Organisers need simple promotion tools.
- Dashboards should show useful action, not just data.

## MEL Style Direction

MEL must follow the v2 style guide:

- Calm
- Welcoming
- Safe
- Modern
- Community-first
- Lightweight
- Credible enough to handle money

Design rules:

- Mobile-first from 390px upward.
- One obvious primary action per screen.
- Trust before flair.
- Soft, not weak.
- Fewer component variations.
- No default Drupal feeling.
- No generic SaaS admin feeling.
- No cluttered ticketing marketplace feeling.

Core visual tokens:

- Page background: `#FFF9F5`
- Surface: `#FFFFFF`
- Soft surface: `#FDF1EC`
- Primary coral: `#F26D5B`
- Primary hover: `#E55C49`
- Accent lavender: `#7C83FD`
- Text primary: `#24303A`
- Border soft: `#E9E3DE`
- Buttons and inputs: `12px` radius
- Cards: `16px` radius
- Feature blocks: `24px` radius
- Minimum tap target: `44x44`
- Body text: minimum `16px`

## Phase 1 Scope

Phase 1 focuses on:

1. Discovery confidence
2. Event page booking confidence
3. Event Studio reset foundation
4. Checkout trust polish
5. Vendor dashboard quick confidence

Phase 1 does not include:

- Native mobile app
- Full seating maps
- Complex SMS automation
- Full ad platform
- New checkout architecture
- Rebuilding Commerce
- Rebuilding RSVP logic
- Rebuilding ticket logic
- Rebuilding Stripe Connect
- Rebuilding QR/check-in

## Non-Negotiable Engineering Rules

1. Audit before editing.
2. Create a branch before changes.
3. Stop if unrelated dirty files exist.
4. Do not guess file paths.
5. Do not duplicate code, services, routes, controllers, checkout panes, ticket logic, RSVP logic, access checks, or Twig components.
6. Reuse existing MEL architecture.
7. Use Drupal 11 best practice.
8. Use Commerce APIs properly.
9. Preserve customer, vendor, staff, and admin security boundaries.
10. Keep public, vendor, checkout, and admin surfaces separate.
11. Render arrays over raw HTML.
12. Escape output in Twig.
13. Maintain cache metadata.
14. Respect `prefers-reduced-motion`.
15. Maintain WCAG 2.1 AA baseline.

## Existing MEL Systems To Reuse

Cursor must audit and reuse existing systems before creating anything new.

Known systems likely involved:

- BookingFlowResolver
- TicketMatrixForm
- Ticket manager logic
- Event Studio module
- Vendor dashboard services
- Checkout flow module
- Ticketing module
- RSVP module
- SurfaceNegotiator and MEL surface systems, if active
- Existing theme tokens
- Existing SCSS component structure
- Existing access control plugins/services
- Existing QR/check-in services
- Existing Commerce product/variation ticket flow

New services are only allowed if the audit proves there is no existing correct place for the logic.

## Slice A: Discovery Confidence

Goal:

Make public discovery feel useful, warm, and trustworthy without rebuilding search.

Implement:

- Discovery intro/header.
- Clear event browsing copy.
- Search/filter prompt.
- Better empty state.
- Stronger event card confidence.
- Clear date/time.
- Location or online state.
- RSVP/free/paid state.
- One clear CTA.

Do not:

- Build a new discovery engine.
- Duplicate event cards.
- Add fake filters that do not work.
- Overload cards with metadata.

## Slice B: Event Page Booking Confidence

Goal:

The event page must answer:

1. What is it?
2. When is it?
3. Where is it?
4. How much?
5. Can I trust this organiser?
6. What do I press next?

Implement:

- One dominant CTA.
- Clear RSVP/free/paid/unavailable state.
- Organiser trust block.
- Secure booking copy.
- Confirmation-by-email copy.
- Refund/cancellation fallback if no field exists.
- Mobile sticky CTA if not already present.
- Calendar/share links only if existing helpers/routes exist.

Do not:

- Duplicate CTA logic.
- Hardcode booking states.
- Bypass BookingFlowResolver or canonical CTA logic.
- Leak attendee/private data.

## Slice C: Event Studio Reset Foundation

Goal:

Make Event Studio feel like a guided event builder.

Structure:

1. Event basics
2. Image and description
3. Date, time, and location
4. RSVP or paid tickets
5. Visibility and sharing
6. Preview and readiness
7. Save/publish

Implement:

- Guided shell around existing form.
- Right-side preview panel if data exists.
- Readiness checklist using existing state/readiness services if present.
- Sticky save/publish footer.
- Keep save/publish logic unchanged unless audit proves it is broken.

Do not:

- Rebuild the Event form.
- Move ticket purchase logic into Event Studio.
- Recreate ticket manager services.
- Remove existing validation.
- Hide important required fields.

## Slice D: Checkout Trust Polish

Goal:

Improve confidence without changing payment logic.

Implement:

- Secure checkout copy.
- Payment processed securely copy.
- Confirmation email copy.
- Clear order summary.
- Mobile-friendly CTA.
- CTA wording such as “Complete booking”, not “Submit”.

Do not:

- Alter Stripe flow.
- Alter Commerce order flow.
- Create parallel checkout logic.
- Add marketing clutter to checkout.

## Slice E: Vendor Dashboard Quick Confidence

Goal:

Help organisers know what needs attention.

Implement:

- Draft events needing action.
- Events missing RSVP/ticket setup.
- Upcoming events.
- Check-in readiness where existing data supports it.
- Promotion status where existing data supports it.

Do not:

- Create a second dashboard.
- Expose private attendee data.
- Duplicate dashboard services.
- Add noisy analytics before the basics are clear.

## Required Audit Document

Before implementation, create:

`docs/product-reset-phase-1-audit.md`

It must include:

1. Current discovery routes/views/templates/components.
2. Current event full page templates and CTA logic.
3. Current Event Studio routes/controllers/forms/services/JS/SCSS.
4. Current booking/checkout trust UI.
5. Current vendor dashboard quick actions.
6. Existing services that must be reused.
7. Existing duplicate/legacy code that should not be extended.
8. Security/access boundaries.
9. Recommended implementation slices.
10. Files proposed for change.

## Required Deferred Document

Create:

`docs/product-reset-phase-1-deferred.md`

Use this for items found during audit but not implemented in Phase 1.

Examples:

- Seating maps
- SMS reminders
- Advanced recommendations
- Complex campaign automation
- Native app
- New discovery engine
- Advanced waitlist logic
- Full marketplace ranking

## Validation Required

Run:

```bash
git status -sb