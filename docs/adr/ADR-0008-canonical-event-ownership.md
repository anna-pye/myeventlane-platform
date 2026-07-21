# ADR-0008: Canonical Event Ownership

## Status

Proposed (Phase 2B.1 discovery).  
**Accepted for planning.** Implementation must not begin until Workstream 1 tests land.

## Date

2026-07-21

## Context

MyEventLane organiser surfaces answer “Can this organiser manage this event?” through multiple overlapping implementations:

- **Workspace parity** via `EventVendorAccessChecker::accountHasWorkspaceParityForEvent()` (author **or** vendor entity owner **or** `field_vendor_users` on the event’s `field_event_vendor`)
- **Managed event ID sets** via `UserVendorMembershipQuery::getManagedEventNodeIds()` for Views, KPIs, and list isolation
- **Controller-local asserts** such as `VendorConsoleBaseController::assertEventOwnership()` that duplicate parity inline
- **Team-partial private helpers** that check author + `field_vendor_users` but **omit vendor entity owner** (attendees export, waitlist, boost workspace, messaging, RSVP QR)
- **Store / Commerce ownership** via `VendorOwnershipResolver` used as a primary gate for refunds (and as an OR path in some comms/check-in flows)
- **Console-trust-only routes** (`VendorConsoleAccess`) that defer event ownership to controllers
- **Permission-only routes** (legacy check-in, vendor API, some QR validate) with ownership enforced only inside controllers

Phase 2A.2 hardened RSVP list scope, attendee exports, and check-in bind without unifying these models. Continuing to add features against divergent helpers will recreate IDOR and PII gaps.

Existing ADR material lives under `docs/architecture/` (ADR-0001). This decision is filed at `docs/adr/` per Phase 2B.1 deliverable path; numbering continues the platform ADR series as **0008**.

Evidence inventory: `docs/implementation/phase2b-ownership-consolidation.md`.

## Decision

Adopt a single canonical ownership stack for organiser event management:

```text
Route
  ↓
Custom Access
  ↓
EventVendorAccessChecker::accountHasWorkspaceParityForEvent()
  ↓
Business logic
```

### Canonical APIs

1. **Per-event decision (required for mutations and PII reads):**  
   `EventVendorAccessCheckerInterface::accountHasWorkspaceParityForEvent()`

2. **Bulk / list / KPI scoping:**  
   `UserVendorMembershipQueryInterface::getManagedEventNodeIds()`

These two MUST remain semantically aligned for modern events keyed by `field_event_vendor`. Any intentional divergence (e.g. legacy `field_vendor`) must be explicit, tested, and documented.

### Composition rules

1. HTTP mutation and PII read routes use parity (or a thin domain wrapper such as `MelAttendeeOperationsAccess` / `VendorManagedEventConsoleAccess` that calls parity).
2. List/query surfaces use managed-event ID scoping and fail closed on empty sets.
3. No new private “is my event?” helpers. Controllers may keep fail-loud asserts that **delegate** to the checker.
4. `VendorOwnershipResolver` answers Commerce store questions only. For refunds and similar finance operations, store/order constraints are **additional AND** conditions after parity — never a substitute that widens or replaces workspace membership.
5. `VendorConsoleAccess` remains the organiser console trust/onboarding gate. It is **not** event ownership.
6. Staff bypasses stay on callers via named permissions (`administer nodes`, `administer event attendees`, `administer commerce_order`, domain admin perms). The parity checker itself does not grant admin.
7. Soft redirects and UI hiding are never access control.

## Alternatives

### A — Keep multiple ownership helpers; document only

Rejected. Documentation without consolidation has already allowed team-partial vs parity drift (vendor entity owner gaps) and permission-only route gaps.

### B — Make `VendorOwnershipResolver` (store) the canonical model

Rejected. Store linkage is necessary for Commerce refunds and finance, but many organisers operate as vendor entity owners or team members where store resolution is incomplete or secondary. Store-primary access incorrectly frames event management as store ownership.

### C — Rely on Drupal node grants / `node.update` alone

Rejected. Node grants are commonly author-centric. Event Studio and vendor console product intent requires workspace parity for vendor entity owners and team members without requiring them to be the node author. `EventStudioAccess` already documents this explicitly.

### D — Single mega access service for all domains

Rejected as a first step. Prefer a small canonical parity API plus thin domain wrappers (`MelAttendeeOperationsAccess`, ticket `EventAccess`, refund resolver with AND constraints) so product permissions remain separable.

## Consequences

### Positive

- One answer for organiser event reach across Studio, console, attendees, exports, check-in, messaging, API, and waitlist
- Reduced IDOR/PII regression risk from copy-pasted `field_vendor_users` loops
- Clear place for refunds to add store/order constraints without inventing a second membership model
- Route-layer ownership becomes testable and menu-safe

### Negative / costs

- Migration touches many modules (estimated 40–75 files across three workstreams)
- Aligning team-partial helpers to full parity **correctly widens** access for vendor entity owners who are not listed in `field_vendor_users` on those surfaces
- Refund AND-with-parity may change edge cases for accounts that previously matched only via store or only via author rules — must be locked in tests before merge
- Temporary double gates (route + controller assert) until cleanup

## Benefits

- Security: consistent fail-closed event scope for PII and mutations
- Maintainability: delete divergent private helpers after tests pass
- Product clarity: “workspace parity” is the organiser contract; store is Commerce
- Testability: one parity matrix reused by domain wrappers

## Migration

Documented in `docs/implementation/phase2b-ownership-consolidation.md`:

| Workstream | Focus | Behaviour intent |
|---|---|---|
| **1** | Lock canonical API + equivalence tests; thin-wrap console assert | Preserve behaviour |
| **2** | Align attendees, waitlist, messaging, boost vendor path, QR, charts, ACH, refunds AND, API | Preserve stranger deny; fix vendor-owner gaps; lock refund edges |
| **3** | Route `_custom_access` (check-in, console event tabs), order IDOR, CSRF follow-up | Preserve deny; move enforcement earlier |

Do **not** combine with Commerce role/permission strip (Phase 2A remaining / 2C) unless a hard dependency is documented.

## Compatibility

- Existing callers of `EventVendorAccessChecker` remain valid
- `VendorConsoleBaseController::assertEventOwnership()` remains until it becomes a pure delegate, then optional defense-in-depth
- `VendorOwnershipResolver` remains for store resolution
- `MelAttendeeOperationsAccess` remains the preferred attendee ops façade
- Legacy `field_vendor` on managed-set queries: decide explicitly in Workstream 1 tests (keep with documented widen **or** remove after data audit)
- Public Boost purchase author-only path may remain a product exception; vendor workspace Boost must use parity

## Future work

- Workstream 1–3 implementation PRs (no behaviour change in the ADR/docs-only stage)
- Phase 2B.5 product backlog: permission/config cleanup after PO sign-off
- Optional: per-request memoisation of parity results
- Optional: deprecate and remove controller asserts once route access coverage is complete
- CSRF hardening for legacy check-in (`docs/security/follow-up-checkin-csrf.md`) with or immediately after route ownership (2B.3)

## References

- `docs/implementation/phase2b-ownership-consolidation.md`
- `docs/implementation/phase2b-ownership-consolidation-plan.md`
- `docs/audits/vendor-permission-inventory.md`
- `docs/audits/vendor-route-access-audit.md`
- `docs/audits/vendor-pii-exposure-audit.md`
- `docs/vendor-console-v2-access-matrix.md`
- `web/modules/custom/myeventlane_vendor/src/Service/EventVendorAccessChecker.php`
- `web/modules/custom/myeventlane_vendor/src/Service/UserVendorMembershipQuery.php`
- `web/modules/custom/myeventlane_checkout_flow/src/Service/VendorOwnershipResolver.php`
- `web/modules/custom/myeventlane_checkout_flow/src/Service/MelAttendeeOperationsAccess.php`
