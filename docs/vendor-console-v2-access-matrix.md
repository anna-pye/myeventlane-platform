# MEL Vendor Console v2 — access matrix (TASK 11)

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Purpose:** Single reference for route-level and controller-level access before/after TASK 11 alignment. No UI redesign.

## Canonical access model (TASK 11)

### Base vendor console

| Actor | Policy |
| ----- | ------ |
| Anonymous | Denied on all `/vendor/*` console routes using `VendorConsoleAccess` (except Stripe path carve-outs handled in that service). |
| Ordinary customer (no organiser trust) | Denied: must satisfy `VendorConsoleTrust` ( **`access vendor console`** permission or **`vendor`** role ) plus onboarding rules in `VendorConsoleAccess`. |
| Vendor owner | Allowed when onboarding complete (or allowed onboarding/Event Studio paths during onboarding per existing rules). |
| Team member (`field_vendor_users`) | Same vendor-console trust + onboarding as owner for route checks; event-scoped surfaces additionally require workspace parity (owner **or** listed team member on linked vendor), aligned with `EventVendorAccessChecker::accountHasWorkspaceParityForEvent()` and `VendorConsoleBaseController::assertEventOwnership()`. |
| Admin/staff | **Explicit bypasses only** (documented per surface): e.g. `administer nodes` in `VendorConsoleAccess`, `administer commerce_order` / `bypass node access` on selected commerce/vendor dashboards, RSVP admins where noted — **no silent widen**. |

### Event-scoped surfaces

| Requirement | Meaning |
| ----------- | ------- |
| Workspace parity | User is event author **or** appears on `field_event_vendor` → `field_vendor_users` for that event (`EventVendorAccessChecker`). |
| `node.update` | Event Studio edit routes retain `_entity_access: node.update` (team members must still satisfy Drupal node access where applicable). TASK 11 adds **controller** parity check so broad node ACL cannot substitute for vendor workspace membership unless `administer nodes` bypass applies. |
| Stronger gates preserved | `EventTicketsAccess` + ticket permissions, RSVP `manage own event rsvps` on legacy RSVP routes, attendee permission `view own event attendees`, check-in permissions (`myeventlane_checkin.access`, `.scan`, `.toggle`), Pro gates (`_myeventlane_pro_access`, `use pro financial analytics`, runtime Pro resolver). |

### Decision legend

| Decision | Meaning |
| -------- | ------- |
| keep | No change required for TASK 11. |
| tighten | Additional server-side check added. |
| add ownership check | Explicit parity/ownership beyond generic permission. |
| align to VendorConsoleAccess | Route gains or relies on `VendorConsoleAccess` where safe. |
| preserve stronger access | Keeps stricter permission/service; only strengthens parity elsewhere. |
| defer | Out of TASK 11 scope (documented reason). |

---

## Matrix (high-traffic vendor console routes)

| Route path | Route name | Owner module | Current access | Desired access | Admin/staff bypass | Vendor owner | Team member | Customer | Decision |
| ---------- | ----------- | ------------ | -------------- | -------------- | ------------------ | ------------ | ----------- | -------- | -------- |
| `/vendor/dashboard` | `myeventlane_vendor.console.dashboard` | `myeventlane_vendor` | `VendorConsoleAccess` + dashboard permission inside service | Same | `administer nodes` | Yes | Yes (trust + dashboard permission) | Denied | keep |
| `/vendor/events` | `myeventlane_vendor.console.events` | `myeventlane_vendor` | `VendorConsoleAccess` | Same | `administer nodes` | Yes | Yes | Denied | keep |
| `/vendor/events/create` | `myeventlane_event_studio.create` | `myeventlane_event_studio` | `access content` + `VendorConsoleAccess` | Same | `administer nodes` (via VC) | Yes | Yes | Denied | keep |
| `/vendor/events/{node}/edit` (+ section routes) | `myeventlane_event_studio.edit` etc. | `myeventlane_event_studio` | `_entity_access: node.update` | Same **plus** controller workspace parity (non-admin) | `administer nodes` | Yes | Yes if parity + node access | Denied | tighten |
| `/vendor/events/autosave` | `myeventlane_event_studio.autosave` | `myeventlane_event_studio` | `_permission: access content` only | `VendorConsoleAccess` + controller: anonymous deny; create allowed only if create route allows; existing nodes require update + workspace parity (admin bypass) | `administer nodes` | Yes | Yes | Denied | tighten |
| `/vendor/events/{event}` workspace | `myeventlane_vendor.console.event_workspace` | `myeventlane_vendor` | `VendorConsoleAccess` + `assertEventOwnership` in controller | Same | `administer nodes` via assertion path | Yes | Yes | Denied | keep |
| `/vendor/events/{event}/tickets` | `myeventlane_vendor.console.event_tickets` | `myeventlane_vendor` | `EventTicketsAccess` | Same; membership branch uses shared checker | `administer nodes` inside access class | Yes | Yes | Denied | preserve stronger access + dedupe parity |
| Ticket submodule routes | various | `myeventlane_tickets` | `EventTicketsAccess` / ticket ops | Same | As implemented | Yes | Yes | Denied | preserve stronger access |
| `/vendor/events/{event}/rsvps` | `myeventlane_vendor.console.event_rsvps` | `myeventlane_vendor` | `VendorConsoleAccess` + controller ownership | Same | `administer nodes` | Yes | Yes | Denied | keep |
| `/vendor/event/{event}/rsvps` (+ export/check-in legacy) | `myeventlane_rsvp.*` | `myeventlane_rsvp` | `VendorEventAccess` (permission + parity) | Same; parity delegated to `EventVendorAccessChecker` | `administer rsvps` / `administer nodes` | Yes | Yes with `manage own event rsvps` | Denied | preserve stronger access + dedupe parity |
| `/vendor/events/{event}/orders` | `myeventlane_vendor.console.event_orders` | `myeventlane_vendor` | `VendorConsoleAccess` + ownership | Same | `administer nodes` | Yes | Yes | Denied | keep |
| `/vendor/events/{node}/attendees` | `myeventlane_event_attendees.vendor_list` | `myeventlane_event_attendees` | `VendorConsoleAccess` | Same | Admin attendee permission on export path; VC on list | Yes | Yes via export access rules | Denied | keep |
| `/vendor/events/{node}/attendees/export` | `myeventlane_event_attendees.vendor_export` | `myeventlane_event_attendees` | `VendorAttendeeController::access` | Same | `administer event attendees` | Yes | Yes | Denied | keep |
| `/vendor/events/{node}/check-in` (+ scan/list/search/toggle) | `myeventlane_checkin.*` | `myeventlane_checkin` | Route permissions + **controller owner-only** | Route permissions + controller **workspace parity** (+ existing admin paths) | `administer nodes` parity | Yes | Yes | Denied | tighten |
| `/vendor/analytics` | `myeventlane_analytics.dashboard` | `myeventlane_analytics` | `VendorConsoleAccess` + Pro route gate | Same | As Pro + VC | Yes | Yes | Denied | keep |
| `/vendor/events/{event}/analytics` | `myeventlane_vendor.console.event_analytics` | `myeventlane_vendor` | VC + Pro permission + runtime Pro | Same | `administer nodes` | Yes | Yes | Denied | keep |
| `/vendor/analytics/event/{node}` (+ exports) | `myeventlane_analytics.event` / exports | `myeventlane_analytics` | `AnalyticsDashboardController::accessEvent` + Pro | Same + `administer nodes` allowed | `administer nodes` + attendee admin as already coded | Yes | Yes | Denied | tighten (admin parity) |
| `/vendor/settings` | `myeventlane_vendor.console.settings` | `myeventlane_vendor_settings` | `VendorConsoleAccess` | Same | `administer nodes` | Yes | Yes | Denied | keep |
| `/vendor/dashboard/messaging/brand` | `myeventlane_vendor.console.messaging_brand` | `myeventlane_vendor` | `VendorConsoleAccess` | Same | As VC | Yes | Yes | Denied | keep |
| `/vendor/settings/branding` | `myeventlane_pro.branding` | `myeventlane_pro` | `ProBrandingController::access` + Pro route gate | Pro + **resolved vendor** (owner/team via `CurrentVendorResolver`) + admin bypass | `administer nodes` | Yes | Yes | Denied | tighten (team/role alignment) |
| `/vendor/events/{event}/promotion` (+ branding subroutes) | vendor comms | `myeventlane_vendor_comms` | `_entity_access: node.view` + `VendorCommsController::checkAccess` | Same; `checkAccess` gains workspace parity so store-only owner resolution cannot deny team | commerce/bypass as today | Yes | Yes | Denied | add ownership check (parity) |
| `/vendor/attendees` | `myeventlane_checkout_flow.vendor_attendees` | `myeventlane_checkout_flow` | `VendorAttendeesController::checkAccess` | Same; underlying store resolution fixed for **team** via `VendorOwnershipResolver` | commerce/bypass | Yes | Yes | Denied | add ownership check (store via vendor entity) |
| Organiser header block create link | N/A (block) | `myeventlane_core` | Broken route `myeventlane_vendor.console.create_event` | `myeventlane_event_studio.create` | N/A | N/A | N/A | N/A | fix fatal |

---

## Notes on deliberate differences

- **Legacy RSVP (`VendorEventAccess`)** still requires **`manage own event rsvps`** for non-admin users; workspace RSVP uses `VendorConsoleAccess` and controller ownership — **not** identical permission shape; both deny cross-vendor via shared parity rules where applicable.
- **Attendee list vs export:** list route uses `VendorConsoleAccess` (must reach console); export uses `VendorAttendeeController::access` with attendee-specific permissions — **stronger** on export path; preserved.
- **Public `/node/add/event` in consumer theme footers:** classified **TASK 12 / public theme** — not changed in TASK 11 per brief.

---

## Residual risks (TASK 12+)

- Menu/account dropdown routes not exhaustively re-grep’d beyond OrganiserContextBlock create link.
- Dual RSVP UX (workspace vs legacy URLs) remains; access parity improved but product may still consolidate redirects.
- `VendorConsoleAccess` onboarding exceptions for `/vendor/events/*` remain broad by design (pre-existing).
