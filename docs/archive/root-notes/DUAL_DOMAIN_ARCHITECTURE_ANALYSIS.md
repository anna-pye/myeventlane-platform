# MyEventLane Dual-Domain Architecture — Analysis & Implementation Plan

**Date:** 2025-01-27  
**Status:** Analysis Complete — Awaiting Approval

---

## Executive Summary

This document provides a comprehensive analysis of the MyEventLane codebase to support the implementation of a dual-domain architecture:

- **Public Domain:** `https://myeventlane.com` — Event discovery, ticketing, RSVPs, customer onboarding
- **Vendor Domain:** `https://vendor.myeventlane.com` — Vendor onboarding, dashboard, event management, Stripe Connect, analytics

Both domains share the same Drupal instance, database, and user system.

---

## 1. Current Architecture Analysis

### 1.1 Module Inventory

#### Vendor-Specific Modules
| Module | Purpose | Key Routes | Domain Impact |
|--------|---------|------------|---------------|
| `myeventlane_vendor` | Vendor entity, onboarding, Stripe Connect | `/vendor/*`, `/create-event`, `/vendor/onboard/*` | **HIGH** — Core vendor functionality |
| `myeventlane_dashboard` | Vendor dashboard | `/vendor/dashboard`, `/dashboard` | **HIGH** — Must be vendor-domain only |
| `myeventlane_boost` | Boost upgrade purchases | `/event/{node}/boost` | **MEDIUM** — Vendor-facing feature |
| `myeventlane_event_attendees` | Attendee management | `/vendor/event/{node}/attendees` | **HIGH** — Vendor-only routes |
| `myeventlane_analytics` | Analytics dashboard | Vendor analytics routes | **HIGH** — Vendor-only |
| `myeventlane_commerce` | Stripe Connect, booking | `/vendor/stripe/connect`, `/event/{node}/book` | **HIGH** — Mixed public/vendor |

#### Public-Facing Modules
| Module | Purpose | Key Routes | Domain Impact |
|--------|---------|------------|---------------|
| `myeventlane_rsvp` | Public RSVP forms | `/event/{event}/rsvp` | **LOW** — Public only |
| `myeventlane_tickets` | Ticket viewing/PDF | Ticket display routes | **LOW** — Public only |
| `myeventlane_core` | Shared services | `/onboard/*`, `/my-categories` | **MEDIUM** — Mixed (customer onboarding) |

#### Shared/Admin Modules
| Module | Purpose | Domain Impact |
|--------|---------|---------------|
| `myeventlane_event` | Event form alterations | **HIGH** — Forms must use vendor theme |
| `myeventlane_checkout_paragraph` | Checkout panes | **LOW** — Public checkout |
| `myeventlane_wallet` | Wallet pass generation | **LOW** — Public feature |
| `myeventlane_location` | Location/map services | **LOW** — Public feature |
| `myeventlane_messaging` | Email messaging | **LOW** — Background service |

### 1.2 Current Route Analysis

#### Vendor Routes (Must Move to Vendor Domain)
```
/vendor/dashboard                    → Vendor dashboard
/vendor/onboard/*                    → Vendor onboarding flow
/vendor/stripe/connect               → Stripe Connect onboarding
/vendor/stripe/manage                → Stripe management
/vendor/event/{event}/edit           → Event edit
/vendor/event/{event}/design          → Event design
/vendor/event/{event}/tickets         → Ticket configuration
/vendor/event/{event}/attendees      → Attendee management
/vendor/event/{event}/checkout-questions → Checkout questions
/create-event                        → Event creation gateway
```

#### Public Routes (Must Stay on Public Domain)
```
/event/{node}                        → Event detail page
/event/{node}/book                   → Booking flow
/event/{event}/rsvp                  → RSVP form
/events                              → Event listing
/events/category/{tid}               → Category listing
/my-events                           → Customer dashboard
/onboard/*                           → Customer onboarding
```

#### Mixed Routes (Need Domain Logic)
```
/node/add/event                      → Should redirect to vendor domain if vendor
/node/{node}/edit                    → Should redirect to vendor domain if event owner
```

### 1.3 Theme Architecture

**Current State:**
- **Public Theme:** `myeventlane_theme` (base: `stable9`)
  - Location: `web/themes/custom/myeventlane_theme/`
  - Vite build system
  - Pastel color palette, mobile-first
  - Used for all public-facing pages

- **Admin Theme:** `gin`
  - Used for `/admin/*` routes
  - Not affected by dual-domain

- **Vendor Theme:** ❌ **DOES NOT EXIST**
  - Must be created: `myeventlane_vendor_theme`
  - Requirements: Bootstrap 5, clean UI, large form fields, strong contrast

**Theme Switching:**
- ❌ No domain-based theme switching exists
- ❌ No `hook_custom_theme()` implementation
- ❌ No theme negotiator service

### 1.4 Domain Detection

**Current State:**
- ❌ No domain detection service
- ❌ No hostname checking logic
- ❌ No domain-aware routing

**Required:**
- Service: `myeventlane_core.domain_detector`
- Methods: `isVendorDomain()`, `isPublicDomain()`, `getCurrentDomain()`
- Autoloaded via `services.yml`

### 1.5 Access Control

**Current State:**
- Vendor access controlled via permissions (`access vendor dashboard`)
- No domain-based access gates
- No redirect logic for wrong-domain access

**Issues Found:**
1. `/vendor/dashboard` accessible from public domain
2. Event forms (`node/add/event`) use public theme on both domains
3. No enforcement that vendors must use vendor domain

---

## 2. Domain Map

### 2.1 Public Domain (`myeventlane.com`)

**Purpose:** Event discovery, ticketing, customer experience

**Routes:**
- `/` — Homepage
- `/events` — Event listing
- `/events/category/{tid}` — Category view
- `/event/{node}` — Event detail
- `/event/{node}/book` — Booking flow
- `/event/{event}/rsvp` — RSVP form
- `/my-events` — Customer dashboard
- `/onboard/*` — Customer onboarding
- `/user/*` — User profiles (public view)
- `/vendor/{id}` — Public vendor profile page
- `/organisers` — Vendor directory

**Theme:** `myeventlane_theme`

**Access:** Public + authenticated customers

**Blocked:** All `/vendor/*` routes (except public vendor profiles)

---

### 2.2 Vendor Domain (`vendor.myeventlane.com`)

**Purpose:** Vendor management, event creation, analytics

**Routes:**
- `/vendor/dashboard` — Main dashboard
- `/vendor/onboard/*` — Onboarding flow
- `/vendor/stripe/connect` — Stripe Connect
- `/vendor/stripe/manage` — Stripe management
- `/vendor/event/{event}/*` — Event management
- `/vendor/login` — Vendor login page
- `/vendor/register` — Vendor registration
- `/create-event` — Event creation gateway
- `/node/add/event` — Event form (vendor theme)
- `/node/{node}/edit` — Event edit (if vendor owns)

**Theme:** `myeventlane_vendor_theme` (NEW)

**Access:** Authenticated vendors only

**Blocked:** Public event discovery routes (`/events`, `/event/{node}`)

---

## 3. Required Rewrites

### 3.1 New Modules/Services

#### A. Domain Detection Service
**File:** `web/modules/custom/myeventlane_core/src/Service/DomainDetector.php`
- Detect current domain from `RequestStack`
- Check for `vendor.` prefix
- Provide helper methods

#### B. Vendor Access Gate
**File:** `web/modules/custom/myeventlane_core/src/EventSubscriber/VendorDomainSubscriber.php`
- Event subscriber on `KernelEvents::REQUEST`
- Redirect vendor routes from public domain
- Redirect public routes from vendor domain
- Enforce vendor login on vendor domain

#### C. Theme Negotiator
**File:** `web/modules/custom/myeventlane_core/src/Theme/VendorThemeNegotiator.php`
- Implement `ThemeNegotiatorInterface`
- Switch theme based on domain
- Preserve Gin admin theme

#### D. Domain Settings Form
**File:** `web/modules/custom/myeventlane_core/src/Form/DomainSettingsForm.php`
- Admin config at `/admin/config/myeventlane/domains`
- Public domain URL
- Vendor domain URL
- Force redirects toggle

### 3.2 Module Modifications

#### `myeventlane_vendor`
**Changes:**
- Update routes to enforce vendor domain
- Modify controllers to check domain
- Add redirect logic for wrong-domain access

**Files to Modify:**
- `myeventlane_vendor.routing.yml` — Add domain requirements
- `src/Controller/*.php` — Add domain checks

#### `myeventlane_dashboard`
**Changes:**
- Ensure dashboard only accessible on vendor domain
- Update `VendorDashboardController` to check domain

**Files to Modify:**
- `myeventlane_dashboard.routing.yml`
- `src/Controller/VendorDashboardController.php`

#### `myeventlane_event`
**Changes:**
- Event forms must use vendor theme on vendor domain
- Redirect event forms to vendor domain if accessed from public

**Files to Modify:**
- `myeventlane_event.module`
- `src/Form/EventFormAlter.php`

#### `myeventlane_commerce`
**Changes:**
- Stripe Connect routes must be vendor-domain only
- Booking routes remain public-domain only

**Files to Modify:**
- `myeventlane_commerce.routing.yml`

#### `myeventlane_boost`
**Changes:**
- Boost purchase page should be vendor-domain only (vendor purchasing boost for their event)

**Files to Modify:**
- `myeventlane_boost.routing.yml`

#### `myeventlane_event_attendees`
**Changes:**
- Attendee management routes must be vendor-domain only

**Files to Modify:**
- `myeventlane_event_attendees.routing.yml`

### 3.3 New Vendor Theme

**Location:** `web/themes/custom/myeventlane_vendor_theme/`

**Structure:**
```
myeventlane_vendor_theme/
├── myeventlane_vendor_theme.info.yml
├── myeventlane_vendor_theme.libraries.yml
├── myeventlane_vendor_theme.theme
├── package.json
├── vite.config.js
├── postcss.config.js
├── dist/                    # Built assets
├── src/
│   ├── js/
│   │   └── main.js
│   └── scss/
│       ├── main.scss
│       ├── tokens/
│       ├── base/
│       ├── components/
│       ├── layout/
│       └── pages/
└── templates/
    ├── page.html.twig
    ├── node--event--form.html.twig
    ├── node--event--edit.html.twig
    └── includes/
```

**Requirements:**
- Bootstrap 5 or Bootstrap-like grid
- Clean, neutral UI (not pastel)
- Large form fields
- Strong contrast
- Responsive, mobile-first
- Vite build system
- Cards for dashboard
- Tables for stats

---

## 4. File Scaffolding Plan

### 4.1 Core Services (New)

```
web/modules/custom/myeventlane_core/
├── src/
│   ├── Service/
│   │   └── DomainDetector.php                    [NEW]
│   ├── EventSubscriber/
│   │   └── VendorDomainSubscriber.php             [NEW]
│   ├── Theme/
│   │   └── VendorThemeNegotiator.php              [NEW]
│   └── Form/
│       └── DomainSettingsForm.php                 [NEW]
├── config/
│   └── install/
│       └── myeventlane_core.settings.yml          [MODIFY]
└── myeventlane_core.services.yml                  [MODIFY]
```

### 4.2 Vendor Theme (New)

```
web/themes/custom/myeventlane_vendor_theme/
├── myeventlane_vendor_theme.info.yml              [NEW]
├── myeventlane_vendor_theme.libraries.yml         [NEW]
├── myeventlane_vendor_theme.theme                 [NEW]
├── package.json                                    [NEW]
├── vite.config.js                                  [NEW]
├── postcss.config.js                               [NEW]
├── .gitignore                                      [NEW]
├── README.md                                       [NEW]
├── src/
│   ├── js/
│   │   └── main.js                                [NEW]
│   └── scss/
│       ├── main.scss                              [NEW]
│       ├── tokens/
│       │   ├── _colors.scss                       [NEW]
│       │   ├── _typography.scss                   [NEW]
│       │   ├── _spacing.scss                      [NEW]
│       │   └── _breakpoints.scss                  [NEW]
│       ├── base/
│       │   ├── _reset.scss                        [NEW]
│       │   └── _forms.scss                        [NEW]
│       ├── components/
│       │   ├── _buttons.scss                      [NEW]
│       │   ├── _cards.scss                        [NEW]
│       │   ├── _tables.scss                       [NEW]
│       │   └── _dashboard.scss                   [NEW]
│       ├── layout/
│       │   ├── _container.scss                    [NEW]
│       │   ├── _grid.scss                         [NEW]
│       │   └── _header.scss                       [NEW]
│       └── pages/
│           ├── _dashboard.scss                    [NEW]
│           └── _event-form.scss                   [NEW]
└── templates/
    ├── page.html.twig                             [NEW]
    ├── page--vendor-dashboard.html.twig            [NEW]
    ├── node--event--form.html.twig                [NEW]
    ├── node--event--edit.html.twig                 [NEW]
    └── includes/
        ├── header.html.twig                       [NEW]
        └── footer.html.twig                       [NEW]
```

### 4.3 Module Modifications

**Files to Modify (Partial List):**

```
web/modules/custom/myeventlane_vendor/
├── myeventlane_vendor.routing.yml                  [MODIFY]
└── src/Controller/
    ├── VendorDashboardController.php              [MODIFY]
    ├── StripeConnectController.php                [MODIFY]
    └── CreateEventGatewayController.php           [MODIFY]

web/modules/custom/myeventlane_dashboard/
├── myeventlane_dashboard.routing.yml               [MODIFY]
└── src/Controller/
    └── VendorDashboardController.php              [MODIFY]

web/modules/custom/myeventlane_event/
├── myeventlane_event.module                        [MODIFY]
└── src/Form/
    └── EventFormAlter.php                          [MODIFY]

web/modules/custom/myeventlane_commerce/
└── myeventlane_commerce.routing.yml                [MODIFY]

web/modules/custom/myeventlane_boost/
└── myeventlane_boost.routing.yml                  [MODIFY]

web/modules/custom/myeventlane_event_attendees/
└── myeventlane_event_attendees.routing.yml        [MODIFY]
```

---

## 5. Step-by-Step Refactor Roadmap

### Phase 1: Foundation (Domain Detection & Access Gate)

1. **Create Domain Detection Service**
   - `DomainDetector.php`
   - Register in `services.yml`
   - Test with `ddev drush php:cli`

2. **Create Vendor Access Gate**
   - `VendorDomainSubscriber.php`
   - Subscribe to `KernelEvents::REQUEST`
   - Implement redirect logic
   - Test redirects

3. **Create Domain Settings Form**
   - `DomainSettingsForm.php`
   - Add config schema
   - Test configuration

### Phase 2: Theme Infrastructure

4. **Create Vendor Theme Scaffold**
   - Basic `info.yml`, `libraries.yml`, `theme` file
   - Minimal templates
   - Enable theme in Drupal

5. **Implement Theme Negotiator**
   - `VendorThemeNegotiator.php`
   - Register in `services.yml`
   - Test theme switching

6. **Build Vendor Theme Assets**
   - SCSS structure
   - Vite configuration
   - Build pipeline
   - Test build

### Phase 3: Route Migration

7. **Update Vendor Routes**
   - Add domain checks to vendor routes
   - Update controllers
   - Test redirects

8. **Update Event Forms**
   - Redirect to vendor domain
   - Apply vendor theme
   - Test form rendering

9. **Update Dashboard Routes**
   - Enforce vendor domain
   - Test access

### Phase 4: Module Updates

10. **Update Commerce Module**
    - Stripe Connect domain enforcement
    - Test onboarding flow

11. **Update Boost Module**
    - Domain enforcement
    - Test boost purchase

12. **Update Attendees Module**
    - Domain enforcement
    - Test attendee management

### Phase 5: DDEV Configuration

13. **Configure DDEV**
    - Add `vendor.myeventlane.ddev.site` to `additional_hostnames`
    - Update `config.yaml`
    - Test both domains locally

14. **Update Documentation**
    - DDEV setup guide
    - Ngrok testing guide
    - Deployment guide

### Phase 6: Testing & Verification

15. **Integration Testing**
    - Test theme switching
    - Test redirects
    - Test Stripe Connect
    - Test event creation
    - Test vendor dashboard

16. **Performance Testing**
    - Check redirect overhead
    - Check theme switching performance

---

## 6. DDEV Configuration Changes

### Current `.ddev/config.yaml`
```yaml
additional_hostnames: []
additional_fqdns: []
```

### Required Changes
```yaml
additional_hostnames:
  - vendor
```

This will create:
- `https://myeventlane.ddev.site` (public)
- `https://vendor.myeventlane.ddev.site` (vendor)

### Local Testing
1. Update `/etc/hosts` (if not using DNS):
   ```
   127.0.0.1 myeventlane.ddev.site
   127.0.0.1 vendor.myeventlane.ddev.site
   ```

2. Test both domains:
   ```bash
   ddev start
   curl -I https://myeventlane.ddev.site
   curl -I https://vendor.myeventlane.ddev.site
   ```

### Ngrok Testing
```bash
# Public domain
ngrok http 59001 --domain=myeventlane.ngrok-free.app

# Vendor domain (separate tunnel)
ngrok http 59001 --domain=vendor.myeventlane.ngrok-free.app
```

---

## 7. Potential Issues & Solutions

### Issue 1: Session/Cookie Domain
**Problem:** Sessions may not work across subdomains  
**Solution:** Configure `settings.php` to use parent domain for cookies:
```php
$settings['cookie_domain'] = '.myeventlane.com';
```

### Issue 2: CSRF Token Validation
**Problem:** CSRF tokens may fail on cross-domain redirects  
**Solution:** Ensure tokens are preserved during redirects, or regenerate on vendor domain

### Issue 3: Stripe Connect Callback
**Problem:** Stripe Connect callback URL must match registered domain  
**Solution:** Update Stripe Connect settings to use vendor domain for callbacks

### Issue 4: Asset Loading
**Problem:** Theme assets may not load correctly on vendor domain  
**Solution:** Ensure asset URLs are absolute or relative, test CDN configuration

### Issue 5: Admin Theme Conflict
**Problem:** Gin admin theme may override vendor theme  
**Solution:** Theme negotiator must check for admin routes and preserve Gin

---

## 8. Verification Checklist

### Domain Detection
- [ ] `DomainDetector::isVendorDomain()` returns `true` on `vendor.myeventlane.com`
- [ ] `DomainDetector::isPublicDomain()` returns `true` on `myeventlane.com`
- [ ] Service autoloads correctly

### Theme Switching
- [ ] Vendor domain uses `myeventlane_vendor_theme`
- [ ] Public domain uses `myeventlane_theme`
- [ ] Admin routes (`/admin/*`) still use Gin
- [ ] Theme switching works on first page load

### Redirects
- [ ] `/vendor/dashboard` on public domain → redirects to vendor domain
- [ ] `/events` on vendor domain → redirects to public domain
- [ ] `/node/add/event` on public domain → redirects to vendor domain
- [ ] Anonymous user on vendor domain → redirects to `/vendor/login`

### Stripe Connect
- [ ] `/vendor/stripe/connect` accessible on vendor domain
- [ ] Callback URL works correctly
- [ ] Onboarding flow completes successfully

### Event Forms
- [ ] Event form uses vendor theme on vendor domain
- [ ] Form fields are large and readable
- [ ] Form submission works correctly

### Vendor Dashboard
- [ ] Dashboard loads on vendor domain
- [ ] Dashboard uses vendor theme
- [ ] All dashboard links work correctly

---

## 9. Git Branch Strategy

### Recommended Branches
1. **`feature/domain-detection`**
   - Domain detection service
   - Access gate subscriber
   - Domain settings form

2. **`feature/vendor-theme`**
   - Vendor theme scaffold
   - Theme negotiator
   - Basic templates

3. **`feature/vendor-routes`**
   - Route updates
   - Controller modifications
   - Redirect logic

4. **`feature/ddev-config`**
   - DDEV configuration
   - Testing documentation

### Merge Strategy
- Merge `feature/domain-detection` first (foundation)
- Merge `feature/vendor-theme` second (infrastructure)
- Merge `feature/vendor-routes` third (functionality)
- Merge `feature/ddev-config` last (deployment)

---

## 10. Next Steps

**Awaiting Approval:**
1. Review this analysis document
2. Confirm domain strategy
3. Approve file scaffolding plan
4. Approve refactor roadmap

**Once Approved:**
1. Begin Phase 1 implementation
2. Create domain detection service
3. Create vendor access gate
4. Test foundation components

---

**End of Analysis Document**
