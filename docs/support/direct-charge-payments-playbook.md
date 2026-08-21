# Direct-charge payments support playbook

**Owner:** MyEventLane Support
**Approved:** 20 August 2026
**Applies to:** organiser ticket payments processed as direct charges on connected Stripe accounts

## Core position

The organiser is the seller for their event. Ticket payments are processed through the organiser's connected Stripe account. The organiser's ticket revenue is managed in Stripe. Stripe sends available funds to the organiser's nominated bank account according to the Stripe payout schedule. MyEventLane does not hold or manually release ticket-sale funds.

Do not apply this procedure to MEL Pro, Boost or any other platform-owned product. Those payments use the MyEventLane platform account.

## Responsibility boundary

| Question or incident | MEL may investigate | Stripe or organiser controls |
|---|---|---|
| Order or ticket status | MEL order, attendee, ticket and fulfilment records | Organiser controls the event and admission decision, subject to law and MEL policy |
| Payment state | MEL payment record, connected account ID, webhook receipt and recorded Stripe object ID | Stripe processes the payment and controls its payment/risk state |
| MEL platform fee | MEL configuration, order adjustment and `application_fee_amount` evidence | Finance owner approves the fee configuration |
| Stripe processing fee | MEL may point to the relevant Stripe payment record | Stripe calculates and charges the processing fee |
| Stripe payout | MEL may show information reported by Stripe | Stripe controls availability, schedule, bank settlement and arrival timing |
| Bank account | MEL may direct the organiser to Stripe | Organiser updates it in Stripe; MEL must not request full bank details |
| Verification or restriction | MEL may identify that Stripe reports an outstanding requirement | Stripe requests and approves verification information |
| Refund | MEL may validate the order and initiate an authorised refund workflow | Organiser decides and funds the refund, subject to law; Stripe processes it |
| Dispute or chargeback | MEL may supply accurate booking, communication and attendance records | Organiser responds; Stripe and the card network control deadlines, fees and outcome |

## Prohibited promises

Staff must not say that MyEventLane:

- holds, releases or pays an organiser's ticket revenue;
- can create, speed up or reschedule a Stripe payout;
- can change the organiser's bank account;
- can approve Stripe verification or remove a Stripe restriction;
- controls Stripe processing fees;
- decides a dispute or chargeback;
- guarantees a refund arrival date; or
- guarantees that every event cancellation refund will complete automatically.

## Refund procedure

1. Confirm the requester, order, event, payment and refundable ticket scope.
2. Confirm the order was an organiser direct charge. Do not use this procedure for a platform-owned product.
3. Record the organiser's authorisation and the customer-facing reason.
4. Remind the organiser: “You remain responsible for refunds for your event. MyEventLane can help you process a refund through the booking system, but the refunded money comes from your connected Stripe account.”
5. Ask the organiser to ensure sufficient funds are available in Stripe. Do not claim MEL maintains a refund reserve.
6. Initiate the refund through the MEL booking workflow. Never collect card or bank details in support messages.
7. Record the Stripe refund ID, amount, initiating staff/user ID and resulting MEL state.
8. If Stripe rejects or leaves the refund pending, preserve the exact provider state. Do not mark it completed manually.
9. Tell the customer that an approved refund returns to the original payment method. Avoid a fixed arrival promise; timing depends on Stripe, the payment method and the financial institution.

Escalate mismatched amounts, duplicate refunds, wrong connected-account context or missing Stripe object IDs to payments engineering immediately. Stop further refund attempts until the account and idempotency evidence are confirmed.

## Event cancellation procedure

1. Confirm the cancellation is authorised by an organiser who manages the event.
2. Capture the organiser's customer communication and refund decision, subject to applicable consumer rights.
3. Identify all affected paid orders and separate platform-owned purchases.
4. Confirm sufficient connected-account funds and run the approved refund workflow.
5. Monitor failed and pending refunds individually. A cancellation record is not proof that every refund completed.
6. Send state-accurate customer and organiser messages. Never use “all refunds were automatic” without completed refund evidence for every order.

## Dispute and chargeback procedure

1. Verify the Stripe account and PaymentIntent/charge linked to the MEL order.
2. Record the dispute ID, amount, reason, response deadline and current Stripe state.
3. Notify the organiser that the dispute belongs to the payment in their connected Stripe account and that they are responsible for the response.
4. Provide factual MEL records only: event listing, terms shown at purchase, order receipt, ticket delivery, attendance/check-in and relevant messages.
5. Do not alter evidence, speculate about the customer or promise an outcome.
6. Direct the organiser to submit the response through the Stripe path available to their account.
7. Record the final Stripe outcome when reported. MEL support must not represent itself as the decision-maker.

## Stripe payout and account-attention procedure

1. Confirm the organiser is looking at a Stripe payout, not MEL sales reporting or a platform-owned-product payment.
2. Read the state exactly: pending balance, available balance, payout pending, payout paid, restricted or requirement due.
3. Direct the organiser to manage payout timing and bank details in Stripe.
4. For verification, ask only whether the Stripe requirement is visible and whether the organiser can access it. Do not ask them to send identity or bank documents to MEL support.
5. Escalate only MEL display, link, webhook or stale-state defects. Stripe timing, verification and bank settlement remain with Stripe.

## Approved reply snippets

**Where is my money?**
“Ticket payments go to your connected Stripe account. Stripe controls when available funds are sent to your nominated bank account. You can review the balance, payout schedule and bank details in Stripe. MyEventLane cannot release or reschedule a Stripe payout.”

**Refund funding**
“MyEventLane can help process an authorised refund through the booking system. The refunded money comes from your connected Stripe account, so please make sure sufficient funds are available.”

**Stripe needs attention**
“Stripe has reported an account requirement or restriction. Open Stripe to review and complete the requested step. MyEventLane can investigate whether our displayed status is current, but cannot approve Stripe verification.”

**Dispute**
“This dispute is attached to the payment in your connected Stripe account. You are responsible for responding by Stripe's deadline. MyEventLane can help locate accurate booking, ticket and attendance records, but Stripe controls the dispute process and outcome.”

## Required case evidence

Record the environment, order ID, payment gateway, connected account ID in masked form, PaymentIntent/refund/dispute ID, current MEL state, current Stripe state, timestamps, action taken and escalation owner. Never paste secret keys, webhook secrets, full bank details or identity documents into the case.
