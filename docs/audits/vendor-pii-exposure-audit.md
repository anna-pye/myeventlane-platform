# Vendor PII Exposure Audit — Phase 2A.1 Discovery

**Date:** 2026-07-21  
**Branch:** `fix/mel-vendor-access-and-create-flow`  
**Question:** Can organiser B obtain organiser A’s customer information?  
**Status:** Audit only — no runtime behaviour changed  
**PII types in scope:** customer name, email, phone, billing profile, order details, attendee rows, CSV/exports, wallet, messaging/resend, refunds, Views/Twig/JSON

---

## 1. Executive answer

**Yes — organiser B can obtain organiser A customer information today**, via multiple confirmed paths that do not require sharing an event.

Phase 1 repaired several **event-scoped** organiser tools (ticket lists, ticket resend, Studio parity). It did **not** remove broad Commerce / Profile / Messaging grants. Those remain primary leak surfaces.

**Additional Critical (deep pass):** `/dashboard/rsvps` (`myeventlane_vendor_rsvps`) lists attendee names across all organisers with no ownership filter (PII-07).

---

## 2. Confirmed cross-tenant exposures (organiser B → organiser A customers)

### PII-01 — Commerce order entity view/update/delete (Critical)

| Field | Detail |
|---|---|
| **Can B obtain A’s data?** | **Yes** |
| **Evidence** | Order `392` (type `default`, customer uid `1`, store `2`). Vendor UIDs `2,35,72,74` (not customer): `access('view'|'update'|'delete')` = **Y**. |
| **Files** | `config/sync/user.role.vendor.yml` (`update default commerce_order`, `delete default commerce_order`, `access commerce_order overview`); Commerce OrderAccessControlHandler (contrib) |
| **Routes** | Commerce admin order routes; entity operations; any code path calling `$order->access()` |
| **Impact** | Full order PII (email, billing profile refs, line items, adjustments). Update/delete is integrity + privacy. |
| **Launch** | **Blocker** |

### PII-02 — Authenticated users can view any default order (Critical)

| Field | Detail |
|---|---|
| **Can B obtain A’s data?** | **Yes** (even without vendor role) |
| **Evidence** | Auth UIDs `3,6,9,77,78` (roles=`authenticated` only): `order 392->access('view')` = **Y**, `update` = N, not customer. |
| **Files** | `config/sync/user.role.authenticated.yml` → `view default commerce_order` |
| **Impact** | Any logged-in buyer/account can read marketplace orders if they learn/guess IDs or hit leaked links. |
| **Launch** | **Blocker** (platform-wide) |

### PII-03 — Commerce orders overview View (Critical)

| Field | Detail |
|---|---|
| **Can B obtain A’s data?** | **Yes** |
| **Evidence** | `views.view.commerce_orders.yml` access = `access commerce_order overview`. Vendor holds that permission. `AccessManager` for `view.commerce_orders.page_1` = **Y** for vendor uid 35. View has no vendor store filter in access plugin. |
| **Files** | `config/sync/views.view.commerce_orders.yml`; `user.role.vendor.yml` |
| **Impact** | Tabular PII across all stores/orders (emails, customers, totals). |
| **Launch** | **Blocker** |

### PII-04 — Customer profiles (`view any profile`) (Critical)

| Field | Detail |
|---|---|
| **Can B obtain A’s data?** | **Yes** |
| **Evidence** | Customer profiles `1,2,3`: vendor UIDs `1,2,35` all `profile->access('view')` = **Y** regardless of profile owner. |
| **Files** | `user.role.vendor.yml` → `view any profile` |
| **Impact** | Billing/customer profile fields (addresses, phones where present). |
| **Launch** | **Blocker** |

### PII-05 — User email addresses (Critical)

| Field | Detail |
|---|---|
| **Can B obtain A’s data?** | **Yes** |
| **Evidence** | Vendor UIDs `35,72`: target user `2` — `user.access('view')` = Y and `mail` field view = Y. |
| **Files** | `user.role.vendor.yml` → `view user email addresses`, `access user profiles` |
| **Impact** | Direct email harvest of any user account. |
| **Launch** | **Blocker** |

### PII-06 — Resend order confirmation for foreign orders (Critical)

| Field | Detail |
|---|---|
| **Can B obtain A’s data?** | **Yes** (and can trigger email to A’s customer) |
| **Evidence** | `ResendOrderConfirmationAccess` short-circuits on `administer myeventlane messaging` (granted to vendor). Runtime: resend allowed=Y for UIDs `2,35,72` on order `392`. |
| **Files** | `myeventlane_messaging/src/Access/ResendOrderConfirmationAccess.php`; `myeventlane_messaging.routing.yml`; `user.role.vendor.yml` |
| **Routes** | `/admin/myeventlane/orders/{commerce_order}/resend-confirmation` |
| **Impact** | Confirms order existence; re-sends confirmation containing PII; potential harassment/spam against A’s customers. |
| **Launch** | **Blocker** |

### PII-07 — Global RSVP Views listing `/dashboard/rsvps` (Critical)

| Field | Detail |
|---|---|
| **Can B obtain A’s data?** | **Yes** — attendee names (and entity carries email even if not in current display) |
| **Evidence** | View `myeventlane_vendor_rsvps` path `dashboard/rsvps`. Filters only `status != cancelled` — **no** event owner / vendor filter (`config/sync/views.view.myeventlane_vendor_rsvps.yml`). Access plugin `VendorAccess` allows any authenticated user with `create event content` OR `edit own event content` (`myeventlane_rsvp/src/Plugin/views/access/VendorAccess.php`). Vendor role holds both. `rsvp_submission` has no entity access handler scoping query results. |
| **Files** | `config/sync/views.view.myeventlane_vendor_rsvps.yml`; `VendorAccess.php`; `user.role.vendor.yml` |
| **Routes** | `/dashboard/rsvps` |
| **Impact** | Cross-tenant attendee name harvest for every vendor. |
| **Launch** | **Blocker** |

### PII-08 — Legacy check-in toggle unbound attendee ID (High)

| Field | Detail |
|---|---|
| **Can B get A’s PII dump?** | No (boolean response). **Can mutate A’s check-in state** if B owns the route `{node}` and supplies A’s attendee/RSVP ID. |
| **Evidence** | `CheckInController::toggle` asserts route event only; `CheckInStorage::toggleCheckIn()` loads by ID and uses that record’s event — no bind to route node. |
| **Files** | `CheckInController.php`; `CheckInStorage.php` |
| **Launch** | Integrity High — fix with Door Mode consolidation |

### PII-09 — Checkout-paragraph routes gated only by `access content` (High)

| Field | Detail |
|---|---|
| **Surfaces** | `/vendor/orders/{commerce_order}/attendees`, `/vendor/export-attendees/{event}/download`, `/vendor/export-attendees/{event}/request` |
| **Evidence** | `myeventlane_checkout_paragraph.routing.yml`. Soft controller checks / redirects; `queueExport` reported without access call. |
| **Cross-tenant?** | Treat as High until hard `_custom_access` + 403. |
| **Launch** | Harden in 2A/2B |

### PII-10 — Legacy `/dashboard/attendees/export` (Medium)

| Field | Detail |
|---|---|
| **Evidence** | `myeventlane_views.routing.yml`: `_permission: access content`. Relies on author-only `event_attendee` ACH today. |
| **Cross-tenant?** | No under current ACH; residual if ACH widens. |

### ESC-01 — Escalation reopen IDOR (High residual)

| Field | Detail |
|---|---|
| **Evidence** | `myeventlane_escalations_portal.vendor_reopen` requires only `reopen escalations`; `VendorEscalationController::reopen` mutates any ID without party check. |
| **On vendor role today?** | **No** (`reopen escalations` absent from sync vendor). Residual if permission granted. |
| **Launch** | Fix access before granting perm |

---

## 3. Surfaces that appear correctly scoped (organiser B denied)

These answers assume B has **no** workspace parity on A’s event. Confidence based on code + prior Phase 1 tests; not every path re-probed with two live organisers in this run.

| Surface | B can get A’s PII? | Evidence | Confidence |
|---|---|---|---|
| Event Studio | **No** | `EventStudioAccess` + parity | High |
| Ticket groups / access codes / widgets lists | **No** | `EventTicketsAccess` | High |
| Ticket email resend | **No** | `TicketOperationsAccess` + CSRF | High |
| Vendor event orders UI (`/vendor/events/{event}/orders`) | **No** (if assert holds) | `assertEventOwnership` + store/event filters | High |
| Attendee list / Door Mode (canonical) | **No** (if access() holds) | Owner/team checks | High |
| Attendee CSV (`VendorAttendeeController::export`) | **No** (same access) | Export after access | High |
| Paragraph attendee export | **No** | `EventVendorAccessChecker` | High |
| Analytics per-event | **No** | Task 12 parity | High |
| Refund approve/reject (event-scoped) | **No** (if resolver holds) | `VendorRefundRequestAccessCheck` | Medium–High |
| Wallet pass download | N/A (buyer entitlement) | `WalletDownloadAccessChecker` | High |

---

## 4. PII surface catalogue

### 4.1 Vendor UI (Twig / controllers)

| Surface | PII shown | Scoped by | Cross-tenant? |
|---|---|---|---|
| Event orders list/detail | purchaser name, email, totals, status | Event ownership + store | No (console path) |
| Attendee list / ops | name, email, ticket, check-in | Event access | No (intended) |
| RSVP list | name, email, status | RSVP vendor access | No (intended) |
| Promotion / messaging UI | recipient lists for event | Event access | No (intended) |
| Dashboard KPIs | aggregates | Vendor metrics services | Review queries (no foreign order dump found in this pass) |

### 4.2 CSV / downloads

| Export | Builder | Access | Cross-tenant? |
|---|---|---|---|
| Attendee CSV | `MelAttendeeExportBuilder` | Event access | No (intended) |
| Obfuscated attendee CSV | same + obfuscate flag | Event access | No |
| Waitlist CSV | Waitlist controller | Event access | No (intended) |
| Checkout-paragraph export | `AttendeeExportController` | Parity | No (intended) |
| Boost vendor export | Boost export controller | Console / event | Medium confidence |
| Reporting export centre | Pro `request exports` | Reporting access | Pro-only |

### 4.3 Messaging / email

| Action | PII | Cross-tenant? |
|---|---|---|
| Ticket resend | Ticket holder email | No (Phase 1) |
| Order confirmation resend | Order email + contents | **Yes (PII-06)** |
| Event promotion blast | Attendee emails for event | No if ownership holds |
| Automation workers | Attendee repository | Service-scoped; not a browser path |

### 4.4 Wallet / calendar / JSON / REST

| Surface | PII | Cross-tenant vendor risk |
|---|---|---|
| Apple/Google Wallet | Holder name/event on pass | Buyer-scoped; vendors shouldn’t access |
| Ticket check-in JSON APIs | Attendee search results | EventTicketsAccess / assert |
| Legacy check-in search JSON | Attendee search | Controller assert (route weak) |
| Vendor API base | Event-scoped helpers | Review if API enabled in prod |

### 4.5 Views

| View | Access | Cross-tenant? |
|---|---|---|
| `commerce_orders` | `access commerce_order overview` | **Yes** |
| `commerce_carts` | same perm | **Likely yes** (cart emails/IDs) |
| `myeventlane_vendor_rsvps` (`/dashboard/rsvps`) | `myeventlane_rsvp_vendor_access` (create/edit event perms) | **Yes (PII-07)** — no organiser filter |

---

## 5. Ownership boundary map (Stage 5)

### 5.1 Ownership services

| Service | Model | Entities |
|---|---|---|
| `EventVendorAccessChecker` | Author **or** vendor entity owner **or** `field_vendor_users` | Event node |
| `VendorConsoleBaseController::assertEventOwnership` | Same parity (duplicated inline) | Event console |
| `EventAccess` / `EventTicketsAccess` / `TicketOperationsAccess` | Admin tickets **or** manage-own+parity **or** console+parity | Tickets tools |
| `MelAttendeeOperationsAccess` | Admin perms **or** parity | Attendees ops |
| `VendorOwnershipResolver` | Store ↔ event vendor/store linkage | Refunds, store |
| `RefundAccessResolver` | Owner **or** store owns event | Refunds |
| `VendorAttendeeController::access` | Owner **or** `field_vendor_users` only | Attendees UI |
| RSVP `canManageEvent` helpers | Owner **or** team (variants) | RSVP |
| `ResendOrderConfirmationAccess` | **Broken bypass** via messaging admin | Orders |
| `WalletDownloadAccessChecker` | Buyer / ticket assignee | Wallet |

### 5.2 Duplication

- Parity logic copied in: `EventVendorAccessChecker`, `VendorConsoleBaseController`, messaging `VendorNotifyForm`, RSVP controllers, check-in controller, attendee controller (partial).
- Refund path uses **store** ownership, not the same parity helper.

### 5.3 Inconsistencies

| Inconsistency | Effect |
|---|---|
| Attendee controller misses vendor entity owner uid | False **deny** for some legitimate owners |
| Refund store model vs workspace parity | False deny/allow divergence |
| `EventAccess::canManageEventTickets` requires `manage own events tickets` (not on vendor) | Dead primary path; fallback required |
| Messaging admin bypasses all ownership | False **allow** cross-tenant |
| Commerce “Default: * orders” ≠ “own orders” | False **allow** platform-wide |
| RSVP Views vs RSVP event routes | Routes use parity; Views unfiltered | False **allow** cross-tenant names |
| Check-in toggle ID vs route event | Mutation on foreign attendees | Integrity IDOR |

---

## 6. Risk classification (Stage 7) — PII-focused

| ID | Severity | Description | Files / routes | Launch impact | Recommended fix | Size |
|---|---|---|---|---|---|---|
| PII-01 | **Critical** | Vendors update/delete/view any default order | role YAML; Commerce entity access | Blocker | Remove grants; custom order access | M |
| PII-02 | **Critical** | All authenticated users view any default order | `user.role.authenticated.yml` | Blocker | Remove `view default commerce_order` | S |
| PII-03 | **Critical** | Orders overview View lists all orders | `views.view.commerce_orders.yml`; vendor perm | Blocker | Remove vendor overview perm; staff-only View | S |
| PII-04 | **Critical** | View any customer profile | vendor role | Blocker | Remove; event-scoped DTOs | S |
| PII-05 | **Critical** | View any user email | vendor role | Blocker | Remove | S |
| PII-06 | **Critical** | Resend confirmation cross-tenant | `ResendOrderConfirmationAccess`; messaging perm | Blocker | Remove bypass; ownership-only | S–M |
| PII-07 | **Critical** | `/dashboard/rsvps` unfiltered RSVP names | `views.view.myeventlane_vendor_rsvps.yml`; `VendorAccess` | Blocker | Scope query by organiser or retire View; parity access | S–M |
| PII-08 | High | Check-in toggle attendee≠event bind | `CheckInStorage::toggleCheckIn` | Integrity | Bind attendee to route event | S |
| PII-09 | High | checkout_paragraph export/order routes `access content` | routing + controllers | Harden before launch | Hard `_custom_access` | S–M |
| PII-10 | Medium | Legacy `/dashboard/attendees/export` | `myeventlane_views` | Residual | Retire or harden | S |
| ESC-01 | High | Vendor escalation reopen permission-only IDOR | `vendor_reopen` route; `VendorEscalationController::reopen` | Residual (perm **not** on vendor sync today) | Add `isVendor` / party check | S |
| R-06 | Medium | Check-in route lacks ownership | `myeventlane_checkin.routing.yml` | Non-blocker if controller holds | `_custom_access` parity | S |
| OWN-01 | Medium | Attendee access ≠ full parity | `VendorAttendeeController` | Support friction | Use EventVendorAccessChecker | S |
| OWN-02 | Medium | Refund ownership model drift | `RefundAccessResolver` | Edge-case | Unify on parity | M |
| OWN-03 | Medium | Duplicated ownership helpers | multiple modules | Drift risk | Single service | M |
| INFO-01 | Info | Phase 1 ticket/studio hardening | PR #696 | Positive | Preserve | — |

---

## 7. Assumptions

1. DDEV database used for runtime probes is representative of sync roles (confirmed active=sync for vendor).
2. Order `392` is a normal marketplace `default` order (not a special test fixture with open access hooks).
3. No custom `hook_entity_access` in MEL overrides Commerce to *restrict* these grants (none found that would negate the probes).
4. Production/staging must be re-checked after role config deploy — this audit did not modify remote environments.

---

## 8. What “own” means (do not trust the label)

| Permission string | Actual meaning in this codebase / Commerce |
|---|---|
| `view own commerce_order` | Orders where user is **customer** |
| `update default commerce_order` | Update **any** order of bundle `default` |
| `view own event attendees` | Permission flag only; real isolation is controller ownership |
| `manage own events tickets` | Only meaningful with parity; **not even granted** to vendor |
| `administer myeventlane messaging` | Global messaging admin — not “own events” |

---

## 9. Validation commands (read-only)

```bash
ddev drush php:eval '/* order/profile/email/resend AccessManager probes — no PII written to docs */'
```

No PHP/YAML/config mutations; no commits.
