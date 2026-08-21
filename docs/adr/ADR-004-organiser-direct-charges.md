# ADR-004 — Organiser direct-charge ticket payments

**Status:** Accepted target; activation blocked pending the evidence gates below
**Date:** 20 August 2026
**Decider:** MyEventLane owner
**Supersedes:** ADR-002 and ADR-003 for new organiser ticket payments

## Decision

MyEventLane will use Stripe Connect direct charges for organiser-owned ticket
sales:

The MyEventLane owner approved an initial MEL platform fee of 1.5%, including
GST, with no fixed fee on 21 August 2026. The percentage remains adjustable at
**Admin → Configuration → MyEventLane → General settings**. The configured
ticket percentage is the single source for both the buyer-visible Commerce
adjustment and Stripe `application_fee_amount`; changing it must not introduce
a separate fixed fee.

> Customer → organiser connected Stripe account → MEL application fee

The PaymentIntent is created in the organiser's connected Stripe account. It
uses `application_fee_amount` for the MEL platform fee and does not use
`transfer_data` or a destination. Stripe manages the organiser's Stripe balance
and payouts to their nominated bank account.

Connected accounts use this approved configuration:

- Dashboard: Full Stripe Dashboard;
- fee collection: Stripe bills the connected account;
- negative balance liability: Stripe; and
- charge pattern: direct charges.

MyEventLane does not hold organiser ticket revenue or later release it through
the legacy MEL payout ledger.

## Scope boundary

One direct charge can contain revenue for one organiser only. It must not be
mixed with MEL-owned products, including MEL Pro, Boost or platform donations.
Those products remain platform-account charges and need their own compatible
checkout path.

Reusable Stripe customer and payment-method identifiers must not be shared
between connected accounts. Ticket checkout therefore uses a single-use payment
method in the connected-account context.

## Runtime controls

- `myeventlane_core.settings:direct_charge_enabled` is the migration switch.
- The switch defaults to `false` and must fail closed if the organiser account
  is absent, invalid or ambiguous.
- When enabled for organiser revenue, checkout must allow only the
  `stripe_connect` gateway.
- PaymentIntent creation, return, capture, void, refund and webhook processing
  must use the immutable connected account recorded on the order.
- Direct-charge orders must not create new legacy payout-ledger liabilities.
- Legacy payout selection, approval, Transfer execution and reversal routes
  must be inaccessible while direct-charge mode is enabled. Historical records
  may remain read-only for accounting and audit purposes.

## Content and responsibility model

The organiser is the seller for their event. Stripe processes the payment in
the organiser's connected account. MEL supplies the booking and refund workflow
and collects its application fee.

Organisers remain responsible for event refunds. A refund initiated through MEL
is funded from the same connected Stripe account. Customer wording must preserve
rights under Australian Consumer Law.

Stripe controls its account verification, restrictions, processing fees,
balance availability, payout schedule, bank settlement and connected-account
dispute process. MEL support can investigate MEL order, ticket, fee, webhook,
payment-record and refund-workflow state but cannot release a Stripe payout or
decide a Stripe dispute.

## Activation gates

This decision does not itself authorise activation. Keep the migration switch
off until all of these are evidenced in the target environment:

1. A configured `stripe_connect` Commerce gateway with protected platform keys
   and a verified Connect webhook signing secret.
2. Stripe account-controller and liability settings match the seller, refund
   and dispute wording approved for the product.
3. The configured GST-inclusive MEL ticket-fee percentage and displayed
   adjustment reconcile to `application_fee_amount`, with a zero fixed fee.
4. Test-mode checkout, return, asynchronous webhook, refund, failed-payment and
   replay scenarios pass for a connected account.
5. Checkout, confirmation, invoice, receipt and refund output identify the
   correct seller and fee recipients.
6. Local, staging and production managed content is migrated and rescanned.
7. The legacy Transfer path is proven inaccessible and cannot double-pay a
   direct-charge order.

## Existing-account reconnection

Five existing connected accounts were identified with a configuration that is
incompatible with the approved responsibility model. They must reconnect using
new compatible connected accounts. The migration is deliberately
non-destructive:

1. Keep the current account ID authoritative while replacement onboarding is
   incomplete.
2. Store the pending replacement separately and block new paid publishing for
   that organiser.
3. Promote the replacement only after card charges are active and Stripe
   confirms the Full Stripe Dashboard, Stripe fee billing and Stripe negative
   balance liability configuration.
4. Archive the previous account ID and retain the immutable connected-account
   ID already recorded on historical orders.
5. Never disconnect, close or delete the previous Stripe account as part of
   the automated flow.

Each organiser must complete Stripe-hosted onboarding. Deployment of this code
does not prove that any of the five reconnections has completed.

## Consequences

The organiser connected account is the Stripe merchant context for ticket
charges. MEL gives up the old operational ability to batch-release organiser
ticket revenue. Payment and refund operations need the originating connected
account ID, including queued webhook work. Mixed carts that cross ownership
boundaries must be split or blocked.

ADR-002 and ADR-003 remain useful historical evidence of the July 2026 runtime;
they are not implementation guidance for the accepted target.
