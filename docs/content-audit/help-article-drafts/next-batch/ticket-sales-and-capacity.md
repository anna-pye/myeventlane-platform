---
title: Ticket sales and capacity
audience: vendor
article_type: guide
product_area: tickets
help_status: draft
ai_allowed: true
recommended_alias: /help/vendors/ticket-sales-and-capacity
source_evidence: mel_ticket_type.capacity; TicketCapacityService; TicketVariationSoldService; TicketTierAnalyticsService; vendor event overview and Pro analytics; Event Studio / ticket workspace tier capacity
needs_verification: Whether refunds change completed-order sold counts; lowering capacity below tickets already sold; event-level capacity fields in use for a given event
---

# Ticket sales and capacity

Capacity tells you how many tickets you can sell for each ticket type. This page explains how sales counts relate to availability, your dashboard, and waitlists.

## What this means

Each paid ticket type may have a **capacity** (maximum tickets for that type). MyEventLane tracks **tickets sold** from **completed** ticket orders and compares that to capacity to decide when a type is sold out.

Some events also set an **overall event capacity** (separate fields on the event). That limit may apply across RSVPs and paid tickets together at checkout — it is not the same as adding up each ticket type’s capacity. **Needs verification** for your event setup.

Free RSVP events use RSVP limits and waitlist settings, not paid ticket type capacity.

## What to do next

1. Sign in to your organiser dashboard or Event Studio.
2. Open the event and go to **Tickets** (ticket workspace) or the tickets section in Event Studio.
3. Set or review **capacity** for each paid ticket type you sell.
4. Optionally set a **limit per order** on a ticket type if you want to cap how many tickets one buyer can purchase in a single order (when that field is available).
5. Publish or update ticket types only when pricing and Stripe connection (for paid events) are ready.
6. Monitor sales from your **event overview** (**Tickets sold**, **Gross sales**) or, if you have Pro analytics, the **Ticket tiers snapshot** on the analytics tab.
7. If a type sells out and waitlist is enabled on that type, buyers may see **Join waitlist** on the public book page instead of purchasing — see *Managing waitlists for your event* and the attendee article *Joining a waitlist*.

## Good to know

- **Tickets sold** on dashboard and overview figures generally means quantities on **completed** Commerce orders for your event, not draft or unpaid carts.
- **Sold out** for a paid ticket type means completed sales have reached that type’s capacity. The book page may also reserve places for active waitlist offers, so buyer-facing “only X left” can differ slightly from organiser **Remaining** on Pro analytics (analytics remaining is based on sold counts and may not subtract waitlist holds).
- Capacity is enforced **per ticket type**. If you have a venue maximum, plan your type capacities (and any event-level capacity field) so they work together — do not assume the system enforces one combined cap across all types unless you have configured and tested that.
- **Refunds and cancellations** are shown in sales summaries where applicable, but they **may not** reduce **tickets sold** unless the underlying order is no longer treated as completed — check your event’s orders before assuming capacity has reopened.
- Active waitlist offers can temporarily hold capacity until they expire or are purchased. That can make availability look tighter on the book page for a short time.
- Lowering capacity below tickets already sold **may be blocked or need support** — treat as **Needs verification** before you change caps near event day.

## Related help

- Managing waitlists for your event
- Payouts and fees
- Choosing RSVP or paid tickets
- Managing your event dashboard
