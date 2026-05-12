# Event Studio Section Contracts

Status: internal governance  
Scope: section registration, navigation, rendering, access, readiness, and operational UX

## Contract

Every Event Studio section must have two explicit pieces:

1. A named route in `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml`.
2. An `EventStudioSection` plugin in `web/modules/custom/myeventlane_event_studio/src/Plugin/EventStudioSection`.

Routes stay explicit for auditability and operational debugging. Plugins govern metadata and shell behavior.

A Studio section is the canonical owner of:

- Rendering.
- Operational state.
- Writable, readonly, deferred, and coming-soon behavior.
- Save and autosave boundaries.
- Readiness participation metadata.
- Empty-state behavior.
- Mobile priority metadata.

The workspace controller orchestrates the event workspace only. It must not own a hardcoded section rendering map.

## Plugin Metadata

Each section plugin must define:

| Metadata | Rule |
| --- | --- |
| `id` | Stable machine id used by autosave and readiness references. |
| `title` | Human-readable section title. |
| `group` | Sidebar group such as Manage Event, Commerce, or Operations. |
| `routeName` | Existing explicit route name. |
| `section_state` | One of `active`, `readonly`, `deferred`, or `coming_soon`. |
| `routeFragment` | Stable URL/autosave fragment when different from id. |
| `weight` | Navigation order inside the group. |
| `icon` | Optional shell icon metadata. |
| `accessPolicy` | Access contract identifier. |
| `renderTarget` | Rendering contract resolved by the section renderer. |
| `writable` | Whether the section can mutate operational state. |
| `supports_autosave` | Whether the section may create autosave drafts. Defaults to active + writable. |
| `supports_publish` | Whether the section participates in publish-state workflows. Defaults to active + writable. |
| `supports_readiness` | Whether readiness metadata applies. Defaults to `readiness_participant`. |
| `supports_mobile_priority` | Whether mobile-priority behavior applies. Defaults from `mobile_priority`. |
| `readiness_participant` | Whether the section contributes readiness metadata. |
| `empty_state_type` | Empty-state behavior, such as `none`, `deferred`, `readonly_empty`, or `coming_soon`. |
| `mobile_priority` | Mobile ordering and responsiveness priority; lower values load earlier. |
| `operationalArea` | Event, ticket, commerce product, fulfilment, operations, or analytics. |
| `navigationVisible` | Whether the section appears in Studio navigation. |

## Current Section Inventory

| Section | Route | Domain | State |
| --- | --- | --- | --- |
| `overview` | `myeventlane_event_studio.workspace` | event | active |
| `information` | `myeventlane_event_studio.workspace_information` | event | active |
| `branding` | `myeventlane_event_studio.workspace_branding` | event | active |
| `content` | `myeventlane_event_studio.workspace_content` | event | active |
| `tickets` | `myeventlane_event_studio.workspace_tickets` | ticket | active |
| `questions` | `myeventlane_event_studio.workspace_questions` | commerce | active |
| `capacity` | `myeventlane_event_studio.workspace_capacity` | ticket | active |
| `merchandise` | `myeventlane_event_studio.workspace_merchandise` | commerce product | deferred |
| `addons` | `myeventlane_event_studio.workspace_addons` | commerce product | deferred |
| `promotions` | `myeventlane_event_studio.workspace_promotions` | event | active |
| `attendees` | `myeventlane_event_studio.workspace_attendees` | operations | readonly |
| `fulfilment` | `myeventlane_event_studio.workspace_fulfilment` | fulfilment | deferred |
| `orders` | `myeventlane_event_studio.workspace_orders` | commerce | readonly |
| `analytics` | `myeventlane_event_studio.workspace_analytics` | analytics | readonly |
| `settings` | `myeventlane_event_studio.workspace_settings` | event | active |

## Rendering Rules

Section plugins expose `build(NodeInterface $event): array`. Rendering is resolved from the plugin contract through the section manager and section renderer. New domain sections must not add large business logic directly to `EventStudioController`.

Future render targets must declare:

- The owning controller, form, or component builder.
- The save/autosave boundary.
- The domain service responsible for persistence.
- Cache contexts and tags.
- Empty-state behavior for zero-data states.

## Access Rules

Every new section route must:

- Use `EventStudioAccess`.
- Require event update access when editing event operations.
- Preserve vendor team permission checks.
- Preserve admin override.
- Deny anonymous users and customers server-side.

`EventStudioAccess` is the canonical route/security gate. Section plugins may expose operational availability metadata, but they must not duplicate anonymous/customer/vendor/admin security policy.

The Studio controller must deny direct section rendering when route access or section operational availability is denied. A section hidden from navigation must not be reachable by direct URL unless a reviewed operational policy explicitly allows it.

## UX Rules

Sections must use shared Studio patterns for:

- Empty states.
- Readiness cards.
- Operational tables.
- Sticky action bars.
- Mobile drawers.
- Save indicators.
- Inline validation.

Raw placeholder render arrays are not allowed for new sections. Use `EventStudioEmptyStateBuilder` until a governed section UI exists.

Deferred sections must remain operational placeholders. They may reserve navigation, route, and metadata contracts, but they must not add hidden product, fulfilment, scanning, POS, or analytics behavior before the owning domain exists.

Readonly sections must not expose mutation forms. Filters, pagination, and export hooks must be implemented through event-scoped reporting services, not raw Commerce admin tables or unrestricted entity trees.

Readonly sections must render DTO/projection data, not mutable entities. Projection services may use aggregate metrics, paginated read models, or sanitized arrays, but they must remain event-scoped and non-mutating.

Autosave capability is enforced server-side. `mel-event-studio-shell.js` may use capability metadata to avoid unnecessary requests, but `EventStudioAutosaveController` must re-evaluate section metadata and reject readonly, deferred, coming-soon, unknown, and autosave-unsupported sections.

Dirty-state tracking is currently shell-assisted. Future reporting and async sections must move dirty-state ownership to section-level contracts so readonly widgets cannot accidentally block publish.

## Anti-Patterns

Do not:

- Add dynamic `/studio/{section}` routes.
- Hide unauthorized sections only in Twig.
- Duplicate ticket lifecycle logic in Studio.
- Resolve Commerce products inside random forms or controllers.
- Add expensive analytics queries to workspace load.
- Add section-specific autosave protocols.
- Add readiness conditionals directly in Twig or JavaScript.
- Add plugin access policies without route-layer parity and entity-access review.
- Add new behavior to `mel-event-studio.js`; workspace behavior belongs in `mel-event-studio-shell.js` while wizard JS remains transitional compatibility.

## Change Protocol

Adding a new section requires:

1. Explicit route YAML.
2. `EventStudioSection` plugin metadata.
3. Server-side access review.
4. Domain owner confirmation.
5. Empty state or full governed rendering target.
6. Performance and cache review.
7. Readiness-provider contract review when the section affects publish readiness.
