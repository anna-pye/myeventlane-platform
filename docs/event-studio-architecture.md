# Event Studio Architecture

Status: internal governance  
Scope: canonical vendor event operations platform

## Purpose

Event Studio is the canonical MEL surface for event operations. Future event operations systems must extend Studio instead of creating parallel Drupal admin, vendor console, or one-off controller surfaces.

Studio owns the operational shell: routing into event workspaces, section navigation, autosave boundaries, publish readiness presentation, and domain delegation. It does not own every business rule. Tickets, Commerce products, fulfilment, access control, payments, and analytics remain with their domain services.

Event Commerce expansion follows the same rule. Event Studio remains the operational orchestration layer, while Drupal Commerce remains the transactional engine for carts, checkout, order items, payments, and product pricing.

## Runtime Shape

The primary implementation lives in `web/modules/custom/myeventlane_event_studio`.

Key code owners:

| Concern | Owner |
| --- | --- |
| Studio shell controller | `web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioController.php` |
| Section registry | `web/modules/custom/myeventlane_event_studio/src/EventStudioSectionManager.php` |
| Section plugins | `web/modules/custom/myeventlane_event_studio/src/Plugin/EventStudioSection` |
| Event access | `web/modules/custom/myeventlane_event_studio/src/Access/EventStudioAccess.php` |
| Autosave | `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioAutosaveService.php` |
| Save and publish orchestration | `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php` |
| Publish readiness evaluation | `web/modules/custom/myeventlane_event_studio/src/Service/EventReadinessService.php` |

Explicit named routes in `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml` are part of the operational contract. The section plugin system does not replace route YAML with a dynamic route engine.

`EventReadinessService` remains the authoritative readiness orchestrator in this phase. Provider contracts exist for future domains, but Studio does not yet use container-tagged provider orchestration for publish gates or readiness display.

## Section Model

Studio sections are governed by `EventStudioSection` plugins. A section plugin describes:

- Stable section id.
- Explicit route name.
- Route fragment used by URLs and autosave keys.
- Navigation group and weight.
- Icon metadata.
- Access policy metadata.
- Rendering target.
- Readiness participation.
- Operational domain area.
- Deferred-placeholder status.

The plugin registry provides shell metadata. It does not authorize bypassing route access, entity access, or vendor parity checks.

The shell must evaluate section access before rendering a requested section. Sidebar visibility, direct URL access, and section rendering are expected to use the same plugin access result.

## Access

Every Studio route must use server-side access enforcement. Required standards:

- Deny anonymous users.
- Deny customers without vendor workspace parity.
- Reuse `EventVendorAccessChecker` through `EventStudioAccess`.
- Preserve admin override through `administer nodes`.
- Preserve entity update access on editable event routes.
- Do not rely on sidebar hiding, Twig conditions, or client-side checks.

Section plugins can add section-level metadata and future section-level access checks, but route access remains explicit and deterministic.

The default section plugin access policy is `event_update`. It must preserve vendor workspace parity, entity update access, and the admin override. New access policies require code and documentation review before use.

## Autosave And Save

Autosave is section-scoped and uses private tempstore. Autosave keys depend on stable section ids and route fragments, so new sections must keep identifiers stable after release.

Canonical event persistence remains with `EventStudioSaveService`. Section UI must delegate to domain services instead of duplicating ticket, Commerce, fulfilment, or analytics business logic.

## Boundaries

Studio owns:

- Operational workspace shell.
- Section navigation.
- Autosave boundaries.
- Publish readiness display.
- Future readiness provider contracts.
- Empty states and operational UX standards.
- Delegation into domain services.

Studio does not own:

- Stripe or Connect charge model.
- Commerce checkout or cart mutation.
- Ticket issuance.
- Merchandise product creation.
- Fulfilment state machines.
- Scanning device flows.
- Large analytics queries.
- Dynamic readiness provider orchestration before the owning domains exist.

## Commerce Expansion

Studio may reserve and surface Commerce-oriented sections, including merchandise, add-ons, discounts, orders, and fulfilment. Deferred sections must remain placeholders until their owning domain services, access rules, persistence model, and verification paths exist.

Commerce sections must not embed raw Commerce admin widgets or create parallel admin routes inside Studio. They must use governed Studio patterns for operational tables, inline validation, isolated saves, responsive overflow handling, and mobile-safe layouts.

The current Commerce expansion foundation is read-only:

- `EventCommerceResolver` resolves event-linked Commerce relationships.
- `EventCommerceClassificationRegistry` exposes canonical purchasable classification metadata.
- Mixed carts remain Drupal Commerce-native with one cart, one checkout, and one payment flow.
- Merchandise, add-ons, donations, and upgrades remain separate purchasable classifications and must not collapse into ticket entities.
- Fulfilment remains a future domain with extension points only.

Future Commerce sections must reuse `EventStudioAccess`, preserve the `administer nodes` override, preserve vendor-team access, and block customers server-side.

## Performance Rules

Future sections must:

- Lazy-load expensive datasets.
- Paginate operational lists.
- Avoid building giant render trees on workspace load.
- Isolate autosave payloads by section.
- Keep analytics and reporting queries out of initial shell rendering.
- Attach cache contexts and tags explicitly when output depends on route, user, permissions, event, or vendor state.

## Related Docs

- `docs/event-studio-section-contracts.md`
- `docs/event-commerce-boundaries.md`
- `docs/event-commerce-classifications.md`
- `docs/mixed-cart-governance.md`
- `docs/readiness-provider-architecture.md`
- `docs/operational-readiness-governance.md`
- `docs/universal-ticket-extension.md`
- `docs/vendor-console-v2-access-matrix.md`
