# Legal Compliance Test Checklist

**Module:** myeventlane_legal + integrations  
**Date:** 2025-02-12  
**Purpose:** Verify C — Legal Integration Mode end-to-end.

---

## Prerequisites

```bash
drush en myeventlane_legal -y
drush updb -y
drush cr
```

Ensure legal config has valid URLs:
```bash
drush config:get myeventlane_legal.settings
```

---

## 1) Customer Registration

### 1.1 Required checkboxes

- [ ] Go to `/user/register`
- [ ] Verify "I agree to the Terms of Service" (required)
- [ ] Verify "I have read the Privacy Policy" (required)
- [ ] Verify "Send me updates, tips and event news" (optional, unchecked by default)
- [ ] Links open correct URLs from config
- [ ] Submit without ticking required boxes → validation errors
- [ ] Submit with required boxes ticked → account created

### 1.2 Storage verification

```bash
drush sql:query "SELECT uid, field_customer_terms_version_value, field_customer_terms_accepted_at_value, field_privacy_version_value, field_privacy_accepted_at_value, field_marketing_opt_in_value FROM user__field_customer_terms_version u LEFT JOIN user__field_customer_terms_accepted_at a ON u.uid = a.uid LEFT JOIN user__field_privacy_version p ON u.uid = p.uid LEFT JOIN user__field_privacy_accepted_at pa ON u.uid = pa.uid LEFT JOIN user__field_marketing_opt_in m ON u.uid = m.uid ORDER BY u.uid DESC LIMIT 5"
```

Or per-field:
```bash
drush sql:query "SELECT entity_id, field_customer_terms_version_value FROM user__field_customer_terms_version ORDER BY entity_id DESC LIMIT 5"
drush sql:query "SELECT entity_id, field_marketing_opt_in_value FROM user__field_marketing_opt_in ORDER BY entity_id DESC LIMIT 5"
```

### 1.3 Admin-created users

- [ ] As admin, go to `/admin/people/create`
- [ ] Verify Terms/Privacy checkboxes are **not** required (skip for admin)
- [ ] Create user → no legal validation

---

## 2) Customer RSVP

### 2.1 Anonymous RSVP (RsvpPublicForm)

- [ ] Find a free RSVP event (CTA = RSVP)
- [ ] Go to `/event/{nid}/book`
- [ ] Verify legal fieldset: Collection notice (APP 5), Terms, Privacy, Marketing opt-in
- [ ] Submit without ticking → validation errors
- [ ] Submit with required ticked → RSVP saved, thank you page

```bash
drush sql:query "SELECT id, field_customer_terms_version_value, field_customer_terms_accepted_at_value FROM rsvp_submission__field_customer_terms_version ORDER BY id DESC LIMIT 5"
```

### 2.2 RSVP with donation (redirects to checkout)

- [ ] RSVP with donation amount
- [ ] Redirects to checkout
- [ ] Legal consent captured on RSVP submission before redirect
- [ ] Checkout LegalConsentPane also applies (order gets consent)

### 2.3 RSVP booking form (free $0 product)

- [ ] Event with $0 product → RsvpBookingForm
- [ ] Legal fieldset present
- [ ] Submit → add to cart, checkout
- [ ] Checkout LegalConsentPane captures consent on order

### 2.4 Email confirmation

- [ ] RSVP completes → confirmation email still sends
- [ ] No regression in mailer

---

## 3) Paid Checkout

### 3.1 Guest checkout

- [ ] Anonymous user adds paid ticket to cart
- [ ] Proceed to checkout
- [ ] mel_buyer_details: enter email, name
- [ ] mel_legal_consent: collection notice (APP 5), required checkbox, Terms/Privacy/Refund links
- [ ] Submit without consent → validation error
- [ ] Submit with consent → order placed
- [ ] Receipt email sends

### 3.2 Version storage on order

```bash
drush sql:query "SELECT order_id, field_legal_consent_given_value, field_legal_consent_timestamp_value, field_customer_terms_version_value, field_privacy_version_value FROM commerce_order__field_legal_consent_given c LEFT JOIN commerce_order__field_customer_terms_version v ON c.order_id = v.entity_id ORDER BY c.order_id DESC LIMIT 5"
```

### 3.3 Receipt

- [ ] Order completes → receipt email sends
- [ ] No regression

---

## 4) Vendor Onboarding

### 4.1 Vendor terms step

- [ ] Log in as vendor (organiser)
- [ ] Complete profile, Stripe, etc.
- [ ] Go to `/create-event`
- [ ] If vendor terms not accepted → redirect to `/vendor/onboard/terms`
- [ ] Terms form: required Vendor Terms + Privacy checkboxes
- [ ] Submit → redirect to `/create-event`

### 4.2 Storage on vendor

```bash
drush sql:query "SELECT id, field_vendor_terms_version_value, field_vendor_terms_accepted_at_value FROM myeventlane_vendor__field_vendor_terms_accepted_at ORDER BY id DESC LIMIT 5"
```

### 4.3 Stripe still enforced

- [ ] New vendor, no Stripe: try direct `/vendor/events/create` → redirect to Stripe onboarding
- [ ] Stripe enforcement unchanged

### 4.4 Cannot reach wizard without terms

- [ ] Vendor with Stripe but no terms: `/create-event` → terms page
- [ ] Direct `/vendor/events/create` → terms page (via LegalGatekeeper)

---

## 5) Cookie Consent

### 5.1 Banner

- [ ] Clear cookies, visit site
- [ ] Cookie banner appears (bottom)
- [ ] "Necessary only" / "Allow all" / "Preferences"
- [ ] Click "Necessary only" → banner dismisses
- [ ] Reload → banner does not reappear (consent cookie set)

### 5.2 Preferences page

- [ ] Go to `/cookies`
- [ ] See cookie categories, checkboxes
- [ ] Save preferences → consent updated

### 5.3 Script gating

- [ ] **Proof:** `rg -i "gtag|google.*analytics|matomo|hotjar" web/` → no third-party tracking scripts
- [ ] Cookie consent framework ready; no analytics to gate
- [ ] When adding analytics later: attach only when `mel_consent` cookie allows

---

## 6) Admin Config

- [ ] Go to `/admin/config/myeventlane/legal`
- [ ] Permission: `administer myeventlane legal`
- [ ] Edit versions, URLs (including Refund Policy), collection notices, save
- [ ] Verify config: `drush config:get myeventlane_legal.settings`

---

## 7) Drush Commands Summary

```bash
# Clear caches
drush cr

# Run updates
drush updb -y

# Inspect legal config
drush config:get myeventlane_legal.settings

# User legal fields
drush sql:query "SELECT entity_id, field_customer_terms_version_value FROM user__field_customer_terms_version LIMIT 3"

# RSVP legal fields
drush sql:query "SELECT id, field_customer_terms_version_value FROM rsvp_submission__field_customer_terms_version LIMIT 3"

# Order legal fields
drush sql:query "SELECT entity_id, field_legal_consent_given_value, field_customer_terms_version_value FROM commerce_order__field_legal_consent_given LIMIT 3"

# Vendor terms
drush sql:query "SELECT id, field_vendor_terms_accepted_at_value FROM myeventlane_vendor__field_vendor_terms_accepted_at LIMIT 3"
```

---

## 8) File-by-File Change List

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_legal/` | **NEW** – full module |
| `web/modules/custom/myeventlane_checkout_flow/.../LegalConsentPane.php` | Versioning, LegalSettingsService |
| `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.info.yml` | Depends on myeventlane_legal |
| `web/modules/custom/myeventlane_vendor/.../CreateEventGatewayController.php` | LegalGatekeeper injection + assert |
| `web/modules/custom/myeventlane_event/.../EventWizardCreateController.php` | LegalGatekeeper injection + assert |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.info.yml` | Depends on myeventlane_legal |
| `web/modules/custom/myeventlane_legal/myeventlane_legal.module` | form_alter, entity_presave, page_attachments |

---

## 9) Update Hooks

- `myeventlane_legal_update_9001`: Adds legal fields to user, rsvp_submission, commerce_order, myeventlane_vendor.
- `myeventlane_legal_update_9002`: Adds `refund_policy_url`, `collection_notice_rsvp`, `collection_notice_checkout` to config.

---

## 10) STOP if Unknown

- Admin user creation: legal checkboxes skipped when `administer users`.
- IP/UA storage: config `store_vendor_ip_ua` controls; default off.
- No analytics scripts detected; consent framework ready for future gating.
