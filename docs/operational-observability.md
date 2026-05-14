# Operational observability (diagnostics spine)

## Diagnostics authority

Operational integrity diagnostics are implemented by **`OperationalIntegrityInspector`** (`myeventlane_tickets.operational_integrity_inspector`) in `web/modules/custom/myeventlane_tickets/src/Service/OperationalIntegrityInspector.php`.

This service is the **single read-only diagnostics layer** for:

- issuance visibility (counts, alignment, orphan links, idempotent guard signals)
- artifact readiness (canonical PDF preconditions, wallet route scaffolds, QR payload operability, attachment continuity)
- **venue operation policy** (`artifacts.venue_operation_policy` — gate semantics, offline eligibility scaffold, replay/conflict summaries from `VenueOperationPolicyManager`, keyed by normalized entitlement type)
- **timed entry policy** (`artifacts.timed_entry_policy` — per-ticket timing snapshots and conflict codes from `TimedEntryPolicyManager`, read-only)
- **session entitlement policy** (`artifacts.session_entitlement_policy` — per-ticket session / progression / exhaustion diagnostics from `SessionEntitlementPolicyManager`, read-only)
- **zone access topology** (`artifacts.zone_access_topology` — per-ticket zone topology summaries, gate policy counts, progression/re-entry semantics, and structural conflicts from `ZoneAccessPolicyManager`, read-only)
- **operational identity** (`artifacts.operational_identity` — per-ticket device/trust/checkpoint summaries, offline signals, reconciliation grouping, and masked operator attribution composed via `DeviceOperationIdentityManager` / `VenueOperationPolicyManager`, read-only)
- recovery visibility (state marker, late-tickets-after-confirmation heuristic, mismatch flags)
- compatibility signals (canonical vs legacy surfaces, mixed paths)
- guest and purchaser continuity checks on stored entities (no live session required)

It **observes** existing operational state only. It does not issue tickets, generate PDFs or wallet bytes, alter recovery state, enqueue work, or expose signing secrets.

**Services consulted (read-only):** `TicketIssuer` (expected counts and line eligibility), `TicketPdfGenerator::canonicalPdfPreconditionsSatisfied()` (same rules as `getPdfContentForTicket()` without rendering bytes), `UniversalTicketViewModelBuilder::build()` (structural operability, no model payload emitted), `TicketQrPayload::buildForTicket()` (operability only; output not returned), `TicketCapabilityManager::getEntitlementType()`, **`EntitlementCapabilityRegistry`** (machine-safe capability summaries in `artifacts.entitlement_capability_policy`), **`VenueOperationPolicyManager`** (machine-safe venue gate descriptors and replay/conflict scaffolding surfaced under `artifacts.venue_operation_policy`; operational identity orchestration for `artifacts.operational_identity`), **`DeviceOperationIdentityManager`** (metadata normalization for operational identity diagnostics), **`TimedEntryPolicyManager`** (timing snapshots and conflict lists under `artifacts.timed_entry_policy`), **`SessionEntitlementPolicyManager`** (session snapshots under `artifacts.session_entitlement_policy`), **`ZoneAccessPolicyManager`** (zone topology summaries under `artifacts.zone_access_topology`), optional `WalletTicketResolver`, optional message sink with `findSentOrderConfirmationsForOrder()` (same shape as `MessageStorage`). Attachment continuity follows **`OrderConfirmationAttachmentResolver`** merge preconditions (holder fields per ticket) without invoking merge.

## Read-only guarantees

- No entity save, delete, or state `set`/`delete` from this service.
- No calls to `TicketIssuer::issueForOrder()` or attachment merge send paths.
- QR diagnostics use **`TicketQrPayload::buildForTicket()`** for operability checks; structured output arrays **must not** include raw payload strings, HMAC segments, or signing material (callers should consume boolean or enum-style status fields only).
- Do not embed purchaser email addresses or holder emails in diagnostics arrays; use counts and machine-safe status tokens only.

## Operational integrity domains

Normalized `inspectOrder(OrderInterface $order)` returns:

| Key | Role |
| --- | --- |
| `issuance` | Expected vs issued counts, alignment status, idempotent replay skip signal, partial-issuance guard anomaly, orphan ticket / orphan eligible order item counts |
| `artifacts` | Canonical PDF readiness (holder preconditions via `TicketPdfGenerator::canonicalPdfPreconditionsSatisfied()`), wallet route scaffold (order item link present), QR operability (`TicketQrPayload`), attachment continuity (holder coverage for merge rules), **`entitlement_capability_policy`** (deduplicated registry summaries per normalized entitlement type), **`venue_operation_policy`** (deduplicated venue gate semantics + descriptors from `VenueOperationPolicyManager`), **`timed_entry_policy`** (per issued ticket id: `TimedEntryPolicyManager::evaluate()` snapshot plus `detectTimingConflicts()` codes), **`session_entitlement_policy`** (per issued ticket id: `SessionEntitlementPolicyManager::buildNormalizedPayload()` snapshot plus machine sequencing / exhaustion conflict codes), **`zone_access_topology`** (per issued ticket id: `ZoneAccessPolicyManager::summarizeZoneInspection()` topology + gate summaries), **`operational_identity`** (per issued ticket id: operational device/trust/checkpoint diagnostics from `DeviceOperationIdentityManager` composed with `VenueOperationPolicyManager`) |
| `recovery` | `OrderPaidConfirmationPdfRecoverySubscriber` state key via `recoveryStateKey()`, optional message sink for sent confirmation timestamps, mismatch when recovery appears required but completion state missing |
| `compatibility` | Order-item PDF legacy surface availability, wallet resolution surface (`WalletTicketResolver`), ticket PDF path from `UniversalTicketViewModelBuilder` probe |
| `guest_continuity` | Purchaser UID alignment with order customer, guest checkout pattern checks (no PII in output) |

Status values are **machine strings** (for example `valid`, `invalid`, `missing`, `canonical`, `legacy`, `mixed`, `recovered`, `pending`, `skipped`, `orphaned`, `unknown`) suitable for logs and automated checks—not UI copy.

## Canonical vs compatibility visibility

- **Canonical** paths are inferred when issued **`myeventlane_ticket`** rows satisfy universal view model build checks and canonical PDF preconditions.
- **Legacy** surfaces remain visible where Commerce order-item PDF adapters or wallet routes operate without issued rows (compatibility only).
- **Mixed** indicates heterogeneous rows (for example some tickets holder-ready, some not) or mixed wallet resolution outcomes across line items.

## Recovery visibility

Recovery completion is read from **`State` API** using the same key as **`OrderPaidConfirmationPdfRecoverySubscriber::recoveryStateKey()`**.

When **`myeventlane_messaging.message_storage`** is available, late-ticket detection mirrors the subscriber’s comparison of earliest sent `order_confirmation` against minimum ticket `created` timestamp for the same recipient channel. Optional test doubles may implement only `findSentOrderConfirmationsForOrder()` for kernel isolation.

## Structured logging

`logger.channel.myeventlane_tickets` receives **warnings only** for:

- orphan ticket or orphan eligible order item counts
- issuance count anomalies and partial issuance under the idempotent guard
- stray ticket rows with zero expected issuable units (duplicate-prevention context)
- recovery mismatch (heuristic requirement without completion marker)

Callers must avoid invoking diagnostics on hot per-request paths to prevent noise.

## Anti-patterns (forbidden)

- Using diagnostics to **issue tickets**, **generate PDFs**, **build wallet passes**, or **mutate recovery state**
- Duplicating QR signing, payload shaping, entitlement normalization, **capability policy**, **venue gate / replay scaffolding**, **operational timing windows**, **session / sequencing / exhaustion semantics**, or **zone topology / gate legality** outside **`TicketQrPayload`**, **`UniversalTicketViewModelBuilder`**, **`TicketCapabilityManager`**, **`TicketIssuer`**, **`EntitlementCapabilityRegistry`**, **`TimedEntryPolicyManager`**, **`SessionEntitlementPolicyManager`**, **`ZoneAccessPolicyManager`**, and **`VenueOperationPolicyManager`** (use the registry for immutable type maps; use the venue policy manager for gate execution metadata and staff-side integrity envelopes; use the timed entry manager for clock policy; use the session entitlement manager for operational session orchestration; use the zone access policy manager for metadata-only zone topology)
- Treating compatibility adapters (`OrderItemPdfCompatibilityAdapter`, etc.) as operational authorities for entitlement truth
- Embedding UI strings, translated labels, or marketing copy inside diagnostics payloads
- Exposing purchaser email, entitlement secrets, raw HMAC material, or full QR payload strings through diagnostics APIs
- Adding public routes, controllers, or admin pages for this phase (callers must enforce access before invoking the service)

## Related documentation

- [issuance-pipeline.md](./issuance-pipeline.md) — issuance order and attachment merge
- [offline-venue-operations-convergence.md](./offline-venue-operations-convergence.md) — venue gate policy, offline scaffolding, replay metadata
- [entitlement-capability-convergence.md](./entitlement-capability-convergence.md) — capability registry delegation
- [timed-entry-capacity-convergence.md](./timed-entry-capacity-convergence.md) — operational timing authority and scanner convergence
- [zone-access-topology-convergence.md](./zone-access-topology-convergence.md) — zone metadata authority and observability
