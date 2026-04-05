# Phase 4: Auto-Billing Implementation

**Status:** Implemented  
**Date:** 2026-03-24

## Summary

Optional auto-billing for MEL vendor contribution invoices using Stripe saved payment methods. **Vendors are never charged by default**; they must explicitly opt in and save a payment method.

---

## Files Changed

### Vendor entity (myeventlane_vendor)
- `src/Entity/Vendor.php` – Base fields: `auto_billing_enabled`, `stripe_customer_id`, `stripe_default_payment_method`; getters
- `myeventlane_vendor.install` – Update 10018: install billing base fields

### Stripe (myeventlane_core)
- `src/Service/StripeService.php` – `createCustomer()`, `createSetupIntent()`, `createPaymentIntentOffSession()`, `getPaymentMethodLast4()`

### Donations (myeventlane_donations)
- `src/Service/VendorContributionInvoiceService.php` – `INVOICE_FAILED`, `applyAutoBillingPayment()`, `markInvoiceFailed()`
- `src/Service/VendorAutoBillingService.php` – **NEW** – `attemptAutoCharge()`, `processAutoBillingForInvoices()`
- `src/Service/VendorBillingPreferencesService.php` – **NEW** – `getBillingState()`, `ensureStripeCustomer()`, `savePaymentMethodFromSetupIntent()`, `removePaymentMethod()`
- `src/Controller/VendorMelContributionBillingController.php` – Billing prefs + URLs passed to template
- `src/Controller/VendorMelSavePaymentMethodController.php` – **NEW** – SetupIntent flow, remove payment
- `src/Form/GenerateMelVendorInvoicesForm.php` – Auto-billing after invoice generation
- `src/Commands/DonationCommands.php` – Auto-billing in Drush command
- `myeventlane_donations.install` – Schema: `mel_vendor_invoice_auto_payment`; Update 9005
- `myeventlane_donations.routing.yml` – `vendor_mel_save_payment_method`, `vendor_mel_remove_payment_method`
- `myeventlane_donations.services.yml` – `vendor_auto_billing`, `vendor_billing_preferences`
- `myeventlane_donations.module` – Theme hooks
- `templates/myeventlane-mel-vendor-billing.html.twig` – Auto-billing section
- `templates/myeventlane-mel-save-payment-method.html.twig` – **NEW**
- `js/mel-save-payment-method.js` – **NEW** – Stripe Payment Element + confirmSetup
- `css/mel-vendor-billing.css` – MEL-style card layout
- `myeventlane_donations.libraries.yml` – `mel-save-payment-method`
- `drush.services.yml` – DonationCommands dependency
- `myeventlane_donations.info.yml` – Dependency on `myeventlane_vendor`

---

## Stripe Reuse

- **StripeService** (`myeventlane_core.stripe`): extended, no new client
- Uses platform secret/publishable from Commerce gateway `stripe` or `mel_stripe`
- New methods:
  - `createCustomer()` – Platform customers for vendors
  - `createSetupIntent()` – Save card for later use
  - `createPaymentIntentOffSession()` – Charge saved method (`off_session=true`)
  - `getPaymentMethodLast4()` – Display “Card ending **** 4242”

---

## Auto-Billing Flow

```
Invoice generated (generateInvoicesForWindow)
       │
       ▼
processAutoBillingForInvoices(created)
       │
       ▼
For each invoice:
  ├─ vendor.isAutoBillingEnabled()? ──No──► Skip
  └─ Yes
       ├─ vendor.getStripeDefaultPaymentMethod()? ──No──► Skip
       └─ Yes
            ▼
       createPaymentIntentOffSession(...)
            │
       ├── Success ──► applyAutoBillingPayment() ──► Invoice PAID/PARTIAL
       └── Failure ──► markInvoiceFailed() ──► Invoice FAILED
```

---

## Failure Handling

| Scenario | Action |
|----------|--------|
| Charge fails (card declined, etc.) | Invoice `status = failed`; vendor sees “Payment failed” |
| No saved payment method | Skip; no charge attempt |
| Auto-billing not enabled | Skip |
| Stripe API error | Log error; mark invoice failed |

**Vendor actions:** Update payment method via “Save payment method”, or pay manually via “Pay now” (checkout).

---

## Opt-Out Safety

- **Remove payment method:** `/vendor/billing/mel-contributions/payment-method/remove`
- Disables auto-billing and clears `stripe_default_payment_method`
- Vendor can turn off auto-billing by removing the card

---

## Legal / Compliance (Australia)

- **Consent:** “By enabling, you authorise MyEventLane to charge your saved payment method for future invoices.”
- **PCI:** Only Stripe IDs stored; no raw card data
- Invoice wording (Phase 3) remains in place

---

## Deployment Steps

1. `drush updb` – Runs updates 10018 (vendor), 9005 (auto-payment table)
2. Ensure Stripe keys are set on the Commerce payment gateway
3. Clear caches

---

## Notifications (Future)

Receipts and failure emails are not implemented. Suggested next steps:

- Use `myeventlane_messaging` to send:
  - Auto-charge success: receipt
  - Auto-charge failure: “Payment failed — please update your method”
- Reuse existing MEL mail templates where possible
