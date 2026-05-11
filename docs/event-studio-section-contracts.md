# Event Studio Section Contracts

Status: internal governance  
Scope: section registration, navigation, rendering, access, readiness, and operational UX

## Contract

Every Event Studio section must have two explicit pieces:

1. A named route in `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml`.
2. An `EventStudioSection` plugin in `web/modules/custom/myeventlane_event_studio/src/Plugin/EventStudioSection`.

Routes stay explicit for auditability and operational debugging. Plugins govern metadata and shell behavior.

## Plugin Metadata

Each section plugin must define:

| Metadata | Rule |
| --- | --- |
| `id` | Stable machine id used by autosave and readiness references. |
| `title` | Human-readable section title. |
| `group` | Sidebar group such as Manage Event, Commerce, or Operations. |
| `routeName` | Existing explicit route name. |
| `routeFragment` | Stable URL/autosave fragment when different from id. |
| `weight` | Navigation order inside the group. |
| `icon` | Optional shell icon metadata. |
| `accessPolicy` | Access contract identifier. |
| `renderTarget` | Rendering contract, such as controller or future form/controller target. |
| `readinessParticipant` | Whether the section contributes readiness signals. |
| `operationalArea` | Event, ticket, commerce product, fulfilment, operations, or analytics. |
| `deferred` | True for reserved placeholders without full feature implementation. |

## Current Section Inventory

| Section | Route | Domain |
| --- | --- | --- |
| `overview` | `myeventlane_event_studio.workspace` | event |
| `information` | `myeventlane_event_studio.workspace_information` | event |
| `branding` | `myeventlane_event_studio.workspace_branding` | event |
| `content` | `myeventlane_event_studio.workspace_content` | event |
| `tickets` | `myeventlane_event_studio.workspace_tickets` | ticket |
| `questions` | `myeventlane_event_studio.workspace_questions` | commerce |
| `capacity` | `myeventlane_event_studio.workspace_capacity` | ticket |
| `merchandise` | `myeventlane_event_studio.workspace_merchandise` | commerce product |
| `addons` | `myeventlane_event_studio.workspace_addons` | commerce product |
| `promotions` | `myeventlane_event_studio.workspace_promotions` | event |
| `attendees` | `myeventlane_event_studio.workspace_attendees` | operations |
| `fulfilment` | `myeventlane_event_studio.workspace_fulfilment` | fulfilment |
| `orders` | `myeventlane_event_studio.workspace_orders` | commerce |
| `analytics` | `myeventlane_event_studio.workspace_analytics` | analytics |
| `settings` | `myeventlane_event_studio.workspace_settings` | event |

## Rendering Rules

The Studio controller may continue to render existing section forms while the shell is being stabilized. New domain sections must not add large business logic directly to `EventStudioController`.

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

Section plugins may expose access metadata, but they must not replace route access.

The default plugin access policy is `event_update`. It must:

- Deny anonymous users.
- Preserve the `administer nodes` override.
- Require vendor workspace parity through `EventVendorAccessChecker`.
- Require event entity update access.
- Add user and permission cache contexts when access depends on account state.

The Studio controller must deny direct section rendering when plugin access is denied. A section hidden from navigation must not be reachable by direct URL unless a reviewed access policy explicitly allows it.

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

## Change Protocol

Adding a new section requires:

1. Explicit route YAML.
2. `EventStudioSection` plugin metadata.
3. Server-side access review.
4. Domain owner confirmation.
5. Empty state or full governed rendering target.
6. Performance and cache review.
7. Readiness-provider contract review when the section affects publish readiness.
