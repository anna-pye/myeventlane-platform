# Ticket Store Migration

**Purpose:** Migrate legacy ticket/RSVP products from the default store to the correct vendor store, fixing vendor dashboard order visibility.

## Background

Due to legacy code, many older ticket products were created with the default Commerce store (ID 2) instead of the vendor's store. This caused:

- Orders to be placed on store 2
- Vendor dashboards filtering orders by event store (e.g. 27) → no orders shown
- `VendorEventOrdersController` was updated to relax the store filter for event-linked orders (so existing orders appear)
- Product creation was fixed to use `resolveEventStore()` so new products use the vendor store

This migration updates **existing** products so they point to the correct store.

## Drush Command

**Command:** `myeventlane:migrate-ticket-stores`  
**Alias:** `mel-migrate-ticket-stores`

### Options

| Option | Description |
|--------|-------------|
| `--dry` | Simulate changes without saving |
| `--event=NID` | Limit to products for a specific event |

### Usage

```bash
# Migrate all eligible ticket products
ddev drush myeventlane:migrate-ticket-stores

# Simulate only (no writes)
ddev drush myeventlane:migrate-ticket-stores --dry

# Limit to one event
ddev drush myeventlane:migrate-ticket-stores --event=754
```

## Logic

1. **Query:** Ticket products (`type=ticket`) with `field_event` set.
2. **For each product:**
   - Load the event node
   - Resolve event store via:
     - `field_event_store` on event
     - Or `field_event_vendor` → `field_vendor_store`
   - If product's store(s) already include the event store → skip
   - Otherwise → set product stores to `[$eventStore]` and save

3. **Skip conditions:**
   - No event linked
   - Event not found or not `event` bundle
   - Event has no resolvable store (no `field_event_store` and no vendor store)

## Output

- Logs each product updated: `Product 123 "Event Title": store 2 → 27`
- Summary: count updated, count skipped
- Skipped reasons breakdown (e.g. `already_correct`, `no_event_store`)

## Files

- **Command:** `web/modules/custom/myeventlane_event/src/Commands/MigrateLegacyTicketStoresCommands.php`
- **Registration:** `web/modules/custom/myeventlane_event/drush.services.yml`

## Related Fixes

- **VendorEventOrdersController:** Skips store filter when orders found via `field_target_event` (so legacy orders on store 2 still appear)
- **TicketTypeManager / EventProductManager:** New products use `resolveEventStore()` 
- **RsvpBookingForm:** Cart uses product's store instead of `loadDefault()`
