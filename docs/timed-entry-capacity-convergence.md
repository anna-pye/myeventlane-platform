# Timed entry and capacity window convergence (Phase 2E)

## Scope

This phase adds **one canonical operational timing policy layer** for ticket-backed entitlements. It does **not** change QR signing contracts, ticket codes, wallet routes, PDF issuance authority, Commerce inventory, waitlists, or scanner JSON field names.

## Canonical service

| Responsibility | Service ID | Class |
| --- | --- | --- |
| Operational timing (entry windows, grace, session/capacity metadata, scanner timing state) | `myeventlane_tickets.timed_entry_policy_manager` | `TimedEntryPolicyManager` |

## Policy flow (mandatory)

Interpretation order for scanner timing and session gating:

1. **`TimedEntryPolicyManager::evaluate()`** — sole authority for machine timing policy (no translated strings, no UI labels).
2. **`SessionEntitlementPolicyManager::buildNormalizedPayload()`** — sole authority for session / sequencing / exhaustion semantics; receives the timed snapshot for shared `session_key` / capacity hints only.
3. **`VenueOperationPolicyManager::evaluateTimedEntryForScan()`** — composes timing + session scanner slices into one `allow` decision, existing `result_token` values, and staff `message` strings; attaches `policy` metadata for audits.
4. **`ScannerOperationManager`** — applies the venue gate before operational mutation paths; does not implement parallel window, session, or sequencing rules.

## Normalized policy shape

`TimedEntryPolicyManager::evaluate()` returns:

```php
[
  'entry_window' => [
    'opens_at' => int|null,
    'closes_at' => int|null,
    'grace_seconds' => int,
    'late_entry_allowed' => bool,
    'early_entry_allowed' => bool,
  ],
  'session' => [
    'session_key' => string|null,
    'capacity_window' => string|null,
  ],
  'scanner' => [
    'allowed_now' => bool,
    'state' => 'allowed|early|late|expired|not_started',
    'reason' => string, // machine token only
  ],
]
```

## Timing sources (no new entities)

The policy manager may derive bounds from, in order of composition:

- explicit Unix bounds in ticket `metadata_json` (see keys below)
- ticket `collect_window` (daterange) for operational windows (for example parking, pickup, staggered sessions)
- optional offsets from linked event `field_event_start` / `field_event_end` when metadata provides `entry_open_offset_seconds` / `entry_close_offset_seconds`
- ticket `expires_at` and optional structured QR `exp` (passed into `evaluate()` as `parsed_qr_expires_at`) as **upper caps** on closing — QR contracts are unchanged; caps are interpretive only

When no window sources exist, **`scanner.state` = `allowed`** with reason **`legacy_unrestricted`** (preserves pre-phase behaviour for typical admission rows).

### Metadata keys (`metadata_json`)

Supported containers (first match wins):

- `mel_operational_timing` (preferred)
- `operational_timing` (alias)

Supported fields inside the container:

| Field | Type | Role |
| --- | --- | --- |
| `entry_opens_at` | int (Unix) | Nominal entry open |
| `entry_closes_at` | int (Unix) | Nominal entry close |
| `entry_open_offset_seconds` | int | Added to event start (when event start present) |
| `entry_close_offset_seconds` | int | Added to event end (or start if end missing) |
| `grace_seconds` | int | Extends close when `late_entry_allowed` is true |
| `late_entry_allowed` | bool | Default true when omitted |
| `early_entry_allowed` | bool | Default false when omitted |
| `session_key` | string | Workshop / session identifier (operational only) |
| `capacity_window` | string | Capacity slot label (operational only) |

Multiple sources combine using **strict intersection**: latest `opens_at` wins among defined opens; earliest `closes_at` wins among defined closes.

## Scanner API compatibility

Denied timing uses existing result tokens only:

- **`expired`** when policy state is `expired` (including post-window and hard caps from ticket or QR expiry interpretation)
- **`invalid`** when policy state is `not_started` (before nominal open without early allowance)

Messages remain staff/scanner-facing English strings consistent with existing scanner responses; policy output itself stays machine-only.

## Observability

`OperationalIntegrityInspector::inspectOrder()` includes **`artifacts.timed_entry_policy`**, keyed by ticket id, with:

- full `policy` array from `TimedEntryPolicyManager::evaluate()` at inspection time
- `conflicts` from `TimedEntryPolicyManager::detectTimingConflicts()` (machine codes only)

Read-only guarantees from [operational-observability.md](./operational-observability.md) still apply.

## View model

`UniversalTicketViewModelBuilder::build()` adds:

- top-level **`timed_entry`** — same normalized policy array
- top-level **`session_entitlement`** — normalized session payload from `SessionEntitlementPolicyManager` (see [session-multiuse-entitlement-convergence.md](./session-multiuse-entitlement-convergence.md))
- **`scanner.timing_state`** / **`scanner.timing_allowed_now`** plus compact **`scanner.session_*`** hints — without altering existing `qr.payload` or wallet/PDF action contracts

## Anti-patterns (forbidden)

- Scanner-local timing rules, PDF-only windows, or wallet-only windows
- UI-owned timing authority or translated strings inside `TimedEntryPolicyManager`
- Duplicate grace-period or entry-window logic outside `TimedEntryPolicyManager`
- Direct wall-clock comparisons on scanner paths that bypass `VenueOperationPolicyManager` / `TimedEntryPolicyManager`
- Mutating ticket issuance or weakening replay protections to satisfy timing

## Related documentation

- [offline-venue-operations-convergence.md](./offline-venue-operations-convergence.md) — venue gate layer and scanner orchestration
- [operational-observability.md](./operational-observability.md) — diagnostics domains
- [entitlement-capability-convergence.md](./entitlement-capability-convergence.md) — registry delegation
- [session-multiuse-entitlement-convergence.md](./session-multiuse-entitlement-convergence.md) — session, sequencing, and multi-use orchestration
