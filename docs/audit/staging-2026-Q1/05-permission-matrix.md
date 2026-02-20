# Phase 5 — Permissions & Access Control

**Audit Date:** 2026-02-20  
**Branch:** audit/staging-2026-Q1

---

## Role Overview

| Role | is_admin | Key Permissions |
|------|----------|-----------------|
| anonymous | false | access check-in, access checkout, access content, view commerce_product, view media |
| authenticated | false | + create customer profile, create * commerce_order, manage own commerce_payment_method, view own commerce_order, create/view escalation |
| vendor | false | create event, create ticket, manage own event attendees, manage_refunds, request_refunds, view stripe dashboard, delete default commerce_order |
| content_editor | — | (see config) |
| administrator | **true** | All permissions (is_admin) |

---

## Critical Permission Checks

### 1. "administer commerce" / Broad Admin

- **administrator:** is_admin = true → full access (expected).
- **vendor:** Does NOT have `administer commerce` or `administer commerce_order`.
- **Vendor has:**
  - `delete default commerce_order` — scoped to vendor’s orders via access control.
  - `update default commerce_order` — same.
  - `manage default commerce_order_item`, `manage rsvp_donation commerce_order_item`
- **Code checks:** VendorDashboardController, VendorCommsController, RefundAccessResolver, etc. use `administer commerce_order` or `administer commerce_store` only for admin override (e.g. user 1 or staff).
- **Classification:** OK — no broad "administer commerce" for vendors. Vendor-scoped access enforced server-side.

### 2. Unintended File Delete

- **authenticated:** `delete own files` — appropriate.
- **vendor:** No additional file delete beyond own.
- **Classification:** OK.

### 3. Bypass Moderation

- No workflow/moderation modules detected in enabled list. N/A for this stack.

### 4. Unsafe REST Exposure

- **anonymous:** `access content`, `view commerce_product` — public read, expected.
- **authenticated:** `view own commerce_order`, `view own customer profile` — scoped.
- **myeventlane_api:** Uses vendor API key authentication. Endpoints should be checked separately for scope.
- **Classification:** No obvious over-exposure.

---

## Over-Permissioning Analysis

### Anonymous

- `access check-in` — May be intentional for QR check-in by attendees.
- `access commerce_order overview` — Unusual for anonymous. **Check:** May allow viewing order list without auth. **HIGH** if it exposes other users’ orders.
- `view unpublished paragraphs` — Could expose draft content. **Medium** risk.

### Authenticated

- `access check-in`, `toggle check-in status` — Attendee self-check-in; verify no privilege escalation.
- `create platform_donation commerce_order`, `create rsvp_donation commerce_order` — Required for donation flows.
- `view own commerce_order` — OK.

### Vendor

- `view user email addresses` — Needed for attendee lists; ensure only for vendor’s events. **Medium** — verify isolation.
- `administer myeventlane donations`, `administer myeventlane messaging` — Scoped to vendor’s data; verify.
- `unlock orders` — Review for abuse potential.

---

## Access Control Implementation

- **VendorContextService:** Restricts dashboard to vendor’s store(s). Admin can override via `administer commerce_store`.
- **Event access:** Vendors limited to own events via `field_event_vendor` and node access.
- **RefundAccessResolver:** Uses `administer commerce_order` for admin override; vendors use manage_refunds/request_refunds.

---

## Classification Summary

| Item | Severity |
|------|----------|
| Broad administer commerce for vendors | OK |
| anonymous access commerce_order overview | **HIGH** — verify scope |
| view unpublished paragraphs (anonymous) | Medium |
| view user email addresses (vendor) | Medium — verify isolation |
