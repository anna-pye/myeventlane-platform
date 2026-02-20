# Phase 3 — Entity & Schema Review

**Audit Date:** 2026-02-20  
**Branch:** audit/staging-2026-Q1

---

## Entity Overview

### Event Content Type (node.event)

- **Source:** myeventlane_schema, myeventlane_event, myeventlane_core
- **Fields (sample):** field_event_start, field_event_end, field_venue, field_event_type, field_ticket_types, field_event_intro, field_refund_policy, field_age_restriction, field_series_*, field_accessibility_*, field_location_*, field_product_target, etc.
- **Cardinality:** Multiple fields with cardinality > 1 (e.g. field_ticket_types, field_attendee_questions, field_event_highlights) — justified for repeatable content.
- **Date fields:** field_event_start, field_event_end (datetime). Indexed via Search API / views where used.

### Ticket Product Type (commerce_product.ticket)

- **Type:** commerce_product, bundle: ticket
- **Fields:** field_event (entity reference)
- **Product variations:** ticket_variation

### Commerce Order Types

- **default** — Standard paid orders
- **platform_donation** — Platform donations
- **rsvp_donation** — RSVP/donation orders

### User Roles (from config sync)

- anonymous, authenticated, administrator, vendor, content_editor

### Taxonomies

- categories, tags, accessibility, help_categories, audience

### Custom Entities

- **myeventlane_page_visuals:** PageVisual
- **myeventlane_vendor:** Vendor (with api_key_hash base field)
- **OnboardingState** (myeventlane_core)

---

## Field Storage Types Check

| Entity/Field | Type | Correct |
|--------------|------|---------|
| node.event.field_event_start | datetime | ✓ |
| node.event.field_venue | entity_reference | ✓ |
| commerce_order_item.field_target_event | entity_reference | ✓ |
| commerce_store.field_stripe_account_id | string | ✓ |

No incorrect storage types identified.

---

## Unlimited Cardinality

- **field_attendee_questions** — Paragraph reference, unlimited; justified for flexible forms.
- **field_event_highlights** — Paragraph reference; justified.
- **field_ticket_types** — Paragraph reference; justified for multiple ticket tiers.

---

## Database Indexes

**I cannot confirm** explicit custom indexes on:
- commerce_order.uid
- commerce_order.state
- event date fields
- product reference fields

Drupal/Commerce provide base indexes via entity schema. Custom indexes would require inspection of `.install` files or DB schema. No custom index definitions were found in config.

**Recommendation:** Verify via `SHOW INDEX FROM commerce_order` and equivalent for key tables if performance issues arise.

---

## Base Field Overrides

No incorrect base field overrides identified. Vendor entity adds api_key_hash via EntityDefinitionUpdateManager in install hook.

---

## Pending Entity Schema Updates

- **updatedb:status:** No database updates required.
- **myeventlane_commerce_update_8000:** Uses reserved 8000 number (see Phase 1). Does not cause schema drift but blocks future update hooks.

**Classification:** No **CRITICAL** pending entity updates. myeventlane_commerce update numbering is **HIGH**.

---

## Summary

| Check | Status |
|-------|--------|
| Field storage types | OK |
| Unlimited cardinality | Justified |
| DB indexes | Not verified (recommend manual check) |
| Base field overrides | OK |
| Pending entity updates | None |
| Update hook numbering | HIGH (myeventlane_commerce) |
