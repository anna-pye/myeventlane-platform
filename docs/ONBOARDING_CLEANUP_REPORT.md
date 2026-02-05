# Vendor Onboarding Cleanup Report

**Date:** 2025-01-31  
**Goal:** Establish ONE canonical vendor onboarding flow.  
**Status:** Complete.

---

## 1. Canonical Onboarding Flow (Final)

### Entry Point
- **`/create-event`** — Single canonical entry for vendors.
  - Anonymous → login/register with destination `/create-event`.
  - Authenticated without vendor → vendor setup (account/profile).
  - Authenticated with vendor → event wizard or dashboard.

### Onboarding Routes (Canonical)
- **`/vendor/onboard`** — Account step (login/register).
- **`/vendor/onboard/account`** — Create organiser account.
- **`/vendor/onboard/profile`** — Set up organiser profile.
- **`/vendor/onboard/stripe`** — Set up payments (Stripe Connect).
- **`/vendor/onboard/branding`** — Add branding.
- **`/vendor/onboard/first-event`** — Create first event.
- **`/vendor/onboard/boost`** — Promote with Boost.
- **`/vendor/onboard/complete`** — Completion screen.

### Dashboard
- **`/vendor/dashboard`** — Workspace, not onboarding entry.
- Resume panel links to `/vendor/onboard/*` (next step per OnboardingManager).

### Deprecated (Internal / Redirect-Only)
- **`/start`** — Deprecated. Anonymous sees landing; authenticated redirects to `/create-event` or `/vendor/dashboard`.
- **`/onboarding`** — Deprecated. Redirects to `/create-event` or next `/vendor/onboard/*` step.
- **`/onboarding/organiser`**, **`/onboarding/event`**, **`/onboarding/tickets`**, **`/onboarding/payouts`**, **`/onboarding/review`** — Deprecated. All redirect to canonical vendor onboarding.

---

## 2. Routes: Used vs Deprecated

| Route | Path | Status | Behaviour |
|-------|------|--------|-----------|
| `myeventlane_vendor.create_event_gateway` | `/create-event` | **CANONICAL** | Entry point; handles auth/vendor routing |
| `myeventlane_vendor.onboard` | `/vendor/onboard` | **CANONICAL** | Account step |
| `myeventlane_vendor.onboard.account` | `/vendor/onboard/account` | **CANONICAL** | Account creation |
| `myeventlane_vendor.onboard.profile` | `/vendor/onboard/profile` | **CANONICAL** | Profile setup |
| `myeventlane_vendor.onboard.stripe` | `/vendor/onboard/stripe` | **CANONICAL** | Stripe Connect |
| `myeventlane_vendor.onboard.branding` | `/vendor/onboard/branding` | **CANONICAL** | Branding |
| `myeventlane_vendor.onboard.first_event` | `/vendor/onboard/first-event` | **CANONICAL** | First event |
| `myeventlane_vendor.onboard.boost` | `/vendor/onboard/boost` | **CANONICAL** | Boost step |
| `myeventlane_vendor.onboard.complete` | `/vendor/onboard/complete` | **CANONICAL** | Complete |
| `myeventlane_vendor.console.dashboard` | `/vendor/dashboard` | **CANONICAL** | Dashboard workspace |
| `myeventlane_onboarding.start` | `/start` | **DEPRECATED** | Anonymous: landing; Auth: redirect |
| `myeventlane_onboarding.resume` | `/onboarding` | **DEPRECATED** | Redirect only |
| `myeventlane_onboarding.organiser` | `/onboarding/organiser` | **DEPRECATED** | Redirect only |
| `myeventlane_onboarding.event` | `/onboarding/event` | **DEPRECATED** | Redirect only |
| `myeventlane_onboarding.tickets` | `/onboarding/tickets` | **DEPRECATED** | Redirect only |
| `myeventlane_onboarding.payouts` | `/onboarding/payouts` | **DEPRECATED** | Redirect only |
| `myeventlane_onboarding.review` | `/onboarding/review` | **DEPRECATED** | Redirect only |

---

## 3. Files Modified

### 3.1 StartController.php

**Path:** `web/modules/custom/myeventlane_onboarding/src/Controller/StartController.php`

```php
<?php

declare(strict_types=1);

namespace Drupal\myeventlane_onboarding\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_onboarding\Service\OnboardingNavigator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Deprecated entry point: /start.
 *
 * - Anonymous: show onboarding_start (login/register links).
 * - Authenticated without vendor: redirect to /create-event.
 * - Authenticated with vendor: redirect to /vendor/dashboard.
 *
 * /start must NEVER redirect authenticated users to /onboarding (prevents
 * redirect loop). Canonical entry is /create-event.
 */
final class StartController extends ControllerBase {

  /**
   * The onboarding navigator.
   */
  private readonly OnboardingNavigator $navigator;

  /**
   * Constructs the controller.
   */
  public function __construct(OnboardingNavigator $navigator) {
    $this->navigator = $navigator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_onboarding.navigator'),
    );
  }

  /**
   * Start entry point (deprecated).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|array
   *   Redirect or render array.
   */
  public function start(): RedirectResponse|array {
    if ($this->currentUser()->isAnonymous()) {
      $host_url = Url::fromRoute('user.login', [], [
        'query' => ['destination' => '/create-event'],
      ])->toString();
      $attend_url = Url::fromRoute('<front>')->toString();
      return [
        '#theme' => 'onboarding_start',
        '#host_url' => $host_url,
        '#attend_url' => $attend_url,
      ];
    }

    $state = $this->navigator->loadVendorState();
    if ($state === NULL) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.create_event_gateway')->toString()
      );
    }

    return new RedirectResponse(
      Url::fromRoute('myeventlane_vendor.console.dashboard')->toString()
    );
  }

}
```

### 3.2 ResumeController.php

**Path:** `web/modules/custom/myeventlane_onboarding/src/Controller/ResumeController.php`

```php
<?php

declare(strict_types=1);

namespace Drupal\myeventlane_onboarding\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_onboarding\Service\OnboardingNavigator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Deprecated resume controller for /onboarding.
 *
 * Redirects to canonical vendor onboarding routes (/vendor/onboard/*).
 * Never renders onboarding shell. Never redirects to /onboarding/*.
 *
 * - No vendor: redirect to /create-event.
 * - Has vendor: redirect to next vendor onboarding step per OnboardingManager.
 */
final class ResumeController extends ControllerBase {

  /**
   * The onboarding navigator.
   */
  private readonly OnboardingNavigator $navigator;

  /**
   * The onboarding manager.
   */
  private readonly OnboardingManager $onboardingManager;

  /**
   * Constructs the controller.
   */
  public function __construct(
    OnboardingNavigator $navigator,
    OnboardingManager $onboarding_manager,
  ) {
    $this->navigator = $navigator;
    $this->onboardingManager = $onboarding_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_onboarding.navigator'),
      $container->get('myeventlane_onboarding.manager'),
    );
  }

  /**
   * Resume onboarding (redirect only).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to /create-event or next vendor step.
   */
  public function resume(): RedirectResponse {
    $state = $this->navigator->loadVendorState();
    if ($state === NULL) {
      $this->messenger()->addStatus($this->t('Please start by creating an organiser account.'));
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.create_event_gateway')->toString()
      );
    }

    $route = $this->onboardingManager->getNextVendorOnboardRoute($state);
    if ($route === NULL) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.console.dashboard')->toString()
      );
    }

    return new RedirectResponse(
      Url::fromRoute($route, [], ['absolute' => TRUE])->toString()
    );
  }

}
```

### 3.3 AskOrganiserController.php

**Path:** `web/modules/custom/myeventlane_onboarding/src/Controller/AskOrganiserController.php`

```php
<?php

declare(strict_types=1);

namespace Drupal\myeventlane_onboarding\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_onboarding\Service\OnboardingNavigator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Deprecated: /onboarding/organiser.
 *
 * Redirects to canonical vendor onboarding. Never renders.
 */
final class AskOrganiserController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly OnboardingNavigator $navigator,
    private readonly OnboardingManager $onboardingManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_onboarding.navigator'),
      $container->get('myeventlane_onboarding.manager'),
    );
  }

  /**
   * Redirects to vendor onboarding.
   */
  public function organiser(): RedirectResponse {
    $state = $this->navigator->loadVendorState();
    if ($state === NULL) {
      $this->messenger()->addStatus($this->t('Please start by creating an organiser account.'));
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.create_event_gateway')->toString());
    }
    $route = $this->onboardingManager->getNextVendorOnboardRoute($state);
    return new RedirectResponse(
      Url::fromRoute($route ?? 'myeventlane_vendor.console.dashboard', [], ['absolute' => TRUE])->toString()
    );
  }

}
```

### 3.4 AskEventController.php, AskTicketsController.php, AskPayoutsController.php, InviteReviewController.php

Same pattern as AskOrganiserController: inject Navigator + OnboardingManager, redirect to `OnboardingManager::getNextVendorOnboardRoute()` or `/create-event` when no vendor.

### 3.5 myeventlane_onboarding.install

**Path:** `web/modules/custom/myeventlane_onboarding/myeventlane_onboarding.install`

```php
<?php

/**
 * @file
 * Install, update and uninstall functions for MyEventLane Onboarding.
 */

declare(strict_types=1);

use Drupal\user\RoleInterface;

/**
 * Implements hook_install().
 *
 * Does NOT grant access mel onboarding to AUTHENTICATED. That permission
 * is restricted to vendor role (granted in myeventlane_onboarding_update_9001).
 */
function myeventlane_onboarding_install(): void {
  // No permissions granted. access mel onboarding is for vendor role only.
}

/**
 * Restrict access mel onboarding to vendor role.
 *
 * Removes from AUTHENTICATED and grants to vendor role.
 */
function myeventlane_onboarding_update_9001(): void {
  $role_storage = \Drupal::entityTypeManager()->getStorage('user_role');

  $authenticated = $role_storage->load(RoleInterface::AUTHENTICATED_ID);
  if ($authenticated) {
    $authenticated->revokePermission('access mel onboarding');
    $authenticated->save();
  }

  $vendor = $role_storage->load('vendor');
  if ($vendor) {
    $vendor->grantPermission('access mel onboarding');
    $vendor->save();
  }
}
```

### 3.6 AskStageAccess.php

**Path:** `web/modules/custom/myeventlane_onboarding/src/Access/AskStageAccess.php`

```php
<?php

declare(strict_types=1);

namespace Drupal\myeventlane_onboarding\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Access check for deprecated /onboarding/* routes.
 *
 * Requires authenticated + access mel onboarding (vendor role only).
 * Controllers redirect to canonical vendor onboarding. Customers
 * (no vendor role) get 403; vendors get redirected.
 */
final class AskStageAccess {

  /**
   * Checks access for deprecated onboarding routes.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public static function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    if ($account->isAnonymous()) {
      return AccessResult::forbidden('User must be logged in.')
        ->addCacheContexts(['user.roles:anonymous']);
    }
    if (!$account->hasPermission('access mel onboarding')) {
      return AccessResult::forbidden('Vendor onboarding is for organisers only.')
        ->addCacheContexts(['user.permissions']);
    }
    return AccessResult::allowed()->addCacheContexts(['user']);
  }

}
```

### 3.7 InviteStageAccess.php

**Path:** `web/modules/custom/myeventlane_onboarding/src/Access/InviteStageAccess.php`

Same pattern as AskStageAccess: requires authenticated + `access mel onboarding` (vendor role only).

### 3.8 VendorDashboardController.php

**Path:** `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php`

Resume panel uses `OnboardingManager::getNextVendorOnboardRoute()` for the resume URL. Panel hidden when `isCompleted()` or `isInviteReady()`.

---

## 4. Files Left Unused (Not Removed)

| File | Reason |
|------|--------|
| `myeventlane_onboarding/templates/onboarding/onboarding-organiser.html.twig` | Extends onboarding-shell; never rendered (controllers redirect). Left for backward compatibility. |
| `myeventlane_onboarding/templates/onboarding/onboarding-event.html.twig` | Same. |
| `myeventlane_onboarding/templates/onboarding/onboarding-tickets.html.twig` | Same. |
| `myeventlane_onboarding/templates/onboarding/onboarding-payouts.html.twig` | Same. |
| `myeventlane_onboarding/templates/onboarding/onboarding-review.html.twig` | Same. |
| `myeventlane_onboarding/templates/onboarding/onboarding-shell.html.twig` | Parent of above; never rendered. |
| `myeventlane_theme/templates/onboarding/*.html.twig` | Duplicates; never used. |
| `myeventlane_onboarding/src/Form/OrganiserOnboardingForm.php` | Never instantiated; Ask* controllers redirect. |
| `myeventlane_onboarding/src/Form/EventOnboardingForm.php` | Same. |
| `myeventlane_onboarding/src/Form/TicketOnboardingForm.php` | Same. |
| `myeventlane_onboarding/src/Form/PayoutOnboardingForm.php` | Same. |
| `myeventlane_vendor_theme/src/scss/components/_onboarding-flow.scss` | For deprecated /onboarding/* shell; no longer rendered. Comment updated to note legacy. |
| `OnboardingNavigator::getNextStepRoute()` | Returns deprecated route names; not used by controllers for redirects. Only `loadVendorState()` is used. |
| `OnboardingNavigator::getProgressSteps()` | Never called. |
| `OnboardingNavigator::redirectToNextStep()` | Never called. |

---

## 5. Confirmation Checklist

| Requirement | Status |
|-------------|--------|
| No redirect loops remain | Yes. `/start` never redirects to `/onboarding`. Authenticated users go to `/create-event` or `/vendor/dashboard`. |
| Only one onboarding UI exists | Yes. Canonical flow uses `vendor_onboard_step` and vendor theme. Deprecated `/onboarding/*` routes only redirect. |
| Resume setup is consistent | Yes. VendorDashboardController and VendorProfileSettingsForm use `OnboardingManager::getNextVendorOnboardRoute()` / `getNextAction()`. |
| Customers cannot enter vendor onboarding | Yes. `access mel onboarding` is vendor role only. Customers hitting `/onboarding/*` get 403. |

---

## 6. Verification Commands

```bash
ddev drush cr
```

### Manual Test Matrix

| Scenario | Expected |
|----------|----------|
| New user → `/create-event` | Anonymous: login/register with destination `/create-event`. |
| Auth user no vendor → `/create-event` | Redirected to vendor onboarding (account/profile). |
| Vendor incomplete → dashboard resume | Resume link goes to next `/vendor/onboard/*` step. |
| Vendor complete → dashboard | No resume panel. |
| Customer login → never sees onboarding | Customer cannot access `/onboarding/*` (403). |

---

## 7. Legacy / Dead Code Summary

- **OnboardingNavigator** `getNextStepRoute`, `getProgressSteps`, `redirectToNextStep`: Deprecated; return /onboarding/* routes. Controllers use `OnboardingManager::getNextVendorOnboardRoute()`.
- **Onboarding forms** (Organiser, Event, Ticket, Payout): Unused; Ask* controllers redirect.
- **onboarding-shell** and step templates: Unused; no controller renders them.
- **OnboardingAccessKernelTest**: Tests `OnboardingNavigator::getNextStepRoute()` (deprecated logic). Tests still pass but document old behaviour. Consider updating to assert controller redirect destinations.
