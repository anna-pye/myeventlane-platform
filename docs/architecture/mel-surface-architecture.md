# MEL surface architecture (foundation)

This document describes the **canonical governance layer** introduced with `myeventlane_surface`. Drupal routes, blocks, and entity forms remain infrastructure; MEL surfaces describe **product ownership** of layout, navigation, and presentation contracts.

## 1. Surface architecture map

| Surface | Role | Primary resolver signals |
| --- | --- | --- |
| **PublicSurface** | Discovery + marketing + content canonical pages | Default when no other surface matches |
| **AuthSurface** | Login, registration, password reset | Route names `user.login`, `user.register`, `user.pass`, `user.reset*`, path prefixes `/user/login`, `/user/register`, `/user/password`, `/user/reset` |
| **CustomerShell** | Tickets, RSVPs bundle, account IA | `myeventlane_account.*`, `/my-*` prefixes, dashboard + ticket routes |
| **VendorShell** | Vendor console | Path prefix `/vendor` |
| **StaffSurface** | Administration | `_admin_route` option or `/admin` prefix |

Implementation: `MelSurfaceId` (`web/modules/custom/myeventlane_core/src/MelSurfaceId.php`), `SurfaceRegistry`, `SurfaceResolver`, `SurfaceManager`.

## 2. Route ownership map

| Owner | Canonical paths / routes (representative) |
| --- | --- |
| AuthSurface | Core user auth routes (Gin Login unchanged; mechanics stay/contrib). |
| CustomerShell | `/my-account`, `/my-settings/{user}` (bare `/my-settings` redirects), `/my-past-events`, `/my-tickets`, `/my-events`, `/my-profile/download-rsvps.ics`, related `myeventlane_account`, `myeventlane_checkout_flow`, `myeventlane_dashboard`, `myeventlane_rsvp.user_list`. |
| VendorShell | `/vendor/*` operational console (see individual module routing). |
| StaffSurface | `/admin/*`, `_admin_route` pages (Gin). |

## 3. Shell ownership map

| Shell artifact | Location | Notes |
| --- | --- | --- |
| Public / default page chrome | `myeventlane_theme/templates/page.html.twig` | Unchanged baseline. |
| Customer sidebar shell | `page--account.html.twig` + account preprocess | `/my-settings/{user}` uses this shell via `myeventlane_account_theme_suggestions_page_alter`. |
| Auth minimal chrome | `SurfaceNegotiator` + `site-header.html.twig` (`minimal_discovery`) | Hides discovery navigation, cart injection, and manual header blocks on auth routes. |
| Shell partials (contracts) | `myeventlane_surface/templates/mel-surface-*.html.twig` | Documentation / includes for shared wrappers. |

`SurfaceNegotiator` publishes `mel_surface`, `mel_surface_auth_minimal`, and full `SurfaceDefinition` to page variables; `data-mel-surface` is added on `<html>`.

## 4. Canonical form architecture

| Piece | Responsibility |
| --- | --- |
| **Drupal Form API** | Validation, CSRF, field widgets, permissions — unchanged. |
| **`mel_surface_customer_profile_settings` theme** | Customer-facing profile/settings chrome wrapping the core user entity form. |
| **`mel-form-system` / `mel-surface-customer-settings`** SCSS | Shared spacing, card radius, readable defaults (`components/_mel-surface-forms.scss`). |
| **`myeventlane_account_form_user_form_alter`** | Field-level UX policy (existing). |

Future expansions should attach here rather than per-route Twig.

## 5. Component governance map

Canonical reusable UI contracts live in **MELComponentSystem** (`MelComponentRegistry`, `MelComponentManager`, `MelComponentPreprocess`, `MelNavigationManager`, `templates/components/*`). See [mel-component-system.md](mel-component-system.md).

| Component area | Governance |
| --- | --- |
| Site header | `is_checkout` ∪ `is_auth_minimal` drives “minimal discovery” chrome. |
| Global blocks | `SurfaceBlockGovernor` denies selected plugins on **AuthSurface** and **checkout-sensitive** paths (`commerce_checkout*` routes, `/cart`, `/checkout`). |
| Customer shell sidebar | `mel_shell_nav` + `MelNavigationManager` contract (`mel_account_shell_nav` on account layout pages). |
| Cards / status / CTA | Prefer `#theme` hooks (`mel_card`, `mel_empty_state`, `mel_alert`, …) over one-off markup. |

## 6. Redirect map

| From | To | Guard |
| --- | --- | --- |
| `entity.user.canonical` (self) | `myeventlane_account.dashboard` | Authenticated + UID match |
| `entity.user.edit_form` (self) | `myeventlane_account.settings` (`/my-settings/{user}`) | Authenticated + UID match; bare `/my-settings` redirects |

Staff editing another user **unchanged**. `MelKernelAuthRouteSilencer` now also bypasses subscribers for `/user/register` alongside other auth paths.

Subscriber: `SurfaceRouteSubscriber` (priority **30**, replaces removed `UserProfileRedirectSubscriber`).

## 7. Security review

- **Access**: Redirects only fire for the **current user’s own** UID; other profiles and admin workflows stay on core routes.
- **CSRF / Form API**: Profile settings remain standard entity forms with core protections.
- **Vendor isolation**: Not altered; vendor routes still resolved to VendorShell for metadata only.
- **Checkout**: Block governor narrows promotional plugins; commerce permissions unchanged.

## 8. Duplication cleanup report

| Item | Action |
| --- | --- |
| `UserProfileRedirectSubscriber` | **Removed**; behaviour folded into `SurfaceRouteSubscriber`. |
| Customer `/my-settings` | **No longer** redirects to naked `/user/{uid}/edit`; renders embedded form in MEL shell. |

Follow-up (not done in this slice): consolidate other scattered route/header special cases into resolver-driven policies where safe.

## 9. File-by-file summary

| File | Purpose |
| --- | --- |
| `web/modules/custom/myeventlane_surface/*` | New module: registry, resolver, negotiator, access helper, block governor, route subscriber, shell Twig contracts. |
| `config/sync/core.extension.yml` | Enables `myeventlane_surface`. |
| `myeventlane_account.info.yml` | Declares dependency on surface module. |
| `myeventlane_account.services.yml` | Drops obsolete profile redirect service. |
| `myeventlane_account.module` | Adds `page__account` suggestion for settings route. |
| `MyAccountController.php` | Embeds user entity form via `mel_surface_customer_profile_settings`. |
| `MelKernelAuthRouteSilencer.php` | Registers `/user/register` bypass for auth-route subscribers. |
| `myeventlane_theme.theme` | Passes `is_auth_minimal`, skips redundant header block injection, caches header by route/user. |
| `templates/components/site-header/site-header.html.twig` | Uses `minimal_discovery` flag. |
| `components/site-header/site-header.twig` | Parity copy for Storybook/component consumers. |
| `src/scss/components/_mel-surface-forms.scss` | Canonical customer form chrome. |
| `src/scss/main.scss` | Imports surface form partial. |
| `MelPostLoginSessionRedirectSubscriber.php` | Comment update (ordering reference). |

## Validation commands

```bash
php -l web/modules/custom/myeventlane_surface/**/*.php
npm run mel:lint
npm run mel:build
ddev drush cr
```

Manual smoke tests: login/register/password reset; `/my-account`, `/my-settings`, `/my-tickets`; vendor dashboard; Gin admin; confirm no redirect loops for staff editing foreign users.

---

**Residual risk:** Block denylist plugin IDs may need tuning as new global blocks ship; resolver lists must gain explicit entries when customer URLs move off `/my-*` prefixes.
