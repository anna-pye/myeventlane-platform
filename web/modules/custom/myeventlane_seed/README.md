# MyEventLane Seed Module

Deterministic test seed data for MyEventLane v2 (Drupal 11) with vendor and event reset capabilities.

## Overview

This module provides Drush commands to:
- Reset all events (delete all Event nodes)
- Seed deterministic demo data (2 vendors, 6 events, products, RSVPs)
- Purge seeded demo data

## Installation

Enable the module:

```bash
ddev drush en myeventlane_seed -y
ddev drush cr
```

## Commands

### Reset Events

Deletes ALL Event nodes (bundle 'event'). Logs counts per bundle.

```bash
ddev drush mel:reset-events
```

**Use with caution** - this permanently deletes all events.

### Seed Demo Data

Runs `reset-events` first, then seeds:
- 2 vendor users: `vendor2`, `vendor3` (password: `password`)
- 2 vendor entities with stores
- 6 events total:
  - Vendor 2: 2 ticketed events + 1 RSVP event
  - Vendor 3: 2 ticketed events + 1 RSVP event
- Ticket products with variations (minimum 2 variations per ticketed event)
- Images for vendors and events
- Sydney locations for all events

```bash
ddev drush mel:seed-demo
```

The command outputs a summary table showing:
- Vendor name
- User ID, Vendor ID, Store ID
- Event NID, Type (paid/rsvp)
- Product ID and variation count

### Purge Demo Data

Removes only seeded demo content:
- Users: `vendor2`, `vendor3`
- Their vendor entities
- Their stores
- Their events and products
- RSVP submissions for their events

**Does NOT** delete unrelated content.

```bash
ddev drush mel:purge-demo
```

## Testing Workflow

### Full Reset and Seed

```bash
# Clear cache
ddev drush cr

# Reset all events
ddev drush mel:reset-events

# Verify: no events remain
ddev drush sqlq "SELECT COUNT(*) FROM node_field_data WHERE type='event'"

# Seed demo data
ddev drush mel:seed-demo

# Verify: events created
ddev drush sqlq "SELECT COUNT(*) FROM node_field_data WHERE type='event'"
```

### Verify Seeded Data

1. **Vendor Dashboard**: Login as `vendor2` or `vendor3` (password: `password`)
   - Verify new events appear in vendor dashboard
   - Verify event cards render correctly

2. **Event Pages**: Visit event pages
   - Verify images render
   - Verify RSVP CTA works for RSVP events
   - Verify "Buy Ticket" CTA works for ticketed events

3. **Commerce**: Verify products and variations
   ```bash
   ddev drush sqlq "SELECT COUNT(*) FROM commerce_product WHERE type='ticket'"
   ddev drush sqlq "SELECT COUNT(*) FROM commerce_product_variation WHERE type='ticket_variation'"
   ```

### Cleanup

```bash
# Purge seeded data
ddev drush mel:purge-demo

# Verify: no seeded users remain
ddev drush sqlq "SELECT COUNT(*) FROM users_field_data WHERE name IN ('vendor2', 'vendor3')"

# Verify: no events remain
ddev drush sqlq "SELECT COUNT(*) FROM node_field_data WHERE type='event'"
```

## Architecture

### Module Structure

```
myeventlane_seed/
├── myeventlane_seed.info.yml
├── myeventlane_seed.services.yml
├── README.md
└── src/
    ├── Commands/
    │   └── MelSeedCommands.php      # Drush commands
    ├── Service/
    │   ├── DemoSeeder.php            # Demo data creation
    │   ├── DemoPurger.php            # Demo data removal
    │   └── EventResetService.php     # Event deletion
    └── Util/
        └── ImageFactory.php          # Placeholder image generation
```

### Entity Relationships

- **Event** (node bundle: `event`)
  - Linked to Vendor via `field_event_vendor`
  - Linked to Product via `field_product_target`
  - Event type: `field_event_type` (rsvp/paid/both/external)

- **Vendor** (entity type: `myeventlane_vendor`)
  - Linked to User via `uid` (owner)
  - Linked to Store via `field_vendor_store`
  - Logo: `field_vendor_logo`

- **Product** (Commerce product type: `ticket`)
  - Linked to Event via `field_event`
  - Linked to Store via `stores` field
  - Variations: `ticket_variation` type

- **RSVP** (entity type: `rsvp_submission`)
  - Linked to Event via `event_id`

### Seeded Content

**Vendor 2:**
- Username: `vendor2`
- Password: `password`
- Email: `vendor2@example.com`
- Events:
  1. "Vendor 2 Event 1 - Ticketed" (paid, 2 variations)
  2. "Vendor 2 Event 2 - Ticketed" (paid, 2 variations)
  3. "Vendor 2 Event 3 - RSVP" (rsvp)

**Vendor 3:**
- Username: `vendor3`
- Password: `password`
- Email: `vendor3@example.com`
- Events:
  1. "Vendor 3 Event 1 - Ticketed" (paid, 2 variations)
  2. "Vendor 3 Event 2 - Ticketed" (paid, 2 variations)
  3. "Vendor 3 Event 3 - RSVP" (rsvp)

**Locations:**
- All events use Sydney locations (rotated from a predefined list)
- Address fields: `field_location` (Address field)
- Venue names: `field_venue_name`

**Images:**
- Vendor logos: 300x300px, stored in `public://vendor_logos/`
- Event images: 1200x630px, stored in `public://events/`

## Code Quality

- Drupal 11 compatible
- Uses dependency injection
- No deprecated APIs
- No raw SQL (except in README examples)
- Batch deletion for memory efficiency

## Troubleshooting

### Users cannot login

Verify users were created:
```bash
ddev drush sqlq "SELECT uid, name, mail FROM users_field_data WHERE name IN ('vendor2', 'vendor3')"
```

Reset password if needed:
```bash
ddev drush user:password vendor2 password
```

### Images not displaying

Check file permissions:
```bash
ddev exec chmod -R 755 web/sites/default/files
```

Verify files exist:
```bash
ddev exec ls -la web/sites/default/files/events/
ddev exec ls -la web/sites/default/files/vendor_logos/
```

### Events not linked to products

Check product references:
```bash
ddev drush sqlq "SELECT nid, title FROM node_field_data nfd JOIN node__field_product_target npt ON nfd.nid = npt.entity_id WHERE nfd.type='event'"
```

### RSVP not working

Verify RSVP entity exists:
```bash
ddev drush sqlq "SELECT COUNT(*) FROM rsvp_submission"
```

## Safety

- **Reset events**: Deletes ALL events. Use with caution.
- **Purge demo**: Only removes seeded content (vendor2, vendor3). Safe to run.
- **Seed demo**: Idempotent - can be run multiple times (resets events first).

## License

Part of MyEventLane platform.

