# VL-5 Acceptance Review — Launch Success and Ticket Workspace

| Field | Value |
| --- | --- |
| Status | Product Owner accepted; not frozen |
| Date | 2026-07-26 |
| Environment | Local DDEV |
| Branch | `docs/governance-baseline` |
| Commit reviewed | `d0804bca8` |
| Organiser | Pro vendor, uid 92 |
| Representative events | Event 1755 plus archived DDEV acceptance fixtures 1764–1766 |
| Governing parent | [Organiser Manifesto](../../../../governance/00-organiser-manifesto.md) |
| Initiative | [TRACE-NOW-02 — Vendor Studio acceptance](../../../../product/initiatives/TRACE-NOW-02-vendor-studio-acceptance.md) |

## Decision boundary

This is an acceptance record, not implementation authority. It does not freeze a component, approve a redesign or authorise defect remediation.

## Product Owner decision

On 26 July 2026, the Product Owner accepted VL-5 and explicitly directed that it must not be frozen.

Acceptance confirms that the reviewed organiser experience is suitable to retain. It does not lock the component contracts, remove the remaining evidence gaps or authorise further implementation. A future freeze requires a separate explicit Product Owner decision.

## Scope reviewed

- Launch Success through the `?mel_celebrate=1` return path
- Launch Success through the AJAX publish path
- Shared success outcome presentation
- Paid-ticket, free RSVP and external-booking outcome variants
- Paid-ticket workspace with two active ticket tiers and existing sales
- Controlled ticket creation and archive
- Protected ticket removal disclosure
- Desktop at 1440 px, tablet at 768 px and mobile at 390 px
- Keyboard focus order
- Reduced-motion behaviour
- Responsive overflow and action sizing

## Results

| Check | Result | Evidence |
| --- | --- | --- |
| Launch Success return path | Pass | Outcome appears after the Event Workspace and communicates that the event is live |
| Launch Success AJAX path | Pass | Publishing each controlled draft updated the Hero to Published and rendered the outcome without page navigation |
| Paid-ticket outcome variant | Pass | “People can now” includes “buy tickets” |
| Free RSVP outcome variant | Pass | “People can now” includes “RSVP” |
| External-booking outcome variant | Pass | “People can now” includes “follow your external booking link” |
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
| Controlled ticket creation | Pass | `Acceptance Test Tier` saved at A$5.00 with capacity 10 and appeared in the customer preview |
| Controlled ticket archive | Pass | The tier became inactive with lifecycle status `archived` |
| Repository contract tests | Pass with notices | 12 tests and 129 assertions passed; two PHPUnit deprecations and a browser-output directory warning were reported |

## Experience findings

### Launch Success

The outcome is calm, specific and appropriately subordinate to the Hero. It explains what guests can now do, names one recommended next step and separates optional growth activity. On mobile, the sequence remains readable without horizontal overflow.

The mobile “Share your event” action is not a filled button. This is intentional under the approved VL-5A hierarchy: the Hero remains the sole filled primary action. It is not recorded as a defect.

### Ticket workspace

The revised hierarchy provides useful operational context before advanced configuration. Existing sales remain visible, the customer preview supports confidence, and the removal disclosure explains the consequence without offering an unsafe destructive action.

At 390 px, the form reflows to one column and the reviewed content does not overflow horizontally.

The controlled create-and-archive journey confirmed that an organiser can add a tier, see it reflected in the customer preview and remove it from sale without permanent deletion.

## Controlled test data

The following DDEV-only events were created from existing representative events:

| Event | Booking model | Final state |
| --- | --- | --- |
| 1764 — `[MEL ACCEPTANCE] Paid publish path` | Paid | Archived and non-public |
| 1765 — `[MEL ACCEPTANCE] Free RSVP publish path` | Free RSVP | Archived and non-public |
| 1766 — `[MEL ACCEPTANCE] External publish path` | External link to `example.test` | Archived and non-public |

Ticket tier 275, `Acceptance Test Tier`, was created only for event 1764 and then archived. It is inactive. No pre-existing ticket tier was edited.

## Evidence not confirmed

| Evidence gap | Reason | Effect |
| --- | --- | --- |
| Real screen-reader use | No screen-reader session was available in this review | Assistive-technology acceptance remains pending |
| Physical touch-device behaviour | Viewport emulation is not physical-device evidence | On-device assurance remains pending |

Where evidence was not available: **I cannot confirm this.**

## Defects

No confirmed implementation defect was found within the tested path. No defect brief was created.

The unconfirmed variants above are evidence gaps, not presumed defects.

## Acceptance outcome

The Product Owner has accepted the browser-tested Launch Success and ticket workspace paths across all three booking models. VL-5A, VL-5B and the ticket workspace refinement remain unfrozen.

The remaining assurance work is:

1. complete a real screen-reader pass;
2. complete a physical mobile-device pass;
3. record any defects as bounded briefs; and
4. return to the Product Owner only if a defect requires remediation or a later freeze is proposed.

No implementation is authorised by this recommendation.
