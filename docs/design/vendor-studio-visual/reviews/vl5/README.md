# VL-5 Acceptance Review — Launch Success and Ticket Workspace

| Field | Value |
| --- | --- |
| Status | Acceptance evidence recorded; Product Owner decision pending |
| Date | 2026-07-26 |
| Environment | Local DDEV |
| Branch | `docs/governance-baseline` |
| Commit reviewed | `d0804bca8` |
| Organiser | Pro vendor, uid 92 |
| Representative event | Event 1755, published paid-ticket event with existing sales |
| Governing parent | [Organiser Manifesto](../../../../governance/00-organiser-manifesto.md) |
| Initiative | [TRACE-NOW-02 — Vendor Studio acceptance](../../../../product/initiatives/TRACE-NOW-02-vendor-studio-acceptance.md) |

## Decision boundary

This is an acceptance record, not implementation authority. It does not freeze a component, approve a redesign or authorise defect remediation.

## Scope reviewed

- Launch Success through the `?mel_celebrate=1` return path
- Shared success outcome presentation
- Paid-ticket workspace with two active ticket tiers and existing sales
- Protected ticket removal disclosure
- Desktop at 1440 px, tablet at 768 px and mobile at 390 px
- Keyboard focus order
- Reduced-motion behaviour
- Responsive overflow and action sizing

## Results

| Check | Result | Evidence |
| --- | --- | --- |
| Launch Success return path | Pass | Outcome appears after the Event Workspace and communicates that the event is live |
| Initial focus | Pass | Focus moves to the `Your event is now live` heading |
| Keyboard order | Pass | Share event → Copy link → View public page → social links → optional Boost |
| Primary-action hierarchy | Pass | Hero retains the filled purple action; Launch Success actions remain subordinate as required by VL-5A |
| Paid-ticket outcome truth | Pass | Outcome states that people can buy tickets and links to the public event |
| Responsive reflow | Pass | No document-level horizontal overflow at 1440 px, 768 px or 390 px |
| Mobile action layout | Pass | Core Launch Success actions are full width and 44 px high at 390 px |
| Reduced motion | Pass | With `prefers-reduced-motion: reduce`, computed animation is `none` with zero duration |
| Ticket hierarchy | Pass | Ticket name, price, availability, capacity and sales are presented before advanced settings |
| Customer preview | Pass | Checkout-visible ticket options and ended-event state are explained within the workspace |
| Protected removal | Pass | A sold ticket cannot be permanently deleted; the disclosure directs the organiser to archive it |
| Repository contract tests | Pass with notices | 12 tests and 129 assertions passed; two PHPUnit deprecations and a browser-output directory warning were reported |

## Experience findings

### Launch Success

The outcome is calm, specific and appropriately subordinate to the Hero. It explains what guests can now do, names one recommended next step and separates optional growth activity. On mobile, the sequence remains readable without horizontal overflow.

The mobile “Share your event” action is not a filled button. This is intentional under the approved VL-5A hierarchy: the Hero remains the sole filled primary action. It is not recorded as a defect.

### Ticket workspace

The revised hierarchy provides useful operational context before advanced configuration. Existing sales remain visible, the customer preview supports confidence, and the removal disclosure explains the consequence without offering an unsafe destructive action.

At 390 px, the form reflows to one column and the reviewed content does not overflow horizontally.

## Evidence not confirmed

| Evidence gap | Reason | Effect |
| --- | --- | --- |
| AJAX publish-success path | Exercising it requires a representative publishable draft and a state-changing publish action | Launch Success cannot be finally frozen |
| Free RSVP outcome variant | The representative event uses paid tickets | Variant acceptance remains pending |
| External-booking outcome variant | The representative event uses paid tickets | Variant acceptance remains pending |
| Real screen-reader use | No screen-reader session was available in this review | Assistive-technology acceptance remains pending |
| Full ticket create, edit and archive submissions | Submitting forms would change local event and Commerce state | Functional mutation was deliberately not performed |
| Physical touch-device behaviour | Viewport emulation is not physical-device evidence | On-device assurance remains pending |

Where evidence was not available: **I cannot confirm this.**

## Defects

No confirmed implementation defect was found within the tested path. No defect brief was created.

The unconfirmed variants above are evidence gaps, not presumed defects.

## Acceptance recommendation

Accept the reviewed paid-ticket path and Launch Success return path as conditionally satisfactory. Do not freeze VL-5A, VL-5B or the ticket workspace refinement until the remaining variant and assistive-technology evidence is recorded.

The smallest safe continuation is:

1. create or identify governed, non-production test events for free RSVP, paid tickets and external booking;
2. exercise both AJAX and return-query success paths;
3. complete a real screen-reader and physical mobile-device pass;
4. record any defects as bounded briefs; and
5. ask the Product Owner to accept, conditionally accept or reject each Catalogue item.

No implementation is authorised by this recommendation.
