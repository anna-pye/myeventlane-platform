# Vendor Route Access Audit — Phase 2A.1 Discovery

**Date:** 2026-07-21  
**Branch:** `fix/mel-vendor-access-and-create-flow`  
**Scope:** Vendor-facing routes — permission callbacks, custom access, entity access, ownership  
**Status:** Audit only — no runtime behaviour changed

---

## 1. Access control patterns in use

| Pattern | Typical use | Ownership? |
|---|---|---|
| `_custom_access: myeventlane_vendor.access.vendor_console:access` | Most `/vendor/*` console routes | **No** — role/permission/onboarding only |
| Controller `assertEventOwnership()` | Orders, settings, overview, etc. | **Yes** — author / vendor owner / team |
| `_custom_access: EventTicketsAccess` | Ticket groups, access codes, widgets, ticket manager | **Yes** — manage-own **or** console + parity |
| `_custom_access: TicketOperationsAccess::accessResend` | Ticket email resend | **Yes** + `resend ticket emails` |
| `_custom_access: EventStudioAccess` | Event Studio workspace | **Yes** — workspace parity (Phase 1) |
| `_custom_access: MelAttendeeOperationsAccess` / controller access | Attendees, door, exports | **Partial–Yes** (parity variants differ) |
| `_permission` only | Check-in module routes, some messaging | **No at route layer** |
| `_entity_access` | Some venue/entity routes | Entity handler dependent |
| Commerce Views `perm: access commerce_order overview` | `/admin/commerce/orders` | **No ownership** |

Canonical ownership service (post–Phase 1):  
`Drupal\myeventlane_vendor\Service\EventVendorAccessChecker::accountHasWorkspaceParityForEvent()`  
— event author **or** linked vendor entity owner uid **or** `field_vendor_users` member.

---

## 2. Route category matrix

### 2.1 Vendor Dashboard / console shell

| Route / path | Access | Ownership | PII risk | Confidence |
|---|---|---|---|---|
| `/vendor`, `/vendor/dashboard` | `VendorConsoleAccess` | N/A (aggregate) | Aggregates should be scoped in controllers | High |
| `/vendor/events` | console access | List builders must filter | Cross-event leak if query unscoped | Medium |
| `/dashboard` | (gateway) | — | — | Medium |

Evidence: `myeventlane_vendor.routing.yml`; `VendorConsoleAccess.php`.

**Finding R-01 (Medium):** Console access alone is not ownership. Safe only when controllers call `assertEventOwnership` / parity before loading PII.

---

### 2.2 Event Studio

| Route family | Access | Ownership | Notes | Confidence |
|---|---|---|---|---|
| `/vendor/events/{nid}/studio/*` | `EventStudioAccess` | Parity (Phase 1: not `node.update` alone) | Mutation routes aligned in Phase 1 | High |
| Create / draft-choice | console + create services | Author-scoped draft lookup | Phase 1 draft choice | High |

Evidence: `myeventlane_event_studio.routing.yml`; `EventStudioAccess.php`; Phase 1 report.

**Verdict:** Cross-organiser Studio access denied when parity fails. Residual risk is parallel legacy edit routes (below).

---

### 2.3 Orders

| Route | Path | Access | Ownership | PII | Confidence |
|---|---|---|---|---|---|
| Event orders list | `/vendor/events/{event}/orders` | console | `assertEventOwnership` + store filter in query | purchaser name/email | High |
| Event order view | `/vendor/events/{event}/orders/{order}` | console | Controller ownership (must verify order∈event) | Full order PII | Medium–High |
| Commerce orders View | admin commerce orders | `access commerce_order overview` | **None** | **All orders** | **High** |
| Entity order canonical / admin forms | Commerce routes | `view/update/delete default commerce_order` | **None** (bundle-wide) | **All default orders** | **High** |

Evidence:

- Controllers: `VendorEventOrdersController.php` (assert + store IDs + event item filter).
- Runtime: vendor can access orders overview route; entity update/delete on foreign order 392 = Y.
- Config: `views.view.commerce_orders.yml` access perm = `access commerce_order overview`.

**Finding R-02 (Critical):** Commerce admin/entity order paths bypass vendor console ownership.

**Finding R-03 (High):** Event order detail must reject order IDs not belonging to the event (controller-level). Console route gate alone is insufficient — treat as required IDOR test in Phase 2.

---

### 2.4 Attendees / Door Mode / exports

| Route | Access | Ownership | PII | Confidence |
|---|---|---|---|---|
| `/vendor/events/{node}/attendees` (+ ops) | `VendorAttendeeController::access` | Owner **or** `field_vendor_users` (**not** vendor entity owner uid) | Names, emails, phones | High |
| Door Mode ops | console / attendee access variants | Intended parity | Check-in PII | High |
| CSV export (`vendor_export`) | Same controller family + `MelAttendeeExportBuilder` | Same as list | Full CSV PII | High |
| Paragraph export `AttendeeExportController` | `EventVendorAccessChecker` parity | Full parity incl. vendor owner | CSV PII | High |
| Waitlist export | Waitlist controller access | Ownership variant | Email/name | Medium |

**Finding R-04 (Medium):** `VendorAttendeeController::access` omits vendor entity owner uid that `EventVendorAccessChecker` includes — parity inconsistency (false deny for owner-only vendor accounts, not cross-tenant allow).

**Finding R-05 (Low–Medium):** Multiple export builders; canonical builder exists (`MelAttendeeExportBuilder`) but parallel exporters remain.

---

### 2.5 Ticket Groups / Access Codes / Widgets

| Route family | Access | Ownership | Phase 1 | Confidence |
|---|---|---|---|---|
| `.../tickets/groups*` | `EventTicketsAccess` | Yes | Fixed from bare permission | High |
| `.../tickets/access-codes*` | `EventTicketsAccess` | Yes | Already correct / retained | High |
| `.../tickets/widgets*` | `EventTicketsAccess` | Yes | Fixed | High |
| Ticket resend | `resend ticket emails` + `TicketOperationsAccess` | Yes + CSRF | Fixed | High |

**Verdict:** Event-scoped ticket tool routes are in good shape post–Phase 1. Cross-organiser denied when parity fails.

---

### 2.6 Check-in (legacy module)

| Route | Path | Route access | Controller ownership | Confidence |
|---|---|---|---|---|
| page/list/search | `/vendor/events/{node}/check-in*` | `_permission: access check-in` only | `assertEventAccess` (parity) | High |
| scan | `.../check-in/scan` | `scan qr codes` | assert + 302 to Door Mode | High |
| toggle | `.../toggle/{attendee_id}` | `toggle check-in status` + POST | assert on `{node}` only; **attendee ID not bound to node** | High |

Runtime: `checkNamedRoute(myeventlane_checkin.page)` = **Y** for vendor 35 on event 1599 where parity = **N**.

**Finding R-06 (Medium):** Route layer allows; controller denies. Menu/link leakage + weaker defence-in-depth.

**Finding R-10 (High):** `CheckInStorage::toggleCheckIn()` loads attendee/RSVP by ID and uses that entity’s event — cross-event check-in mutation IDOR (PII-08).

Ticket check-in PWA routes under `myeventlane_tickets` combine `check in tickets` + `EventTicketsAccess` — stronger pattern.

### 2.6b Global RSVP Views

| Route / path | Access | Ownership | PII | Confidence |
|---|---|---|---|---|
| `/dashboard/rsvps` (`myeventlane_vendor_rsvps`) | Views plugin: create/edit event content | **None** — filter is status only | Attendee names | **High** |

**Finding R-11 (Critical):** Cross-tenant RSVP name listing (PII-07).

---

### 2.7 Messaging / Resend

| Route | Path | Access | Ownership | Confidence |
|---|---|---|---|---|
| Resend order confirmation | `/admin/myeventlane/orders/{commerce_order}/resend-confirmation` | `ResendOrderConfirmationAccess` | **Bypassed** if `administer myeventlane messaging` | **High** |
| Messaging settings | `/admin/config/myeventlane/messaging` | `administer myeventlane messaging` | Admin settings | High |
| Vendor promotion/comms | `/vendor/events/{event}/promotion` | `VendorCommsController::checkAccess` | Event-scoped (verify) | Medium |
| Postmark webhooks | `/webhooks/postmark/*` | `_access: TRUE` + secret header | N/A | Medium (secret config) |

Runtime: resend allowed=Y for vendors 2/35/72 on foreign order 392.

**Finding R-07 (Critical):** Order confirmation resend is cross-tenant for any vendor.

---

### 2.8 Refunds

| Route family | Access | Ownership | Confidence |
|---|---|---|---|
| Approve/reject refund request | `manage_refunds` + `VendorRefundRequestAccessCheck` | `RefundAccessResolver::vendorCanManageEvent` | High |
| Direct vendor refund form | `manage_refunds` + form access | Event/order scoped | High |
| Cancel event | `cancel_events` + form access | Event scoped | High |

**Finding R-08 (Medium):** `RefundAccessResolver` uses store ownership via `VendorOwnershipResolver`, not identical to workspace parity (author / vendor owner / team). Possible false deny or divergent allow vs console.

---

### 2.9 Wallet

| Route | Access | Ownership | PII | Confidence |
|---|---|---|---|---|
| Apple/Google wallet download | `access content` + `WalletDownloadAccessChecker` | Buyer/ticket entitlement (not vendor) | Ticket holder PII in pass | High |
| Admin wallet | `administer myeventlane wallet` | Staff | — | High |

**Verdict:** Wallet is buyer-scoped; vendors should not use these routes for attendee PII. Risk is buyer IDOR if entitlement checks fail — covered by wallet unit tests; not re-proven in this audit.

---

### 2.10 Customer profiles / users

| Surface | Access | Ownership | Confidence |
|---|---|---|---|
| Profile entity view | `view any profile` | **None** | High |
| User email field | `view user email addresses` | **None** | High |
| User profiles | `access user profiles` | **None** | High |

**Finding R-09 (Critical):** Core profile/user permissions grant cross-tenant identity access outside event context.

---

### 2.11 Reporting / exports centre

| Route | Access | Ownership | Notes |
|---|---|---|---|
| Vendor insights | `view vendor insights` + `VendorReportingAccess` | Reporting access class | mel_pro typically |
| Event insights / charts | `view event insights` + custom | Event-scoped checkers | Pro |
| Export centre | `request exports` + access | Pro | |

Base vendor role lacks most of these — reduces exposure for non-Pro organisers.

---

### 2.12 RSVP

| Route family | Access | Ownership | Confidence |
|---|---|---|---|
| Vendor RSVP list/export/check-in | `myeventlane_rsvp.vendor_event_access` | Custom event access | High |
| Some routes | `manage own event rsvps` only | Weaker | Medium |

`QrCheckinController::canManageEvent` duplicates ownership logic (author + `field_vendor_users`, may miss vendor entity owner).

---

### 2.13 Legacy singular `/vendor/event/{event}/*`

| Routes | Access | Risk | Confidence |
|---|---|---|---|
| design/content/tickets/checkout-questions/series | Mixed custom | Parallel to Studio | High |
| promote/payments/comms/advanced | Stubs / weak | UX dead ends; access still matters | High |

Independent audit F-04 still applies for consolidation (not all security Critical).

---

## 3. IDOR checklist (entity ID in path)

| Parameter | Safe pattern | Unsafe pattern | Status |
|---|---|---|---|
| `{event}` / `{node}` event | Parity / assertEventOwnership | Permission-only | Check-in route layer unsafe; most console controllers safe |
| `{order}` under event | Order must belong to event + ownership | Order load by ID only | Needs Phase 2 automated IDOR tests |
| `{commerce_order}` resend | Ownership of all events on order | Admin messaging perm | **Broken** |
| `{myeventlane_ticket}` resend | TicketOperationsAccess | Permission only | Fixed Phase 1 |
| `{event_attendee}` | accessAttendee → event access | — | OK if event access OK |
| Profile / user ID | Must not be vendor-browsable | `view any profile` | **Broken** |

---

## 4. Routes that rely only on role/permission (no ownership)

| Area | Example | Severity |
|---|---|---|
| Commerce order overview View | `access commerce_order overview` | Critical |
| Commerce order entity ops | `update/delete default commerce_order` | Critical |
| Messaging admin + resend bypass | `administer myeventlane messaging` | Critical |
| Profile/user PII | `view any profile`, `view user email addresses` | Critical |
| Check-in routes (layer) | `access check-in` etc. | Medium (controller mitigates) |
| Console shell without event | `access vendor console` | Expected; aggregate queries must filter |

---

## 5. Ownership services referenced by routes

| Service / class | Used by |
|---|---|
| `EventVendorAccessChecker` | Studio, tickets access fallback, exports, attendee ops, check-in controller |
| `VendorConsoleBaseController::assertEventOwnership` | Most event console controllers |
| `EventTicketsAccess` / `EventAccess` / `TicketOperationsAccess` | Tickets workspace |
| `MelAttendeeOperationsAccess` | Attendee operations layer |
| `RefundAccessResolver` + `VendorOwnershipResolver` | Refunds |
| `ResendOrderConfirmationAccess` | Order email resend (**bypass bug**) |
| `VendorReportingAccess` / event insights access | Reporting |
| `WalletDownloadAccessChecker` | Wallet (buyer) |
| `VendorConsoleAccess` | Console gate only |

---

## 6. Category verdict

| Category | Organiser A/B isolation | Notes |
|---|---|---|
| Event Studio | **OK** (post Phase 1) | |
| Ticket groups/codes/widgets | **OK** | |
| Ticket resend | **OK** | |
| Vendor event orders UI | **Likely OK** | Confirm order IDOR tests |
| Door Mode / attendee CSV (canonical) | **Mostly OK** | Parity edge cases |
| Legacy check-in module | **Partial** | Controller OK; route weak |
| Commerce admin orders | **FAIL** | Critical |
| Order confirmation resend | **FAIL** | Critical |
| Profiles / user email | **FAIL** | Critical |
| `/dashboard/rsvps` Views | **FAIL** | Critical — unfiltered attendee names |
| Checkout-paragraph export/order paths | **FAIL / soft** | High — `access content` only |
| Legacy check-in toggle | **Partial/weak** | High integrity (attendee≠event) |
| Escalation reopen | **FAIL if perm granted** | High residual (perm not on vendor sync) |
| Refunds | **Mostly OK** | Ownership model drift |
| Wallet | Buyer-scoped | Separate threat model |

---

## 7. Validation (read-only)

```text
DDEV AccessManager::checkNamedRoute(myeventlane_checkin.page) = Y without parity
DDEV AccessManager orders overview = Y for vendor
DDEV ResendOrderConfirmationAccess::check foreign order = allowed
Entity order access update/delete = Y for foreign vendors
```

Drush `route:list` unavailable in this Drush build (`route` namespace missing); routing YAML + AccessManager used instead.
