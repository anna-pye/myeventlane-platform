# Vendor commerce sales monitoring

Read-only vendor dashboards separate **ticket sales** (attendance capacity) from **operational extras** (merch, food/drink, parking, hospitality, timed collection, bundles).

## Surfaces

| Panel | Route | Service | Counts |
|-------|-------|---------|--------|
| **Ticket sales** | `/vendor/events/{event}` (`myeventlane_vendor.console.event_workspace`) | `EventStudioCommerceSalesSummaryBuilder` | Paid ticket tiers; completed-order ticket-backed line items only |
| **Merch & add-on sales** | Same Manage event page | `EventOperationalExtrasSalesSummaryBuilder` | Operational composition lines grouped by category |
| **Merch & add-ons configured** | Same Manage event page (above sales) | `EventStudioExtrasConfiguredSummaryBuilder` | What is set up (counts, top products, CTA) — not revenue |
| **Merch & add-ons setup** | `/vendor/events/{node}/studio/extras` | Event Studio extras builder | Stock/product editor only — no ticket sales table |
| **Event commerce (analytics)** | `/vendor/events/{event}/analytics` | Reuses ticket + extras summary services | Separate ticket vs extras KPIs |
| **Add-on orders (detail)** | `/vendor/events/{event}/addons` | `VendorOperationalAddonOrderBuilder` | Per-order operational line breakdown |

## Category grouping (extras panel)

Display groups (stable order):

1. **Merchandise** — `operational_merchandise` composition lines (excluding parking capability)
2. **Food / drink** — `operational_product_type` tokens containing food/drink, or timed collection classified as food/drink
3. **Parking** — composition `parking` group (`parking_addon` capability on merchandise bundle)
4. **Hospitality** — `hospitality_package` products
5. **Timed collection** — `timed_collection_product` not classified as food/drink
6. **Bundles / other add-ons** — `operational_bundle` and unmapped operational lines

Classification uses `OperationalPurchaseCompositionManager`, `field_mel_operational_product` metadata, and product bundle — **never product titles**.

## Order states counted (extras)

Aligned with add-on orders and event orders list:

`completed`, `partially_refunded`, `refunded`, `placed`, `fulfilled`, `fulfillment`

Ticket sold counts on the same page use **`completed` only** (attendance capacity convention).

## Excluded from extras summary

- Ticket-backed order items (`TicketBackedOrderItemClassifier`)
- Boost / donation lines unless explicitly operational add-on bundles
- Customer PII, raw payment identifiers, scanner/inventory internals
- Commerce admin routes

## Stock state (extras groups)

Per category, aggregated from configured event operational products via `OperationalVariationStockResolver::summarizeProductStock()`:

- **Selling** — sales activity or catalog available
- **Sold out** — all variations in group at zero stock
- **Low stock** — finite total ≤ 5 across published variations
- **No stock limit** — at least one unlimited variation
- **No sales yet** — no qualifying sales and no catalog in group

## Operational documents (planning UI)

Manage event **Merch & add-on sales** panel includes an **Operational documents** area (packing slips, parking slips, labels, export report) — all **Coming soon** until routes exist. No dead links.

See [vendor-operational-documents.md](./vendor-operational-documents.md) for audit, privacy rules, and proposed routes (`/vendor/events/{event}/extras/export`, etc.).

## Distribution / export (deferred)

Manage event panel links:

- **View extras orders** → `myeventlane_vendor.console.event_operational_addon_orders` when available
- **Manage merch & add-ons** → `myeventlane_event_studio.workspace_extras`
- **Export extras report** — disabled in Operational documents (next slice: `myeventlane_vendor.event_extras_export`)

Future task: **Operational extras sales export by category/type** (merch team, food/drink vendor, parking, hospitality distribution lists). Email/staff assignment not implemented in this slice.

## Platform fee (merch & add-ons)

- Config: **Admin → Config → MyEventLane → General settings** → `operational_extras_platform_fee_percent`
- Default when unset: **double** `platform_fee_percent` (tickets)
- Checkout: `PlatformFeeOrderProcessor` applies separate adjustments:
  - `myeventlane_platform_fee` on ticket-backed subtotal
  - `myeventlane_operational_extras_platform_fee` on operational extras subtotal
- Stripe Connect application fee remains on **ticket revenue only** (unchanged)
- Studio copy: Merch & add-ons tab intro (`PlatformFeeSettings::buildExtrasStudioFeeCopy()`)

## Still deferred

- CSV export by category/type
- Email distribution to operational teams
- Fulfilment / shipping routing
- Stock reservation / decrement on purchase
- Refund / restock automation
- Full analytics reporting (Pro analytics remains separate)

## Implementation map

| Layer | Path |
|-------|------|
| Extras summary service | `web/modules/custom/myeventlane_commerce/src/Service/EventOperationalExtrasSalesSummaryBuilder.php` |
| Ticket summary service | `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioCommerceSalesSummaryBuilder.php` |
| Manage event controller | `web/modules/custom/myeventlane_vendor/src/Controller/EventWorkspaceController.php` |
| Extras panel template | `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-extras-sales-panel.html.twig` |
| Mission control shell | `web/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig` |
