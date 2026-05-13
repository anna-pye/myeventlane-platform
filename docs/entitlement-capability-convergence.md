# Entitlement capability convergence (Phase 2E)

## Canonical authority

Operational capability policy for ticket-backed entitlements is centralized in **`EntitlementCapabilityRegistry`** (`myeventlane_tickets.entitlement_capability_registry`), implemented at `web/modules/custom/myeventlane_tickets/src/Service/EntitlementCapabilityRegistry.php`.

This service is the **single machine-safe policy authority** for:

- scan semantics (`scanner_mode`, aligned with `mel_redemption_log` action tokens)
- redemption semantics (`redeemable`, `multi_use` as type-level policy flags)
- fulfilment semantics (`requires_fulfilment`, `supports_collection`, `fulfilment_mode`)
- expiry semantics (`expires` as type-level policy; entity `expires_at` remains authoritative for runtime)
- transfer semantics (`transferable`)

It does **not** load entities for policy (callers pass normalized entitlement type strings), call scanners, PDFs, wallets, render systems, notifications, or mutate operational state.

## Delegation rules

- **Delegate inward:** `TicketCapabilityManager`, `ScannerOperationManager`, `UniversalTicketViewModelBuilder`, `OperationalIntegrityInspector`, **`VenueOperationPolicyManager`** (which composes **`TimedEntryPolicyManager`** for operational clock policy and **`SessionEntitlementPolicyManager`** for session / sequencing / multi-use orchestration), wallet scaffolds, and PDF preprocessors **consume** registry output for entitlement-type policy. Venue gate descriptors, offline scaffolding metadata, and staff-side replay fingerprints are authored in **`VenueOperationPolicyManager`**; entry windows and scanner timing states are authored in **`TimedEntryPolicyManager`**; session payloads and progression semantics are authored in **`SessionEntitlementPolicyManager`** and must not be duplicated in scanners, PDFs, or wallets.
- **Registry isolation:** the registry **must never** call scanners, PDF generators, wallet builders, view models, or mail paths.
- **Entity authority unchanged:** `myeventlane_ticket` rows remain the entitlement authority for codes, limits, counts, status, and fulfilment fields. The registry describes **policy by entitlement type**, not per-row commerce configuration.

## Operational policy normalization

Each supported entitlement type (`ticket`, `merch`, `parking`, `drink`, `food`, `vip`, `addon`) maps to one immutable capability array. Unknown stored types normalize to **`ticket`** policy at the registry boundary (same behaviour as the pre-convergence capability manager).

Scanner routing uses **`scanner_mode`**, which matches **`RedemptionLog`** action constants (`admit`, `collect`, `validate`, `redeem`, `verify`). `ScannerOperationManager::resolveAction()` reads policy from the registry after normalizing the entitlement type via **`TicketCapabilityManager::getEntitlementType()`**.

## Surfaces

| Surface | Consumption |
| --- | --- |
| **Universal view model** | Adds `capabilities` (full map) and `fulfilment.mode` (registry `fulfilment_mode`). |
| **PDF template builder** | Exposes `capabilities` alongside `view_model` for Twig. |
| **Apple wallet scaffold** | JSON scaffold includes `capabilities` from the view model (QR payload contract unchanged). |
| **Operational observability** | `artifacts.entitlement_capability_policy` lists deduplicated capability summaries per normalized entitlement type observed on the order; `artifacts.venue_operation_policy` lists deduplicated venue gate semantics and descriptors from **`VenueOperationPolicyManager`**; `artifacts.timed_entry_policy` lists per-ticket timing diagnostics from **`TimedEntryPolicyManager`**; `artifacts.session_entitlement_policy` lists per-ticket session diagnostics from **`SessionEntitlementPolicyManager`**. |

## Anti-patterns (forbidden)

- Entitlement `switch` / large `match` tables on entitlement type **outside** `EntitlementCapabilityRegistry` for operational policy (UI label `match` for human-readable copy in presenters is not policy).
- Scanner-specific, wallet-specific, or PDF-specific **duplicated** interpretations of redeemability, multi-use, scanner action routing, **venue gate / replay policy**, **operational timing windows**, or **session / sequencing / exhaustion** (use `VenueOperationPolicyManager` for execution metadata; use `TimedEntryPolicyManager` for clock policy; use `SessionEntitlementPolicyManager` for session orchestration).
- Duplicate parallel capability arrays or entitlement-to-action maps outside the registry.
- UI strings, translated labels, or marketing copy inside **`EntitlementCapabilityRegistry`** (machine tokens only).
- Weakening redemption, fulfilment, or access checks by bypassing **`TicketCapabilityManager`** / scanner paths that already enforce entity state.

## Related documentation

- [issuance-pipeline.md](./issuance-pipeline.md) — issuance order and attachment merge
- [operational-surface-convergence.md](./operational-surface-convergence.md) — customer, PDF, and wallet surfaces
- [offline-venue-operations-convergence.md](./offline-venue-operations-convergence.md) — venue operations layer and replay scaffolding
- [timed-entry-capacity-convergence.md](./timed-entry-capacity-convergence.md) — timed entry and capacity window authority
- [session-multiuse-entitlement-convergence.md](./session-multiuse-entitlement-convergence.md) — session and multi-use orchestration authority