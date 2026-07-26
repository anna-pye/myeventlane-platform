# Customer Experience Acceptance Refresh — 27 July 2026

| Field | Value |
| --- | --- |
| Status | Evidence refresh complete; Product Owner decisions required |
| Initiative | [TRACE-NOW-03 — Customer Experience Acceptance Refresh](../../product/initiatives/TRACE-NOW-03-customer-experience-acceptance.md) |
| Highest authority | [Organiser Manifesto](../../governance/00-organiser-manifesto.md) |
| Environment | Local DDEV |
| Evidence date | 2026-07-27 |
| Implementation authority | Not authorised |

## Purpose

This record refreshes bounded evidence for the attendee journey after material customer, checkout, ticket, wallet, access and RSVP changes made since the June 2026 customer verification.

It does not replace the earlier records or carry their conclusions forward automatically. It records what can be confirmed in the current repository and local DDEV environment, what cannot be confirmed and which decisions require Product Owner authority.

## Evidence boundary

Reviewed:

- the approved TRACE-NOW-03 initiative brief;
- the June [Customer Acceptance Audit](../customer-acceptance/customer-acceptance.md);
- the June [Customer Verification Summary](verification-summary.md);
- the June [Launch Certification Sign-off](../launch-certification/launch-signoff.md);
- repository history affecting customer, RSVP, checkout, tickets, messaging, refunds and the public theme after 26 June 2026;
- current route and controller declarations;
- current published DDEV test-event relationships; and
- read-only DDEV HTTP responses.

After Product Owner approval on 27 July 2026, the scope also included one controlled anonymous RSVP execution using:

- existing published DDEV test event `1659`;
- synthetic name `MEL Acceptance Test 20260727`;
- reserved example address `mel-acceptance-20260727@example.invalid`; and
- a separate cookie-isolated HTTP session.

Not performed:

- cancelling an RSVP;
- adding a ticket to a cart or submitting checkout;
- using a payment gateway or moving money;
- sending or receiving transactional messages;
- accessing a customer account;
- changing content or configuration;
- physical-device testing;
- assistive-technology testing;
- target-environment or production verification; or
- implementation or remediation.

## Environment condition

DDEV was running successfully with Drupal 11.4.4 at `https://myeventlane.ddev.site`.

`drush config:status` reported configuration differences affecting, among other items:

- `commerce_checkout_flow.mel_event_checkout`;
- `commerce_payment_gateway.stripe_pe_recurring`;
- `field.node.event.field_product_target`;
- `views.view.myeventlane_vendor_rsvps`; and
- several unrelated local settings.

The local environment therefore does not establish repository configuration parity or production readiness. Behavioural observations below apply only to this DDEV state.

## Change since the previous verification

Repository history after 26 June includes material changes to:

- canonical checkout configuration and Stripe Payment Element compatibility;
- booking and confirmation presentation;
- account booking summaries;
- digital passes, wallet access and ticket readiness;
- refund and attendee ownership checks;
- organiser RSVP isolation; and
- RSVP audience and messaging status labels.

The June 2026 score and launch conclusion are historical evidence. They are not a current certification.

## Representative journey evidence

| Journey step | Current evidence | Result | Boundary |
| --- | --- | --- | --- |
| Published RSVP event | Existing published test event `1659` returned HTTP 200 at its canonical node route | Reachability confirmed | Content quality and target-environment state not assessed |
| Canonical RSVP entry | `/event/1659/rsvp` followed its redirect and returned HTTP 200 | Route continuity confirmed in DDEV | Submission was not performed |
| Direct RSVP form | `/event/1659/rsvp/form` returned HTTP 200 | A second public RSVP form contract remains active | Product authority for this path is unclear |
| RSVP confirmation | `/event/1659/rsvp/thank-you` returned HTTP 200 and rendered “RSVP Confirmed” and “You're in. See you there.” for an anonymous direct request | Material trust gap recorded as `CXA-01` | No RSVP or authenticated session preceded the request |
| Published paid event | Existing published test event `1665` returned HTTP 200 | Reachability confirmed | Event accuracy and availability not assessed |
| Paid booking entry | `/event/1665/book` returned HTTP 200 with ticket selection and review-before-payment guidance | Entry presentation confirmed | Cart, checkout, payment and ticket issuance were not exercised |
| My Tickets | Anonymous `/my-tickets` returned HTTP 403 | Access boundary confirmed | Signed-in empty, booking and ticket states not assessed |
| Saved events | Anonymous `/my-saved-events` returned HTTP 302 to login, then HTTP 200 | Earlier “feature missing” finding remains corrected | Signed-in list and empty state not assessed |
| Calendar | `/calendar` returned HTTP 200 | Public route reachability confirmed | Event population, keyboard use and responsive layout not assessed |
| Support | `/support` returned HTTP 200 | Public support entry reachability confirmed | Monitoring and service coverage are not implied |
| Controlled guest RSVP | Canonical `/event/1659/rsvp` redirected to `/event/1659/book`; form submission created RSVP submission `165` with anonymous user ID `0` and status `confirmed` | Canonical guest submission and confirmation redirect confirmed in this DDEV state | One synthetic record; configuration parity not established |
| Confirmation page after submission | The isolated session redirected to `/event/1659/rsvp/thank-you` and rendered the event-specific confirmation | Successful-path continuity confirmed | Does not resolve direct-access false confirmation |
| Confirmation message | Watchdog recorded “RSVP confirmation queued” for the synthetic address | Queue request confirmed | Mailpit contained no message during the check; delivery is not confirmed |
| Organiser attendee mirror | Watchdog recorded an integrity failure because `event_attendee.uid` cannot be null; no matching mirror row exists | `CXA-03` confirmed | Canonical RSVP submission itself remained confirmed |

An HTTP response establishes route behaviour only. It does not establish usability, accessibility, visual quality, successful submission or operational coverage.

## Acceptance findings

### CXA-01 — RSVP confirmation can be presented without confirmed RSVP evidence

| Field | Value |
| --- | --- |
| Classification | Trust and journey continuity |
| Materiality | Material; Product Owner decision required |
| Evidence | Anonymous direct request to `/event/1659/rsvp/thank-you`; route requires only `access content`; controller renders the confirmation presentation when no submission is resolved |
| Human effect | A person may be told that they are attending when MyEventLane has no submission evidence for that visitor |
| Manifesto effect | Conflicts with earning trust continuously and showing truthful consequences |
| Current authority | No remediation authorised |

The controller resolves a submission only from a qualifying donation order or the latest submission for an authenticated user. When neither exists, it continues to build the RSVP confirmation presentation.

Recommended decision: require confirmation presentation to depend on verifiable RSVP context, while retaining a calm recovery path for expired, missing or invalid context. The implementation approach requires a separate bounded investigation and approval.

### CXA-02 — Two public RSVP entry contracts remain active

| Field | Value |
| --- | --- |
| Classification | Product ownership and journey coherence |
| Materiality | Product Owner decision required |
| Evidence | `/event/{event}/rsvp` permanently redirects to unified Commerce booking; `/event/{node}/rsvp/form` remains public and is exercised by legal-consent functional tests |
| Human effect | Entry points may diverge in consent, capacity, donation, confirmation or recovery behaviour |
| Manifesto effect | Risks competing workflows instead of one coherent next step |
| Current authority | No route retirement, redirect or implementation authorised |

Repository evidence does not establish whether the direct form is an approved compatibility path, an internal test dependency or a second supported customer journey. **I cannot confirm this.**

Recommended decision: designate one canonical customer RSVP path and explicitly classify the other as supported, compatibility-only or superseded before any implementation change.

### CXA-03 — Anonymous RSVP does not synchronise to the organiser attendee mirror

| Field | Value |
| --- | --- |
| Classification | Journey continuity and organiser operations |
| Materiality | Material; investigation required |
| Evidence | Controlled guest RSVP submission `165`; watchdog IDs `1063536` and `1063537`; zero matching `event_attendee` rows |
| Human effect | The attendee receives confirmation while the organiser-facing attendee record may be absent |
| Manifesto effect | Breaks complete-journey continuity and may create avoidable event-day recovery |
| Current authority | Investigation approved; implementation not authorised |

The RSVP submission uses anonymous user ID `0`. The mirror write attempted to insert a null value into the non-null `event_attendee.uid` column and failed.

Recommended next step: run a bounded read-only investigation of the canonical RSVP-to-attendee synchronisation contract, its expected anonymous identity representation and all downstream organiser views. Do not change the schema or substitute a user identity without approved architecture.

### CXA-04 — Confirmation delivery is not established

| Field | Value |
| --- | --- |
| Classification | Transactional messaging evidence |
| Materiality | Acceptance gap |
| Evidence | Watchdog recorded confirmation queued; Mailpit returned zero messages during the evidence window |
| Human effect | The page promises that confirmation will arrive by email, but this run did not demonstrate delivery |
| Manifesto effect | Requires truthful next-step communication |
| Current authority | Evidence collection only; implementation not authorised |

This may reflect queue execution, local mail transport or another environment condition. **I cannot confirm this.**

Recommended next step: inspect the current mail queue and transport configuration without sending to a real address, then exercise delivery only in the approved local test environment.

## Confirmed corrections retained from June

Current repository evidence still supports the existence of:

- the saved-events View at `/my-saved-events`;
- canonical Commerce ownership of event-ticket checkout;
- separate order, payment and ticket-entitlement states;
- refund access and ownership controls; and
- ticket PDF and wallet capabilities.

This refresh did not re-exercise refund, checkout, payment or ticket issuance behaviour. Their June runtime conclusions remain historical rather than renewed passes.

## Evidence still required

- Representative RSVP completion for guest and signed-in users
- RSVP cancellation and recovery
- Paid booking through cart, checkout, test payment, confirmation and ticket access
- Guest and signed-in post-booking continuity
- Transactional-message inventory, rendering and deliverability
- Signed-in My Tickets and saved-events empty and populated states
- Waitlist confirmation and next-step language
- Keyboard, focus, status announcement, zoom and screen-reader evidence
- Physical-device event, booking, confirmation, ticket and calendar evidence
- Target-environment configuration and route verification
- Operational support and recovery ownership

The controlled run now supplies guest submission and page-confirmation evidence. Signed-in RSVP, cancellation, organiser visibility and message delivery remain open.

## Decision register

| ID | Decision required | Recommendation | Implementation authority |
| --- | --- | --- | --- |
| CXA-01 | Require verifiable context before RSVP confirmation | Approved 27 July 2026; provide a calm recovery state when context is absent | Investigation approved; implementation not authorised |
| CXA-02 | Designate the canonical RSVP entry | Approved 27 July 2026: `/event/{event}/rsvp` is canonical; `/rsvp/form` is temporary compatibility-only pending investigation | Investigation approved; route change not authorised |
| CXA-03 | Execute a controlled guest RSVP scenario | Approved and completed 27 July 2026 using synthetic local evidence | Evidence collection complete |
| CXA-04 | Move paid booking evidence into TRACE-NOW-04 | Approved in principle after the customer boundary and target test environment are confirmed | Not yet ready because DDEV configuration drift remains |
| CXA-05 | Investigate failed anonymous attendee-mirror synchronisation | Recommended as the next bounded investigation | Product Owner approval required |
| CXA-06 | Inspect queued confirmation and local mail transport | Recommended as a read-only evidence step | Product Owner approval required |

## Current conclusion

The customer journey has substantial architecture and reachable public entry points. Controlled DDEV evidence now confirms an anonymous canonical RSVP submission and page confirmation, but current end-to-end acceptance is not established.

The direct RSVP confirmation behaviour remains a material trust finding. The Product Owner has designated the canonical entry and temporary compatibility boundary. The controlled run additionally found that anonymous RSVP synchronisation to the organiser attendee mirror failed and that confirmation delivery was not demonstrated.

The safe next step is a bounded read-only investigation of `CXA-03` and `CXA-04`. Checkout execution remains governed by TRACE-NOW-04 and must wait for a confirmed test-environment configuration boundary.
