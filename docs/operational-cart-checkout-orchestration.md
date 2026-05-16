# Operational cart + checkout orchestration

This document describes the **presentation-only** layer that groups operational merchandise signals for carts, checkout sidebars, order surfaces, and the event book. Commerce remains authoritative for carts, checkout transitions, orders, and payments.

## Authority

- **Commerce checkout / order / cart** own all mutation and state transitions.
- **Operational purchase composition** (`OperationalPurchaseCompositionManager`) remains the single source for how order lines are classified into operational product groups.
- **Operational checkout orchestration** (`OperationalCheckoutOrchestrationManager`) only **reshapes** composition output into the `mel_operational_checkout` customer contract: grouped sections, readiness rollup, pickup slices, continuity rows, governance labels, deterministic guidance, and a mobile projection slice. It never writes to orders, line items, checkout panes, inventory, shipments, entitlements, scanners, or QR payloads.

## Customer contract (`mel_operational_checkout`)

Top-level keys (render-safe arrays and scalars only):

- `checkout_groups` — labelled bands (merchandise, bundles, hospitality, timed collection, parking) with the same customer-safe line payloads composition already produced.
- `fulfillment_groups` — merged merchandise + bundle lines for sidebar continuity (still **not** fulfillment execution; execution stays in `myeventlane_tickets` projections).
- `pickup_groups` — lines across merchandise, bundles, and parking whose presentation includes a non-empty `pickup_mode` other than `none`.
- `hospitality_groups` / `timed_collection_groups` — passthrough slices from composition.
- `continuity_groups` — composition continuity hint plus per-line summaries for lightweight customer continuity copy.
- `readiness_rollup` — counts of `readiness_mode` values from customer-safe presentation.
- `operational_banners` + `customer_guidance` — deterministic strings from `OperationalCustomerGuidanceBuilder`.
- `governance` — read-only signals from `OperationalCheckoutGovernanceManager` (severity, readiness counts, pickup cardinality, hospitality visibility, timed-collection conflict rows, empty fulfillment execution placeholder).
- `cart_projection` — `OperationalCartProjectionBuilder::buildMobileSections()` output for stacked cards.

Forbidden keys are stripped recursively (`OperationalCheckoutOrchestrationManager::FORBIDDEN_CHECKOUT_KEYS`), including `qr_payload`, `replay_token`, `scanner_action`, `inventory_quantity`, `stock_count`, `warehouse_ids`, `shipment_provider`, and `device_fingerprint`.

## Theming

- Theme hook `mel_operational_checkout` ships in `myeventlane_commerce` with library `myeventlane_commerce/mel_operational_checkout`.
- `myeventlane_theme` preprocessors attach `mel_operational_checkout` render arrays wherever `mel_operational_purchase_composition` already appears (cart summary, checkout sidebar + completion, checkout order summary pane, commerce order user view, My Tickets cards, order detail). The event book adds the strip via `myeventlane_commerce_preprocess_myeventlane_event_book()`.

## Anti-patterns

- Duplicating purchase composition classification, reservation governance, fulfillment execution, capability mapping, or continuity semantics inside Twig or the orchestration manager.
- Mutating Commerce entities or checkout forms from preprocessors or orchestration services.
- Surfacing scanner replay tokens, warehouse identifiers, raw stock counts, or shipment provider strings in customer templates.

## Audit notes (Phase 4D — Step 2)

Prior to orchestration, customer surfaces mixed three parallel strips: Event Studio operational experience, tickets fulfillment execution projection, and purchase composition. Each used different templates and spacing, which caused **duplicate operational storytelling** on checkout sidebars and carts, **fragmented pickup vs hospitality vs timed collection** messaging, and **long mobile stacks** without a single grouped contract. The orchestration contract does **not** remove the other strips (they remain authoritative in their domains); it adds a **single grouped card hierarchy** derived only from composition so mobile readers see one predictable “checkout plan” region.

## Cross-reference (Phase 4E)

Vendor-facing **productisation authoring** (link + copy for operational products) lives in Event Studio and feeds the same `operational_merchandise.linked_products` data that composition reads. See [vendor-productisation-studio.md](./vendor-productisation-studio.md). Productisation does **not** change orchestration services or checkout mutation rules.
