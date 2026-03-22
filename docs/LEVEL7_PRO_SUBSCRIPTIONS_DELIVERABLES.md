# Level 7 Pro Subscriptions — Implementation Deliverables

**Date:** 2026-03-22

---

## 1. Files Changed

### Created
- `web/modules/custom/myeventlane_pro/src/Service/ProSubscriptionStatusService.php`
- `web/modules/custom/myeventlane_pro/src/Form/ProCancelRequestForm.php`
- `web/modules/custom/myeventlane_pro/src/Form/ProDiagnosticsForm.php`
- `web/modules/custom/myeventlane_pro/src/Controller/ProDiagnosticsController.php`
- `web/modules/custom/myeventlane_pro/src/Controller/ProSubscriptionWebhookController.php`
- `web/modules/custom/myeventlane_pro/templates/mel-pro-status-card.html.twig`
- `web/modules/custom/myeventlane_pro/templates/pro-diagnostics.html.twig`
- `web/modules/custom/myeventlane_pro/css/pro-diagnostics.css`

### Modified
- `web/modules/custom/myeventlane_pro/src/Service/ProAccessService.php`
- `web/modules/custom/myeventlane_pro/src/Controller/ProOverviewController.php`
- `web/modules/custom/myeventlane_pro/src/Form/ProSettingsForm.php`
- `web/modules/custom/myeventlane_pro/myeventlane_pro.services.yml`
- `web/modules/custom/myeventlane_pro/myeventlane_pro.routing.yml`
- `web/modules/custom/myeventlane_pro/myeventlane_pro.module`
- `web/modules/custom/myeventlane_pro/myeventlane_pro.permissions.yml`
- `web/modules/custom/myeventlane_pro/myeventlane_pro.libraries.yml`
- `web/modules/custom/myeventlane_pro/config/install/myeventlane_pro.settings.yml`
- `web/modules/custom/myeventlane_pro/config/schema/myeventlane_pro.schema.yml`
- `web/modules/custom/myeventlane_pro/myeventlane_pro.install`
- `web/modules/custom/myeventlane_pro/templates/vendor-pro-overview.html.twig`
- `web/modules/custom/myeventlane_pro/templates/vendor-pro-success.html.twig`
- `web/modules/custom/myeventlane_pro/css/myeventlane-pro.css`

---

## 2. What Was Implemented

### ProSubscriptionStatusService
- Returns a structured status array for any user: `is_pro`, `is_subscription_managed`, `has_active_subscription`, `subscription_state`, `billing_schedule`, `grace_expires`, `is_in_grace`, `is_manual_pro`, `can_manage_billing`, `can_cancel`, `status_label`, `status_message`
- Reads from `commerce_subscription`, `field_pro_subscription_managed`, `field_pro_grace_expires`, `vendor.is_pro`
- Uses `ProSubscriptionStateResolver` and `ProActiveResolver`; no new Pro tables

### ProAccessService (extended)
- Added `isManualPro()`, `isInGrace()`, `getProStatus()`, `canAccessFeature()`
- Injects `ProSubscriptionStatusService` for status resolution

### Vendor-facing Pro status panel
- New `mel_pro_status_card` theme: badge, plan, message, billing type, grace warning, actions (Manage subscription, Request to cancel, Contact support, Upgrade)
- Classes: `mel-pro-status-card`, `--active`, `--grace`, `--manual`, `--inactive`
- Australian English copy; integrated into Pro overview page

### Admin diagnostics
- Route: `/admin/reports/myeventlane/pro-status`
- Table per mel_pro user: uid, email, role_has_mel_pro, field_pro_subscription_managed, has_active_subscription, grace_expires, vendor_is_pro, alignment (OK / Drift detected)
- Actions: Reconcile now (per user), Reconcile all Pro users, Dry-run reconcile all
- Uses existing `ProEntitlementReconciler`; new permission `access pro diagnostics`

### Cancellation request flow
- Route: `/vendor/pro/cancel` → `ProCancelRequestForm`
- Records requests in `myeventlane_pro_cancel_request`
- Does not revoke access; does not apply Commerce cancel transition
- Only for subscription-managed Pro users; supports reason (optional)
- Existing Commerce cancel moved to `/vendor/pro/cancel/confirm` (`ProCancelConfirmForm`)

### Billing management (Stage A)
- Config: `billing_support_url`, `cancel_request_enabled`, `grace_days`, `upgrade_cta_enabled`
- Status card shows “Contact support” when URL is set; “Manage subscription” links to manage page
- No billing portal; clear “Billing management coming soon” via support CTA

### Webhook audit scaffolding
- Route: `/stripe/webhook/subscription` (POST)
- Validates with `subscription_webhook_secret`
- Logs events when `webhook_audit_enabled` is TRUE
- Does not create subscription state; Commerce Recurring remains source of truth

### Pro feature gating (strengthened)
- `ProAccessService`: `isPro()`, `isManualPro()`, `isInGrace()`, `canAccessFeature()`, `getProStatus()`
- UI uses status service; no Twig role/field guessing

### Upgrade and success flows
- Pro overview: status card, upgrade CTA, “What MEL Pro includes” pillars
- Success page: “Your Pro access is now active”, feature list, actions (Dashboard, Manage, Analytics, Branding)

### Admin config
- New commercial settings: grace_days, upgrade_cta_enabled, cancel_request_enabled, billing_support_url, webhook_audit_enabled, subscription_webhook_secret

---

## 3. Status Model

| State | How determined | status_label | status_message |
|-------|----------------|--------------|----------------|
| **Active** | Has active `commerce_subscription` (billing_schedule=mel_pro_monthly) | Active | Your MEL Pro subscription is active. |
| **Grace** | `field_pro_grace_expires` > now | Grace period | Your payment needs attention. Your Pro access is in a 7-day grace period. |
| **Manual Pro** | Has `mel_pro` role, NOT subscription-managed, NO active subscription | Manual Pro | You have manual MEL Pro access. |
| **Cancelled** | Subscription state is cancelled | Cancelled | Your Pro subscription has been cancelled. |
| **Payment failed** | Subscription in payment-failure state | Payment failed | Your payment failed. Please update your payment method. |
| **Inactive** | No Pro, no subscription | Inactive | Upgrade to MEL Pro to unlock advanced organiser tools. |

---

## 4. Billing Management

| Item | Status |
|------|--------|
| Request cancellation | Live — records in `myeventlane_pro_cancel_request` |
| View subscription state | Live — manage page, status card |
| Billing support CTA | Live — when `billing_support_url` is set |
| Commerce cancel at period end | Live — `/vendor/pro/cancel/confirm` |
| Stripe Billing Portal | Scaffolded — config keys; no portal yet |
| Stripe Customer / Subscription ID storage | Not implemented — future Stage B |

---

## 5. Diagnostics

| Feature | Description |
|---------|-------------|
| Drift detection | Compares `role_has_mel_pro`, `vendor_is_pro` vs expected (subscription + grace + manual) |
| Reconcile now | Calls `ProEntitlementReconciler::reconcileUser()` for one user |
| Reconcile all | Calls `ProEntitlementReconciler::reconcile()` |
| Dry-run | Informational — explains table and “Reconcile all” |

---

## 6. Validation

- PHP syntax: no errors on new/updated files
- No Stripe-native subscriptions introduced
- No second subscription source of truth
- `ProEntitlementReconciler` used for all reconciliation
- Manual Pro users preserved via `field_pro_subscription_managed` = FALSE
- Pro checkout path unchanged: product → cart → checkout → stripe_pe_recurring → commerce_recurring → reconciler

**Recommended checks:**
- `drush cr`
- `drush updb` (for update 10017: cancel_request table, config)
- Visit `/vendor/pro` as Pro and non-Pro vendor
- Visit `/admin/reports/myeventlane/pro-status` as admin

---

## 7. Assumptions

- `field_pro_subscription_managed` and `field_pro_grace_expires` exist (myeventlane_pro install)
- `commerce_subscription` entity and `mel_pro_monthly` billing schedule exist
- Vendor role and `myeventlane_vendor` entity with `is_pro` field exist
- `myeventlane_analytics.dashboard` and `myeventlane_pro.branding` routes exist for success page links
