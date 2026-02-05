# Vendor Dashboard Audit — MyEventLane

**Date:** 2026-02-03  
**Focus:** Orders tab, vendor filtering, routing, styling

---

## 1. Route Structure (Actual vs Expected)

### You mentioned
- `/dashboard`
- `/dashboard/orders`
- `/dashboard/attendees`
- `/dashboard/analytics`

### Actual routes

| Path | Route | Purpose |
|------|-------|---------|
| `/dashboard` | **NO ROUTE** | Header links here → likely 404 |
| `/vendor/dashboard` | `myeventlane_vendor.console.dashboard` | Main vendor dashboard |
| `/vendor/events/{event}/orders` | `myeventlane_vendor.console.event_orders` | **Per-event** orders |
| `/vendor/events/{event}/attendees` | `myeventlane_vendor.console.event_attendees` | Per-event attendees |
| `/vendor/events/{event}/analytics` | `myeventlane_vendor.console.event_analytics` | Per-event analytics |
| `/vendor/analytics` | `myeventlane_analytics.dashboard` | Global analytics |
| `/dashboard/attendees/export` | `myeventlane_views` | Attendees CSV export |
| `/my-account` | `myeventlane_account.dashboard` | Customer My Account |

**Finding:** There is no global “Orders” tab. Orders are per-event only (`/vendor/events/{event}/orders`). The header’s `/dashboard` link has no route and may 404.

---

## 2. Orders Tab — Why Orders Might Not Appear

### Data flow

1. **Primary:** Order items with `field_target_event` = event ID
2. **Fallback 1:** Order items whose purchased variation has `field_event` or `field_event_ref` = event ID
3. **Fallback 2:** Orders for the event’s store with `state` in allowed states, then filtered to items for this event

### Possible root causes

| Cause | Where to check |
|-------|----------------|
| `field_target_event` empty on order items | `scripts/diagnose-event-orders.php` |
| Event has no store (`field_event_store`, vendor store) | Event entity, diagnose script |
| Variation uses `field_event_ref` only | Presave only uses `field_event` (see fix below) |
| Order state not in allowed list | `completed`, `placed`, `fulfilled`, `partially_refunded`, `refunded`, `fulfillment` |
| Store mismatch (order.store_id ≠ event store) | Diagnose script step 3 |

### Presave hook gap

In `web/modules/custom/myeventlane_commerce/myeventlane_commerce.module` (lines 287–308):

- Only checks variation’s `field_event`
- Does **not** check `field_event_ref`

If ticket variations use `field_event_ref` instead of `field_event`, `field_target_event` will not be auto-populated on order items. The Orders controller does handle both via fallbacks, but the primary path (direct `field_target_event` query) will miss them.

---

## 3. Vendor Filtering

- **Access:** `VendorConsoleAccess::access` and `assertEventOwnership()` ensure vendors only see their own events.
- **Orders:** Scoped by event ownership (event → store → orders for that store with event-linked items).
- **Store resolution:** `field_event_store` on event, or `field_event_vendor` → `field_vendor_store`.

---

## 4. Files to Check (One at a Time)

### First: Orders diagnosis

```bash
# Run for a specific event that should have orders:
ddev drush scr scripts/diagnose-event-orders.php <event_nid>
```

This prints:
- Event and resolved store
- Primary discovery (items with `field_target_event`)
- Variation-based discovery (items via `field_event` / `field_event_ref`)
- Store + state discovery and filtering

### Exact file to check next

1. **`web/modules/custom/myeventlane_commerce/myeventlane_commerce.module`** — Presave hook around lines 287–308  
   - Add support for variation’s `field_event_ref` so `field_target_event` is set when only `field_event_ref` is present.

2. **`web/themes/custom/myeventlane_theme/templates/region--header.html.twig`** — `/dashboard` link  
   - Add a route for `/dashboard` that redirects vendors to `/vendor/dashboard` and customers to `/my-account` (or similar), or change the link to use the correct route.

---

## 5. Empty State & Styling

The orders template (`web/themes/custom/myeventlane_vendor_theme/templates/event/orders.html.twig`):

- Has a clear empty state with `mel-empty`
- Empty message: “No orders yet” with explanation
- Uses `mel-card`, `mel-table`, `mel-badge`, `mel-link`

Empty state does not yet use your desired pattern:  
“No orders yet — Share your event to start selling!”

---

## 6. Checkout Store Assignment (FIXED)

**Problem:** Ticket products were created with the default store; carts used default store. Orders ended up on store 2 instead of the vendor store (27).

**Fix applied:**
- **TicketTypeManager::getOrCreateTicketProduct()** — Uses `resolveEventStore()` (field_event_store → field_event_vendor→field_vendor_store → default) instead of always default
- **EventProductManager::createRsvpProduct()** — Same `resolveEventStore()` logic
- **RsvpBookingForm** — Uses product's store for cart (from variation→product→getStores()) instead of `loadDefault()`, with fallback

**Files changed:**
- `web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php`
- `web/modules/custom/myeventlane_event/src/Service/EventProductManager.php`
- `web/modules/custom/myeventlane_commerce/src/Form/RsvpBookingForm.php`

**Note:** Existing products created before this fix remain on the default store. The VendorEventOrdersController change (skip store filter when orders found via event link) ensures those orders still appear. New products and new orders will use the correct vendor store.
