# Studio Section States

Status: internal governance  
Scope: Event Studio section activation, rendering, access, readiness metadata, and mobile behavior

## Rule

No Studio section may exist without an explicit operational state.

A Studio section is the canonical owner of:

- Rendering.
- Operational state.
- Writable, readonly, deferred, and coming-soon behavior.
- Empty-state behavior.
- Save and autosave boundary metadata.
- Readiness participation metadata.
- Mobile priority metadata.

`EventStudioController` orchestrates the workspace and delegates section content to the plugin lifecycle. It must not own a hardcoded section rendering map.

## States

| State | Meaning | Writable | Navigation |
| --- | --- | --- | --- |
| `active` | Production operational section. | Usually true; summary-only active sections may be false. | Visible unless explicitly hidden. |
| `readonly` | Operational reporting only. | False. | Visible when route access and operational availability allow it. |
| `deferred` | Governed placeholder for planned capability. | False. | Visible when the placeholder is intentionally reserved. |
| `coming_soon` | Future capability intentionally disabled. | False. | Hidden or denied unless explicitly approved. |

## Initial Activation Map

Active:

- `overview`
- `information`
- `branding`
- `content`
- `promotions`
- `settings`
- `tickets`
- `questions`
- `capacity`

Readonly:

- `attendees`
- `orders`
- `analytics`

Deferred:

- `merchandise`
- `addons`
- `fulfilment`

## Rendering

Each section plugin exposes `build(NodeInterface $event): array`. Rendering is resolved from the plugin metadata through `EventStudioSectionManager` and `EventStudioSectionRenderer`.

Render targets must be explicit. Active writable sections can render existing governed forms. Readonly sections must render event-scoped summaries or empty states through reporting services. Deferred and coming-soon sections must use `EventStudioEmptyStateBuilder`.

Capacity is active, not deferred. It uses existing capacity/ticket lifecycle data and remains non-mutating until a reviewed capacity edit contract exists.

## Access

`EventStudioAccess` is the canonical route/security gate. It owns anonymous denial, customer denial, vendor parity, entity update access, and admin override.

Section plugins own operational availability only. They must not duplicate vendor/customer/admin security rules.

## Readiness

`readiness_participant` is metadata in this phase. `EventReadinessService` remains the current readiness orchestrator. Future readiness providers may consume section metadata, but section-state work must not add readiness conditionals in Twig or JavaScript.

## Autosave And Dirty State

Autosave remains section-scoped and writable-section-only. Readonly, deferred, and coming-soon sections must not create autosave drafts.

Autosave capability is now explicit metadata. Client settings expose `currentSectionCapabilities`, but client flags are advisory only. The autosave endpoint must reload the event, re-check vendor parity and update access, and reject unsupported section states server-side before storing tempstore data.

Dirty-state tracking is still shell-assisted. The target architecture is section-owned dirty-state tracking so readonly filters, reporting widgets, and async components cannot accidentally block publish.

## Readonly Projections

Readonly sections render projection DTOs or sanitized projection arrays. They must not render mutable Drupal entities directly into Twig or workspace render arrays. Projection queries must be event-scoped, paginated when listing rows, and non-mutating.

Archived attendee questions remain hidden from new checkout capture but must still be readable in historical reporting projections when answers already exist.

## Mobile

Every section declares `mobile_priority`. Lower values represent higher mobile priority. Sidebar DOM metadata must expose section id, state, operational area, route fragment, writable state, and mobile priority so shell behavior can order and defer low-priority sections without guessing.

## JavaScript Boundary

`mel-event-studio-shell.js` owns workspace shell behavior: sidebar, autosave, publish controls, and section metadata behavior.

`mel-event-studio.js` is legacy transitional compatibility for wizard-era behavior. Do not expand it for new Studio workspace features.
