# Vendor Permission Inventory — Phase 2A.1 Discovery

**Date:** 2026-07-21  
**Branch:** `fix/mel-vendor-access-and-create-flow`  
**Scope:** Read-only audit of role grants, permission definitions, and runtime effect  
**Related:** PR #696 (Vendor Experience Phase 1 – Trust & Access Repair) merged 2026-07-21  
**Method:** `config/sync` YAML + DDEV active role comparison + entity `access()` probes + code path review  
**Status:** Audit only — no runtime behaviour changed

---

## 0. Repository safety

| Check | Result |
|---|---|
| Repository root | `/Users/anna/myeventlane-wt-apple-wallet-poster` |
| Branch | `fix/mel-vendor-access-and-create-flow` |
| Working tree at Stage 0 | Clean |
| Active vs sync (`user.role.vendor`) | **Matched** — active=91, sync=91 (DDEV 2026-07-21) |

**Assumption:** DDEV project `myeventlane-wt-wallet` reflects current sync after Phase 1. Historical drift in `docs/audits/vendor-role-active-vs-sync-2026-07-12.json` is superseded for this environment.

---

## 1. Roles found

| Role ID | Label | Permission count (active) | Notes |
|---|---|---|---|
| `anonymous` | Anonymous | 15 | Not organiser-facing |
| `authenticated` | Authenticated user | 38 | Includes broad Commerce order view |
| `vendor` | Vendor | **91** | Primary organiser role |
| `mel_pro` | MEL Pro | 35 | Additive Pro entitlements |
| `content_editor` | Content editor | 22 | Staff editorial |
| `administrator` | Administrator | 0 listed (is_admin) | Full bypass |

There is **no separate “event organiser” role** in sync. Organiser capability = `vendor` (+ optional `mel_pro`).

Evidence: `config/sync/user.role.*.yml`; DDEV `Role::load()->getPermissions()`.

---

## 2. Vendor role — full permission list (sync = active)

Source: `config/sync/user.role.vendor.yml`

```
access analytics dashboard
access attendee repository
access check-in
access checkout
access commerce_order overview
access content overview
access contextual links
access files overview
access toolbar
access user profiles
access vendor console
access vendor dashboard
access vendor venues
administer myeventlane donations
administer myeventlane messaging
administer url aliases
assign mel_ticket_type entities
cancel_event
cancel_events
change own username
check in tickets
comment on vendor escalations
create customer profile
create event content
create image media
create mel_ticket_type entities
create paragraph content attendee_answer
create paragraph content attendee_extra_field
create paragraph content event_highlight
create terms in tags
create ticket commerce_product
create url aliases
delete default commerce_order
delete own event content
delete own mel_ticket_type entities
delete own social auth profile
delete own ticket commerce_product
delete paragraph content attendee_extra_field
delete paragraph content event_highlight
edit own comments
edit own event content
edit own mel_ticket_type entities
manage boost commerce_order_item
manage checkout_donation commerce_order_item
manage default commerce_order_item
manage own commerce_payment_method
manage own event attendees
manage own event rsvps
manage rsvp_donation commerce_order_item
manage_refunds
purchase boost for events
request_refunds
resolve vendor escalations
resend ticket emails
reuse mel_ticket_type entities
scan qr codes
toggle check-in status
unlock orders
update default commerce_order
update own boost_upgrade commerce_product
update own customer profile
update own ticket commerce_product
update paragraph content attendee_extra_field
update paragraph content event_highlight
use editorial transition create_new_draft
use editorial transition publish
use editorial transition send_back_to_draft
use text format basic_html
use vendor ai assistant
view any profile
view automation dispatch log
view boost_upgrade commerce_product
view commerce_product
view mel_ticket_type entities
view own commerce_order
view own event attendees
view own profile
view own unpublished commerce_product
view own unpublished content
view paragraph content attendee_answer
view paragraph content attendee_extra_field
view paragraph content event_highlight
view rsvp_donation commerce_order
view stripe dashboard links
view ticket commerce_product
view unpublished paragraphs
view user email addresses
view vendor bas reports
view vendor escalations
view vendor help centre
view vendor refund summary
```

**Not granted (notable):** `manage own events tickets`, `resend order confirmation emails`, `request exports`, `view event insights`, `view vendor insights`, `administer nodes`.

---

## 3. High-risk permission deep dive

For each: Permission | Provided by | Used by | Current access path | Actual runtime effect | Required? | Risk | Recommendation | Confidence

### 3.1 Commerce / orders

| Permission | Provided by | Used by | Access path | Runtime effect | Required? | Risk | Recommendation | Confidence |
|---|---|---|---|---|---|---|---|---|
| `access commerce_order overview` | `commerce_order` | Views `commerce_orders`, `commerce_carts` | Vendor role grant | Vendor can open Commerce orders overview (`view.commerce_orders.page_1` access = **Y** for vendor uid 35) — **platform-wide order list** | No for scoped console | **Critical** | Remove; rely on vendor event order controllers | **High** (runtime) |
| `update default commerce_order` | `commerce_order` (title: “Default: Update orders”) | Entity access on bundle `default` | Vendor role | Vendor UIDs 2/35/72/74: `order->access('update')` = **Y** on order 392 (customer uid 1, store 2) | No | **Critical** | Remove; replace with ownership service | **High** (runtime) |
| `delete default commerce_order` | `commerce_order` | Entity delete | Vendor role | Same accounts: `access('delete')` = **Y** on foreign order 392 | No | **Critical** | Remove | **High** (runtime) |
| `unlock orders` | `commerce_order` | Order unlock ops | Vendor role | Unlocks Commerce order lock for any order the entity access allows | Unclear | **High** | Remove unless product proves need | **Medium** |
| `view own commerce_order` | `commerce_order` | Buyer order view | Vendor (+ authenticated) | “Own” = order **customer**, not event organiser. Safe alone; insufficient for vendor ops | Yes (as buyer) | Low | KEEP for buyer journey | High |
| `view default commerce_order` | `commerce_order` (title: “Default: View orders”) | Entity view | **Authenticated** role (not vendor-specific) | Auth UIDs 3/6/9/77/78: `access('view')` = **Y** on foreign order 392 | No | **Critical** | Remove from authenticated immediately | **High** (runtime) |
| `manage default commerce_order_item` | `commerce_order` | Order item management | Vendor role | Broad item manage on default order type | Unclear | High | Needs Commerce architecture review | Medium |
| `access checkout` | `commerce_checkout` | Checkout | Vendor + authenticated | Buyer checkout | Yes | Low | KEEP | High |

### 3.2 Profiles / users / PII

| Permission | Provided by | Used by | Access path | Runtime effect | Required? | Risk | Recommendation | Confidence |
|---|---|---|---|---|---|---|---|---|
| `view any profile` | `profile` | Profile entity access | Vendor role | Vendor UIDs can `profile->access('view')` = **Y** on customer profiles they do not own | No | **Critical** | Remove; event-scoped attendee DTOs only | **High** (runtime) |
| `view user email addresses` | `user` | User `mail` field access | Vendor role | Vendor UIDs 35/72: `user.mail` view = **Y** on foreign user | No | **Critical** | Remove; expose email only via owned-event presenters | **High** (runtime) |
| `access user profiles` | `user` | User canonical / listings | Vendor role | Combined with email perm enables cross-tenant identity browsing | No | **Critical** | Remove | High |
| `view own profile` | `profile` | Own profile | Vendor | Own profile only | Yes | Low | KEEP | High |
| `create customer profile` / `update own customer profile` | `profile` | Checkout profiles | Vendor + authenticated | Buyer profile create/update | Yes (buyer) | Low | KEEP | High |

### 3.3 Messaging / resend

| Permission | Provided by | Used by | Access path | Runtime effect | Required? | Risk | Recommendation | Confidence |
|---|---|---|---|---|---|---|---|---|
| `administer myeventlane messaging` | `myeventlane_messaging` | Settings route + **resend short-circuit** | Vendor role | `ResendOrderConfirmationAccess::check()` allows **any** order if this perm is present — no ownership. Runtime: resend allowed=Y for vendors 2/35/72 on foreign order 392. Also gates `/admin/config/myeventlane/messaging` | No | **Critical** | Strip from vendor; use ownership-only resend | **High** (runtime + code) |
| `resend ticket emails` | `myeventlane_tickets` | Ticket resend route | Vendor (Phase 1) | Plus `TicketOperationsAccess` ownership | Yes | Low (when ownership holds) | KEEP | High |
| `resend order confirmation emails` | messaging (active historically) | Resend short-circuit #2 | **Not** on sync vendor | Would also bypass ownership if granted | No | Critical if granted | Never grant to vendor | High |

Code evidence (`ResendOrderConfirmationAccess.php`):

```php
if ($account->hasPermission('administer myeventlane messaging')) {
  return AccessResult::allowed()->cachePerPermissions();
}
if ($account->hasPermission('resend order confirmation emails')) {
  return AccessResult::allowed()->cachePerPermissions();
}
```

### 3.4 Attendees / check-in / RSVP / tickets

| Permission | Provided by | Used by | Access path | Runtime effect | Required? | Risk | Recommendation | Confidence |
|---|---|---|---|---|---|---|---|---|
| `view own event attendees` | `myeventlane_event_attendees` | `VendorAttendeeController::access` | Vendor | Permission + owner/team check (team via `field_vendor_users` only — **misses vendor entity owner uid**) | Yes | Medium (parity gap) | Align with `EventVendorAccessChecker` | High |
| `manage own event attendees` | attendees | Manage ops | Vendor | Named “own”; must not be sole gate | Yes | Medium | Pair with parity checker | Medium |
| `access attendee repository` | `myeventlane_attendee` | Repository services | Vendor | Service-layer; not a route alone. Risk if callers skip event scope | Unclear | Medium | Audit callers; prefer route ownership | Medium |
| `access check-in` / `scan qr codes` / `toggle check-in status` | `myeventlane_checkin` | Check-in routes | Vendor | Route = permission only; controller `assertEventAccess` uses parity. Route access = Y without parity (uid 35 / event 1599) | Partial | Medium | Move ownership to `_custom_access` | High |
| `check in tickets` | `myeventlane_tickets` | Ticket check-in + EventTicketsAccess | Vendor | Permission + event tickets access | Yes | Low–Med | KEEP with event access | High |
| `manage own event rsvps` | `myeventlane_rsvp` | RSVP vendor routes | Vendor | Custom `vendor_event_access` on most routes | Yes | Low–Med | KEEP; unify fallbacks | Medium |
| `manage own events tickets` | `myeventlane_tickets` | `EventAccess::canManageEventTickets` | **Not on vendor** | Manage-own path dead for vendors; `EventTicketsAccess` falls back to console + parity (Phase 1) | Product | Medium | Product decision: grant vs keep fallback | High |

### 3.5 Refunds / cancel / exports / analytics

| Permission | Provided by | Used by | Access path | Runtime effect | Required? | Risk | Recommendation | Confidence |
|---|---|---|---|---|---|---|---|---|
| `manage_refunds` | `myeventlane_refunds` | Refund routes + `RefundAccessResolver` | Vendor + mel_pro | Permission + event ownership via store/owner (note: store path ≠ workspace parity) | Yes | Medium | Align ownership model with parity | Medium |
| `cancel_events` / `cancel_event` | refunds / event_state | Cancel forms | Vendor | Event-scoped custom access | Yes | Low–Med | KEEP; dedupe names | Medium |
| `request_refunds` | refunds | Buyer requests | Vendor + authenticated + mel_pro | Buyer-side | Yes | Low | KEEP | High |
| `request exports` | reporting | Export centre | **mel_pro only** | Vendor sync lacks it — Pro export path | Product | Low | Product decision | High |
| `access analytics dashboard` | analytics | Analytics routes | Vendor | Event access uses parity (Task 12) | Yes | Low | KEEP | High |
| `use pro financial analytics` | vendor | Event analytics route | mel_pro (not base vendor) | Pro-gated | Product | Info | KEEP as Pro | High |

### 3.6 Console / studio / admin-flavoured

| Permission | Provided by | Used by | Access path | Runtime effect | Required? | Risk | Recommendation | Confidence |
|---|---|---|---|---|---|---|---|---|
| `access vendor console` | `myeventlane_vendor` | Most `/vendor/*` routes | Vendor + mel_pro | Console gate; **not** ownership | Yes | Medium if used alone | KEEP; always pair with event ownership | High |
| `access vendor dashboard` | vendor | Dashboard | Vendor | Dashboard entry | Yes | Low | KEEP | High |
| `administer url aliases` | path | Alias UI | Vendor | Site-wide alias admin | No | High | Remove | Medium |
| `access content overview` / `access files overview` / `access toolbar` | system/file/toolbar | Admin UI | Vendor | Broad admin surfaces | Unclear | Medium–High | Strip unless proven | Medium |
| `administer myeventlane donations` | donations | Donation admin | Vendor | Admin-flavoured | Unclear | High | Needs product decision | Low |
| `access contextual links` | contextual | Contextual UI | Vendor | Low direct PII | Unclear | Low | Review | Low |

---

## 4. Authenticated role — overlapping risks

Source: `config/sync/user.role.authenticated.yml`

Critical overlap:

| Permission | Risk | Evidence |
|---|---|---|
| `view default commerce_order` | **Any logged-in user can view any default-type order entity** | Runtime: auth UIDs 3/6/9/77/78 view=Y on order 392 (not customer) |
| `view own commerce_order` | Intended buyer scope | KEEP |
| `view rsvp_donation commerce_order` | Bundle-scoped view | Review separately |

This is **broader than the vendor problem** and is a launch blocker for the whole marketplace.

---

## 5. MEL Pro role — additive grants

Source: `config/sync/user.role.mel_pro.yml`

Notable: `request exports`, `view event insights`, `view vendor insights`, `use pro financial analytics`, `manage_refunds`, `access vendor console`.  
Does **not** carry the Critical Commerce update/delete or `view any profile` grants (those come from `vendor` when both roles are assigned).

---

## 6. Custom MEL permissions defined (inventory of YAML providers)

54 `*.permissions.yml` files under `web/modules/custom/`. Vendor-relevant modules include:

- `myeventlane_vendor`, `myeventlane_tickets`, `myeventlane_checkin`, `myeventlane_rsvp`
- `myeventlane_event_attendees`, `myeventlane_attendee`, `myeventlane_messaging`
- `myeventlane_refunds`, `myeventlane_reporting`, `myeventlane_wallet`
- `myeventlane_dashboard`, `myeventlane_analytics`, `myeventlane_pro`

No evidence of `hook_user_permissions_alter` systematically expanding vendor grants at runtime (not exhaustive of all contrib). Role grants are config-driven.

---

## 7. Phase 1 (PR #696) permission impact

| Change | Effect on this inventory |
|---|---|
| Granted `resend ticket emails` to vendor | Fixes ticket resend 403, ownership via `TicketOperationsAccess` |
| Ticket groups/widgets lists → `EventTicketsAccess` | Avoids missing `manage own events tickets` |
| Check-in toggle `_method: POST` | Mutation hardening only |
| Organiser owner parity in `EventVendorAccessChecker` | Improves event-scoped surfaces |

**Not addressed by Phase 1:** F-06 broad Commerce / profile / messaging admin grants (still present). Confirmed still Critical at runtime.

---

## 8. Summary verdict

| Question | Answer |
|---|---|
| Do “own” permissions alone guarantee tenant isolation? | **No** |
| Are vendor-scoped console controllers generally ownership-aware? | **Often yes** (assertEventOwnership / EventTicketsAccess / MelAttendeeOperationsAccess) |
| Can vendor role still reach foreign customer PII via core/Commerce permissions? | **Yes — confirmed** |
| Can any authenticated user view foreign orders? | **Yes — confirmed** |
| Can vendors list foreign RSVP attendee names via Views? | **Yes — `/dashboard/rsvps` unfiltered (PII-07)** |

---

## 9. Validation commands used (read-only)

```bash
git status
ddev drush php:eval '... Role::load vendor/authenticated; order/profile access probes ...'
# Entity access on commerce_order 392, customer profiles, resend access service
```

No config export, no role mutation, no PHP/YAML edits in this workstream.
