# Intent-Aware Authentication UI — Implementation Report

**Date:** 2025-01-31  
**Goal:** Context-aware authentication for vendor vs customer flows.  
**Status:** Complete.

---

## 1. Summary of Intent Detection Logic

**VendorIntentResolver** (`myeventlane_core.vendor_intent_resolver`) determines vendor intent from:

| Signal | Example |
|--------|---------|
| `destination` starts with `/vendor` | `/user/login?destination=/vendor/dashboard` |
| `destination` is `/create-event` | `/user/login?destination=/create-event` |
| Query param `intent=vendor` | `/user/login?intent=vendor` |

**Method:** `VendorIntentResolver::isVendorIntent(?Request $request = NULL): bool`

- Uses `request_stack` (injected)
- Returns `TRUE` if any signal matches
- Path comparison strips query string from destination

---

## 2. Files Modified

### 2.1 VendorIntentResolver.php (NEW)

**Path:** `web/modules/custom/myeventlane_core/src/Service/VendorIntentResolver.php`

```php
<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves whether the current request indicates vendor/organiser intent.
 *
 * Used for contextual auth UI (login/register copy, CTAs).
 * Signals:
 * - destination starts with /vendor
 * - destination is /create-event
 * - query param intent=vendor
 */
final class VendorIntentResolver {

  /**
   * Constructs the resolver.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Whether the request indicates vendor intent.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request, or NULL to use current request.
   *
   * @return bool
   *   TRUE if vendor intent is declared.
   */
  public function isVendorIntent(?\Symfony\Component\HttpFoundation\Request $request = NULL): bool {
    if ($request === NULL) {
      $request = $this->requestStack->getCurrentRequest();
    }
    if ($request === NULL) {
      return FALSE;
    }

    // Explicit intent flag.
    if ($request->query->get('intent') === 'vendor') {
      return TRUE;
    }

    $destination = $request->query->get('destination');
    if ($destination === NULL || $destination === '') {
      return FALSE;
    }

    // Strip query string from destination for comparison.
    $path = str_contains($destination, '?')
      ? strstr($destination, '?', TRUE)
      : $destination;
    $path = '/' . trim($path, '/');

    // destination starts with /vendor.
    if (str_starts_with($path, '/vendor')) {
      return TRUE;
    }

    // destination is /create-event.
    if ($path === '/create-event') {
      return TRUE;
    }

    return FALSE;
  }

}
```

### 2.2 myeventlane_core.services.yml

Added:

```yaml
  myeventlane_core.vendor_intent_resolver:
    class: Drupal\myeventlane_core\Service\VendorIntentResolver
    arguments:
      - '@request_stack'
```

### 2.3 CreateEventGatewayController.php

Anonymous redirect now includes `intent=vendor`:

```php
$login_url = Url::fromRoute('user.login', [], [
  'query' => [
    'destination' => $destination,
    'intent' => 'vendor',
  ],
]);
```

### 2.4 myeventlane_vendor.module

**Form alter:** Uses VendorIntentResolver; sets contextual copy and signup URL.

**Preprocess:** Copies `#mel_*` form keys to template variables.

**Registration:** Unchanged — vendor registration still uses `user.register?vendor=1` via VendorOnboardAccountController.

### 2.5 form/form--user-login-form.html.twig (NEW)

**Paths:**
- `web/themes/custom/myeventlane_theme/templates/form/form--user-login-form.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/form/form--user-login-form.html.twig`

Both use `mel_auth_headline`, `mel_auth_subcopy`, `mel_auth_signup_url`, `mel_auth_signup_text`.

### 2.6 _auth.scss (NEW)

**Path:** `web/themes/custom/myeventlane_vendor_theme/src/scss/components/_auth.scss`

Centered card, rounded inputs, primary gradient button, mobile-first, optional illustration slot.

### 2.7 main.scss (vendor theme)

Added: `@use 'components/auth';`

---

## 3. New Helper/Service

**Service ID:** `myeventlane_core.vendor_intent_resolver`  
**Class:** `Drupal\myeventlane_core\Service\VendorIntentResolver`  
**Usage:** Injected where needed; `$resolver->isVendorIntent()` or `$resolver->isVendorIntent($request)`.

---

## 4. Confirmation Checklist

| Requirement | Status |
|-------------|--------|
| No duplicate forms | Yes. Reuses `user_login_form`; no new form. |
| No duplicate onboarding logic | Yes. No changes to onboarding state or flows. |
| No new routes | Yes. Uses `user.login`, `user.register`, `myeventlane_vendor.onboard.account`. |
| Vendor intent declared before login | Yes. `/create-event` → login with `intent=vendor`. |
| Login UI adapts to intent | Yes. Headline, subcopy, signup link vary by intent. |
| Vendors never land on raw /user/register | Yes. "Create organiser account" → `/vendor/onboard/account`. |
| Customers never see vendor language | Yes. Customer intent uses generic copy. |
| Login UI matches mockup style | Yes. Centered card, rounded inputs, gradient button. |

---

## 5. Verification (Manual Tests)

1. **Anonymous → Create Event**
   - Go to `/create-event` → redirect to login with `intent=vendor`
   - Headline: "Log in to create your event"
   - Subcopy: "You'll need an organiser account"
   - "Create organiser account" → `/vendor/onboard/account` → vendor onboarding

2. **Anonymous → Sign up (customer)**
   - Go to `/user/register` (no vendor intent)
   - Default registration; no vendor copy

3. **Vendor incomplete → logout → login**
   - Login with `destination=/vendor/dashboard` or `/vendor/onboard/*`
   - Vendor-styled login; resume vendor flow

4. **Customer login**
   - Login with `destination=/my-account` or no vendor destination
   - Generic copy; no organiser language

---

## 6. Access Safety

- Customers cannot reach vendor onboarding (existing `access mel onboarding` restriction).
- Vendors see organiser copy only when intent is vendor.
- Admin access unchanged.
- No changes to access checks or permissions.
