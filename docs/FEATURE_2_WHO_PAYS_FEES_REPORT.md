# Feature 2 — Who-Pays-Fees Configuration

**Date:** 2026-02-02  
**Status:** Complete

---

## Phase A — Foundations Confirmed

- PlatformFeeOrderProcessor: `myeventlane_commerce/src/OrderProcessor/PlatformFeeOrderProcessor.php` ✅
- StripeConnectPaymentService: `myeventlane_commerce/src/Service/StripeConnectPaymentService.php` ✅
- FeeTransparencyPane: `myeventlane_checkout_flow/src/Plugin/Commerce/CheckoutPane/FeeTransparencyPane.php` ✅
- GeneralSettingsForm: `myeventlane_core/src/Form/GeneralSettingsForm.php` ✅
- myeventlane_core.settings: `myeventlane_core/config/install/myeventlane_core.settings.yml` ✅

---

## Phase B — Design by Extension

- **Stripe fee config:** stripe_fee_percent, stripe_fee_fixed_cents from config
- **Fee payer:** buyer (default) or organizer_absorbs
- **PlatformFeeOrderProcessor:** Skip platform fee when organizer_absorbs
- **FeeTransparencyPane:** Show "Organiser absorbs" when applicable

---

## Phase C — Implementation

### Files Modified

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_core/config/schema/myeventlane_core.schema.yml` | Added stripe_fee_percent, stripe_fee_fixed_cents, fee_payer |
| `web/modules/custom/myeventlane_core/config/install/myeventlane_core.settings.yml` | Added defaults: stripe_fee_percent: 3, stripe_fee_fixed_cents: 30, fee_payer: buyer |
| `web/modules/custom/myeventlane_core/src/Form/GeneralSettingsForm.php` | Added form elements and submit handler for Stripe fee and fee_payer |
| `web/modules/custom/myeventlane_commerce/src/Service/StripeConnectPaymentService.php` | Injected ConfigFactory; calculateApplicationFee reads from config |
| `web/modules/custom/myeventlane_commerce/myeventlane_commerce.services.yml` | Added @config.factory to StripeConnectPaymentService |
| `web/modules/custom/myeventlane_commerce/src/OrderProcessor/PlatformFeeOrderProcessor.php` | Skip platform fee when fee_payer === organizer_absorbs |
| `web/modules/custom/myeventlane_checkout_flow/src/Plugin/Commerce/CheckoutPane/FeeTransparencyPane.php` | Injected ConfigFactory; show "Organiser absorbs" when fee_payer is organizer_absorbs |
| `web/modules/custom/myeventlane_core/myeventlane_core.install` | Added myeventlane_core_update_9109() for existing installations |

---

## Phase D — Verification & Safety Checks

### Manual Verification Checklist

1. [ ] Run `ddev drush updatedb -y` to apply config update
2. [ ] Navigate to Admin > Config > MyEventLane > General settings
3. [ ] Confirm Stripe fee percentage, fixed cents, and fee payer fields appear
4. [ ] Set fee_payer to "Organiser absorbs", save
5. [ ] Add ticket to cart, go to checkout
6. [ ] Confirm "Organiser absorbs" appears in order summary instead of fee amount
7. [ ] Set fee_payer back to "Buyer", confirm fee appears
8. [ ] Change stripe_fee_percent to 5, complete test payment, verify vendor payout reflects new fee

### Failure Scenarios

- **Config missing:** Code uses ?? defaults (3%, 30 cents, buyer)
- **Invalid fee_payer:** GeneralSettingsForm validates; defaults to buyer

### Security/Access Validation

- GeneralSettingsForm requires administer site configuration
- No new routes or public forms

### Existing Flows Unchanged

- Donation exclusion logic — not touched
- Stripe Connect transfer_data — not touched
- PlatformFeeOrderProcessor EXCLUDED_BUNDLES — not touched

---

## Phase E — Feature Lock Report

### Files Modified

- myeventlane_core/config/schema/myeventlane_core.schema.yml
- myeventlane_core/config/install/myeventlane_core.settings.yml
- myeventlane_core/src/Form/GeneralSettingsForm.php
- myeventlane_commerce/src/Service/StripeConnectPaymentService.php
- myeventlane_commerce/myeventlane_commerce.services.yml
- myeventlane_commerce/src/OrderProcessor/PlatformFeeOrderProcessor.php
- myeventlane_checkout_flow/src/Plugin/Commerce/CheckoutPane/FeeTransparencyPane.php
- myeventlane_core/myeventlane_core.install

### Services Extended

- StripeConnectPaymentService (ConfigFactory injected)
- FeeTransparencyPane (ConfigFactory injected)

### New Components Added

- None

### Explicit Confirmation

- **No duplicated logic:** Reused existing fee calculation; extended with config
- **No refactors:** Only extension of existing services
- **No unrelated changes:** Only fee configuration

---

*Feature 2 complete. Proceed to Feature 3.*
