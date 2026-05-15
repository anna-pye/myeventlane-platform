# Operational incident lifecycle convergence (Phase 3A, Commit 3)

## Purpose

Introduce a **canonical operational coordination layer** for staff-owned acknowledgement, assignment, escalation, suppression, resolution tracking, and bounded workflow metadata using the `mel_operational_incident` content entity and dedicated lifecycle services. This phase **does not** mutate entitlement truth, scanner execution, continuity authority, or issuance pipelines.

## Coordination authority

| Component | Service ID | Responsibility |
| --- | --- | --- |
| `OperationalIncidentWorkflowNormalizer` | `myeventlane_tickets.operational_incident_workflow_normalizer` | Canonical lifecycle, escalation, and acknowledgement tokens; allowed lifecycle transition graph; acknowledgement transition graph. |
| `OperationalIncidentNormalizer` | `myeventlane_tickets.operational_incident_normalizer` | Machine-safe incident type tokens; **coordination** severity (`low`, `moderate`, `elevated`, `severe`, `critical`) for stored incidents; separate **workspace sampled** severity (`info`, `warning`, `critical`) for inspector-derived rows. |
| `OperationalIncidentLifecycleManager` | `myeventlane_tickets.operational_incident_lifecycle_manager` | Bounded writes to `mel_operational_incident` only: register, lifecycle transitions (with suppression reason when entering `suppressed`), escalation, acknowledgement (validated transitions + owner consistency), assignment, resolution note append; strips sensitive keys from `operational_snapshot` and `workflow_metadata` before persistence. |
| `OperationalIncidentProjectionBuilder` | `myeventlane_tickets.operational_incident_projection_builder` | Twig-safe workspace projections (rows, badges, descriptor tokens, suppression excerpt, workflow metadata key list, optional manage URL); explicit per-entity access checks after storage queries; coordination severity normalized for display. |
| `OperationalIncidentAuditBuilder` | `myeventlane_tickets.operational_incident_audit_builder` | Structured machine-token audit payloads for logger output on coordination changes; `buildCoordinationAuditSummary()` for non-PII workflow summaries. |
| `OperationalIncidentEntityAccessControlHandler` | Entity `access` handler on `mel_operational_incident`: view allowed with workspace or manage permission; create/update/delete require `manage mel operational incidents`. |

`OperationalIntegrityInspector`, `OperationalIncidentBuilder`, and ticket/scanner services remain **read-only** from the perspective of this phase.

## Entity fields (`mel_operational_incident`)

| Field | Role |
| --- | --- |
| `incident_id` | Unique correlation token (entity label). |
| `incident_type` | Machine-safe type token. |
| `severity` | Coordination severity (`low` … `critical`). |
| `lifecycle_state` | Coordination lifecycle (see below). |
| `acknowledgement_state` | Handling acknowledgement (see below). |
| `escalation_state` | Staff escalation ladder (see below). |
| `assigned_uid` | Operational owner uid only (no profile or email on-entity). |
| `event_id`, `order_id`, `ticket_id` | Optional correlation to commerce/event entities (ids only). |
| `operational_snapshot` | Sanitized JSON projections (sensitive keys stripped at save). |
| `operational_descriptors` | JSON list of machine tokens. |
| `workflow_metadata` | JSON object of machine-safe coordination metadata (sensitive keys stripped). |
| `suppression_reason` | Required text when `lifecycle_state = suppressed`. |
| `created`, `changed`, `resolved_at`, `resolution_notes` | Audit-friendly timestamps and staff notes (no purchaser PII). |

## Workflow boundaries

- **In scope:** coordination entity semantics bounded by `OperationalIncidentLifecycleManager`; staff forms under `/admin/mel/operations/incidents*`; workspace section `operational_coordination_incidents` fed exclusively by `OperationalIncidentProjectionBuilder`.
- **Out of scope:** ticket repair, order repair, redemption log mutation, QR regeneration, wallet minting, continuity fingerprint writes, automated reconciliation, queue orchestration, realtime sync.

## Lifecycle semantics

Normalized lifecycle states: `detected`, `acknowledged`, `investigating`, `monitoring`, `resolved`, `suppressed`. Transitions are **strictly validated** in `OperationalIncidentWorkflowNormalizer::isAllowedLifecycleTransition()`. **`resolved` and `suppressed` are terminal** (no outbound transitions).

Entering **`suppressed` requires a non-empty `suppression_reason`** (enforced in `OperationalIncidentLifecycleManager::transitionLifecycle()` and the coordination form). **`suppressed` is terminal** in the transition graph alongside `resolved`.

## Acknowledgement semantics

Tokens: `unassigned`, `assigned`, `accepted`, `handed_off`.

- `OperationalIncidentLifecycleManager::assignOwner()` promotes `unassigned` → `assigned` when a positive uid is set; clearing assignment resets to `unassigned` when the transition is allowed.
- `setAcknowledgementState()` enforces `OperationalIncidentWorkflowNormalizer::isAllowedAcknowledgementTransition()` and requires `assigned_uid` for `assigned`, `accepted`, and `handed_off`.
- Staff coordination form validates acknowledgement changes against the **effective** state after assignment would be applied (so uid + `accepted` in one save remains valid).

## Escalation semantics

Tokens: `none`, `review`, `urgent`, `executive`. Unknown inputs normalize to `none`. Escalation changes are orthogonal to lifecycle transitions and never imply entitlement or scanner outcomes.

## Suppression semantics

`lifecycle_state = suppressed` marks operational noise / false-positive handling from a **coordination** perspective only, with a **mandatory** `suppression_reason`. It does **not** alter diagnostics sources, scanner logs, or tickets.

## Assignment semantics

`assigned_uid` is the operational owner reference (Drupal uid). It is not a entitlement holder field and must not be populated from purchaser or guest email resolution paths on this entity.

## Audit boundaries

- Logger lines use `OperationalIncidentAuditBuilder::buildTransitionAudit()` with scalar fields only (including `suppression_reason_len` and `workflow_metadata_key_count`).
- `buildCoordinationAuditSummary()` returns workflow tokens, ownership uid, suppression flags, and sorted metadata keys — **not** raw snapshots, replay material, or notes bodies.

## Forbidden patterns

- Mutating `myeventlane_ticket`, `commerce_order`, or `mel_redemption_log` from coordination code paths.
- Embedding replay tokens, HMAC material, raw QR payloads, fingerprints, wallet payloads, or purchaser or guest email into `operational_snapshot` / `workflow_metadata` (stripped at persistence; keys containing `email` or `fingerprint` are removed defensively).
- Computing lifecycle, escalation, coordination severity, or acknowledgement rules in Twig or thin controllers — Twig renders **pre-shaped** projections only.
- Auto-resolving, auto-escalating, or auto-suppressing incidents from diagnostics feeds (no automation engines in this commit).

## Related documents

- [operational-incident-recovery-convergence.md](./operational-incident-recovery-convergence.md) — Phase 3A Commit 2 diagnostics workspace
- [venue-operations-workspace-convergence.md](./venue-operations-workspace-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [offline-reconciliation-operational-continuity.md](./offline-reconciliation-operational-continuity.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
