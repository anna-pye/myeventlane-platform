# Transactional email formatting audit

Date: 24 August 2026
Scope: MyEventLane emails sent to customers, organisers and platform staff.

## Outcome

The shared MyEventLane email shell is already branded, responsive and applied
centrally. The main inconsistency is the inner message content. Most templates
are plain paragraphs with weak hierarchy and, where action is required, an
unstyled link or no link.

The repository has two content systems that must be governed together:

1. 46 exported `myeventlane_messaging.template.*` configurations.
2. Direct `hook_mail()` implementations in 13 custom modules, including ticket,
   RSVP, Boost, escalation, venue and automation emails.

Changing only the exported templates will not make the complete email journey
consistent.

## Baseline evidence

Before the refund-email slice:

| Check | Templates passing | Templates missing |
|---|---:|---:|
| Meaningful preheader | 13 | 33 |
| `<h1>` message heading | 6 | 40 |
| Presentation-table content structure | 1 | 45 |
| Styled action link | 13 | 33 |

The global wrapper uses a 600px card, responsive width, inline typography and a
consistent brand/footer shell. Those foundations should remain frozen while the
inner templates are repaired in small groups.

## Exported template inventory

| Journey | Templates | Primary recipient | Priority |
|---|---|---|---|
| Refund lifecycle | `refund_requested_buyer`, `refund_requested_vendor`, `refund_approved_buyer`, `refund_approved_vendor`, `refund_rejected_buyer`, `refund_rejected_vendor`, `refund_completed_buyer`, `refund_completed_vendor`, `refund_completed_admin`, `refund_failed_buyer`, `refund_failed_vendor`, `refund_failed_admin` | Customer, organiser, admin | P0-P1 |
| Pro subscription | `pro_subscription_started`, `pro_subscription_renewal_reminder`, `pro_subscription_payment_failed_day_0`, `pro_subscription_payment_failed_day_3`, `pro_subscription_payment_failed_day_6`, `pro_subscription_payment_recovered`, `pro_subscription_payment_update_link`, `pro_cart_abandoned_w1`, `pro_cart_abandoned_w2` | Organiser | P0-P1 |
| Stripe operations | `stripe_account_restricted_vendor`, `stripe_dispute_created_vendor`, `stripe_payout_failed_vendor` | Organiser | P0 |
| Booking and attendance | `assign_tickets_buyer`, `order_confirmation`, `order_invoice`, `order_receipt`, `rsvp_confirmation`, `event_cancelled`, `event_reminder`, `event_reminder_24h`, `event_reminder_2h`, `event_reminder_7d`, `sales_open`, `ticket_tier_waitlist_offer`, `waitlist_invite`, `cart_abandoned` | Customer | P1-P2 |
| Organiser event communication | `vendor_event_cancellation`, `vendor_event_important_change`, `vendor_event_update` | Customer | P1 |
| Organiser operations | `boost_confirmation`, `boost_reminder`, `export_ready_csv`, `export_ready_ics`, `rsvp_vendor_copy` | Organiser | P1-P2 |

## Direct-mail paths outside the catalogue

These paths receive the shared wrapper but own their content in PHP or a
feature-specific Twig template. They need separate acceptance tests:

- `myeventlane_tickets`: ticket-ready email.
- `myeventlane_rsvp`: RSVP confirmation and organiser copy fallbacks.
- `myeventlane_boost`: Boost expiring and expired notices.
- `myeventlane_event_attendees`: waitlist promotion.
- `myeventlane_escalations_portal`: five customer/organiser case updates.
- `myeventlane_escalations_policy`: internal policy-review alert.
- `myeventlane_escalations_sla`: internal SLA-breach alert.
- `myeventlane_venue`: internal venue-issue alert.
- `myeventlane_core`: category digest.
- `myeventlane_automation`: dynamic lifecycle messages.
- `myeventlane_pro`, `myeventlane_launch` and `myeventlane_messaging`: transport
  entry points whose supplied body must also obey the content standard.

## Required content standard

Every external email should have:

1. A useful preheader that adds information rather than repeats the subject.
2. One plain-language `<h1>` describing the outcome or required action.
3. A short opening paragraph that says what happened.
4. A presentation-table summary for dates, amounts, event names and references.
5. One primary action with a specific label and a minimum 44px touch target.
6. Policy, legal and troubleshooting detail after the primary outcome/action.
7. Inline styles and presentation tables so mobile and common email clients do
   not depend on the website CSS pipeline.
8. A text alternative when an action is not possible by link.

Internal admin alerts should keep the same hierarchy, but should not contain
marketing, decorative promotions or vague customer copy.

## Accessibility finding

White text on the standard coral `#f26d5b` has a calculated contrast ratio of
2.95:1, below WCAG AA for normal text. The first refund slice uses the related
darker coral `#c24737` for primary email buttons, which gives white text a
4.94:1 ratio. The shared wrapper and existing templates still need a controlled
colour-token repair rather than a global search-and-replace.

## Promotional-content boundary

Do not add event recommendations or “browse more” links to mandatory refund,
invoice, payment-failure or account-action messages. ACMA guidance says a
factual service email can become a commercial message when it contains
promotional content or links to promotional content. Commercial messages need
consent, accurate sender information and a working unsubscribe facility.

Recommendations should be sent as a separate marketing message only when the
recipient's consent and unsubscribe state are proven by the messaging system.

Official guidance:

- <https://www.acma.gov.au/avoid-sending-spam>
- <https://www.acma.gov.au/publications/2024-08/report/action-scams-spam-and-telemarketing-april-june-2024>

## Safe remediation order

1. Complete and accept the three refund emails already observed in staging:
   buyer request received, organiser review required, buyer refund completed.
2. Complete the remaining refund lifecycle without changing legal, tax or
   connected-account responsibility wording.
3. Repair Pro dunning, payment update, renewal and recovery emails, preserving
   idempotency and transactional delivery rules.
4. Repair Stripe dispute, restriction and payout alerts.
5. Repair booking, RSVP, reminders and waitlist journeys.
6. Repair the direct-mail PHP paths and remove duplicate content ownership where
   a canonical configuration already exists.
7. Add a repository-wide contract test that inventories every external email
   owner and fails when a new ungoverned path is introduced.

## Current implementation slice

This branch changes only:

- `refund_requested_buyer`
- `refund_requested_vendor`
- `refund_completed_buyer`

It adds preheaders, headings, summary cards, accessible action buttons and
purpose-specific routes. It removes the misleading Digital Pass action from the
completed-refund email. It deliberately does not add event recommendations.
