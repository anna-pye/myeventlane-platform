# Follow-up: Legacy Check-in Toggle CSRF

**Date:** 2026-07-21  
**Status:** Deferred intentionally  
**Not part of Phase 2A.2**

---

## Problem

The legacy check-in toggle mutation endpoint does not enforce Drupal CSRF token validation at the route layer, and the accompanying JavaScript client does not send a CSRF token header or query parameter.

Phase 2A.2 closed attendee/event bind IDOR (PII-08). CSRF was explicitly out of scope for that hotfix and remains a separate follow-up.

---

## Evidence

### Route requirements

`web/modules/custom/myeventlane_checkin/myeventlane_checkin.routing.yml` — route `myeventlane_checkin.toggle`:

- Path: `/vendor/events/{node}/check-in/toggle/{attendee_id}`
- `_permission: toggle check-in status`
- `_method: POST`
- **No** `_csrf_token` requirement

### Controller expectations

`CheckInController::toggle()` docblock states:

> Requires POST + CSRF (route) + event ownership + attendee∈event bind.

Runtime behaviour currently enforces:

1. Event ownership via `assertEventAccess()`
2. Attendee/event bind via `attendeeBelongsToEvent()` / storage re-check
3. POST method (route requirement)

CSRF is **documented but not enforced** by the route YAML or controller code.

### JavaScript request

`web/modules/custom/myeventlane_checkin/js/checkin.js`:

- Uses `fetch(..., { method: 'POST', headers: { 'Content-Type': 'application/json' } })`
- Does **not** include `X-CSRF-Token`
- Does **not** append a `token` query parameter from `drupalSettings`

### Actual protection currently in place

| Control | Present |
|---|---|
| Authenticated session required (via permission) | Yes |
| Permission `toggle check-in status` | Yes |
| POST-only | Yes |
| Event workspace ownership check | Yes |
| Attendee belongs to route event (Phase 2A.2) | Yes |
| Route `_csrf_token` | **No** |
| Client CSRF header/token | **No** |

Same-site cookie defaults and SameSite browser behaviour may reduce cross-site POST risk depending on deployment cookie flags, but this is **not** an application-layer CSRF control and must not be treated as sufficient for launch hardening backlog closure.

---

## Affected routes

| Route name | Path | Mutation |
|---|---|---|
| `myeventlane_checkin.toggle` | `/vendor/events/{node}/check-in/toggle/{attendee_id}` | Yes — toggles check-in state |

Related legacy check-in GET routes (`page`, `list`, `search`, `scan`) are out of this CSRF finding’s mutation scope. Prefer Door Mode consolidation under Phase 2B/2C rather than expanding legacy CSRF work beyond toggle.

---

## Current behaviour

An authenticated vendor with `toggle check-in status` who can satisfy event ownership may POST to the toggle URL and mutate check-in state for attendees that belong to that event, without presenting a Drupal form/CSRF token.

Foreign attendee IDs on an owned event are denied (Phase 2A.2 bind) with no mutation and no existence disclosure.

---

## Recommended solution

1. Add `_csrf_token: 'TRUE'` (or equivalent Drupal CSRF access check) to `myeventlane_checkin.toggle`.
2. Update `checkin.js` to send `X-CSRF-Token` from `drupalSettings` / `csrf_token` session value (same pattern as other MEL vendor AJAX mutations).
3. Extend `CheckinRoutingSafetyTest` to assert CSRF requirement presence.
4. Optionally migrate callers to Door Mode and retire the legacy toggle once redirects are complete (Phase 2C surface consolidation).

Do **not** weaken ownership or bind checks while adding CSRF.

---

## Risk assessment

| Factor | Assessment |
|---|---|
| Impact if exploited | Unwanted check-in / undo for an event the victim organiser can already manage |
| Preconditions | Victim must be authenticated as a privileged vendor session; attacker must induce a cross-site POST |
| Data confidentiality | No additional PII disclosure beyond existing check-in UI for owned events |
| Integrity | Medium — attendance state integrity |
| Relationship to Phase 2A.2 | Orthogonal; bind fix does not substitute for CSRF |
| Severity (launch) | Medium residual — track as security follow-up, not Phase 2A.2 blocker |

---

## Testing strategy

- Unit/static: routing YAML asserts `_csrf_token` (or CSRF access service) on toggle.
- Functional/JS: legitimate UI toggle still succeeds with token.
- Negative: POST without token → 403; no check-in state change.
- Regression: foreign attendee ID still 403 with identical denial (no leak).

---

## Launch impact

**Deferred intentionally. Not part of Phase 2A.2.**

Phase 2A.2 may ship without CSRF on this legacy route. Track this document as a known follow-up before or during Phase 2B.3 (check-in route ownership) / Phase 2C.2 (surface consolidation).

No runtime behaviour is changed by this documentation-only follow-up.
