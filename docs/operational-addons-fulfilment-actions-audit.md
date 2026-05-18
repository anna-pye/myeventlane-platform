# Operational add-on fulfilment actions — Phase 4G audit

**Phase:** 0 (audit only — no implementation)  
**Date:** 2026-05-18  
**Branch at audit:** `feature/event-studio-extras-editor` (`cf0e07bd`)

## Phase 0 gate

```text
git status --short   → modified/untracked files present (not merge/rebase)
git branch           → feature/event-studio-extras-editor
git log -10          → extras editor + booking summary commits on branch; main at 48f93dbb
```

**Note:** Working tree was **dirty** at audit time (commerce form, theme cart/toasts, etc.). No code changes were made for this document.

---

## Executive summary

Event Extras (operational Commerce products) have **strong read-only visibility** for vendors and customers, but **no persisted per-purchase fulfilment state** that vendors can update. Existing “fulfilment” infrastructure in `myeventlane_tickets` is **ticket- and entitlement-centric** (scanner, lifecycle projections, staff workspace). It does **not** cover standalone `commerce_order_item` rows for `operational_merchandise` / related bundles unless those lines also issued `Ticket` entities—which Event Extras on the book page typically do **not**.

**Phase 4G MVP** should introduce a **small, order-item-scoped fulfilment record** (new custom entity recommended) with four vendor-managed states, wired into the existing `/vendor/events/{event}/addons` surface and customer guidance strips—**without** mutating Commerce order workflow, stock, shipping, scanners, or entitlements.

---

## Current architecture

### 1. Vendor add-on order visibility (Phase 4F)

| Item | Location |
| --- | --- |
| Service | `myeventlane_commerce.vendor_operational_addon_order_builder` → `VendorOperationalAddonOrderBuilder` |
| Controller | `VendorOperationalAddonOrdersController::addons()` |
| Route | `myeventlane_vendor.console.event_operational_addon_orders` → `/vendor/events/{event}/addons` |
| Template | `mel-vendor-operational-addon-orders.html.twig` |
| Library | `myeventlane_commerce/vendor_operational_addon_orders` → `css/mel-vendor-operational-addon-orders.css` |
| Contract flag | `mel_vendor_operational_addon_orders` |

**Behaviour (read-only):**

- Discovers orders via `field_target_event` on order items (plus product `field_event` / store fallbacks aligned with `VendorEventOrdersController`).
- Groups lines using `OperationalPurchaseCompositionManager::composeForOrder()` (no re-classification).
- Exposes product label, size, qty, pickup note, operational summary, payment status label, chips.
- Explicit copy: *“This list is read-only — collection and scanning tools are not available here yet.”*
- Cache: event + product + order tags; `max-age` 300; contexts `user`, `user.permissions`, `languages:language_interface`.

**Access:**

- Route: `myeventlane_vendor.access.vendor_console:access`
- Controller: `VendorConsoleBaseController::assertEventOwnership($event)` (admin, node owner, or `field_event_vendor` → `field_vendor_users`).

See [vendor-operational-addon-order-visibility.md](./vendor-operational-addon-order-visibility.md).

### 2. Customer add-on guidance (Phase 4F)

| Item | Location |
| --- | --- |
| Service | `myeventlane_commerce.operational_addon_guidance_builder` → `OperationalAddonGuidanceBuilder` |
| Composition input | `OperationalPurchaseCompositionManager::composeForOrder()` |
| Optional checkout input | `OperationalCheckoutOrchestrationManager` contract |
| Theme attach | `myeventlane_theme.theme` → `_myeventlane_theme_attach_operational_addon_guidance()` |
| Surfaces | Checkout completion, `commerce_order`, My Tickets, order detail |
| Template | `mel-operational-addon-guidance.html.twig` |

**Behaviour:** Static, purchase-time messaging (“Collect at the event…”, pickup notes from product presentation). **No live fulfilment state** from vendor actions.

See [customer-operational-addon-confirmation-guidance.md](./customer-operational-addon-confirmation-guidance.md).

### 3. Related read-only layers (not Phase 4G execution)

| Layer | Module | Role for add-ons |
| --- | --- | --- |
| Purchase composition | `myeventlane_commerce` | Groups operational lines for UI; no state |
| Checkout orchestration | `myeventlane_commerce` | Mobile-friendly regrouping; no writes |
| Customer operational experience | `myeventlane_event_studio` | Event-scoped strips on book page |
| Fulfillment lifecycle | `myeventlane_tickets` | **Ticket** `fulfilment_status` normalization |
| Fulfillment execution | `myeventlane_tickets` | **Ticket** inspector → customer/staff projections |
| Event Studio “Collection” | `myeventlane_event_studio` | **Product authoring** metadata (`fulfillment_mode`, `pickup_mode` on capabilities)—not per-order state |

**Critical boundary:** `OperationalIntegrityInspector::inspectOrder()` loads **tickets for the order** and builds `fulfillment_operational_signals` from ticket rows. Orders with **only** operational Commerce lines (no ticket issuance) produce an **empty** execution bundle → customer execution strip omitted. That path must **not** be repurposed as the Phase 4G write model.

### 4. Commerce / schema storage today

| Store | What it holds | Fulfilment actions? |
| --- | --- | --- |
| `commerce_order` + state machine | Payment/checkout lifecycle | **Out of scope** — do not mutate for pickup |
| `commerce_order_item` + `field_target_event` | Line linkage to event | **Linkage only** — no fulfilment state field |
| Product `field_mel_operational_product` JSON | Authoring: `fulfillment_mode`, `pickup_mode`, chips | **Catalog metadata**, not per purchase |
| `myeventlane_ticket.fulfilment_status` | Ticket lifecycle (`pending`, `ready`, `collected`, …) | **Ticket entitlements only** |
| `myeventlane_tickets.install` | `myeventlane_ticket`, check-in log tables | No operational order-item fulfilment table |
| `myeventlane_commerce.install` | Intentionally empty | No schema |

No `*.install` schema exists for operational add-on fulfilment records.

### 5. Permissions (existing)

| Permission | Audience | Relevance to 4G |
| --- | --- | --- |
| `access vendor console` | Vendors | Required for `/vendor/events/{event}/addons` |
| `govern mel fulfillment lifecycle` | Staff workspace | **Read-only** ticket lifecycle projections |
| `govern mel fulfillment execution` | Staff workspace | **Read-only** execution cards |
| `administer nodes` | Admin | Bypass on vendor routes |

**Gap:** No vendor permission for “update add-on fulfilment state”. Recommend a dedicated permission (see below)—do **not** reuse staff `govern mel fulfillment *` permissions for vendor console writes.

---

## Audit questions (A–I)

### A. Is there already a fulfilment state storage model?

**Partially, and not for Event Extras order lines.**

- **Tickets:** `Ticket::fulfilment_status` (`pending`, `ready`, `collected`, `redeemed`, `expired`, `cancelled`) on `myeventlane_ticket` entities tied to ticket-backed order items.
- **Products:** Operational capability JSON includes `fulfillment_mode` / `pickup_mode` (authoring hints only).
- **Projections:** `FulfillmentLifecycleManager` canonical states (`pending`, `prepared`, `ready`, `collected`, …) are **derived read-models** from ticket operational signals—not persisted vendor toggles for merch lines.
- **Commerce order items:** No fulfilment state field in `myeventlane_schema` for `commerce_order_item`.

**Conclusion:** There is **no** storage model for vendor-managed fulfilment of operational add-on **order items**.

### B. If yes, should we reuse it?

**Do not reuse ticket fulfilment storage for Phase 4G MVP.**

| Reuse candidate | Verdict |
| --- | --- |
| `Ticket.fulfilment_status` | **No** — Event Extras are Commerce lines; many orders will have **zero** tickets for those lines. Coupling would require artificial ticket issuance. |
| `FulfillmentLifecycleManager` / execution projections | **No for writes** — read-only, ticket-sourced, staff-governed permissions. |
| Product `fulfillment_mode` metadata | **Display only** — describes how the product is generally collected, not “this purchaser’s size M shirt is ready”. |
| Commerce order state (`completed`, `fulfillment`, …) | **No** — payment/fulfillment workflow ≠ pickup desk state. |

Optional **future** alignment: map the new entity’s states to **customer-safe descriptors** using vocabulary similar to `FulfillmentLifecycleManager` constants for copy consistency—without writing into ticket lifecycle services.

### C. If no, which storage option for Phase 4G?

| Option | Pros | Cons | MVP fit |
| --- | --- | --- | --- |
| **Custom content entity** (`mel_operational_addon_fulfilment` or similar) | Cache tags, access hooks, revisions optional, one row per order item, keeps Commerce entities clean | New module wiring, migrations | **Recommended** |
| **Custom SQL table** | Lightweight, fast queries | Duplicates Drupal entity patterns; harder access/cache integration | Acceptable if entity weight is a concern |
| **Order item field** | Simple queries | Config/sync surface; bundle-specific; blurs Commerce vs operational domains; awkward for audit/history | **Not recommended** |
| **State API** | Workflow transitions | Overkill for 4 states; still needs storage | **Not recommended** for MVP |
| **Log-only** | Easy audit trail | Cannot drive “current state” UX without replay | **Insufficient** for MVP |

**Granularity:** One fulfilment record per **`commerce_order_item`** (line-level), not per unit. Quantity remains on the order item; state applies to the whole line (MVP). Per-unit splitting is a later enhancement if needed.

### D. Which option best preserves Commerce order integrity?

**Custom entity (or dedicated table) keyed by `order_item_id` + denormalized `event_id`.**

- Commerce remains SoT for **payment, refunds, line items, prices**.
- Fulfilment entity is a **parallel operational overlay** updated only through a dedicated service.
- On refund/cancel: service layer should **block transitions** or auto-move to `cancelled` / read-only (define in slice 1)—never delete Commerce data.
- Do **not** add checkout panes, order processors, or `commerce_order` state transitions.

### E. Which surfaces should show fulfilment state?

| Surface | Show state? | Notes |
| --- | --- | --- |
| `/vendor/events/{event}/addons` | **Yes (primary)** | Per-line badge + action control |
| Vendor event order detail (`/vendor/events/{event}/orders/{order}`) | **Optional slice 2** | Reuse same builder fragment |
| Customer: `mel_operational_addon_guidance` | **Yes** | Simple language per line (“Ready for pickup”) |
| Customer: My Tickets / order detail / checkout completion | **Yes** (via guidance or line template) | Same projection |
| `mel_operational_fulfillment_execution_customer` | **No** | Ticket-based; keep separate |
| Event book page | **No** (pre-purchase) | N/A |
| Staff venue workspace | **Later** | Could read same entity read-only; not MVP |
| Scanner / QR / wallet | **No** | Explicit non-goal |

### F. Which route should own vendor actions?

**Extend the existing event-scoped console route** rather than inventing a parallel fulfilment app.

| Approach | Recommendation |
| --- | --- |
| **Same route** `/vendor/events/{event}/addons` with POST/AJAX per `order_item_id` | **Preferred MVP** — vendors already land here; minimal navigation churn |
| Sub-route `/vendor/events/{event}/addons/items/{order_item}/state` | Good for clean cache invalidation and tests; can be internal to form API |
| Event Studio `workspace_fulfilment` | **Not MVP** — that section is **capability authoring** (`EventStudioOperationalCapabilityForm`), not order desk |

Controller pattern: thin `VendorOperationalAddonOrdersController` or dedicated `VendorOperationalAddonFulfilmentController` delegating to `OperationalAddonFulfilmentManager` with `assertEventOwnership()` + order-item/event validation.

### G. What permissions / access checks are needed?

**Server-side (mandatory):**

1. `access vendor console` (existing).
2. `assertEventOwnership($event)` (existing).
3. **New:** `manage operational addon fulfilment` (vendor-facing, `restrict_access: false` or true per platform policy).
4. **Item-level:** Loaded `commerce_order_item` must belong to order that matches event via `field_target_event` (or same rules as `VendorOperationalAddonOrderBuilder::orderItemIsForEvent()`).
5. **Order state guard:** Only allow updates for orders in vendor-visible states (`completed`, `placed`, `fulfilled`, `fulfillment`, etc.—align with builder `INCLUDED_STATES`; exclude `draft` carts).
6. **Admin:** `administer nodes` or dedicated `administer operational addon fulfilment` for support overrides.

**Do not** grant vendors `govern mel fulfillment lifecycle` / `govern mel fulfillment execution` for this feature—those are staff read projections.

### H. What cache tags / contexts are needed?

**Invalidation tags (minimum):**

- `mel_operational_addon_fulfilment:{id}` (entity)
- `commerce_order_item:{order_item_id}`
- `commerce_order:{order_id}`
- `node:{event_id}`

**Render caches:**

| Surface | Contexts | Tags |
| --- | --- | --- |
| Vendor addons page | `user`, `user.permissions`, `languages:language_interface` | Event + products + orders + **fulfilment entities for listed items** |
| Customer guidance | `user`, `languages:language_interface` | `commerce_order:{id}` + fulfilment tags per item |

Keep `VendorOperationalAddonOrderBuilder::VENDOR_ADDON_ORDERS_MAX_AGE` as a safety bound when order volume is high.

### I. What are the risks?

| Risk | Mitigation |
| --- | --- |
| Confusion with **ticket** fulfilment / scanner | Distinct entity name, UI copy (“Add-on pickup”), never write `Ticket.fulfilment_status` |
| Vendors see other events’ items | Reuse `orderItemIsForEvent()` checks on every write |
| Stale UI after AJAX | Invalidate listed cache tags; return updated fragment/document |
| Refunded/cancelled orders | Disallow transitions or force terminal state; show read-only label |
| Mixed cart / wrong event linkage | Require `field_target_event`; log and reject mismatches |
| PII in fulfilment notes | Optional vendor note field must stay off customer surfaces |
| Permission creep | Separate vendor permission from staff `govern mel *` |
| Duplicating composition logic | Reads still go through `VendorOperationalAddonOrderBuilder`; writes only touch fulfilment entity |
| Scope creep into stock/shipping | Enforce non-goals in code review + service guards |

---

## Recommended data model (Phase 4G MVP)

### Entity: `mel_operational_addon_fulfilment` (proposed)

| Field | Type | Notes |
| --- | --- | --- |
| `order_item_id` | entity reference → `commerce_order_item` | **Unique**; required |
| `event_id` | entity reference → `node` (event) | Denormalized for access queries |
| `state` | list_string | See states below |
| `vendor_note` | string (plain, optional) | **Vendor-only**; max length bounded |
| `changed` | timestamp | |
| `changed_by` | entity reference → `user` | |

### States (vendor)

| Machine value | Vendor label | Customer label (examples) |
| --- | --- | --- |
| `pending` | Pending | “We’re preparing your extra” |
| `ready_for_pickup` | Ready for pickup | “Ready to collect at the event” |
| `collected` | Collected | “Collected” |
| `issue` | Issue / needs attention | “Please speak to staff at the collection point” |

**Initial state:** `pending` on first vendor page load or lazily on first purchase (define in implementation—recommend **lazy create on first vendor view** or **queue on order paid** to avoid write amplification).

**Terminal:** `collected` (no further transitions except admin). `issue` → `ready_for_pickup` or `collected` allowed.

### Service API (proposed)

- `OperationalAddonFulfilmentManager` (in `myeventlane_commerce`):
  - `getStateForOrderItem(OrderItemInterface $item, NodeInterface $event): ?FulfilmentRecord`
  - `transition(OrderItemInterface $item, NodeInterface $event, string $new_state, AccountInterface $actor, ?string $note): void`
  - `buildCustomerLabelsForOrder(OrderInterface $order): array` keyed by `order_item_id`
  - `assertVendorMayTransition(...)` — all access here, not in Twig

---

## Recommended routes / actions

| Method | Path | Action |
| --- | --- | --- |
| GET | `/vendor/events/{event}/addons` | Existing list + fulfilment badges (enhanced builder) |
| POST | `/vendor/events/{event}/addons/items/{commerce_order_item}/fulfilment` | Set state (CSRF + permission) |

Form API or POST route with `FormBuilder`—no raw query params for state.

**Alternative:** Single form on addons page with per-line submit buttons (`#name` scoped like `EventOperationalAddonCartForm` per-card submit).

---

## Access model (summary)

```text
Request → vendor console access
        → assertEventOwnership(event)
        → permission manage operational addon fulfilment (or admin)
        → load order_item + verify field_target_event == event.id
        → verify order in INCLUDED_STATES
        → OperationalAddonFulfilmentManager::transition()
```

Customer read path: order owner or session that placed order (existing Commerce order access)—**project state only**, no writes.

---

## Customer / vendor UX (MVP)

### Vendor (`/vendor/events/{event}/addons`)

- Per line: status pill + single primary action (e.g. “Mark ready”, “Mark collected”, “Flag issue”).
- Optional expand for `vendor_note` (internal).
- Bulk actions: **non-goal** for MVP.
- Filter by state: **nice-to-have** slice 2.

### Customer

- Extend `OperationalAddonGuidanceBuilder` sections (or `mel_operational_order_item_line`) with **one sentence** per operational line from fulfilment projection.
- Tone: calm, non-technical; no vendor notes; no scanner language.

---

## Explicit non-goals (Phase 4G)

The following are **out of scope** and must not be introduced via fulfilment actions:

- Stock decrement or inventory reservation mutation
- Warehouse logic or shipment orchestration
- Scanner, QR, wallet, or entitlement issuance/redemption
- Commerce **order** or **order item** workflow state mutation (payment/refund lifecycle)
- Checkout pane changes or cart processors
- Ticket `fulfilment_status` writes for non-ticket lines
- Real-time notifications / websockets (optional later)
- Automatic “mark collected” via scanner

---

## Implementation slices (recommended order)

| Slice | Deliverable | Module |
| --- | --- | --- |
| **4G-1** | Entity definition, storage, default `pending`, kernel tests for uniqueness + event linkage | `myeventlane_commerce` (or thin `myeventlane_operational_fulfilment` if boundary preferred) |
| **4G-2** | `OperationalAddonFulfilmentManager` + permission + transition guards + logging on failure | `myeventlane_commerce` |
| **4G-3** | Vendor UI on addons page (forms/AJAX) + extend `VendorOperationalAddonOrderBuilder` document with `fulfilment_state` + customer labels | `myeventlane_vendor` + `myeventlane_commerce` |
| **4G-4** | Customer projection in `OperationalAddonGuidanceBuilder` + theme templates | `myeventlane_commerce` + theme |
| **4G-5** | QA, cache metadata, docs update, optional admin override tool | cross-cutting |

**Dependency order:** 4G-1 → 4G-2 → 4G-3 → 4G-4.

---

## QA checklist (post-implementation)

### Access

- [ ] Anonymous cannot POST fulfilment transitions.
- [ ] Unrelated vendor cannot transition items for another organiser’s event.
- [ ] Event owner and linked vendor team member can transition.
- [ ] Admin can transition (if admin override included).
- [ ] User without `manage operational addon fulfilment` cannot transition (console access alone insufficient).

### Data integrity

- [ ] Transition does not modify `commerce_order` state or line item price/qty.
- [ ] Item with wrong/missing `field_target_event` is rejected.
- [ ] Refunded/cancelled order items are read-only or auto-terminal.
- [ ] One fulfilment record per order item (no duplicates on concurrent POST).

### Vendor UX

- [ ] `/vendor/events/{event}/addons` shows correct state per line after transition (hard refresh + without).
- [ ] Mobile layout: actions reachable, no overflow.

### Customer UX

- [ ] Guidance strip shows updated customer label after vendor marks “Ready for pickup”.
- [ ] Vendor-only notes never appear on customer surfaces.
- [ ] Orders with tickets only: no fulfilment strip change.

### Cache

- [ ] After transition, vendor page and customer order view update within same session after reload.
- [ ] `drush cr` not required for normal operation (tag invalidation works).

### Regression

- [ ] Existing read-only addon orders list still works when fulfilment module enabled with zero records.
- [ ] `OperationalFulfillmentExecutionProjectionBuilder` unchanged for ticket orders.

---

## Related documentation

- [vendor-operational-addon-order-visibility.md](./vendor-operational-addon-order-visibility.md)
- [customer-operational-addon-confirmation-guidance.md](./customer-operational-addon-confirmation-guidance.md)
- [operational-merchandise-architecture.md](./operational-merchandise-architecture.md)
- [operational-purchase-composition-convergence.md](./operational-purchase-composition-convergence.md)
- [fulfillment-lifecycle-convergence.md](./fulfillment-lifecycle-convergence.md)
- [operational-fulfillment-execution-convergence.md](./operational-fulfillment-execution-convergence.md)
- [event-commerce-boundaries.md](./event-commerce-boundaries.md)
- [operational-addons-mvp-qa.md](./operational-addons-mvp-qa.md)

---

## Confirmation

This audit confirms:

1. **No** existing per-order-item fulfilment store for Event Extras.  
2. **Ticket** lifecycle and **fulfillment execution** layers are **read-only** and **ticket-backed**—not suitable for Phase 4G writes.  
3. **Recommended MVP:** custom fulfilment entity + manager + vendor addons route actions + customer guidance projection.  
4. **Stock, warehouse, shipping, scanner/QR/entitlement, and Commerce order mutation remain out of scope.**
