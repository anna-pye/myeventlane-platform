# Top 20 priority help articles

**Audit date:** 2026-05-22  
Ranked for first production writing pass. Items marked **(seeded)** already exist in `myeventlane_help_centre.help_content.yml` — expand/review, don’t blindly duplicate.

| Rank | Title | Audience | Product area | Seeded? | Source evidence | Action |
|------|-------|----------|--------------|---------|-----------------|--------|
| 1 | How to buy tickets | public | Booking | Yes | `how_to_buy_tickets` seed; `/event/{node}/book` | Review + verify checkout steps on staging |
| 2 | How to RSVP to a free event | public | RSVP | Yes | `how_to_rsvp`; `/event/{event}/rsvp` | Review + add cancel/calendar links |
| 3 | How to access your tickets | public | Tickets | Yes | `how_to_access_tickets`; `/my-tickets` | Review + wallet/assign if applicable |
| 4 | How to request a refund | public | Refunds | Yes | `how_to_request_refund`; refund routes | Review + link My Tickets path |
| 5 | Refund policy | public | Policies | Yes | `refund_policy`; `/help/policies` | Legal review |
| 6 | Finding events on MyEventLane | public | Discovery | No | `upcoming_events` `/events` | **Write** |
| 7 | Creating a MyEventLane account | public | Account | No | `/user/register` | **Write** |
| 8 | Ticket confirmation emails and receipts | public | Post-purchase | No | checkout flow + phase-6 docs | **Drafted** — `help-article-drafts/ticket-confirmation.md` |
| 9 | Contacting support about a booking | public | Support | Partial (nid 1498) | `/my/support` | **Drafted** — `help-article-drafts/support-contact.md` (merge with 1498) |
| 10 | What to do when checkout fails | public | Checkout | No | checkout contextual panel | **Drafted** — `help-article-drafts/checkout-errors.md` |
| 11 | How to create an event | public | Organiser | Yes | `how_to_create_event`; Event Studio | Review for Studio vs wizard |
| 12 | Choosing RSVP or paid tickets | vendor/public | Tickets | Yes | `choosing_rsvp_or_paid` | Review audience tags |
| 13 | Setting up ticket types and pricing | vendor | Tickets | No | Studio tickets; vendor ticket routes | **Write** |
| 14 | Connecting Stripe to receive payouts | vendor | Payments | Partial (nid 1510) | `/stripe/connect`, Stripe audit doc | **Drafted** — `help-article-drafts/stripe-payouts.md` (merge with 1510) |
| 15 | Managing your event dashboard | public | Organiser | Yes | `managing_dashboard`; `/dashboard` | Review |
| 16 | Managing attendees and orders | vendor | Operations | No | `/vendor/events/{node}/attendees` | **Write** |
| 17 | Handling refund requests from attendees | vendor | Refunds | No | vendor refund-requests routes | **Write** |
| 18 | Publishing and event visibility | vendor | Event Studio | No | publish routes; journeys.md states | **Write** |
| 19 | Vendor dashboard overview | vendor | Getting started | Yes | `vendor_dashboard_overview` | Review |
| 20 | Accessibility on your event listing | public | Events | No | journeys.md accessibility section | **Write** — needs verification |

## Honourable mentions (21–25)

| Title | Audience | Why next |
|-------|----------|----------|
| Community guidelines | public | Seeded — policy review |
| Joining a waitlist when sold out | public | **Drafted** — `help-article-drafts/waitlist.md` |
| Cancelling your RSVP | public | Route exists |
| Checking in guests at the door | vendor | Event-day ops |
| When you can start selling paid tickets | vendor | Stripe gating — must match live rules |

## Publishing checklist (all 20)

1. `field_audience` correct (canonical list).
2. `field_help_status`: `published` or `approved`.
3. `field_help_ai_allowed`: ON for Assistant-eligible public/vendor articles.
4. Path alias under `/help/...` consistent with hub IA.
5. `drush search-api:index mel_content` (or project equivalent) after batch publish.

## What not to put in this list

- `staff_playbook` topics (separate backlog).
- Internal `support_procedure` articles without staff access review.
- Features marked **Needs verification** until staging QA confirms UI labels and gates.
