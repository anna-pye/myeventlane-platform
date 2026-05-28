# Help documentation gap analysis

**Audit date:** 2026-05-22

## Existing baseline (do not duplicate)

YAML seeds in `myeventlane_help_centre.help_content.yml` (10 articles):

| Seed key | Title | Audience (canonical) |
|----------|-------|----------------------|
| `how_to_buy_tickets` | How to buy tickets | public |
| `how_to_rsvp` | How to RSVP to a free event | public |
| `how_to_access_tickets` | How to access your tickets | public |
| `how_to_request_refund` | How to request a refund | public |
| `how_to_create_event` | How to create an event | public |
| `choosing_rsvp_or_paid` | Choosing RSVP or paid tickets | public |
| `managing_dashboard` | Managing your event dashboard | public |
| `vendor_dashboard_overview` | Vendor dashboard overview | vendor |
| `refund_policy` | Refund policy | public |
| `community_guidelines` | Community guidelines | public |

**Needs verification:** Which seeds exist as published nodes on staging/production and whether aliases match.

---

## Missing help articles

### Attendee / public

| Proposed title | Audience | Product area | User problem | Source evidence | Priority | Status |
|----------------|----------|--------------|--------------|-----------------|----------|--------|
| Finding events on MyEventLane | public | Discovery | Where to browse, filters, categories | `views.view.upcoming_events.yml` paths `/events`, `/events/free` | P1 | ready to write |
| What to do when checkout fails or payment is declined | public | Checkout | Card errors, abandoned cart | `support_panels.buy_tickets` surfaces `commerce_checkout.form`; `myeventlane_commerce` | P1 | **Markdown draft** — `help-article-drafts/checkout-errors.md` (not published) |
| Creating a MyEventLane account | public | Account | Register and verify email | `/user/register`, `mel-surface-architecture.md` | P1 | ready to write |
| Resetting your password | public | Account | Locked out | Core user routes | P2 | ready to write |
| Joining a waitlist when an event is sold out | public | Booking | Sold-out CTA behaviour | `docs/qa/journeys.md` waitlist CTA table | P1 | **Markdown draft** — `help-article-drafts/waitlist.md` (not published; auto-invite **Needs verification**) |
| Cancelling your RSVP | public | RSVP | Release a free spot | `/rsvp/{rsvp_id}/cancel` in `myeventlane_rsvp.routing.yml` | P2 | ready to write |
| Adding an event to your calendar | public | RSVP/Events | ICS download | `/event/{node}/ics` | P3 | ready to write |
| Using Apple Wallet or Google Wallet for your ticket | public | Tickets | Mobile pass | `myeventlane_wallet.routing.yml` | P2 | needs verification (eligibility rules) |
| Transferring or assigning a ticket to someone else | public | Tickets | Assignee flow | `/ticket/assign/{token}` | P2 | needs verification |
| Understanding ticket confirmation emails and receipts | public | Post-purchase | What arrives after checkout | `docs/phase-6-confirmation-and-receipts.md` (internal reference) | P1 | **Markdown draft** — `help-article-drafts/ticket-confirmation.md` (not published; wallet/calendar **Needs verification**) |
| Contacting support about a booking | public | Support | When and how to open a case | `/my/support`, `/my/support/escalations/add` | P1 | **Markdown draft** — `help-article-drafts/support-contact.md` (not published; overlaps nid **1498** — merge before publish) |
| Accessibility information on event pages | public | Events | What hosts can publish; attendee needs | `docs/qa/journeys.md` accessibility section | P2 | needs verification (field names on event) |
| What happens when an event is cancelled | public | Refunds/Events | Refunds, notifications | Event state `Cancelled` in journeys.md; refunds module | P1 | needs verification |

### Organiser / vendor (public or vendor audience)

| Proposed title | Audience | Product area | User problem | Source evidence | Priority | Status |
|----------------|----------|--------------|--------------|-----------------|----------|--------|
| Setting up ticket types and pricing | vendor | Tickets | Configure paid tickets | `/vendor/events/{event}/studio/tickets`, tickets module routes | P1 | ready to write |
| Connecting Stripe to receive payouts | vendor | Payments | Connect onboarding | `/stripe/connect`, `/vendor/onboard/stripe`; `docs/audits/mel-stripe-connect-audit.md` | P1 | **Markdown draft** — `help-article-drafts/stripe-payouts.md` (not published; overlaps nid **1510** — merge before publish) |
| When you can start selling paid tickets | vendor | Payments | charges_enabled gating | Stripe safety rules / vendor module — **do not invent thresholds** | P1 | needs verification |
| Managing attendees and orders for your event | vendor | Operations | Lists, export, door | `/vendor/events/{node}/attendees`, attendees module | P1 | ready to write |
| Checking in guests with QR scan | vendor | Check-in | Door operations | RSVP/tickets check-in routes | P2 | code evidence only — browser verification needed |
| Handling refund requests from attendees | vendor | Refunds | Approve/reject | `/vendor/events/{node}/refund-requests` | P1 | ready to write |
| Publishing, unpublishing, and event visibility | vendor | Event Studio | Draft vs live | Event states in journeys.md; studio publish routes | P1 | ready to write |
| Setting capacity and waitlists | vendor | Capacity | Limits | Wizard step 4 in journeys.md; capacity module | P2 | needs verification |
| Promoting your event with Boost | vendor | Marketing | Paid promotion | `myeventlane_boost` routes | P3 | needs verification |
| Checkout questions and collecting attendee details | vendor | Checkout | Custom questions | `/vendor/event/{event}/checkout-questions` | P2 | ready to write |
| Ticket access codes and hidden ticket types | vendor | Tickets | Access codes routes | tickets module access-code routes | P3 | code evidence only |
| Embedding a ticket widget on your website | vendor | Tickets | Purchase surfaces | tickets widgets routes | P3 | code evidence only |

### Policies and trust (extend seeds)

| Proposed title | Audience | Product area | User problem | Source evidence | Priority | Status |
|----------------|----------|--------------|--------------|-----------------|----------|--------|
| Privacy and how we use your data | public | Legal | Privacy expectations | `/privacy` redirect; `myeventlane_privacy`, `myeventlane_legal` modules | P2 | needs verification (canonical policy node) |
| Public trust and safety reporting | public | Trust | Report issues | `myeventlane_public_trust` route (see support-route audit) | P3 | needs verification |

### Not recommended as public help_article

| Topic | Reason |
|-------|--------|
| Staff escalation playbooks | Use `staff_playbook` only |
| Internal support procedures | `support_procedure` bundle — staff playbooks gap file |
| Communication snippet authoring | Route requires `administer escalations` |

---

## Coverage summary

| Area | Seeded | Gap count (proposed) |
|------|--------|----------------------|
| Attendee booking/RSVP | 3 | 10+ |
| Organiser/vendor ops | 4 | 12+ |
| Policies | 2 | 2 |
| Checkout/payments | 0 dedicated | 3 |
| Support contact | 0 dedicated | 1 |

## Editorial workflow reminders

Before marking **ready to write**:

1. Set `field_audience` (canonical list, not taxonomy alone).
2. Set `field_help_ai_allowed` if article should appear in Help Assistant.
3. Set `field_help_status` to `published` or `approved`.
4. Reindex `mel_content` after publish batch.
