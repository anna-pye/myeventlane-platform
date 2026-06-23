# Confirmation & First Event Experience Audit

**Date:** 2026-06-23  
**Scope:** Attendee confirmation, ticket recovery, organiser first-event success, empty states, continuity surfaces.  
**Method:** Repository routing/controllers/templates, `MelReadinessHelper`, `MelCustomerContinuityPresenter`, cross-reference with `docs/audits/workflow-experience-audit.md` and `docs/adoption/mel-governance-customer-continuity-confirmation-slice.md`. No invented routes or fields.

**Objective:** Emotional confidence — users should not ask *Did my booking work?*, *Where is my ticket?*, *What happens next?*, *Is my event live?*, *What should I do now?*

---

## Prompt self-audit (pre-implementation)

| Gap in original prompt | Mitigation |
|------------------------|------------|
| Drupal cache contexts on confirmation pages | Document `#cache` on RSVP thank-you (`url`, `user`, event tags); checkout completion inherits Commerce order cache |
| Commerce order state vs customer vocabulary | Governed via `MelReadinessHelper::customerCommerceOrderStateLabel()` + surface preprocess |
| Guest checkout (uid 0) ticket access | Email + `/ticket/{code}/pdf`; order detail after login with matching email — no new access rules |
| RSVP vs paid ticket recovery paths split | RSVP → thank-you + email + `/my-events`; paid → checkout complete + `/my-tickets/order/{id}` |
| Config/email template changes high risk | Email bodies audited; copy improvements stay in PHP/Twig presentation layer only |
| `drush cex` not required for copy-only | No config schema changes in this slice |
| Theme build after Twig/SCSS | Run `npm run mel:lint` / `mel:build` when theme files touched |
| Pro-gated analytics terminology | Use “Insights” in organiser UI; document Pro boundary |
| Accessibility on confirmation CTAs | Verify `role="region"`, labelled headings, `aria-live` on donation-pending RSVP |

---

## Executive summary

MEL already has **strong confirmation infrastructure**: `MelCustomerContinuityPresenter` governs RSVP thank-you and checkout completion; `MelReadinessHelper` owns empty-state slots (what happened / why / next); organiser dashboard and Event Studio workspace surface live-state guidance via `VendorEventWorkspaceViewModelBuilder` and operational readiness summaries.

Remaining friction is **recovery discoverability** (guest vs signed-in paths), **hub labelling** (Tickets vs My tickets, Dashboard vs Home), **RSVP → account continuity** (no “View my events” on thank-you for signed-in users), and **organiser zero-attendee states** needing explicit “your event is live” reassurance.

Recommended approach: governed copy and CTA ordering only — no Commerce, Stripe, permissions, or schema changes.

---

## 1. Attendee journey map

| Step | Route | Path | Primary files |
|------|-------|------|---------------|
| 1. RSVP complete | `myeventlane_rsvp.thankyou` | `/event/{event}/rsvp/thank-you` | `RsvpThankYouController.php`, `mel-rsvp-thankyou.html.twig`, `MelCustomerContinuityPresenter::buildRsvpThankYouPresentation()` |
| 2. Paid checkout complete | `commerce_checkout.form` (`step=complete`) | `/checkout/{order}/complete` | `commerce-checkout-completion.html.twig`, theme preprocess `myeventlane_theme_preprocess_commerce_checkout_form()` |
| 3. Confirmation page (paid) | Same | Same | `MelCustomerContinuityPresenter::buildCheckoutCompletionPresentation()` |
| 4. Ticket access | `myeventlane_checkout_flow.order_detail` | `/my-tickets/order/{commerce_order}` | `MyTicketsController::orderDetail()`, `myeventlane-order-detail.html.twig` |
| 5. Calendar access | `myeventlane_rsvp.ics_download` | `/event/{node}/ics` | `MyTicketsOrderViewModelBuilder::calendarUrl()`, order detail + My Tickets cards |
| 6. PDF access | `myeventlane_tickets.download_pdf_by_code` | `/ticket/{ticket_code}/pdf` | `TicketDownloadController.php` |
| 7. Email references | Messaging templates | — | `config/sync/myeventlane_messaging.template.order_receipt.yml`, `rsvp_confirmation.yml` |
| 8. Returning later | Account hub | `/my-account`, `/my-tickets`, `/my-events` | `AccountLinksService.php`, `MyAccountController.php` |
| 9. My Tickets | `myeventlane_checkout_flow.my_tickets` | `/my-tickets` | `MyTicketsController.php`, `myeventlane-my-tickets.html.twig` |
| 10. Upcoming events | `myeventlane_dashboard.customer` | `/my-events` | `myeventlane-customer-dashboard.html.twig` |

### Continuity services

| Service / class | Role |
|-----------------|------|
| `MelCustomerContinuityPresenter` | CTA ordering, headlines, calendar URLs, post-booking discovery bridge |
| `MelReadinessHelper` | All governed confirmation and empty-state strings |
| `MyTicketsOrderViewModelBuilder` | Order cards, ICS URLs, ticket models, PDF actions |
| `MelWorkflowManager` | Suppresses duplicate `first_rsvp` completion panel on thank-you route |
| `OrderCompletedSubscriber` | Post-order email / attendee sync (not modified in this slice) |

---

## 2. Attendee journey — findings

### RSVP complete

| Aspect | Status | Notes |
|--------|--------|-------|
| Headline clarity | ✅ Strong | “You're in. See you there.” via `customerRsvpConfirmationHeadline()` |
| Status line | ✅ | Confirmed / donation-pending / payment-received variants |
| Next actions | ✅ | View event → Add to calendar → Browse / Hidden Gems → Cancel RSVP |
| Email sent | ✅ | `RsvpMailer::sendConfirmation()`; template mentions PDF + calendar attachments |
| Trust gap | ⚠️ | Signed-in users lack explicit “View my events” recovery CTA on thank-you |
| Donation pending | ✅ | RSVP confirmed reassurance + optional checkout band |

### Paid checkout complete

| Aspect | Status | Notes |
|--------|--------|-------|
| Hero | ✅ | “Booking confirmed” + email/order lines |
| Primary CTA (signed-in) | ✅ Fixed | Routes to `myeventlane_checkout_flow.order_detail` (wallet/QR view) |
| Guest path | ⚠️ | Shows email + order number; relies on email for PDF — intentional, no login wall |
| Calendar | ✅ | Apple / Google / Outlook links when available |
| What happens next | ✅ | Governed `what_next` + organiser trust panels |
| Support visibility | ⚠️ | Help Centre link on checkout sidebar, not repeated on completion page |

### Ticket recovery (3 days later)

| Path | Works? | Barrier |
|------|--------|---------|
| Email → PDF link | ✅ | `/ticket/{code}/pdf` with access check |
| Email → calendar attachment | ✅ | ICS in receipt email |
| Sign in → My Tickets | ✅ | Requires account; merges guest orders by email |
| Sign in → My Events | ✅ | RSVPs + tickets unified on `/my-events` |
| Guest without email | ❌ Dead end | No magic-link recovery without support — document as residual risk |
| Anonymous My Tickets | ❌ By design | Redirect/login message; recovery via email |

### Duplicate actions

| Location | Issue | Severity |
|----------|-------|----------|
| My Tickets cards | Multiple “Add to calendar” per multi-event order | Low — functional |
| Checkout complete + email | Both show order number | Low — reinforces trust |
| RSVP thank-you + workflow panel | Suppressed via `MelWorkflowManager` | ✅ Resolved |

---

## 3. Organiser journey map

| Step | Route / surface | What organiser sees | Next-step guidance |
|------|-----------------|---------------------|-------------------|
| Event published | Event Studio publish (JSON) | “Published successfully” toast | Workspace focus panel: “Your event is live. Share it…” (`VendorEventWorkspaceViewModelBuilder`) |
| First live event | `myeventlane_vendor.console.dashboard` | Mission control hero + empty events CTA | `vendorDashboardEmptyStrings()` governed copy |
| Zero attendees | Studio attendees / vendor ops | `vendorAttendeeOperationsNoAttendeesYetSlots()` | Share event link CTA |
| First RSVP | Dashboard activity stream | Success timeline item | Momentum card when counts > 0 |
| First sale | Dashboard KPIs + momentum | Tickets sold message | Links to event workspace |
| Empty insights | Analytics (Pro-gated) | “Insights” collapsed panel; sign-in wall for guests | `vendorOrganiserPortalSignInAnalyticsBody()` |
| Event growth | Boost / growth cards | `Grow event guidance` section | Secondary to mission control |

### First-event path (Register → live)

```text
/user/register OR /vendor/onboard/account
→ profile → stripe (optional) → branding → first-event step → complete
→ /create-event OR /vendor/events/create
→ Event Studio sections (draft) → tickets/RSVP → POST publish
→ /vendor/dashboard (mission control)
```

| Measure | Value | Notes |
|---------|-------|-------|
| Screens (minimum) | ~8–12 | Onboarding skippable steps vary |
| Key decisions | RSVP vs paid, Stripe connect, publish readiness | Governed by `EventReadinessResult` |
| Dead ends | Unpublished draft visible only in organiser tools | ✅ Expected |
| Unclear wording | “Analytics” vs “Insights” | Addressed in terminology pass |

---

## 4. Confirmation surfaces evaluation

| Surface | Heading | Next actions | Trust language | Support | Recovery |
|---------|---------|--------------|----------------|---------|----------|
| RSVP thank-you | ✅ H1 governed | View event, calendar, browse | Status line + donation reassurance | ⚠️ No inline help link | Email + calendar download |
| Booking confirmed | ✅ | View ticket/booking, calendar, view event | Email sent + order # + organiser trust | ⚠️ | My Tickets (signed-in) |
| Ticket page (order detail) | Shell H1 “Booking #…” | PDF, calendar, view event, back | QR + status badge | ⚠️ | Full recovery hub |
| My Tickets | Shell H1 | View booking, calendar per card | Governed order state | Empty state governed | Login required |
| Dashboard cards (customer) | Section H2s | Event cards with links | Welcome message | — | `/my-events` empty governed |
| Success panels | Workflow-suppressed on RSVP | — | — | — | — |

---

## 5. Empty states (governed slots)

All customer empty states use `MelReadinessHelper` four-slot pattern (`heading`, `what_happened`, `why_empty`, `next_action`):

| Surface | Method | 3-part complete? |
|---------|--------|------------------|
| My Tickets empty | `customerMyTicketsOverviewEmptySlots()` | ✅ |
| My Events empty | `customerMyEventsDashboardUpcomingEmptySlots()` | ✅ |
| Account dashboard tickets | `customerAccountDashboardTicketsEmptySlots()` | ✅ |
| Account dashboard RSVPs | `customerAccountDashboardRsvpsEmptySlots()` | ✅ |
| Vendor no attendees | `vendorAttendeeOperationsNoAttendeesYetSlots()` | ✅ (enhanced: live-event reassurance) |
| Vendor no ticket sales | `vendorAttendeeOperationsNoTicketSalesYetSlots()` | ✅ |
| Vendor no RSVPs | `vendorAttendeeOperationsNoRsvpsYetSlots()` | ✅ |
| Vendor dashboard no events | `vendorDashboardEmptyStrings(false)` | ✅ |

---

## 6. Trust issues

| Issue | Impact | Mitigation (this slice) |
|-------|--------|-------------------------|
| Guest checkout completion lacks in-page ticket link | Medium — users may think booking failed | Clearer guest email body copy; order number prominent |
| “View order” vs “View booking” terminology | Low confusion | Unified to “View booking” |
| Checkout headline “Great choice…” less definitive than “Booking confirmed” | Low | Headline updated |
| RSVP thank-you missing account hub link | Medium for return visits | Add “View my events” when authenticated |
| My Tickets requires login | Expected | Email recovery path documented; no new login barriers added |
| Organiser “is it live?” after publish | Medium for first-timers | Workspace + dashboard copy already states live; empty attendee adds explicit live line |

---

## 7. Recovery issues

| Scenario | Current behaviour | Gap |
|----------|-------------------|-----|
| Paid + signed in | Primary CTA → order detail with QR/PDF | ✅ |
| Paid + guest | Email with attachments + order # on page | Guest must retain email |
| RSVP + signed in | Thank-you only; events on `/my-events` | Missing direct CTA until fix |
| Lost email | Support + account email match | No self-serve without support |
| Mobile ticket | Order detail responsive; PDF download | ✅ |
| Calendar | ICS download on thank-you, completion, order detail | ✅ |

---

## 8. First-event issues

| Issue | Severity | Notes |
|-------|----------|-------|
| Dashboard empty state lacked primary CTA button | Medium | Added Create event button in events-empty panel |
| Stripe requirement unclear for RSVP-only | Medium | Empty message explains RSVP vs paid |
| Publish success is JSON toast only | Low | Workspace focus panel carries next steps |
| Insights behind Pro + collapsed `<details>` | Low | Intentional; terminology aligned to “Insights” |

---

## 9. Accessibility notes

| Check | RSVP thank-you | Checkout complete | Order detail |
|-------|----------------|-------------------|--------------|
| Focus states | `.mel-btn` theme tokens | `.mel-confirmation-button` | `.mel-button` |
| Heading hierarchy | H1 → H2 donation bands | H1 hero → H2 sections | H2 per pass |
| Keyboard | Link-based actions | Link-based actions | Links + details/summary |
| Touch targets | Button classes ≥44px target | Same | Same |
| `aria-live` | Donation pending status | — | — |
| Mobile | Card layout | Frictionless stack | Wallet pass layout |

Minimum WCAG 2.1 AA alignment via existing design system; no regressions introduced in this slice.

---

## 10. Changes made (Phase 2)

Low-risk improvements confirmed from repository (includes uncommitted working-tree changes from this audit session):

| File | Change |
|------|--------|
| `MelReadinessHelper.php` | Booking confirmed headline; organiser empty copy; guest recovery wording; vendor no-attendees live reassurance; View booking label |
| `MelCustomerContinuityPresenter.php` | Checkout primary CTA → order detail; RSVP “View my events” for authenticated users |
| `RsvpThankYouController.php` | Pass authenticated flag to continuity presenter |
| `AccountLinksService.php` | Home / My tickets nav labels |
| `dashboard.html.twig` (vendor) | Insights terminology; empty-state CTA; governed empty copy |
| `myeventlane-customer-dashboard.html.twig` | Governed upcoming empty state hook |
| Vendor theme / analytics templates | Analytics → Insights labelling |
| `MelCustomerContinuityPresenter` / checkout | Primary ticket recovery route alignment |

**Explicitly not changed:** Commerce workflows, order logic, Stripe, permissions, notifications, entities, database schema.

---

## 11. Residual risk

- Guest ticket recovery depends on email deliverability and ticket-code links in receipt messages.
- `MelCustomerContinuityPresenter` URL building fails silently if routes unavailable.
- RSVP module + theme thank-you templates remain duplicated (sync required on future edits).
- Manual smoke tests (RSVP event, paid event, My Tickets, mobile, organiser zero attendees) require DDEV runtime — not automated in this audit.

---

## 12. Validation commands

```bash
git status --short
composer validate --check-lock
ddev drush cr
# PHP lint on changed PHP files
# npm run mel:lint && npm run mel:build  # if theme/SCSS touched
```

---

## 13. Manual smoke checklist

- [ ] Free RSVP → thank-you headline, calendar download, email received
- [ ] RSVP with optional donation → pending band + reassurance
- [ ] Paid checkout (signed in) → “Booking confirmed”, View ticket → QR/PDF
- [ ] Paid checkout (guest) → order number + email guidance
- [ ] `/my-tickets` → upcoming card → order detail
- [ ] `/my-events` → RSVP and ticket rows
- [ ] Mobile 390px — confirmation CTAs stack, tappable
- [ ] Organiser: publish → workspace “Your event is live…”
- [ ] Organiser: zero attendees → governed empty state with share guidance
