# Vendor operational capability studio (Event Studio)

## Boundary

Event Studio **authors** operational capability metadata for an event. It does **not**:

- execute scans or mutate redemption truth
- reserve stock or decrement inventory
- run warehouse, shipping, or hospitality orchestration
- issue entitlements or change ticket/QR payloads
- enqueue background operational jobs

Canonical execution semantics remain in:

- `EntitlementCapabilityRegistry`
- `VenueOperationPolicyManager` (+ timed entry, session, zone, occupancy, continuity delegates)
- `OperationalEntitlementCapabilityManager`
- `FulfillmentLifecycleManager`
- `InventoryReservationGovernanceManager`

Event Studio delegates **inward** to these services for vocabulary and projection only.

Customer-facing read-only projections built from the same metadata are documented in
`docs/customer-operational-commerce-experience.md` (`CustomerOperationalCommerceExperienceBuilder`).

## Storage contract

Persisted on the event node as JSON in `field_mel_op_capabilities`:

```json
{
  "schema_version": 2,
  "capabilities": {
    "merch_pickup": {
      "capability_type": "merch_pickup",
      "enabled": true,
      "fulfillment_mode": "collect",
      "reservation_mode": "merch",
      "timed_entry": false,
      "session_rules": "none",
      "zone_rules": "none",
      "occupancy_rules": "none",
      "continuity_mode": "online",
      "pickup_mode": "counter",
      "readiness_state": "configured",
      "customer_visibility": "after_purchase",
      "preview_summary": "Merch pickup · collect on site",
      "commerce_linkage": {
        "product_id": 0,
        "variation_ids": [],
        "store_id": 0,
        "linkage_mode": "none",
        "fulfillment_reference": "",
        "reservation_reference": "",
        "readiness_projection": {
          "descriptor": "unbound",
          "commerce_link_ready": false,
          "authoring_only": true
        },
        "customer_visibility": "inherit",
        "operational_preview": "",
        "capability_binding_state": "unbound",
        "validation_message": ""
      }
    }
  }
}
```

No inventory quantities, execution state, replay tokens, or scanner secrets. See
[operational-commerce-capability-linking.md](./operational-commerce-capability-linking.md) for the
commerce linkage authoring contract.

## Authoring services

| Service ID | Role |
| --- | --- |
| `myeventlane_event_studio.operational_capability_studio_manager` | Normalize, validate, persist, policy projection |
| `myeventlane_event_studio.operational_capability_studio_builder` | Card render data for Twig |
| `myeventlane_event_studio.operational_capability_commerce_link_manager` | Commerce product/variation linkage validation + normalization |
| `myeventlane_event_studio.operational_capability_commerce_preview_builder` | Customer-safe linkage presentation for cards |

## Capability types

`admission`, `merch_pickup`, `hospitality_access`, `food_drink_redemption`, `parking_access`, `vip_access`, `cloakroom_retrieval`, `timed_collection`, `digital_redemption`.

## Autosave

Fulfilment section autosave stores draft `mel` in private tempstore only. It does **not** write `field_mel_op_capabilities` until an explicit save.

## Anti-patterns

- Duplicating entitlement maps in forms, controllers, or Twig
- Calling `ScannerOperationManager` from Event Studio
- Persisting `replay_token`, QR payloads, or device fingerprints in event fields
- Treating authoring `readiness_state` as live operational capability state

## Related docs

- [operational-commerce-capability-linking.md](./operational-commerce-capability-linking.md)
- [operational-entitlement-capability-convergence.md](./operational-entitlement-capability-convergence.md)
- [fulfillment-lifecycle-convergence.md](./fulfillment-lifecycle-convergence.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
- [operational-observability.md](./operational-observability.md)
