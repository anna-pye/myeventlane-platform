# MEL Payment Sequence Diagrams

**Status:** READ-ONLY Phase 2  
**Date:** 20 July 2026  
**Basis:** Phase 1 runtime map + Phase 2 DDEV verification  
**Note:** Diagrams show the **wired** runtime, not the unused `stripe_connect` destination-charge path.

---

## 1. Ticket purchase

```mermaid
sequenceDiagram
  autonumber
  actor Customer
  participant Browser
  participant Drupal as Drupal (BookController)
  participant Commerce as Commerce cart/checkout
  participant Gateway as Payment Gateway (stripe typical)
  participant Stripe as Stripe API
  participant Webhook as Webhook
  participant Email as Messaging queue
  participant Wallet as myeventlane_wallet
  participant Ledger as Payout ledger
  participant Queue as Queue worker

  Customer->>Browser: Book tickets
  Browser->>Drupal: GET /event/{node}/book
  Drupal->>Commerce: Add ticket order items (order type default)
  Customer->>Commerce: mel_event_checkout panes
  Commerce->>Gateway: payment_information / payment_process
  Gateway->>Stripe: PaymentIntent (platform account)
  Stripe-->>Gateway: succeeded
  Note over Webhook: No MEL ticket payment webhook required for capture
  Commerce->>Commerce: place + ORDER_PAID
  Commerce->>Email: OrderConfirmationQueueBuilder enqueue
  Email->>Queue: MessagingQueueWorker
  Queue->>Customer: Confirmation email (+ optional wallet URLs)
  Customer->>Wallet: Download pass (post-payment, issued ticket)
  Note over Ledger: Ledger row NOT created on ORDER_PAID<br/>May appear later via PlatformMetricsService KPIs
```

---

## 2. Boost purchase

```mermaid
sequenceDiagram
  autonumber
  actor Vendor
  participant Browser
  participant Drupal as Boost forms/controller
  participant Commerce as Commerce cart/checkout
  participant Gateway as Gateway stripe
  participant Stripe as Stripe API
  participant Email as Messaging
  participant Ledger as Payout ledger
  participant Queue as Queue worker

  Vendor->>Browser: Select boost
  Browser->>Drupal: BoostSelect/Purchase form
  Drupal->>Commerce: Add boost order item → checkout
  Commerce->>Gateway: Charge
  Gateway->>Stripe: PaymentIntent (platform)
  Stripe-->>Gateway: succeeded
  Commerce->>Commerce: ORDER_PAID → BoostEntitlementManager
  Commerce->>Email: Boost confirmation template
  Email->>Queue: Send
  Note over Ledger: Boost orders can incorrectly enter ledger (CF-007)
```

---

## 3. MEL Pro

```mermaid
sequenceDiagram
  autonumber
  actor Vendor
  participant Browser
  participant Drupal as ProSubscribeForm
  participant Commerce as Cart default + Recurring
  participant Gateway as stripe_pe_recurring (intended)
  participant Stripe as Stripe API
  participant Webhook as /stripe/webhook/subscription
  participant Email as Email/Messaging
  participant Ledger as Payout ledger

  Vendor->>Browser: Subscribe
  Browser->>Drupal: ProSubscribeForm
  Drupal->>Commerce: Add mel_pro_subscription_variation
  Commerce->>Gateway: Initial payment / PM save (off_session)
  Gateway->>Stripe: PaymentIntent / Setup
  Stripe-->>Gateway: succeeded
  Commerce->>Commerce: Subscription entity + ProSubscriptionSubscriber
  Note over Webhook: Audit log only — does not mutate Commerce
  Stripe-->>Webhook: subscription.*/invoice.* (if secret set)
  Commerce->>Email: Receipts / messaging as configured
  Note over Ledger: Pro orders can incorrectly enter ledger (CF-007)
```

---

## 4. Platform donation

```mermaid
sequenceDiagram
  autonumber
  actor Customer
  participant Browser
  participant Drupal as Donations / wizard
  participant Commerce as Order type platform_donation
  participant Gateway as Gateway stripe
  participant Stripe as Stripe API
  participant Email as Messaging
  participant Ledger as Payout ledger

  Customer->>Browser: Donate to platform
  Browser->>Commerce: Checkout flow default
  Commerce->>Gateway: Charge
  Gateway->>Stripe: PaymentIntent
  Stripe-->>Gateway: succeeded
  Commerce->>Commerce: ORDER_PAID → VendorWizardPlatformDonationSubscriber
  Commerce->>Email: Confirmation as configured
  Note over Ledger: KPI path may insert unpaid vendor liability (CF-007)
```

---

## 5. RSVP donation

```mermaid
sequenceDiagram
  autonumber
  actor Customer
  participant Browser
  participant Drupal as RsvpDonationService
  participant Commerce as Order type rsvp_donation
  participant Gateway as Gateway stripe
  participant Stripe as Stripe API
  participant Email as RsvpDonationConfirmationSubscriber
  participant Ledger as Payout ledger

  Customer->>Browser: RSVP donate
  Browser->>Drupal: Create/attach donation order
  Drupal->>Commerce: Checkout flow default
  Commerce->>Gateway: Charge
  Gateway->>Stripe: PaymentIntent
  Stripe-->>Gateway: succeeded
  Commerce->>Email: ORDER_PAID confirmation
  Note over Ledger: KPI path may insert unpaid vendor liability (CF-007)
```

---

## 6. Refund

```mermaid
sequenceDiagram
  autonumber
  actor Actor as Buyer/Vendor/Admin
  participant Browser
  participant Drupal as Refund routes/forms
  participant Commerce as commerce_payment
  participant Gateway as Original payment gateway plugin
  participant Stripe as Stripe API
  participant Email as Notifications/Messaging
  participant Wallet as WalletDownloadAccessChecker
  participant Queue as Refund retry queue

  Actor->>Browser: Request / approve refund
  Browser->>Drupal: RefundProcessor
  Drupal->>Gateway: refundPayment(payment, amount)
  Gateway->>Stripe: Refund API
  Drupal->>Stripe: Verify via StripeService platform client
  Stripe-->>Drupal: Refund id / status
  Drupal->>Commerce: Update payment/order state
  Drupal->>Email: Notify parties
  Note over Wallet: Ticket status refunded/void/cancelled → wallet download denied
  Drupal->>Queue: Retry path if needed
```

---

## 7. Vendor auto billing

```mermaid
sequenceDiagram
  autonumber
  actor Admin as Admin/Drush operator
  participant Drupal as VendorAutoBillingService
  participant Commerce as (none — not checkout)
  participant Gateway as (none — StripeService)
  participant Stripe as Stripe API
  participant Email as Invoice/billing messaging
  participant Ledger as Contribution tables (not payout ledger)

  Note over Admin,Drupal: Triggered from admin form / Drush — cron not proven
  Admin->>Drupal: attemptAutoCharge(invoiceId)
  Drupal->>Stripe: createPaymentIntentOffSession (saved PM)
  Stripe-->>Drupal: succeeded / requires_action / failed
  Drupal->>Ledger: Apply to MEL contribution invoice
  Drupal->>Email: Notify as configured
```

---

## 8. Vendor onboarding (Connect Express)

```mermaid
sequenceDiagram
  autonumber
  actor Vendor
  participant Browser
  participant Drupal as StripeConnectController
  participant Commerce as commerce_store fields
  participant Gateway as (not used)
  participant Stripe as Stripe Connect API

  Vendor->>Browser: Start onboarding
  Browser->>Drupal: GET /stripe/connect
  Drupal->>Stripe: accounts.create (express) via StripeService
  Drupal->>Stripe: accountLinks.create
  Stripe-->>Browser: Hosted onboarding
  Browser->>Drupal: /stripe/callback
  Drupal->>Stripe: getAccountStatus
  Drupal->>Commerce: field_stripe_account_id / connected / status
```

---

## 9. Vendor payout

```mermaid
sequenceDiagram
  autonumber
  actor Admin
  participant Browser
  participant Drupal as PayoutBatchWorkflowService
  participant Commerce as (order history only)
  participant Gateway as (not checkout)
  participant Stripe as Stripe Transfers API
  participant Webhook as /stripe/webhook/payout
  participant Ledger as myeventlane_payout_ledger

  Note over Ledger: Rows often created lazily by PlatformMetricsService KPIs
  Admin->>Browser: Create / approve payout batch
  Browser->>Drupal: Execute batch
  Drupal->>Ledger: Select unpaid rows
  Drupal->>Stripe: transfers.create(destination=acct_...)
  Stripe-->>Webhook: transfer.created / paid / failed
  Webhook->>Ledger: Update status (idempotent checks)
```

---

## 10. Wallet generation

```mermaid
sequenceDiagram
  autonumber
  actor Customer
  participant Browser
  participant Drupal as WalletApple/Google controllers
  participant Commerce as Order item (route key only)
  participant Gateway as (none)
  participant Stripe as (none)
  participant Email as Confirmation email CTAs
  participant Wallet as PkPass / Google builders
  participant Queue as Messaging (email only)

  Note over Stripe,Gateway: Wallet never charges Stripe
  Commerce->>Email: After paid order — wallet URLs optional
  Email->>Queue: Send confirmation
  Customer->>Browser: Add to Wallet / download
  Browser->>Drupal: Wallet route + access check
  Drupal->>Commerce: Resolve order item → issued ticket
  alt Ticket void/refunded/cancelled
    Drupal-->>Browser: 403 not eligible
  else Eligible
    Drupal->>Wallet: Build signed pass / JWT
    Wallet-->>Browser: .pkpass or Google save URL
  end
```

---

## Participant coverage checklist

| Participant | Appears in |
| --- | --- |
| Customer / Vendor / Admin | All relevant flows |
| Browser | All |
| Drupal | All |
| Commerce | Ticket, Boost, Pro, Donations, Refund, Wallet (route only) |
| Payment Gateway | Ticket, Boost, Pro, Donations, Refund |
| Stripe | All money / Connect / Transfer flows |
| Webhook | Pro (audit), Payout; not ticket capture |
| Email | Ticket, Boost, Pro, Donations, Refund, Wallet CTA |
| Wallet | Ticket confirmation + Wallet generation |
| Ledger | Ticket note, Boost/Pro/Donation risk notes, Payout |
| Queue | Ticket, Boost, Refund retry, Wallet email |
