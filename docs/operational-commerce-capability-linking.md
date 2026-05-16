# Operational commerce capability linking (authoring)

This slice **links** operational capability rows to Commerce products and variations in Event
Studio. It **does not** execute inventory, reservations, checkout changes, scanners, or
entitlement issuance.

## Responsibilities

- `OperationalCapabilityCommerceLinkManager` validates vendor/store ownership, product access,
  and variation membership. All commerce rules live in this service—not in forms, Twig, or
  controllers.
- `commerce_linkage` on each capability row stores normalized metadata only: product id,
  variation ids, resolved store id, linkage mode, fulfillment/reservation **reference** tokens,
  readiness projection, customer visibility for linkage, operational preview text, and binding
  state.
- `EventStudioSaveService` / autosave pipelines delegate normalization to the studio manager,
  which delegates commerce shaping to the link manager.

## Anti-patterns

- Stock counts, warehouse identifiers, shipment state, or reservation quantities in
  `commerce_linkage`.
- Duplicating entitlement or fulfillment execution logic outside the existing tickets
  managers.
- Customer-facing previews that expose SKUs, scanner internals, replay tokens, or QR payloads.

## Related docs

- [vendor-productisation-studio.md](./vendor-productisation-studio.md)
- `docs/customer-operational-commerce-experience.md`
- `docs/vendor-operational-capability-studio.md`
- `docs/fulfillment-lifecycle-convergence.md`
- `docs/inventory-reservation-governance-convergence.md`
- `docs/operational-entitlement-capability-convergence.md`
- `docs/issuance-pipeline.md`
