# Vendor Permission Hardening — Phase 2 Implementation Plan

**Date:** 2026-07-21  
**Input audits:**

- `docs/audits/vendor-permission-inventory.md`
- `docs/audits/vendor-route-access-audit.md`
- `docs/audits/vendor-pii-exposure-audit.md`

**Prerequisite completed:** PR #696 Phase 1 Trust & Access Repair  
**This document:** Implementation plan only — no code or config changes performed in discovery

---

## Goal

Ensure organisers can access **only**:

- their own events, orders, attendees, ticket groups, access codes, widgets  
- their own check-ins, refunds, exports, customer information  

…and that **Commerce / Profile / Messaging** grants cannot bypass event ownership.

---

## Commerce permission review (Stage 4) — decisions for implementers

| Permission | Current holder | Decision |
|---|---|---|
| `view default commerce_order` | authenticated | **Remove** — replace with buyer `view own commerce_order` only |
| `update default commerce_order` | vendor | **Replace with custom access service** (event/store ownership) |
| `delete default commerce_order` | vendor | **Remove** from vendor (staff only) |
| `access commerce_order overview` | vendor | **Remove** from vendor; staff/admin only |
| `unlock orders` | vendor | **Needs product decision** (default: remove) |
| `manage default commerce_order_item` | vendor | **Needs Commerce architecture review** |
| `view own commerce_order` | vendor + authenticated | **KEEP** |
| `access checkout` | vendor + authenticated | **KEEP** |
| `view any profile` | vendor | **Remove** |
| `view user email addresses` | vendor | **Remove** |
| `access user profiles` | vendor | **Remove** |
| `administer myeventlane messaging` | vendor | **Remove**; resend via ownership checker only |
| `resend ticket emails` | vendor | **KEEP** |
| `access vendor console` / dashboard | vendor | **KEEP** |
| `manage_refunds` / `cancel_events` | vendor | **KEEP** + unify ownership |
| `access check-in` / scan / toggle | vendor | **KEEP** + route ownership |
| `manage own events tickets` | (not on vendor) | **Needs product decision** (grant vs keep EventTicketsAccess fallback) |
| `access attendee repository` | vendor | **Needs product decision** |
| `administer url aliases` / content/files overview | vendor | **Needs product decision** (default: remove) |
| `administer myeventlane donations` | vendor | **Needs product decision** |

---

## Phase 2A — Launch blockers (security hotfix)

**Objective:** Stop confirmed cross-tenant PII and order mutation immediately.

### 2A.1 Strip authenticated order over-grant

| | |
|---|---|
| **Objective** | Remove `view default commerce_order` from authenticated role |
| **Files** | `config/sync/user.role.authenticated.yml` |
| **Tests** | Kernel/Functional: authenticated non-customer denied `commerce_order` view; customer still views own; checkout regression |
| **Risk** | Buyer “view order” paths that incorrectly relied on bundle view — must use `view own` / My Tickets access |
| **Effort** | S (0.5–1d) |
| **Dependencies** | Audit My Tickets / order receipt routes first |

### 2A.2 Strip vendor Commerce admin grants

| | |
|---|---|
| **Objective** | Remove from vendor: `access commerce_order overview`, `update default commerce_order`, `delete default commerce_order`, `unlock orders` |
| **Files** | `config/sync/user.role.vendor.yml`; update `MelCommerceOrderOverviewRoleConfigTest` expectations |
| **Tests** | Vendor denied Commerce orders View; denied entity update/delete on foreign orders; vendor event orders UI still works |
| **Risk** | Any hidden organiser tool that used admin order forms will 403 — replace with console routes |
| **Effort** | S–M (1–2d) |
| **Dependencies** | Confirm no production dependency on `/admin/commerce/orders` for vendors |

### 2A.3 Strip vendor profile/user PII grants

| | |
|---|---|
| **Objective** | Remove `view any profile`, `view user email addresses`, `access user profiles` |
| **Files** | `config/sync/user.role.vendor.yml` |
| **Tests** | Vendor denied foreign profile/user mail; attendee list still shows emails via presenter for **owned** events |
| **Risk** | Twig/Views that rendered `user.mail` via field access may blank out — switch to explicit safe presenters |
| **Effort** | S–M (1–2d) |
| **Dependencies** | Grep vendor Twig for direct user/profile field render |

### 2A.4 Fix order confirmation resend ownership

| | |
|---|---|
| **Objective** | Remove vendor `administer myeventlane messaging`; delete permission short-circuits that allow without ownership; require event parity for **all** ticketed events on the order |
| **Files** | `user.role.vendor.yml`; `ResendOrderConfirmationAccess.php`; messaging settings remain admin-only |
| **Tests** | Unit: foreign order denied; owned-event order allowed; mixed-owner order denied; admin still allowed via staff perm |
| **Risk** | Vendors lose messaging settings UI (intended) |
| **Effort** | S (0.5–1d) |
| **Dependencies** | None |

### 2A.5 Fix or retire unfiltered RSVP Views (`/dashboard/rsvps`)

| | |
|---|---|
| **Objective** | Stop cross-tenant attendee name listing (PII-07). Prefer retire View in favour of event-scoped RSVP routes, **or** add organiser filter (event author / `field_event_vendor`) + replace `VendorAccess` with parity-aware access |
| **Files** | `config/sync/views.view.myeventlane_vendor_rsvps.yml`; `myeventlane_rsvp/src/Plugin/views/access/VendorAccess.php`; optional `rsvp_submission` ACH |
| **Tests** | Organiser B sees zero rows / 403 for A’s RSVPs; organiser A still sees own |
| **Risk** | Bookmarks to `/dashboard/rsvps` break if retired — redirect to `/vendor/events` |
| **Effort** | S–M (1d) |
| **Dependencies** | None |

### 2A.6 Harden soft-gated export / order-attendee routes

| | |
|---|---|
| **Objective** | Replace `_permission: access content` with hard ownership `_custom_access` on checkout_paragraph vendor order/export routes; ensure `queueExport` checks access |
| **Files** | `myeventlane_checkout_paragraph.routing.yml` + controllers |
| **Tests** | Foreign event/order → 403 (not soft redirect) |
| **Risk** | Low |
| **Effort** | S (0.5–1d) |
| **Dependencies** | 2A.5 optional parallel |

### 2A.7 Cross-tenant regression suite (must ship with 2A)

| | |
|---|---|
| **Objective** | Automated Organiser A/B matrix: orders View, entity access, profiles, email, resend, `/dashboard/rsvps`, event orders, attendees CSV, ticket resend, checkout-paragraph export |
| **Files** | New Kernel tests under `myeventlane_vendor` / `myeventlane_messaging` / `myeventlane_rsvp` / surface |
| **Tests** | A allowed on A; B denied on A with no PII in responses |
| **Risk** | Low |
| **Effort** | M (2d) |
| **Dependencies** | 2A.1–2A.6 |

**Phase 2A exit criteria:** All Critical PII-01…PII-07 closed; suite green; sync+active roles verified equal.

---

## Phase 2B — Ownership consolidation

**Objective:** One ownership source of truth; close Medium gaps; defence-in-depth on routes.

### 2B.1 Canonical ownership API

| | |
|---|---|
| **Objective** | Make `EventVendorAccessChecker` (or thin facade) the only event-ownership API; deprecate duplicated private methods |
| **Files** | `EventVendorAccessChecker.php`; callers in vendor, attendees, RSVP, messaging, check-in, refunds |
| **Tests** | Existing unit tests + parity matrix (author / vendor owner / team / stranger) |
| **Risk** | Behaviour changes for edge membership cases |
| **Effort** | M (2–3d) |
| **Dependencies** | 2A complete |

### 2B.2 Align attendee + refund ownership

| | |
|---|---|
| **Objective** | `VendorAttendeeController::access` uses full parity; `RefundAccessResolver` uses same parity (keep store check as additional constraint if needed) |
| **Files** | `VendorAttendeeController.php`; `RefundAccessResolver.php`; possibly `VendorOwnershipResolver` |
| **Tests** | Vendor entity owner (not in field_vendor_users) can access attendees/refunds; stranger denied |
| **Risk** | Refund false allow if store check removed carelessly — prefer AND with event parity |
| **Effort** | M (1–2d) |
| **Dependencies** | 2B.1 |

### 2B.3 Check-in route ownership + toggle bind

| | |
|---|---|
| **Objective** | Replace permission-only check-in route requirements with `_custom_access` parity; bind `toggleCheckIn` attendee/RSVP ID to route event (PII-08); prefer redirect legacy module to Door Mode |
| **Files** | `myeventlane_checkin.routing.yml`; `CheckInStorage.php`; shared access service |
| **Tests** | Route denied without parity; foreign attendee_id on owned event → 403/no-op |
| **Risk** | Low |
| **Effort** | S (0.5–1d) |
| **Dependencies** | 2B.1 |

### 2B.4 Order IDOR hardening on console detail

| | |
|---|---|
| **Objective** | Event order detail/resend links reject orders not containing that event / not on vendor stores |
| **Files** | `VendorEventOrdersController` (+ related) |
| **Tests** | Swap order ID across events → 403 |
| **Risk** | Low |
| **Effort** | S–M (1d) |
| **Dependencies** | 2A |

### 2B.5 Product decisions backlog (config cleanup)

| Decision | Options |
|---|---|
| Grant `manage own events tickets`? | Grant + simplify EventAccess **or** keep console fallback |
| Keep `access attendee repository` on vendor? | Remove if unused at HTTP layer |
| Admin-flavoured grants (`administer url aliases`, overviews, donations admin) | Remove vs justify |
| `unlock orders` / order item manage | Staff only vs custom |

| | |
|---|---|
| **Effort** | S–M for config once decisions made |
| **Dependencies** | Product owner sign-off |

---

## Phase 2C — Architecture & surface consolidation

**Objective:** Long-term maintainability; reduce parallel PII surfaces.

### 2C.1 Custom Commerce order access layer

| | |
|---|---|
| **Objective** | If organisers need any global order operation, implement `hook_ENTITY_TYPE_access` / access handler decoration that allows update only when account has parity on **all** ticketed events on the order (fail closed) |
| **Files** | New service in `myeventlane_commerce` or `myeventlane_vendor`; tests |
| **Tests** | Mixed-owner orders denied; single-event owned allowed |
| **Risk** | High (Commerce core interaction) — **Needs Commerce architecture review** |
| **Effort** | L (3–5d) |
| **Dependencies** | 2A removal of broad grants; product need confirmation |

### 2C.2 Export / check-in surface consolidation

| | |
|---|---|
| **Objective** | Single export builder path; redirect legacy check-in module to Door Mode; retire stubs |
| **Files** | attendees, checkin, checkout_paragraph, rsvp routing |
| **Tests** | Redirect + access parity |
| **Risk** | Medium (bookmarks) |
| **Effort** | M–L |
| **Dependencies** | 2B |

### 2C.3 Views lockdown

| | |
|---|---|
| **Objective** | Ensure `commerce_orders` / `commerce_carts` Views cannot be reached by vendor; optional store filter if staff View retained |
| **Files** | View config; role grants |
| **Tests** | Config guard tests (extend `MelCommerceOrderOverviewRoleConfigTest`) |
| **Risk** | Low |
| **Effort** | S |
| **Dependencies** | 2A.2 |

### 2C.4 Active vs sync role CI guard

| | |
|---|---|
| **Objective** | CI job or Kernel test failing when active vendor permissions diverge from sync (prevents 2026-07-12 style drift) |
| **Files** | Test + optional Drush script (read-only in CI against SQLite/kernel) |
| **Effort** | S–M |
| **Dependencies** | None |

---

## Recommended implementation order

1. **2A.1** authenticated `view default commerce_order` removal (widest blast radius)  
2. **2A.5** RSVP Views `/dashboard/rsvps` (easy PII dump)  
3. **2A.4** messaging resend bypass  
4. **2A.2** + **2A.3** vendor Commerce + profile/email grants  
5. **2A.6** checkout_paragraph hard access  
6. **2A.7** A/B regression suite  
7. **2B.1 → 2B.2 → 2B.3 → 2B.4** ownership consolidation (+ check-in toggle bind)  
8. **2B.5** product decisions (include escalation reopen party check before granting perm)  
9. **2C.*** as capacity allows  

---

## Launch blockers (must clear before public launch)

| ID | Item | Phase |
|---|---|---|
| PII-02 | Authenticated can view any default order | 2A.1 |
| PII-07 | `/dashboard/rsvps` unfiltered attendee names | 2A.5 |
| PII-01 / PII-03 | Vendor Commerce order overview + mutate any order | 2A.2 |
| PII-04 / PII-05 | View any profile + any user email | 2A.3 |
| PII-06 | Resend confirmation cross-tenant | 2A.4 |

---

## Out of scope for Phase 2 (explicit)

- Stripe Connect architecture changes  
- Event Studio UX redesign  
- Dashboard visual redesign  
- Broad Views/Twig refactors unrelated to PII  
- D7 legacy comparison (MEL-only workstream)

---

## Validation plan (post-implementation)

```bash
ddev drush cr
ddev drush config:status
# Role probes: authenticated + vendor entity access on foreign order/profile
# Organiser A/B manual: orders, attendees CSV, resend, ticket tools, Studio
composer test / targeted phpunit groups
```

Do not deploy role removals without the regression suite (2A.5).

---

## Discovery confirmation

Phase 2A.1 discovery produced documentation only. No PHP, YAML role, Views, Studio, Dashboard, Stripe, or Commerce runtime code was modified for this plan.
