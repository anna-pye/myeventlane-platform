# Customer operational commerce experience (read-only)

Customer-facing commerce and booking surfaces show **expectation copy** derived from Event Studio
operational capability metadata. This layer is **presentation and projection only**.

## Service

- **`CustomerOperationalCommerceExperienceBuilder`** (`myeventlane_event_studio.customer_operational_commerce_experience_builder`)
  lives in `myeventlane_event_studio` so it can depend on `OperationalCapabilityCommerceLinkManager` and
  `OperationalCapabilityPreviewBuilder` without introducing a **module dependency cycle**
  (`myeventlane_event_studio` already depends on `myeventlane_tickets`; the inverse would be invalid).

## Canonical projection contract

The builder returns a top-level document with `customer_operational_experience: true` and an `items`
array. Each item is **whitelisted** to customer-safe fields only, then passed through
`sanitizeCustomerOperationalExperience()` (recursive strip of forbidden keys).

**Allowed (examples):** `capability_type`, `capability_label`, `fulfillment_summary`,
`reservation_summary`, `pickup_summary`, `readiness_label`, `readiness_state`,
`redemption_summary`, `customer_visibility`, `operational_notice`, `operational_chips`,
`timing_summary`, `collection_summary`, `hospitality_summary`, `parking_summary`.

**Forbidden:** replay tokens, QR payloads, device fingerprints, scanner actions/secrets,
operational/topology fingerprints, occupancy/zone enforcement internals, audit traces,
inventory quantities, warehouse identifiers, execution descriptors.

Sanitisation is **centralised in PHP** (`CustomerOperationalCommerceExperienceBuilder`), not in Twig.

## Delegation

The builder delegates inward to:

- `OperationalCapabilityStudioManager` (document shape, policy semantics vocabulary)
- `OperationalCapabilityPreviewBuilder` / `OperationalCapabilityCommercePreviewBuilder` (customer previews)
- `OperationalCapabilityCommerceLinkManager` (commerce linkage preview summaries for chips)
- `EntitlementCapabilityRegistry`, `FulfillmentLifecycleManager`,
  `InventoryReservationGovernanceManager` (read-only summaries; no execution paths)

It does **not** re-map capabilities or duplicate registry logic beyond assembling labels and chips.

Phase 4D adds **`OperationalPurchaseCompositionManager`** output (`mel_operational_purchase_composition`) as a **sibling** themed strip for operational **Commerce product** grouping (merch, hospitality, timed collection, bundles). It stays Commerce-backed and read-only; see [operational-merchandise-architecture.md](./operational-merchandise-architecture.md). Vendor-authored products created through the [Vendor Product Creation Wizard](vendor-product-creation-wizard.md) flow into the same customer-safe projections once linked on the event.

The same phase adds **`OperationalCheckoutOrchestrationManager`** (`mel_operational_checkout`) as another **sibling** strip that only **re-groups** composition output for cart/checkout/order/event UX; see [operational-cart-checkout-orchestration.md](./operational-cart-checkout-orchestration.md).

## Surfaces

Theme preprocess attaches a themed render array (`mel_customer_operational_experience`) on:

- Event book (`myeventlane_event_book`)
- Commerce checkout form (all steps when an order is available)
- Checkout completion include
- Commerce order user view
- My Tickets / order detail (via `myeventlane_theme` preprocess on checkout_flow themes)
- **Fulfillment execution strip** — `OperationalFulfillmentExecutionProjectionBuilder` + `mel_operational_fulfillment_execution_customer` (Phase 4C; read-only execution orchestration over merged diagnostics; see [operational-fulfillment-execution-convergence.md](./operational-fulfillment-execution-convergence.md))
- **Operational purchase composition** — `mel_operational_purchase_composition` (Phase 4D; operational Commerce product grouping; cart, checkout, order user, My Tickets, order detail; see [operational-purchase-composition-convergence.md](./operational-purchase-composition-convergence.md))
- **Operational checkout orchestration** — `mel_operational_checkout` (Phase 4D; grouped checkout plan from composition; same surfaces; see [operational-cart-checkout-orchestration.md](./operational-cart-checkout-orchestration.md))

Twig templates only **print** pre-built strings and lists; they perform no filtering or business rules.

## Anti-patterns

- Moving sanitisation or capability mapping into Twig, JavaScript, or controllers.
- Exposing vendor-only governance metadata, scanner modes, or issuance material to customers.
- Adding checkout mutations, inventory execution, fulfillment actions, or QR changes alongside this layer.

## Related docs

- `docs/operational-merchandise-architecture.md`
- `docs/operational-fulfillment-execution-convergence.md`
- `docs/operational-purchase-composition-convergence.md`
- `docs/operational-cart-checkout-orchestration.md`
- `docs/operational-commerce-capability-linking.md`
- `docs/vendor-operational-capability-studio.md`
- `docs/fulfillment-lifecycle-convergence.md`
- `docs/operational-entitlement-capability-convergence.md`
- `docs/issuance-pipeline.md`
