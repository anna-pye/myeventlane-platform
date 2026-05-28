# Vendor operational documents (merch & add-ons)

Status: **planning + Manage event UI placeholders** (no PDF/print generation in this slice)  
Scope: parking slips, packing slips, item labels, grouped fulfilment sheets, CSV export — **not** ticket issuance

## Purpose

Give organisers printable/operational outputs for merch, parking, food/drink, hospitality, timed collection, and bundles **without** treating extras as admission tickets or building a full fulfilment state machine.

## Part L — Audit: existing PDF / export / label capability

### Reusable for operational extras (read models & patterns)

| Capability | Location | Reuse notes |
|------------|----------|-------------|
| **Add-on order visibility** | `VendorOperationalAddonOrderBuilder`, route `myeventlane_vendor.console.event_operational_addon_orders` (`/vendor/events/{event}/addons`) | Order/item grouping by operational category; privacy stripping (`FORBIDDEN_VENDOR_ORDER_KEYS`). Foundation for packing slips and fulfilment sheets. |
| **Sales summary by category** | `EventOperationalExtrasSalesSummaryBuilder` | Aggregated items/revenue/orders/stock per display group — foundation for grouped fulfilment sheet + future CSV. |
| **Operational composition** | `OperationalPurchaseCompositionManager` | Canonical line grouping (merch, parking, hospitality, etc.). |
| **Stock labels** | `OperationalVariationStockResolver` | Stock remaining for export/sheets. |
| **CSV export patterns** | `VendorAttendeeController::export`, `VendorRsvpExportController`, `BasCsvExportService`, `StreamedResponse` + `fputcsv` | Pattern for future `/extras/export`; not copied blindly (different columns/privacy). |
| **Tax invoice presentation** | `TaxInvoicePresentationBuilder` | Line-item presentation for **customer** receipts — reference only for line structure, **not** for vendor packing slips (includes payment context). |
| **Operational entitlement registry** | `OperationalEntitlementCapabilityManager` (`parking_access`, etc.) | Policy/capability metadata for **ticket** operational entitlements — evaluate before any parking QR on slips. |

### Ticket-only — do not reuse directly

| Capability | Location | Why not for extras docs |
|------------|----------|-------------------------|
| **Ticket PDF generation** | `TicketPdfGenerator`, `TicketPdfTemplateBuilder`, `TicketDownloadController` | Issues/downloads **admission tickets**; QR tied to ticket/attendee payloads. |
| **QR for tickets** | `QrCodeGenerator` | Ticket PDF admission QR — **must not** be pasted onto parking/packing slips without a dedicated non-ticket entitlement channel. |
| **Ticket issuance** | `TicketIssuer`, mixed-order tests | Explicitly excludes operational lines from ticket count. |
| **Order confirmation PDF recovery** | `OrderPaidConfirmationPdfRecoverySubscriber` | Ticket attachment pipeline only. |
| **Attendee CSV export** | `myeventlane_event_attendees.vendor_export` | Ticket/RSVP attendee PII model — different purpose. |
| **Fulfillment execution UI** | `FulfillmentLifecycleManager`, `OperationalFulfillmentExecutionManager` | Ticket-row fulfilment lens; not vendor printable documents. |

### Safe for operational extras (with constraints)

- **Vendor add-on orders page** — already live; customer-safe labels only.
- **Manage event sales panel** — category metrics; no PII.
- **Future CSV export** — category/product aggregates; order-level export may include purchaser name only if aligned with existing vendor order screens (no card data, no raw email on slips).

### Deferred

- PDF templates for packing/parking/labels
- Shipping/warehouse state machines
- Scanner redemption for extras (unless non-ticket entitlement issuance is designed)
- Commerce admin routes for vendors
- Email distribution to operational teams
- Stock reservation/decrement on print

---

## Part N — Proposed future routes

| Path | Route name (proposed) | Purpose |
|------|------------------------|---------|
| `/vendor/events/{event}/extras/orders` | `myeventlane_vendor.event_extras_orders` | Order/item operational extras (may alias or supersede `/addons`) |
| `/vendor/events/{event}/extras/packing-slips` | `myeventlane_vendor.event_extras_packing_slips` | PDF/HTML packing slips by order or collection group |
| `/vendor/events/{event}/extras/parking-slips` | `myeventlane_vendor.event_extras_parking_slips` | Parking passes/slips |
| `/vendor/events/{event}/extras/labels` | `myeventlane_vendor.event_extras_labels` | Item/order labels (print-friendly) |
| `/vendor/events/{event}/extras/export` | `myeventlane_vendor.event_extras_export` | CSV grouped by category/type |

**Current live route (orders):** `myeventlane_vendor.console.event_operational_addon_orders` → `/vendor/events/{event}/addons` (documented in [vendor-operational-addon-order-visibility.md](./vendor-operational-addon-order-visibility.md)).

All future routes must:

- Use `VendorConsoleBaseController::assertEventOwnership()` (or equivalent).
- Render inside `mel_event_workspace` shell where applicable.
- Never expose Commerce admin paths.

---

## Part O — Document types (future)

### Packing slip

**Purpose:** Vendor/team preparation before/during the event.

**Include:** Event name, date, order ID, purchaser name, collection name/code (if any), items by category, quantity, variant/size, pickup note, safe internal notes.

**Exclude:** Card/payment details, Stripe IDs, platform fee breakdowns.

### Parking slip / parking pass

**Purpose:** Parking team or customer display.

**Include:** Event name, date/time, parking product name, quantity, order reference, parking instructions from product metadata.

**Optional QR:** Only if a **non-ticket** entitlement validation service exists and is explicitly wired — **not** ticket `QrCodeGenerator` payloads.

### Item / order label

**Purpose:** Bag/pack labelling.

**Include:** Short order reference, customer first name or initials (privacy rule), item group, quantity, variant/size, collection window, event name/date.

### Grouped fulfilment sheet

**Purpose:** Team handoff (merch, F&B, parking, hospitality, timed collection, bundles).

**Include:** Per-group totals, order counts, item quantities; mirrors `EventOperationalExtrasSalesSummaryBuilder::DISPLAY_GROUPS`.

---

## Part P — Privacy and access rules

1. **Event scope** — Documents only for the event the vendor owns or is a team member of (`assertEventOwnership` / `EventVendorAccessChecker`).
2. **Admin/staff** — Platform admin override per existing vendor console rules.
3. **PII minimisation** — No payment card data on slips/labels. Email/phone hidden on packing slips/labels unless a future fulfilment slice explicitly requires them (document decision in ADR).
4. **QR / tokens** — Do not reuse ticket QR payloads. Parking validation requires a dedicated operational entitlement path if implemented.
5. **Audit** — Future generation/download actions should be logged (user, event, document type, timestamp).
6. **No fake downloads** — UI must use disabled controls or real routes only.

---

## Part Q — CSV export (`/extras/export`)

**Status: deferred** (next slice).

Planned columns (no payment data):

- Event ID, event title  
- Category/type  
- Product title, variation/SKU, size/option  
- Quantity sold, order count, stock remaining  

Implementation should reuse `EventOperationalExtrasSalesSummaryBuilder` + catalog queries; exclude ticket-backed lines via `TicketBackedOrderItemClassifier`.

---

## Part M / R — Current UI (this slice)

**Manage event** — Merch & add-on sales panel includes **Operational documents**:

- Generate packing slips (disabled, Coming soon)  
- Generate parking slips (disabled, Coming soon)  
- Print item labels (disabled, Coming soon)  
- Export extras report (disabled, Coming soon)  

Helper: *Use these to prepare merch, parking, food/drink, and pickup packs for your event.*  
Notice: *Document generation is coming soon.*

Active links remain: **View extras orders**, **Manage merch & add-ons**.

**Studio extras list** (`/vendor/events/{node}/studio/extras`) — note: *After sales begin, you’ll be able to generate packing slips, parking slips, and labels from Manage event.*

**Studio product editor** (`?extra={product_id}`) — card **“Packing slips, parking slips and labels”** with the same guidance and a **Back to Manage event** link (`myeventlane_vendor.console.event_workspace`). No print/download URLs in the editor. Document generation remains deferred.

---

## Implementation map (UI only)

| Layer | Path |
|-------|------|
| Panel data | `web/modules/custom/myeventlane_commerce/src/Service/EventOperationalExtrasSalesSummaryBuilder::buildOperationalDocumentsBlock()` |
| Template | `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-extras-sales-panel.html.twig` |
| Styles | `web/modules/custom/myeventlane_event_studio/css/mel-event-studio-extras-sales-panel.css` |
| Studio list note | `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioEventExtrasForm.php` |
| Studio editor card | `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioExtrasProductEditorBuilder::buildOperationalDocumentsSection()` |

---

## Related docs

- [vendor-commerce-sales-monitoring.md](./vendor-commerce-sales-monitoring.md)  
- [vendor-manage-event-dashboard-ux.md](./vendor-manage-event-dashboard-ux.md)  
- [vendor-operational-addon-order-visibility.md](./vendor-operational-addon-order-visibility.md)  
- [operational-extras-stock-fields.md](./operational-extras-stock-fields.md)  
- [studio-merch-addon-product-setup.md](./studio-merch-addon-product-setup.md)
