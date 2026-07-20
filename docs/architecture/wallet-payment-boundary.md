# MEL Wallet ↔ Payment Boundary

**Status:** READ-ONLY Phase 2  
**Date:** 20 July 2026  
**Modules:** `myeventlane_wallet`, `myeventlane_tickets`, `myeventlane_messaging`  
**Related:** [`apple-wallet-presentation.md`](./apple-wallet-presentation.md)

---

## Verdict

**Wallet is completely decoupled from Stripe checkout charging.**

Wallet never creates PaymentIntents, Charges, SetupIntents, Customers, Transfers, or Refunds. It consumes **issued ticket entitlements** and presentation/signing configuration after a purchase has already produced ticket rows.

---

## What Wallet depends on

| Dependency | Role | Evidence |
| --- | --- | --- |
| Issued `myeventlane_ticket` entity | Operational authority for pass content + eligibility | `WalletTicketResolver` — “issued myeventlane_ticket rows are the operational authority” |
| Commerce order item | Compatibility **route key** only (`/wallet/.../{commerce_order_item}`) | Controllers + resolver |
| Order / purchaser identity | Access control (uid / email match) | `WalletDownloadAccessChecker` |
| Ticket status + fulfilment status | Block void / refunded / fulfilment-cancelled | `isWalletBlockedStatus()` |
| Ticket PDF expiry setting | Optional time window (`myeventlane_tickets.settings:pdf_expiry_days`) | `isExpired()` |
| Wallet config + env credentials | Apple pass type / certs; Google issuer / service account | `WalletPresentationGate`, settings form |
| `WalletEventPresentation` | Event/venue/attendee projection for pass fields | Presentation service |
| `PkPassBuilder` / `GoogleWalletBuilder` / `WalletSigner` | Assemble + sign artifacts | Wallet services |
| Messaging (optional) | Confirmation email may embed wallet URLs | `OrderConfirmationQueueBuilder` + wallet action builder |
| QR / ticket payload services | Admission barcode content (outside Stripe) | Presentation docs; Universal ticket builders |

---

## What Wallet never depends on

| Non-dependency | Proof |
| --- | --- |
| Stripe PaymentIntent / Charge APIs | No Stripe charge/client usage under `myeventlane_wallet` (Phase 1/2 search) |
| Commerce payment gateway entity | No gateway load in wallet builders/controllers |
| Payment state machine | Access uses ticket status/fulfilment, not `commerce_payment` state |
| Connect account / Transfers / ledger | No references in wallet module payment path |
| Checkout flow / panes | Passes are post-purchase downloads/links |
| Webhook controllers | Wallet does not subscribe to Stripe webhooks |

---

## When passes become available

```text
Checkout payment succeeds
  → Commerce place / ORDER_PAID
  → Ticket issuance (myeventlane_tickets OrderPaidSubscriber path)
  → Confirmation email may include wallet CTAs (if presentation gate allows)
  → Customer hits wallet route
  → Resolver picks issued ticket
  → Access checker allows
  → Builder generates signed pass
```

**Prerequisites for customer-visible actions:**

1. Ticket row exists for the order item.  
2. Ticket not wallet-blocked.  
3. Not past PDF/wallet expiry window (if configured).  
4. Account authorised (purchaser / matching guest email / admin permission).  
5. Provider credentials ready (`WalletPresentationGate`).

If no issued ticket exists, legacy order-item-only path may still run with weaker checks — documented as compatibility in `WalletDownloadAccessChecker`.

---

## How revoked / void tickets are handled

`WalletDownloadAccessChecker::isWalletBlockedStatus()` returns TRUE when:

- Ticket `status` is `STATUS_VOID` or `STATUS_REFUNDED`, or  
- Fulfilment status is `FULFILMENT_CANCELLED`.

On blocked status:

- Download asserts **AccessDenied** with message: “This ticket is no longer eligible for wallet download.”  
- Applies even to admins for minting (comment: “including admins”).  
- Resolver prefers eligible tickets; if all blocked, returns a blocked row so the checker can deny with the correct message.

**Not proven in this audit:** Apple/Google push updates that remotely invalidate an already-installed pass. Boundary documented here is **download/mint eligibility**, not Wallet network voiding.

---

## How refunds affect Wallet

| Stage | Effect |
| --- | --- |
| Refund executes via `RefundProcessor` | Payment/order/ticket domain updates (refund module + ticket status transitions as implemented there) |
| Ticket becomes `refunded` / void / fulfilment cancelled | Subsequent wallet downloads denied |
| Already-downloaded pass on phone | **Not proven** to auto-remove; MEL gate is server-side re-mint/download |

Wallet does **not** call Stripe Refund APIs.

---

## Confirmation: decoupling from Stripe checkout

| Question | Answer |
| --- | --- |
| Does wallet run during PaymentIntent creation? | **No** |
| Does wallet require a specific gateway (`stripe` vs PE)? | **No** |
| Can a manually completed order (`mel_stripe_cc`) still get wallet if tickets issued? | **Yes, in principle** — wallet keys off tickets/access, not gateway id (gateway risk is commerce/ops, not wallet coupling) |
| Is wallet a payment method? | **No** |

---

## Launch note

Wallet launch readiness is independent of Connect destination-charge wiring. It **does** depend on reliable ticket issuance after paid orders and correct refund→ticket status transitions. See [`wallet-go-live.md`](../launch/wallet-go-live.md) for non-payment wallet ops.
