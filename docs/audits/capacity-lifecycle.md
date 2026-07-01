# MyEventLane capacity lifecycle audit

**Date:** 2026-06-22  
**Scope:** Event-level capacity enforcement (`myeventlane_capacity`), related Commerce/RSVP flows, and adjacent tier-level limits.  
**Method:** Repository inspection only — no runtime changes.

## Executive summary

MyEventLane uses **multiple capacity layers** that do not all share one counter:

| Layer | Authority | Permanent consumption |
|-------|-----------|----------------------|
| **Event total** | `EventCapacityService` (`myeventlane_capacity.service`) | Confirmed `rsvp_submission` rows + quantities on **completed** `commerce_order_item` lines |
| **Ticket tier** | `TicketAvailabilityService` + `mel_ticket_type.capacity` | Completed variation sold counts + active waitlist offer holds |
| **RSVP (legacy path)** | `RsvpCapacityService` | Confirmed `rsvp_submission` entity count vs `field_capacity` |
| **Vendor attendance mirror** | `AttendanceManager` | Confirmed `event_attendee` rows vs `field_capacity` |

**Event-level enforcement invariant** (documented in `EventCapacityService`):

> Ticket capacity MUST be enforced in `getCapacityTotal`, `getSoldCount`, and `assertCanBook`. Do not rely on node edit form validation or UI state.

**Temporary holds** at event level are `myeventlane_capacity_reservation` rows (15-minute TTL) written by `assertCanBook()`. They are **not** permanent consumption; they block concurrent booking until TTL expiry or explicit `releaseReservation()`.

---

## 1. What permanently consumes capacity?

### Ticket entity (`myeventlane_tickets` `Ticket`)

**Does not consume event-level capacity.**

The `Ticket` entitlement entity is a post-purchase fulfilment/check-in record. `EventCapacityService::computeSoldCount()` has no reference to `Ticket`, `event_attendee`, or entitlement tables.

Confirmed from: `web/modules/custom/myeventlane_capacity/src/Service/EventCapacityService.php` — only `rsvp_submission` and `commerce_order_item` paths.

### Order item (`commerce_order_item`)

**Consumes capacity only when the parent order is `completed`.**

`countPaidTickets()` loads order items with `field_target_event = {event_id}`, loads each parent order, and sums `quantity` **only if** `order->getState()->getId() === 'completed'`.

- Draft cart lines, checkout-in-progress orders, `canceled` orders, and any non-`completed` state **do not** count toward sold.
- Donations, boosts, and operational merchandise add-ons are excluded from enforcement via `CapacityOrderInspector::isNonTicketItem()` (not from `countPaidTickets()` itself — that method loads all items with `field_target_event`; tier/event assertions skip non-ticket bundles earlier).

Source: `EventCapacityService::countPaidTickets()`, `CapacityOrderInspector`.

### Completed order (`commerce_order`)

**The order state gate is what makes ticket lines permanent.**

Permanent consumption is indirect: completed order → its ticket order items count in `countPaidTickets()`. There is no separate “order consumes 1 slot” rule; consumption is the **sum of ticket line quantities** on completed orders.

`TicketVariationSoldService` (tier-level sold counts) uses the same `completed` gate.

### RSVP submission (`rsvp_submission`)

**Consumes event-level capacity when `status = confirmed`.**

`countRsvps()` counts entities:

```php
->condition('event_id', $eventId)
->condition('status', 'confirmed')
->count()
```

Important behaviours:

- **`waitlist` and `cancelled` submissions do not count.**
- Count is **per submission entity**, not per `guests` / `quantity` field. A single confirmed RSVP with `guests = 4` still counts as **1** toward `computeSoldCount()` (verified: `countRsvps()` uses `->count()`, not a SUM of guests).
- `hook_entity_*` on `rsvp_submission` invalidates the sold cache; status change to `cancelled` frees capacity on next authoritative read.

RSVP paths that **do not** write `myeventlane_capacity_reservation`:

- `RsvpPublicForm` → `RsvpSubmissionManager` uses `RsvpCapacityService::isAtCapacity()` + Drupal `LockBackend` (`rsvp:event:{id}`), not `assertCanBook()`.
- `RsvpSubmissionForm` uses `RsvpCapacityService` against `field_capacity`.

### `event_attendee` (vendor attendance mirror)

**Does not consume `EventCapacityService` sold count.**

`AttendanceManager::getAttendeeCount()` counts `event_attendee` rows for vendor UI and `RsvpForm` waitlist branching (`field_capacity`), but `computeSoldCount()` never queries `event_attendee`.

---

## 2. What is temporary?

### `myeventlane_capacity_reservation` rows

Written inside `assertCanBook()` under a per-event DB row lock (`myeventlane_capacity_lock` + `SELECT … FOR UPDATE`).

| Field | Role |
|-------|------|
| `event_id` | Event node ID |
| `quantity` | Held seats for this key |
| `reservation_key` | Stable upsert key (unique) |
| `created` | Unix timestamp |
| `expires` | `created + 900` seconds (15 minutes) |

Lifecycle:

- **Created/upserted** on successful `assertCanBook()`.
- **Counted** in `sumActiveReservations()` while `expires > now` (excluded key omitted when upserting same hold).
- **Purged** lazily on each `assertCanBook()` via `purgeExpiredReservations()`.
- **Released** explicitly via `releaseReservation($key)` (see §6).

Ephemeral keys (`ephemeral:event:{id}:{uniqid}`) are generated when callers omit `reservation_key`; logged as a warning.

### Tier waitlist offer holds (separate from event reservations)

`TicketTierWaitlistService::sumActiveOfferReserved()` sums `offer_reserved` on active `ticket_waitlist_entry` rows per tier. Used in `TicketAvailabilityService::assertTierCapacity()` as **held** pool against `mel_ticket_type.capacity`.

This is **tier-level**, not event-total, and is stored on waitlist entities — not in `myeventlane_capacity_reservation`.

### Drupal `LockBackend` mutexes (in-memory / DB locks, not reservation rows)

| Lock name | Owner | Purpose |
|-----------|-------|---------|
| `mel_ticket_capacity:{event_id}` | `TicketCapacityOrderSubscriber` | Serialize placement checks; held until `KernelEvents::TERMINATE` |
| `myeventlane_checkout:place_order:{order_id}` | `TicketAvailabilityCommerceSubscriber` | Block duplicate placement submits |
| `rsvp:event:{event_id}` | `RsvpSubmissionManager` | Serialize RSVP create/update |

These prevent races during a request; they do **not** decrement sold counts and are not visible to `computeSoldCount()`.

---

## 3. What does `computeSoldCount()` count?

Private method in `EventCapacityService`; used uncached inside `assertCanBook()` and cached (300s) via `getSoldCount()`.

### Event type branching

Reads `field_event_type` (default `rsvp` if empty):

| `field_event_type` | RSVP branch | Paid branch |
|--------------------|-------------|-------------|
| `rsvp` | Yes | No |
| `paid` | No | Yes |
| `both` | Yes | Yes |

### RSVP branch (`countRsvps`)

- Entity: `rsvp_submission`
- Filter: `event_id` match, `status = confirmed`
- Metric: **entity count** (not guest sum)
- Throws if `rsvp_submission` entity type unavailable

### Paid branch (`countPaidTickets`)

- Entity: `commerce_order_item` with `field_target_event = event_id`
- Parent order must be `state = completed`
- Metric: **SUM(order_item.quantity)** across qualifying lines
- Swallows storage errors → returns `0` (paid branch only)

### Not included

- `Ticket` entitlements
- `event_attendee` rows
- Draft/placed/canceled/refunded (non-completed) orders
- `myeventlane_capacity_reservation` quantities (those are subtracted separately in `assertCanBook()` only)
- Tier-level caps (`mel_ticket_type.capacity`)

### Capacity total (`getCapacityTotal`)

- Primary: `field_event_capacity_total` (> 0)
- Fallback: `field_capacity` (> 0)
- `NULL` = unlimited (enforcement skipped in `assertCanBook()`)

### Display vs enforcement

- `getRemaining()` / `isSoldOut()` use **cached** `getSoldCount()` and **do not** include active reservations.
- `assertCanBook()` uses **uncached** `computeSoldCount()` **plus** active reservations.

---

## 4. Lifecycle scenarios

### Cart abandoned

| Phase | Sold count | Reservations | Locks |
|-------|------------|--------------|-------|
| User adds tickets | Unchanged (cart is draft) | `assertCanBook` on cart add / cart form validate writes `cart:{cart_id}:event:{event_id}` | — |
| User leaves site | Unchanged | Reservation remains until **TTL (15 min)** or next `assertCanBook` purge | — |
| After TTL | Unchanged | Row deleted on next enforcement pass | — |

**No `releaseReservation()` on cart abandonment.** Capacity frees implicitly via TTL.

Call sites that create cart reservations:

- `myeventlane_capacity_cart_form_validate()` — `cart:{cart_id}:event:{event_id}`
- `TicketAvailabilityCommerceSubscriber::onCartEntityAdd()` — same key via `assertPaidLineAndEventTotal()`
- `TicketSelectionForm` — `cart:{cart_id}:event:{event_id}` or `ticket-select:event:{id}:session:{session_id}` if no cart yet

### Checkout abandoned

| Phase | Sold count | Reservations | Locks |
|-------|------------|--------------|-------|
| Checkout in progress | Unchanged | Cart/order reservation keys may be refreshed on each validation | — |
| Order placement attempt | Unchanged until transition completes | `order:{order_id}:event:{event_id}` written at placement check | `mel_ticket_capacity:*` + `myeventlane_checkout:place_order:{order_id}` during request |
| User abandons mid-checkout | Unchanged | Order/cart reservation TTL expires (15 min) | Locks released on HTTP terminate |

**No `releaseReservation()` on checkout abandon** (only on completion — see below).

### Order completed

| Effect | Detail |
|--------|--------|
| **Sold count increases** | On next `computeSoldCount()`, ticket line quantities on this order count (state `completed`). |
| **Cache invalidated** | `hook_entity_insert/update` on `commerce_order` → `invalidateCache(event_id)`. |
| **Reservations released** | `myeventlane_capacity_invalidate_event_cache()` calls `releaseReservation('order:{order_id}:event:{event_id}')` and `releaseReservation('cart:{order_id}:event:{event_id}')` when `state === 'completed'`. |

Note: Cart and order share the same numeric ID once the cart becomes an order; both key patterns are cleared defensively.

Post-completion, capacity is held by **completed order items**, not reservation rows.

### RSVP completed

Multiple RSVP surfaces:

| Surface | Pre-check | Permanent write | Reservation release |
|---------|-----------|-----------------|---------------------|
| **`RsvpPublicForm`** (primary public RSVP) | `RsvpSubmissionManager` + `RsvpCapacityService` + lock | Creates `rsvp_submission` (`confirmed` or `waitlist`) | **None** — does not use `assertCanBook()` |
| **`RsvpForm`** (`myeventlane_event_attendees`) | `assertCanBook(..., rsvp:event:{id}:session:{session})` | Creates `event_attendee` (not `rsvp_submission`) | `releaseReservation(session key)` on submit |
| **`RsvpSubmissionForm`** | `RsvpCapacityService` vs `field_capacity` | `rsvp_submission` | **None** |

When `rsvp_submission` is saved `confirmed`:

- **Sold count +1** (entity count) on cache invalidation.
- Hook attempts `releaseReservation('rsvp:event:{eventId}:submission:{submission_id}')` — this key **does not match** session-based keys used by `RsvpForm`; harmless no-op for that form.

RSVP cancellation (`status → cancelled` via `RsvpCancelConfirmForm` / `RsvpCancelController`):

- Entity no longer matches `status = confirmed` → **frees capacity** on next count.
- Cache invalidated via entity hooks.
- No reservation row involved for public RSVP path.

### Order refunded

| Effect | Event-level sold count | Attendee access |
|--------|------------------------|-----------------|
| **Partial or full refund** | **No change** in `countPaidTickets()` — still counts items on `completed` orders | `RefundProcessor::cancelRefundedTicketAttendees()` cancels selected `event_attendee` rows on full ticket-inclusive refunds |
| **Order state** | Refund processing does not change `EventCapacityService` logic; gate remains `completed` only | — |

**Gap:** Refunded tickets continue to occupy event-level capacity unless order state or order item quantities are changed elsewhere. `OrganiserAutomationScheduler` contains a `@todo` noting capacity should react to refunds/cancels.

Tier sold counts (`TicketVariationSoldService`) share the same `completed`-only gate — refunds do not reduce tier sold pools in this service.

### Order cancelled

| When | Sold count | Reservations |
|------|------------|--------------|
| Cart/order **before** `completed` | Unchanged (never counted) | TTL expiry; no explicit release hook for `canceled` state |
| **After** `completed` | Still counted if state remains `completed` | N/A |

Commerce `canceled` orders are excluded from `countPaidTickets()` by the state check. Vendor event cancel (`VendorCancelEventForm`) queues emails/refunds but does not invoke `EventCapacityService`.

---

## 5. Enforcement entry points (`assertCanBook`)

| Caller | Reservation key pattern |
|--------|-------------------------|
| `myeventlane_capacity.module` cart validate | `cart:{cart_id}:event:{event_id}` |
| `TicketAvailabilityCommerceSubscriber` cart add | `cart:{cart_id}:event:{event_id}` |
| `TicketCapacityOrderSubscriber` placement | `order:{order_id}:event:{event_id}` |
| `TicketSelectionForm` | `cart:{cart_id}:event:{event_id}` or `ticket-select:event:{id}:session:{session_id}` |
| `TicketWaitlistClaimController` | **No key** → ephemeral reservation |
| `RsvpForm` | `rsvp:event:{id}:session:{session_id}` |

Tier constraints (`assertPaidVariationLineConstraints`, `assertTierCapacity`) run **in addition to** event total checks in Commerce flows.

---

## 6. `releaseReservation()` call sites

Validation command output (2026-06-22):

```
web/modules/custom/myeventlane_capacity/myeventlane_capacity.module
  rsvp:event:{eventId}:submission:{entity.id}   # on confirmed rsvp_submission insert/update
  order:{order_id}:event:{eventId}              # on commerce_order completed
  cart:{order_id}:event:{eventId}               # on commerce_order completed

web/modules/custom/myeventlane_event_attendees/src/Form/RsvpForm.php
  rsvp:event:{id}:session:{session_id}          # after attendance created

web/modules/custom/myeventlane_capacity/src/Service/EventCapacityService.php
  (implementation)

web/modules/custom/myeventlane_capacity/tests/src/Kernel/CapacityConcurrencyTest.php
  (test)
```

**Not called** on: cart abandon, checkout abandon, refund, order cancel (pre-completion), or public RSVP submit.

---

## 7. Cache invalidation triggers

`myeventlane_capacity_entity_{insert,update,delete}` → `myeventlane_capacity_invalidate_event_cache()`:

| Entity | Action |
|--------|--------|
| `rsvp_submission` | `invalidateCache(event_id)`; conditional `releaseReservation` |
| `commerce_order_item` | `invalidateCache(event_id)` |
| `commerce_order` | Per-line `invalidateCache`; `releaseReservation` on `completed` |
| `node` event | `invalidateCache(event_id)` |

Sold count cache key: `capacity_sold:{event_id}` (TTL 300s, tag `node:{event_id}`).

---

## 8. Parallel systems and consistency risks

| Risk | Evidence |
|------|----------|
| **RSVP guest count vs entity count** | `guests` field on `rsvp_submission` not summed in `countRsvps()` |
| **Dual capacity fields** | `field_event_capacity_total` (enforcement primary) vs `field_capacity` (RSVP public form, `AttendanceManager`) |
| **Dual RSVP enforcement** | Public RSVP bypasses `assertCanBook()` / reservation table |
| **`event_attendee` vs `rsvp_submission`** | `RsvpForm` writes attendee mirror; may not create `rsvp_submission` — event sold count unaffected |
| **Refunds do not free event capacity** | `countPaidTickets()` only checks `completed`, not refund/attendee state |
| **Ephemeral reservation keys** | `TicketWaitlistClaimController` omits `reservation_key` |
| **Display sold vs enforcement** | `getRemaining()` ignores active reservations; UI may show more availability than `assertCanBook` allows during hold window |
| **Tier cap vs event cap** | Tier can sell out while event total has headroom (and vice versa) — by design in `TicketAvailabilityService` |

---

## 9. Data model reference

### Permanent stores (event-level sold)

```
rsvp_submission (status=confirmed, per event_id)     → +1 each (not guests)
commerce_order_item (field_target_event, qty)        → +quantity when order.state=completed
```

### Temporary stores (event-level holds)

```
myeventlane_capacity_lock      → concurrency mutex row per event
myeventlane_capacity_reservation → provisional holds (15 min TTL)
```

### Tier temporary holds

```
ticket_waitlist_entry.offer_reserved → tier pool only
```

---

## 10. Validation commands

```bash
# Reservation release call sites
grep -R "releaseReservation" web/modules/custom

# Capacity enforcement entry points
grep -R "assertCanBook" web/modules/custom

# Sold count authority
grep -R "computeSoldCount\|countPaidTickets\|countRsvps" web/modules/custom/myeventlane_capacity

# Schema
grep -R "myeventlane_capacity_reservation\|myeventlane_capacity_lock" web/modules/custom
```

---

## 11. Source file index

| Concern | Primary file |
|---------|----------------|
| Event sold count + reservations | `web/modules/custom/myeventlane_capacity/src/Service/EventCapacityService.php` |
| Entity hooks + cart validate | `web/modules/custom/myeventlane_capacity/myeventlane_capacity.module` |
| Order line → event qty map | `web/modules/custom/myeventlane_capacity/src/Service/CapacityOrderInspector.php` |
| Commerce enforcement orchestration | `web/modules/custom/myeventlane_commerce/src/Service/TicketAvailabilityService.php` |
| Placement serialization | `web/modules/custom/myeventlane_commerce/src/EventSubscriber/TicketCapacityOrderSubscriber.php` |
| Tier sold counts | `web/modules/custom/myeventlane_commerce/src/Service/TicketVariationSoldService.php` |
| RSVP capacity (legacy) | `web/modules/custom/myeventlane_rsvp/src/Service/RsvpCapacityService.php` |
| Attendance mirror | `web/modules/custom/myeventlane_event_attendees/src/Service/AttendanceManager.php` |
| Refund → attendee cancel | `web/modules/custom/myeventlane_refunds/src/Service/RefundProcessor.php` |
| Concurrency tests | `web/modules/custom/myeventlane_capacity/tests/src/Kernel/CapacityConcurrencyTest.php` |

---

## Residual risk summary

1. **Refunded/cancelled completed orders** still count toward event capacity until order/item model changes.
2. **Abandoned carts/checkouts** rely on 15-minute reservation TTL, not explicit release.
3. **RSVP public path** does not use the reservation table; relies on `LockBackend` + entity-count checks at submit time.
4. **Guest quantities** on RSVP submissions are not reflected in event-level sold math.
5. **Multiple capacity fields and mirrors** (`field_event_capacity_total`, `field_capacity`, `event_attendee`) can diverge in edge paths.

No code was modified for this audit.
