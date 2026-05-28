# Organiser ticket sales, capacity, and waitlist — QA verification log

**Date:** 2026-05-22  
**Branch:** `feature/help-verify-organiser-ticket-capacity`  
**Scope:** `ticket-sales-and-capacity.md`, `organiser-manage-waitlists.md`  
**Environment:** DDEV local (`https://myeventlane.ddev.site`) — code audit + targeted Drush/PHP checks; no browser E2E, no node import, no publish.

## Test data (local)

| Item | Value |
|------|--------|
| Paid test event | **1666** — [MEL TEST] Event 8 - Paid |
| Tier (General admission) | **245** — capacity 50, `waitlist_enabled=0` |
| Commerce variation | **4292** |
| Sold (completed orders) | **0** (no order items for this variation) |
| `TicketCapacityService::getRemaining` | **50** |
| Legacy waitlist sample | Entry **1** on event **1584**, tier **95** — `status=offered`, `offer_reserved=1`; tier sold **2**, held **1**, remaining pool **47**, evaluator status **active** (not sold out) |
| Tier waitlist table | `mel_ticket_waitlist_entry` — 1 row (`offered`) |

**Routes referenced (code):**

- Organiser dashboard / event list: `myeventlane_vendor.console.dashboard`, Studio navigator cards
- Event overview KPIs: `myeventlane_vendor.console.event_overview` — labels **Gross sales**, **Tickets sold**, **Across all ticket types**
- Event analytics (Pro): `myeventlane_vendor.console.event_analytics` — **Ticket tiers snapshot**: Tickets sold (tiers), Remaining, Active / sold out
- Ticket workspace: `myeventlane_vendor.console.event_tickets` (`EventTicketManagerForm`)
- Event Studio tickets: Studio tier cards (capacity per tier; no waitlist fields in JS payload)
- Public book: `myeventlane_commerce.event_book` — `TicketSelectionForm`
- Paid waitlist claim: `myeventlane_commerce.event_ticket_waitlist_claim` — `/event/{node}/book/waitlist/{token}`
- **RSVP waitlist (organiser UI):** `myeventlane_event_attendees.waitlist_manage` — `/vendor/event/{node}/waitlist`
- RSVP waitlist export: `myeventlane_event_attendees.waitlist_export`

## Ticket sales and capacity findings

### Per-tier capacity (verified in code)

- Capacity lives on **`mel_ticket_type.capacity`** (ticket type / tier).
- **Sold count:** `TicketVariationSoldService` — sums **Commerce order item quantities** where order state is **`completed`**, scoped by `field_target_event` + `purchased_entity` (variation).
- **Sold-out (tier):** `TicketStatusEvaluator` — paid tier with finite capacity and `sold >= capacity` → `sold_out`.
- **Public remaining pool (booking):** `TicketCapacityService::getRemaining` = `capacity - sold - held`, where **held** = sum of `offer_reserved` on active tier waitlist offers (`STATUS_OFFERED`, not expired).
- **Organiser analytics remaining:** `TicketTierAnalyticsService::buildTierMetrics` — `remaining = capacity - sold` only (**does not subtract waitlist holds**). Event rollup sums tier remainings; mixed unlimited/finite tiers → display strings like **Tickets available**, **No limit**, or a numeric sum.

### Event-level / venue capacity (separate, partial)

- `myeventlane_capacity` `EventCapacityService` uses **`field_event_capacity_total`** or **`field_event_capacity`** on the **event** node.
- Counts RSVPs + paid tickets together for event-level sold; enforced at checkout via `TicketAvailabilityService` + `TicketCapacityOrderSubscriber` (per-event lock).
- **Not verified:** combined venue cap across all ticket types in organiser copy — tier caps and event caps are **separate mechanisms**; do not promise a single combined venue number unless both are set and tested for the event.

### Per-order limit (may apply)

- Commerce variation **`field_limit_per_order`** exposed in **Ticket workspace** (`EventTicketManagerForm`) and Event Studio tier “more” fields — optional per ticket type.

### Dashboard counts (verified in code)

| Surface | Label(s) | Source |
|---------|----------|--------|
| Studio / dashboard event cards | **Tickets**, **RSVPs**, **Revenue** | `TicketSalesService::getSalesSummary` (`tickets_sold`), `RsvpStatsService` |
| Event overview | **Tickets sold** / **RSVPs**, **Gross sales** | `MetricsAggregator` → `TicketSalesService` (completed orders only) |
| Pro analytics | **Tickets sold (tiers)**, **Remaining**, **Active / sold out** | `TicketTierAnalyticsService::buildEventTierRollup` |
| Vendor dashboard KPIs | **Tickets Sold** | Completed-order ticket quantities (same family as above) |

**Note:** Dashboard **tickets sold** aligns with **completed-order** logic, not waitlist holds. **Remaining** on analytics may be **higher** than buyer-facing availability when active waitlist offers hold seats.

### Refund / cancel impact on sold count

| Question | Result |
|----------|--------|
| Do refunds reduce **tickets sold** on dashboard? | **Unknown / likely no** unless order leaves `completed` state. `TicketVariationSoldService` only counts `completed`. `TicketSalesService` tracks refund **amounts** separately via `myeventlane_refund_log`; docblock says refunds excluded from revenue tallies but sold count still driven by completed line items. |
| Does refund free tier capacity automatically? | **Not verified** in this pass. Auto-offer cron runs when tier returns to **active** (not sold out); refund→capacity path not exercised locally. |

### Manual capacity changes

- Event Studio and ticket workspace save **tier capacity** via `TicketTierLifecycleService`.
- **Lowering capacity below sold:** no explicit validation found in lifecycle service — treat as **Needs verification** / may require support.

## Sold-out behaviour

- **Book page:** sold-out paid tier with `waitlist_enabled` → **Sold out — join a waitlist** block (`TicketSelectionForm`) with email + quantity + **Join waitlist**.
- **Without waitlist:** tier hidden from purchasable list when sold out (waitlist section only lists eligible tiers).
- **Buyer availability copy:** uses `capacity - sold - held` (“Only N left”, etc.) via `CustomerTicketTierDisplayBuilder`.

## Waitlist settings findings

### Paid ticket tier waitlist (Commerce / `mel_ticket_type`)

| Setting | Entity field | Label (entity definition) |
|---------|--------------|---------------------------|
| Enable | `waitlist_enabled` | **Waitlist when sold out** |
| Waitlist cap | `waitlist_capacity` | **Waitlist capacity** |
| Auto-offer | `auto_promote_waitlist` | **Auto-offer waitlist when tickets free up** |

**Join rules (code):** paid tier only; finite capacity; tier **sold out**; email + quantity stored on **`mel_ticket_waitlist_entry`** (`waiting` → `offered` → accepted/expired).

**Organiser UI gap (critical):** Event Studio tier cards (`mel-event-studio.js` `buildDraftTierFromCard`) send **title, kind, capacity, price** only — **no waitlist fields**. Ticket workspace form has capacity / limit per order on **variations**, not tier waitlist toggles. Enabling paid waitlist today likely requires **non–Event Studio paths** (e.g. data migration, admin entity edit) — **not verified in organiser self-service UI**.

**Organiser list/export for paid tier waitlist:** **No route found** — `/vendor/event/{node}/waitlist` is **RSVP only** (`AttendanceWaitlistManager` / `event_attendee` waitlist status).

### RSVP waitlist (separate product path)

| Item | Evidence |
|------|----------|
| Event fields | `field_waitlist_capacity`, `field_waitlist_enabled` (schema / wizard) |
| Organiser UI | `/vendor/event/{node}/waitlist` — name, email, position, status; CSV export |
| Public signup | `/event/{node}/waitlist/signup` |
| Auto-promote | `myeventlane_rsvp` config `auto_promote` (RSVP module settings) — separate from tier `auto_promote_waitlist` |

## Auto-offer / claim findings

| Item | Status |
|------|--------|
| Auto-offer | `TicketTierWaitlistService::processAutoPromotions()` — requires `auto_promote_waitlist` on tier and tier status **active** (capacity available); creates **offered** entry, reserves quantity, queues **`mel_ticket_waitlist_offer_mail`** |
| Offer TTL | **172800** seconds (48h) in service constant |
| Claim | `TicketWaitlistClaimController` — token URL sets session claim, adds to cart, redirects to book flow |
| Cron | `myeventlane_commerce` cron calls `runCronMaintenance()` on tier waitlist service |
| Staging email content | **Not verified** in this pass (Mailpit not exercised) |

## Access control findings

| Surface | Rule |
|---------|------|
| Vendor console | `VendorConsoleBaseController::assertEventOwnership` — event owner, **field_event_vendor** team users, or **administer nodes** |
| Waitlist manage `/vendor/event/{node}/waitlist` | Same pattern in `WaitlistManagementController::access` |
| Event analytics (tier rollup) | **Pro subscription required** (`VendorEventAnalyticsController`) |
| Anonymous / other vendors | **Forbidden** on ownership routes (kernel tests exist for console access) |

## Article readiness

| Draft | Ready to publish? | Ready to export? | Blockers |
|-------|-------------------|------------------|----------|
| `ticket-sales-and-capacity.md` | **No** | **Yes** (after draft wording pass) | Refund→sold count unverified; manual capacity-down unverified; analytics vs book **remaining** mismatch must stay in copy |
| `organiser-manage-waitlists.md` | **No** | **No** | Paid-tier organiser UI/list not verified; `/vendor/event/.../waitlist` is RSVP-only; tier waitlist toggles not in Event Studio; auto-offer not browser-tested |

## Wording constraints (for Help Assistant)

**Ticket sales and capacity**

- Say **completed orders** drive **tickets sold** on dashboard/overview/analytics.
- Use **may** for refunds restoring availability — do not state refunds always free capacity or reduce sold counts.
- Per-tier capacity is enforced separately; event-level caps (**field_event_capacity_total** / **field_capacity**) may also apply — do not claim one combined venue cap across types unless configured and tested.
- **Remaining** on organiser analytics may not match the book page when waitlist offers hold seats.
- Do not promise exact real-time counts during active checkout peaks.

**Managing waitlists**

- **Clearly separate** RSVP waitlist (`/vendor/event/{event}/waitlist`, attendee names) vs **paid ticket waitlist** (book page join, email offers, no organiser list route found).
- Do not instruct organisers to use the waitlist page for **paid** tier entries unless product adds that UI.
- Auto-offer: only when **Auto-offer waitlist when tickets free up** is enabled on the ticket type — **may** send time-limited email; buyers must complete checkout.
- Do not duplicate steps from published **Joining a waitlist** (attendee article) — cross-link only.
- Treat waitlist emails/exports as **personal data**.

## Remaining blockers

1. Browser QA: sold-out paid event with `waitlist_enabled` → Join waitlist → offer email → claim link (Mailpit).
2. Product/doc alignment: organiser-facing controls and reporting for **`mel_ticket_waitlist_entry`**.
3. Refund scenario: complete order → refund → observe sold count and tier remaining on dashboard vs book page.
4. Confirm whether lowering tier capacity below sold is blocked in UI.

## YAML batch 04 recommendation

- **Include:** `ticket_sales_and_capacity` only, after draft tighten — **conditional yes** for `help-articles-batch-04-2026-05.yml`.
- **Exclude for now:** `organiser_manage_waitlists` — blocked on organiser UI/route accuracy for paid tier waitlists.

## Commands run

```bash
git status --short && git branch --show-current
ddev describe
ddev drush sqlq "..."  # mel_ticket_type, mel_ticket_waitlist_entry
ddev drush php:eval "..."  # TicketCapacityService / TicketStatusEvaluator on tiers 245, 95
```

**Residual risk:** Local DB test events mostly have waitlist disabled; one legacy offered entry on event 1584. Production behaviour may differ where tiers have waitlist enabled via non-Studio configuration.
