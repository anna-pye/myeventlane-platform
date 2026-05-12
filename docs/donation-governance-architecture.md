# Donation Governance Architecture

MyEventLane currently supports several donation/contribution mechanisms. They are intentionally distinct because each one serves a different business, checkout, reporting, and refund contract.

Do not force these into one donation system without a migration plan, reporting backfill, refund review, and product approval.

## Donation Types

| Type | Purpose | Commerce shape | Owner |
| --- | --- | --- | --- |
| `platform_donation` | Vendor-to-MEL platform support | `platform_donation` order and order item | `myeventlane_donations` |
| `rsvp_donation` | Attendee-to-event support after RSVP | `rsvp_donation` order and order item | `myeventlane_donations` / `myeventlane_rsvp` |
| Checkout adjustment donation | Attendee contribution in ticket checkout | Locked order adjustment plus `field_mel_donation` | `myeventlane_commerce` |
| `checkout_donation` | Legacy/hybrid donation line-item shape | Historical order item bundle | Legacy reporting/refund consumers |

## Platform Support Flow

Platform support is vendor-to-MEL. It must not be mixed into attendee ticket totals or RSVP attendee donation flows.

The canonical implementation uses:

- `myeventlane_donations.platform` route at `/vendor/donate`.
- `PlatformDonationService` for `platform_donation` carts and line items.
- `VendorEventMelSupportService` for event-linked platform support preferences and post-publish one-time checkout handoff.
- `MelPlatformSupportWizardFormHelper` where legacy wizard surfaces still need the shared support fields.
- Event fields such as `field_mel_sup_mode`, `field_mel_sup_amt`, `field_mel_sup_pct`, `field_mel_sup_chk`, and `field_mel_sup_oid`.

Event Studio must resolve support URL, copy, enabled state, and placement through a resolver/service. Twig and JavaScript must not hardcode payment URLs or payment logic.

## RSVP Donation Flow

RSVP donations are attendee-to-event and optional. They are recommendation-level setup in Event Studio, not publish blockers.

The public RSVP flow:

- Shows donation UI only when event donation fields enable it.
- Validates optional amount selections in the RSVP form.
- Creates `rsvp_donation` Commerce orders through the existing RSVP donation service.
- Redirects to standard Commerce checkout for the optional donation.
- Keeps the attendee RSVP confirmed even if donation checkout is skipped or unavailable.

Studio guidance may help vendors configure:

- `field_enable_donations`.
- Suggested/default donation amount fields.
- Donation label/options fields.

Studio must not create a parallel RSVP checkout architecture.

## Ticket Checkout Contribution Flow

Paid ticket checkout contributions are not `rsvp_donation` orders and are not platform support.

The current ticket checkout model stores attendee contributions as order-level donation state/adjustments. This is integrated with checkout totals, summaries, reporting, and refunds.

Do not replace this with `platform_donation` or `rsvp_donation` without a migration and reconciliation plan.

## Disabled DonationPane

`DonationPane` is intentionally disabled. Do not re-enable it in this slice.

Re-enabling the pane would risk:

- Double donation collection.
- Duplicate donation line items.
- Broken fee transparency.
- Refund inconsistencies.
- Divergence from the current checkout adjustment model.

Future work that wants a live checkout donation pane must first reconcile adjustments, order item bundles, refund inspection, summaries, reporting, and historical `checkout_donation` data.

## Refund And Reporting Implications

Refund/reporting logic may need to recognize all donation shapes:

- `platform_donation` for vendor-to-MEL support and invoice settlement.
- `rsvp_donation` for attendee-to-event RSVP support.
- Checkout adjustment donations for paid ticket checkout contributions.
- Legacy `checkout_donation` for historical or hybrid orders.

Refund code must preserve the distinction between platform revenue, vendor event support, attendee ticket revenue, and historical donation line items.

## Future Convergence Rules

Any convergence of donation systems must be explicit and staged:

1. Document the target model and why it is safer than the current split.
2. Inventory affected order types, order item bundles, adjustments, fields, reports, summaries, refunds, and messages.
3. Provide a data migration and backfill plan.
4. Preserve historical reporting semantics.
5. Keep existing checkout and refund flows working during transition.
6. Get product and payment ownership approval before changing charge, payout, or platform support behavior.

Until then, Event Studio should present donation guidance as governed orchestration over existing systems, not as a new payment implementation.
