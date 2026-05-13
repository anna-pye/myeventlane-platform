# Wallet operational convergence (Phase 2C)

This document is the **governance contract** for how MyEventLane generates wallet artifacts after Phase 2C. It complements [issuance-pipeline.md](./issuance-pipeline.md) and [operational-surface-convergence.md](./operational-surface-convergence.md).

## Canonical authority

- **`myeventlane_ticket`** entities are the **operational entitlement authority** for wallet bytes and wallet-adjacent metadata (alongside PDFs and scanner-facing QR).
- **Commerce order items** remain **purchase context** and **route compatibility keys** only. They must not be used as a parallel source of operational truth when issued tickets exist.

## Canonical lifecycle (inward-only)

Paid Commerce flow (unchanged):

1. `ORDER_PAID` → `TicketIssuer::issueForOrder()` → issued `myeventlane_ticket` rows.
2. Customer surfaces (My Tickets, PDFs) already normalize through `UniversalTicketViewModelBuilder` and `TicketQrPayload`.

Wallet flow (Phase 2C):

1. Stable public routes: `/wallet/apple/{order_item_id}` and `/wallet/google/{order_item_id}`.
2. **`WalletTicketResolver`** loads issued tickets for that order item ID and selects the operational row (deterministic lowest ID, or unique holder-email match when multiple rows exist for quantity > 1).
3. **`WalletDownloadAccessChecker`** enforces the same entitlement ownership semantics as ticket PDF download (including guest checkout continuity for `uid` 0 orders matched by purchaser email).
4. **`PkPassBuilder`** (and future Google JWT work) consumes the **issued `Ticket` entity** and derives QR text from the **universal view model**, which in turn uses **`TicketQrPayload`** — no parallel QR shaping.

If **no** issued ticket exists for the order item, builders keep the **legacy placeholder** behaviour (empty Apple scaffold, frozen Google placeholder URL). **No silent issuance** and **no issuance state mutation** occur from wallet routes.

## Frozen external contracts

The following must remain stable unless explicitly re-scoped with scanner and customer sign-off:

- Wallet **path patterns** and **route names** (`myeventlane_wallet.apple`, `myeventlane_wallet.google`).
- **QR payload formats** (`mel:v1:` and `mel:v1:json:`), signing, and ticket codes (owned by `TicketQrPayload` and ticket issuance).
- **Guest continuity** for orders purchased as `uid` 0 with email-based access for signed-in purchasers.
- **Google Wallet save URL** placeholder until JWT integration is implemented.

## Forbidden patterns

- Deriving wallet QR strings directly from Commerce order items, checkout paragraphs, or attendee rows when an issued ticket exists.
- Duplicating entitlement normalization outside `UniversalTicketViewModelBuilder` / `TicketCapabilityManager`.
- Duplicating QR construction outside `TicketQrPayload` (wallet code must not reimplement `mel:v1` signing or JSON payload shaping).
- Exposing internal numeric ticket IDs in public URLs (routes remain order-item keyed for compatibility).

## Residual intentional boundaries

- **Multi-ticket order items (quantity > 1)** still share one wallet URL per order item; resolver picks a single deterministic row. Holder-email disambiguation applies when exactly one row matches the active account email. This matches the historical limitation of order-item-keyed wallet links documented in Phase 2B.
- **Production `.pkpass` zip signing** remains future work; Phase 2C only guarantees **canonical operational payload scaffolding** inside the generated artifact path used by tests and local verification.

## Related tests

Kernel regressions live in `IssuancePipelineConvergenceTest` (wallet + issuance fixture). Unit coverage for guest legacy access lives in `WalletDownloadAccessCheckerTest`.
