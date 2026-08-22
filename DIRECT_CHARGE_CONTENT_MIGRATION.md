# Stage 14 — Direct-charge content migration report

**Status:** Repository and staging content GO; production activation remains NO-GO  
**Evidence date:** 22 August 2026  
**Architecture:** Organiser-as-seller Stripe Connect direct charges  
**Scope:** Customer, organiser, staff and transactional payment wording

## Decision summary

Stage 14 content blockers are cleared for the current repository and staging
release. Critical content now agrees with the approved model:

> Customer → organiser connected Stripe account → MEL application fee

The organiser is the seller. Stripe processes ticket payments in the
organiser's connected account and controls its balance, processing fees,
verification, disputes and payouts. MyEventLane provides the marketplace,
booking, fee and refund workflows. It does not hold or manually release
organiser ticket revenue.

This is not production activation approval. Direct-charge mode and the
`stripe_connect` Commerce gateway remain disabled. Production managed-content,
live-key, real-payment and production webhook evidence are still required by
ADR-004 before activation.

## Evidence boundary

This report distinguishes four evidence layers:

1. **Repository:** source, configuration, templates, tests and static rescan.
2. **Staging:** managed Help/legal content, exact deployed revision and rendered
   HTTP availability.
3. **Stripe sandbox:** connected-account webhook delivery and queue handling.
4. **Production:** not claimed by this report.

The owner approved the customer terms, refund position, seller identity,
dispute responsibility and support procedure during Stage 14. The repository
records that owner approval. It is not evidence of independent legal advice.

## 1. Current content inventory

| Source or surface | Audience | Role | Classification | Owner | Result |
|---|---|---|---|---|---|
| `DirectChargeCopy` | Customer and organiser | Canonical payment, refund and payout wording | KEEP | Product | Authoritative short-form policy source |
| ADR-004 | Product, engineering and operations | Architecture and activation gates | KEEP | Product and engineering | Accepted target; activation remains guarded |
| Customer Terms and Organiser Agreement | Customer and organiser | Seller, payment, refund and dispute responsibility | REWRITE + LEGAL REVIEW | Product/legal | Migrated and owner-approved; staged managed pages match source apart from the generated date |
| Help Centre governed content | Customer and organiser | Payment, payout, fee, refund and dispute guidance | REWRITE | Product/support | Sixteen required articles match active staging configuration |
| `/vendor/payments` and `/vendor/payouts` | Organiser | Payment health, revenue, Stripe balances and payout history | RENAME | Product | Visible terminology identifies Stripe payouts |
| Stripe onboarding and reconnect flows | Organiser | Connected-account setup and migration guidance | REWRITE | Product/support | Direct-charge and non-destructive reconnect wording deployed |
| Refund workflow and customer booking view | Customer and organiser | Refund request, approval, funding and status | REPLACE | Product/support | Connected-account funding is explicit; customer refund action restored and staging-accepted |
| Direct-charge transactional templates | Customer and organiser | Confirmation, refund, dispute, restriction and payout-failure messages | REWRITE + REPLACE | Product/support | Direct-charge responsibility model deployed |
| Direct-charge support playbook | Staff | Refund, dispute, payout and restriction procedures | SUPPORT REVIEW | Support owner | Approved 20 August 2026 |
| Legacy MEL payout actions | Organiser and staff | Destination-charge transfer workflow | REMOVE | Product/engineering | Guarded from use when direct-charge mode is enabled; history remains read-only |
| Historical ADR-002, ADR-003 and audit records | Staff and engineering | Historical architecture evidence | KEEP | Engineering | Clearly superseded; not current implementation guidance |

## 2. Incorrect or misleading wording resolved

| Previous concept | Problem | Migration action | Current meaning |
|---|---|---|---|
| MEL pays or releases organisers | Implied MEL owned or held ticket proceeds | REWRITE / REMOVE | Stripe sends available organiser funds to the organiser's bank |
| MEL payout or pending MEL payout | Confused the legacy transfer ledger with Stripe payouts | RENAME | Stripe payout, Stripe balance or organiser revenue reporting |
| Withdraw MEL balance | Described a workflow that does not exist in direct-charge mode | REMOVE | Manage balance, schedule and bank details in Stripe |
| Fees deducted from an organiser payout | Misstated when and how MEL collects its fee | REWRITE | The MEL application fee is applied when the ticket charge is processed |
| One combined “fee” | Conflated MEL and Stripe charges | REWRITE | MEL platform fee and Stripe processing fee are separate |
| MEL funds refunds | Assigned the refund liability to the platform | REWRITE | The organiser authorises and funds refunds from the connected account |
| MEL controls disputes | Misstated the connected-account responsibility boundary | REWRITE | The organiser responds; Stripe and the card network control the process and outcome |
| Generic payout page | Did not identify the provider or distinguish reporting | RENAME | Stripe payouts with separate revenue and refund reporting |

Remaining static references to old terms are limited to historical architecture
records, internal identifiers, guards or explicit instructions that staff must
not make the old promise. They are not active customer-facing claims.

## 3. Product surfaces affected

The migrated distribution surfaces are:

- customer Terms, Organiser Agreement, pricing and refund guidance;
- customer checkout/confirmation and booking refund actions;
- organiser Stripe onboarding, reconnect, paid-publish gate and payment health;
- Payments Workspace, Stripe payout view, dashboard cards, warnings and empty
  states;
- refund request and approval forms;
- Event Studio payment and refund guidance;
- Help Centre organiser and customer articles;
- support panels and staff payment procedures; and
- order, refund, dispute, account-restriction, payout-failure and cancellation
  emails.

Internal route, class and database names may retain `payout` where they refer to
Stripe data or historical audit records. Visible labels must identify Stripe or
revenue reporting accurately.

## 4. Help articles affected

The staging-managed Help Centre contains and matches one governed seed for each
required subject:

1. How ticket payments work
2. Connecting Stripe
3. When will I receive my ticket money?
4. Understanding Stripe payouts
5. MEL fees and Stripe fees
6. How refunds work for organisers
7. What happens if I cancel an event?
8. Payment disputes and chargebacks
9. Why Stripe may hold or delay a payment
10. Why my Stripe account needs attention
11. Updating my bank account
12. Reconciling ticket sales with Stripe
13. What MEL can and cannot do with my Stripe account
14. How to request a refund
15. Refunds on MyEventLane
16. Trust and safety

The former `/help/payouts-and-fees` article is migrated by stable seed or alias
to the governed payment explanation instead of creating a duplicate.

## 5. Staff documentation affected

`docs/support/direct-charge-payments-playbook.md` is the approved staff
procedure. It separates:

- MEL order, ticket, fee, webhook, payment-record and refund-workflow checks;
- Stripe verification, processing fees, balance, payout, settlement and dispute
  control;
- organiser refund authorisation and funding responsibility;
- evidence that support may provide for a dispute; and
- the non-destructive existing-account reconnect procedure.

Staff must not promise to release funds, alter Stripe payout timing, change bank
details, remove a Stripe restriction or decide a dispute.

## 6. Transactional emails affected

| Template or event | Required meaning | Result |
|---|---|---|
| Order confirmation | Identifies the organiser as seller and connected-account processing | Migrated |
| Refund requested/approved/completed/failed | States organiser responsibility, connected-account funding and exact outcome | Migrated |
| Event cancellation | Does not promise automatic refunds; states connected-account funding | Migrated |
| New Stripe dispute | Directs the organiser to respond; MEL supplies records only | Migrated and owner-approved |
| Stripe account becomes restricted | Sent only on a transition into restriction; directs the organiser to Stripe | Migrated; duplicate reason-change alert fixed |
| Stripe payout failed | Identifies a Stripe payout and Stripe-controlled remediation | Migrated and owner-approved |

Routine successful-payout emails are intentionally not added. Stripe remains
the authoritative payout interface and already communicates payout state.

## 7. Terminology replacement map

| Avoid | Use |
|---|---|
| MEL payout | Stripe payout |
| MEL balance | Stripe balance, or organiser revenue where it is MEL reporting |
| MEL pays you | Stripe sends available funds to your nominated bank account |
| Withdraw funds | Manage payouts in Stripe |
| MEL releases funds | Stripe controls payout timing and bank settlement |
| Ticket funds held by MEL | Ticket revenue in your connected Stripe account |
| Payout request | Stripe payout schedule or payout status |
| Booking fee | MEL platform fee, where that is the actual configured adjustment |
| Payment fee | MEL platform fee or Stripe processing fee, stated separately |
| Vendor payout | Stripe payout for the organiser's connected account |

## 8. Canonical payment explanation

> Ticket payments are processed through your connected Stripe account. Your
> ticket revenue belongs to you and is managed through Stripe. Stripe sends
> available funds to your nominated bank account according to your Stripe
> payout schedule. MyEventLane does not hold or manually release your
> ticket-sale funds.

The short variants in the product must retain the same seller, account and fund
ownership meaning.

## 9. Canonical refund explanation

> You remain responsible for refunds for your event. MyEventLane can help you
> process a refund through the booking system, but the refunded money comes
> from your connected Stripe account. Make sure sufficient funds are available
> to cover refunds, disputes or event cancellations.

The approved implementation returns the MEL application fee in full for a full
customer refund and proportionally for a partial customer refund. It must not
be applied to platform-owned products.

## 10. Canonical Stripe payout explanation

> Stripe controls when available funds are sent from your Stripe account to
> your nominated bank account. You can view your Stripe balance and manage your
> payout schedule and bank details in Stripe. MyEventLane can show payment
> information reported by Stripe, but cannot release a payout or change
> Stripe's payout timing.

## 11. Legal-review items

The owner approved the customer terms, refund position, seller identity and
dispute responsibility for the migration. The staged `/terms` and
`/vendor-terms` pages contain those approved meanings and preserve rights that
cannot be excluded under Australian Consumer Law.

Residual legal note:

- the repository records owner approval, not independent legal advice;
- the general liability paragraph in the customer Terms still identifies its
  final liability wording as requiring legal review; and
- that residual note does not change or contradict the implemented seller,
  payment, refund or dispute model.

Independent legal review may therefore remain a broader launch governance item.
It is not being represented as completed by this report.

## 12. Implementation priorities and result

| Priority | Item | Result |
|---|---|---|
| P0 | Remove claims that MEL holds or releases organiser ticket revenue | Complete in repository and staging managed content |
| P0 | Align seller, refund and dispute responsibility with direct-charge runtime | Complete in repository and staging wording |
| P0 | Prevent legacy payout execution for direct-charge orders | Implemented behind guarded activation |
| P1 | Separate MEL platform fees from Stripe processing fees | Complete; initial MEL fee is 1.5% including GST with no fixed fee and remains configurable |
| P1 | Migrate Help Centre without duplicate legacy articles | Complete on staging |
| P1 | Migrate critical transactional emails and make acceptance durable | Complete on staging; signed queue probes passed |
| P1 | Reconnect incompatible existing accounts | In progress; each organiser must complete Stripe-hosted onboarding |
| P1 | Prove test-mode checkout, refund, failure and invoice lifecycle | Partially evidenced; retain as activation gate until one complete recorded lifecycle is attached |
| P0 production | Rescan production managed content and verify live responsibility configuration | Not performed; production activation remains NO-GO |

## 13. Content acceptance criteria

| Criterion | Repository/staging result |
|---|---|
| No active organiser surface claims MEL holds ticket revenue | PASS |
| No active organiser surface claims MEL controls Stripe payouts | PASS |
| Stripe payout terminology is explicit | PASS |
| Organiser and customer refund responsibility is clear | PASS |
| MEL and Stripe fees are distinguished | PASS |
| Support procedure matches the target runtime | PASS |
| Critical transactional templates match the target runtime | PASS |
| Required Help Centre subjects exist without a parallel legacy duplicate | PASS |
| Customer wording preserves Australian Consumer Law | PASS |
| One canonical payment/refund/payout source is established | PASS |
| Automated content contracts cover critical hard-coded wording | PASS |
| Repository and staging managed content were rescanned | PASS |
| Production managed content was rescanned | NOT RUN |
| Production direct-charge runtime and provider responsibility were verified | NOT RUN |

## Implementation and deployment evidence

| Change | Merge revision | Evidence |
|---|---|---|
| PR #841 — reconnect redirect override | `12e4b70bdfa2326b0c30bbe50044294706549d86` | Owner confirmed cross-browser Stripe redirect acceptance |
| PR #843 — Help content convergence | `ea6bbcf6a2ad57edccd22ce7c8f461b0aa471d99` | Required managed Help seeds converged on staging |
| PR #844 — refund and operational alert gaps | `9559c0ceb73c74d48e9c398a71621b141a9b8a3b` | Customer refund action and direct-charge alert templates/queue path restored |
| PR #846 — nullable webhook status | `4c3c1180dcb85d82a0146e8f279524c825381e4c` | Failed signed probe rows reprocessed successfully |
| PR #847 — restriction transition | `d4ea865b0fab33fd3d230f69c45769b86a19ffca` | Reason-to-reason restriction change no longer queues a duplicate alert |

The current staging release is `/home/mel/staging/releases/20260822112627`
from artifact revision `d4ea865b0fab33fd3d230f69c45769b86a19ffca`
(Deploy Staging run `32570200039`). Drupal 11.4.5 bootstraps, the database
requires no updates and the public staging homepage returned HTTP 200.

## Post-implementation rescan and webhook acceptance

Staging managed-content audit:

- all 16 required Help article seeds matched active config, title, body, alias
  and published state;
- `/terms` and `/vendor-terms` matched the deployed definitions after ignoring
  only the generated “Last updated” date;
- 68 published Help/basic pages were checked; and
- no prohibited high-risk legacy phrase was found.

Staging Stripe sandbox acceptance:

- signed `account.updated` ledger rows 248 and 249 processed successfully after
  PR #846 and were skipped as non-restrictions;
- one over-broad restriction alert discovered during acceptance was suppressed
  before delivery with zero attempts and no provider message ID;
- PR #847 corrected the transition test so a restriction-reason change while
  already restricted does not create a second alert;
- post-deploy signed rows 252 and 253 were accepted, both queue jobs completed,
  both were skipped as non-restrictions and no organiser message was created;
- the superseded sandbox webhook endpoint is disabled;
- the replacement sandbox Connect endpoint remains enabled for the seven
  approved event types; and
- the `stripe_connect` gateway and direct-charge activation remain disabled.

No live keys, live charge, live refund or production webhook were used.

## Unresolved activation items

These are no longer Stage 14 repository/staging content contradictions. They
remain overall direct-charge activation gates:

1. Rescan production managed Help, legal, configurable email and support
   content after the production release.
2. Verify production connected-account Dashboard, Stripe-fee billing and
   negative-balance responsibility against the approved configuration.
3. Complete and record the remaining existing-account reconnections. Sandbox
   payout ineligibility is expected and is not itself a blocker.
4. Attach one end-to-end test-mode charge, return, asynchronous webhook, failed
   payment, full refund, supplier invoice/receipt and replay record to the
   release evidence.
5. Verify the configured 1.5% GST-inclusive, zero-fixed MEL fee reconciles from
   rendered order adjustment to Stripe `application_fee_amount` in the target
   environment.
6. Obtain production release, secret, provider-destination, cron/queue and
   rendered acceptance evidence before enabling direct charges.

## GO / NO-GO conclusion

**Stage 14 content migration: GO for the current repository and staging
environment.** No P0/P1 customer, organiser, staff or transactional wording
contradiction was found in the verified scope.

**Overall production direct-charge migration: NO-GO until the unresolved
activation items above are evidenced.** This report does not authorise live
keys, production charges or enabling the direct-charge switch.
