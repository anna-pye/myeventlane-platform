# Phase 2B — Ownership Consolidation Plan

**Date:** 2026-07-21  
**Status:** Planning only — no implementation in this document  
**Prerequisite:** Phase 2A.2 security hotfix (RSVP isolation, export hardening, check-in bind)  
**Inputs:**

- `docs/audits/vendor-permission-inventory.md`
- `docs/audits/vendor-route-access-audit.md`
- `docs/audits/vendor-pii-exposure-audit.md`
- `docs/implementation/vendor-permission-hardening-phase2-plan.md`
- `docs/implementation/vendor-permission-hardening-phase2.md`
- `docs/security/follow-up-checkin-csrf.md`

---

## Goal

Establish **one canonical event-ownership model** for organiser console, attendees, RSVP, check-in, refunds, messaging/resend, and exports — so every route and service answers the same question:

> Does this account have workspace parity for this event?

Phase 2B does **not** strip Commerce/profile role grants (Phase 2A remaining / 2C) and does **not** redesign product entities.

---

## Current ownership models (as of Phase 2A.2)

Multiple overlapping definitions exist in custom code:

| Model | Primary API | Membership signals | Used by (examples) |
|---|---|---|---|
| **Workspace parity** | `EventVendorAccessChecker::accountHasWorkspaceParityForEvent()` | Event author UID; vendor entity owner; `field_vendor_users` team via event’s vendor reference | Export `_custom_access`, many console controllers, Event Studio gates |
| **Managed event ID set** | `UserVendorMembershipQuery::getManagedEventNodeIds()` | Author **or** `field_event_vendor` / optional legacy `field_vendor` for user’s vendor IDs | RSVP Views scope (Phase 2A.2), dashboard KPIs, ticket sales aggregation |
| **Controller-local asserts** | `VendorConsoleBaseController::assertEventOwnership()`, `CheckInController::assertEventAccess()`, private helpers | Often mirrors parity, sometimes author-only or store-linked | Legacy check-in, various vendor controllers |
| **Store / Commerce path** | Refund / order helpers | Commerce store ownership, order customer, bundle permissions | Refunds, residual Commerce admin surfaces |
| **Permission-only gates** | Route `_permission` | Role grant without event scope | Legacy check-in routes (parity only in controller); some console entry points |

### Known gaps after Phase 2A.2

- Check-in **routes** still permission-only; ownership enforced in controller (Phase 2B.3 incomplete).
- Attendee controller historically used `field_vendor_users` team checks that can miss vendor-entity owner parity (audit Medium).
- Refund resolver may AND/OR store checks differently from workspace parity.
- Order detail IDOR hardening on console still planned (2B.4).
- Duplicate private “is my event?” helpers risk drift from `EventVendorAccessChecker`.

Phase 2A.2 intentionally reused existing APIs (`getManagedEventNodeIds` for RSVP list scope; workspace parity for exports) without unifying them.

---

## Canonical ownership model (target)

**Canonical decision API (per event, per account):**

`EventVendorAccessCheckerInterface::accountHasWorkspaceParityForEvent(NodeInterface $event, AccountInterface $account): bool`

**Canonical managed-set API (bulk listing / Views / KPIs):**

`UserVendorMembershipQueryInterface::getManagedEventNodeIds(int $uid, bool $publishedOnly): array`

These two must remain **semantically aligned**:

- Membership for a single event via parity ⇔ event ID ∈ managed set for that UID (publish filter aside).
- Staff bypass remains explicit (`administer nodes` and domain-specific admin perms) — never silent.

**Rules:**

1. HTTP mutation and PII read routes use parity (or equivalent `_custom_access` service).
2. List/query surfaces use managed-event ID scoping and fail closed on empty sets.
3. No new private ownership helpers; call or inject the canonical services.
4. Store/customer checks may be **additional** constraints (e.g. refunds), never a substitute that widens access.
5. Soft redirects are not access control.

---

## Migration strategy

1. **Inventory callers** of private assert methods and ad-hoc `uid` / `field_vendor_users` checks (vendor, attendees, RSVP, check-in, refunds, messaging, tickets).
2. **Lock the parity matrix** in unit/kernel tests: author, vendor entity owner, team member, stranger, staff.
3. **Prove managed-set ≡ parity** for the same fixtures (published and unpublished variants).
4. **Replace callers** module-by-module; delete dead helpers after tests pass.
5. **Route hardening:** move ownership from controller-only to `_custom_access` where still missing (check-in first).
6. **Do not** combine with Commerce role strip (remaining Phase 2A / 2C) in the same PR unless forced by dependency.

Prefer additive access services + tests over large refactors.

---

## Expected workstreams

| ID | Workstream | Outcome |
|---|---|---|
| **2B.1** | Canonical ownership API | Single public API; deprecate duplicates; docs for callers |
| **2B.2** | Attendee + refund alignment | Full parity on attendee access; refunds AND parity (+ store if required) |
| **2B.3** | Check-in route ownership | `_custom_access` on check-in routes; keep Phase 2A.2 bind; optionally schedule CSRF follow-up (`docs/security/follow-up-checkin-csrf.md`) |
| **2B.4** | Order IDOR on console detail | Reject order IDs not tied to the route event / vendor stores |
| **2B.5** | Product decision backlog | Config cleanup decisions only after PO sign-off (tickets perm, repository perm, admin-flavoured grants) |

---

## Risk analysis

| Risk | Mitigation |
|---|---|
| Edge membership behaviour change (owner vs team) | Parity matrix tests before/after; feature flag only if needed |
| Refund false allow if store check dropped | Keep store as AND with parity |
| Performance of repeated parity checks | Cache per-request; reuse managed ID set for lists |
| Scope creep into Commerce permission strip | Keep role YAML changes in separate Phase 2A/2C PRs |
| Legacy check-in CSRF | Documented follow-up; implement with 2B.3 or immediately after |

---

## Dependencies

- Phase 2A.2 merged or equivalent runtime on target branch.
- Stable `EventVendorAccessChecker` + `UserVendorMembershipQuery` interfaces (Phase 2A.2 introduced the membership interface).
- Product sign-off for 2B.5 only.
- CSRF follow-up may land with 2B.3 but is tracked separately.

**Non-dependencies / do not start here:** Stripe, Wallet, dashboard redesign, Phase 2C Commerce access handler rewrite.

---

## Modules affected (expected)

| Module | Likely touch |
|---|---|
| `myeventlane_vendor` | Canonical API, console base, membership query alignment |
| `myeventlane_event_attendees` | Vendor attendee access |
| `myeventlane_refunds` / `myeventlane_escalations_refunds` | RefundAccessResolver |
| `myeventlane_checkin` | Route `_custom_access`; CSRF follow-up |
| `myeventlane_messaging` / `myeventlane_tickets` | Resend ownership callers (if still divergent) |
| `myeventlane_rsvp` | Prefer inject canonical scope; avoid new parallel helpers |
| `myeventlane_checkout_paragraph` / `myeventlane_views` | Confirm export access already on parity (Phase 2A.2) — regression only |

---

## Testing strategy

1. **Unit:** parity matrix on `EventVendorAccessChecker`; managed-set equivalence cases.
2. **Kernel:** organiser A/B — attendees, refunds, check-in routes, order detail IDOR.
3. **Static routing safety:** check-in routes require `_custom_access` (and CSRF when scheduled).
4. **Regression:** Phase 2A.2 focused suite (29 tests) remains green.
5. **Manual:** vendor entity owner (not in `field_vendor_users`) can operate owned events; stranger denied.

---

## Explicit non-goals

- No Phase 2B implementation in the Phase 2A.2 PR.
- No ownership architecture redesign beyond consolidating onto existing MEL services.
- No Commerce role permission strip in Phase 2B PRs unless a documented hard dependency appears.

---

## Exit criteria

- Call-site inventory closed; no divergent private ownership helpers for event console PII/mutations.
- Parity ≡ managed-set proven in tests.
- Check-in routes ownership at route layer; bind retained.
- Attendee + refund parity aligned.
- Order detail IDOR tests green.
- CSRF follow-up either fixed or still explicitly deferred with updated launch sign-off.
