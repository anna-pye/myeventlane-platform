# MyEventLane SaaS Dashboard Routing Refactor Report

## Scope

- Refactor route ownership only for shell/dashboard/workspace concerns.
- Preserve existing business logic handlers and forms.
- No core or Commerce module changes.

## Modules Scanned for Dashboard Routes

Routing files under `web/modules/custom` were scanned for route paths containing:

- `dashboard`
- `vendor`
- `admin`
- `event`

Primary dashboard/shell ownership findings:

- `myeventlane_vendor`
- `myeventlane_admin_dashboard`
- `myeventlane_dashboard`
- `mel_admin_dashboard`
- `myeventlane_vendor_comms`

## Duplicate/Split Ownership Identified

- `/dashboard`
  - Previously in: `myeventlane_dashboard`
  - Moved to shell owner: `myeventlane_vendor`
- `/admin/myeventlane`
  - Previously in: `myeventlane_admin_dashboard` and `mel_admin_dashboard`
  - Final single owner: `myeventlane_admin_dashboard`
- Vendor comms workspace pages were shell-split:
  - Previously in: `myeventlane_vendor_comms`
  - Final owner: `myeventlane_vendor` (route definitions moved)

## Routes Moved / Added

### Vendor shell (`myeventlane_vendor`)

Moved into shell ownership:

- `/dashboard`
- `/vendor`
- `/vendor/events/{event}/promotion`
- `/vendor/events/{event}/promotion/branding`

Added workspace shell routes:

- `/vendor/events/{event}`
- `/vendor/events/{event}/publish`

Preserved existing workspace routes (already shell-owned):

- `/vendor/events/{event}/overview`
- `/vendor/events/{event}/tickets`
- `/vendor/events/{event}/attendees`
- `/vendor/events/{event}/orders`
- `/vendor/events/{event}/analytics`
- `/vendor/events/{event}/settings`

### Admin shell (`myeventlane_admin_dashboard`)

Single-owner confirmed for:

- `/admin/myeventlane`

Normalized admin shell paths to `/admin/myeventlane` prefix:

- `/admin/myeventlane/platform`
- `/admin/myeventlane/review/{node}/approve-deploy`

## Duplicate Routes Removed

- Removed `mel_admin_dashboard` ownership of `/admin/myeventlane` route.
- Removed `myeventlane_dashboard` ownership of `/dashboard`.
- Removed `myeventlane_vendor_comms` module-local routing ownership (shell now owns those route definitions).

## Modules Converted to Service/Widget Role

- `myeventlane_vendor_analytics`
  - No standalone routing; remains service-provider module.
- `myeventlane_vendor_nudges`
  - No standalone routing; remains service/widget module.
- `myeventlane_vendor_comms`
  - Routing moved into shell module.
  - Module continues to provide form/service/queue logic without owning shell pages.

## Navigation Updates

### Vendor sidebar

Updated to shell-owned navigation model:

- Dashboard
- Events
- Orders
- Attendees
- Audience
- Payouts
- Growth
- Settings

### Admin sidebar

Updated to shell-owned navigation model:

- Overview
- Vendors
- Events
- Finance
- Payouts
- Reports
- Escalations
- Support
