# Phase 4 — Commerce Security Audit

**Audit Date:** 2026-02-20  
**Branch:** audit/staging-2026-Q1

---

## 1. Order Workflow States

- **Workflow:** order_default ( Commerce core )
- **States:** draft, completed, canceled, etc.
- **No custom bypass identified.** Order state transitions follow Commerce workflow. StripeConnectValidationSubscriber validates post-checkout but does not bypass completion.

---

## 2. Payment Workflow

- **Gateway:** Commerce Stripe (StripePaymentElement)
- **Mode:** Test (sk_test_* in config backups)
- **Stripe Connect:** myeventlane_commerce validates Stripe Connect setup for paid events post-completion.

---

## 3. Webhook Validation

**Stripe webhooks (commerce_stripe):**

```php
$webhook_signing_secret = $this->getWebhookSigningSecret();
if (!empty($webhook_signing_secret)) {
  $webhook_event = Webhook::constructEvent($payload, $stripe_signature, $webhook_signing_secret);
} else {
  $data = Json::decode($payload);
  $webhook_event = Event::constructFrom($data);  // NO VERIFICATION
}
```

- **When secret configured:** Signature verified via `Webhook::constructEvent()`.
- **When secret empty:** Webhooks processed **without verification**. Anyone can POST fake payment completion events.

**Classification:** **CRITICAL** if webhook_signing_secret is not set in production. Verify staging/production gateway config.

**Postmark (myeventlane_messaging):** Uses shared secret in header (Authorization or X-Webhook-Secret). Code comments note: "implement proper HMAC signature validation" — currently simple secret match.

**Custom webhooks (myeventlane_webhooks):** Outbound only; HMAC signing implemented.

---

## 4. Refund Logic

- **myeventlane_refunds:** RefundProcessor, RefundAccessResolver
- **Vendor permission:** manage_refunds, request_refunds
- **Admin override:** administer commerce_order
- **No bypass identified** — refund flow uses Commerce/Stripe APIs.

---

## 5. Cart Expiration

- **commerce_order.commerce_order_type.default:** cart_expiration in third_party_settings.commerce_cart is `{ }` (empty) in sync.
- **Classification:** Low — consider enforcing cart expiration for production.

---

## 6. Credential Storage

**CRITICAL FINDINGS:**

1. **Stripe keys in committed config:**
   - `_INVALID_config_backup_2026-01-02/sync/commerce_payment.commerce_payment_gateway.stripe.yml`
   - `_myeventlane_audit/config-sync/commerce_payment.commerce_payment_gateway.stripe.yml`
   - Contains: `secret_key: sk_test_...`, `publishable_key: pk_test_...`

2. **Google Maps API key:**
   - `_INVALID_config_backup_2026-01-02/sync/myeventlane_location.settings.yml`
   - `_myeventlane_audit/config-sync/myeventlane_location.settings.yml`
   - Contains: `google_maps_api_key: AIzaSy...`

**Classification:** **CRITICAL** — Secrets in version control. Even test keys must be rotated and removed from history.

---

## 7. Payment Method Isolation

- Commerce stores payment methods per user; no cross-user leakage identified.
- Stripe Connect: vendor-scoped stores; VendorContextService restricts by store.

---

## 8. Card Data

- **No card data stored locally.** Commerce Stripe uses Stripe Elements; card details never touch Drupal.
- credit_card fields in config are for display metadata only (card_type, last4), not PAN.

---

## 9. Test Keys on Staging

- Stripe config shows `mode: test` — appropriate for staging if intentional.
- **Risk:** Test keys committed in backup/audit folders. If these keys are still valid, rotate immediately.

---

## Summary

| Item | Status | Severity |
|------|--------|----------|
| Order state bypass | None identified | — |
| Webhook verification (Stripe) | Conditional — only when secret set | **CRITICAL** if unset |
| Refund logic | Secured | — |
| Credentials in config/repo | Yes (backup folders) | **CRITICAL** |
| Card data stored | No | — |
| Payment method isolation | OK | — |
