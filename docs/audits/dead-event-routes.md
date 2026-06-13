# Dead Event Route Candidates — Phase 1A (WP-7)

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Method:** Routes with **no menu links**, and **no references** in Twig, JS, or PHP outside their own routing file, controller, redirect subscriber, or test fixtures. "Dead" here means **no user-facing navigation or external caller found** — not proof of zero HTTP traffic.

**No routes were removed in Phase 1A.**

---

## 1. High-confidence dead / redirect-only candidates

| Route | Evidence | Candidate Action |
|-------|----------|------------------|
| `myeventlane_event_studio.edit_basic` | Only `VendorLegacyWizardRedirectSubscriber.php` + routing YAML; no Twig/JS/menu links | **Remove Later** (after redirect-only period) |
| `myeventlane_event_studio.edit_datetime` | Same as above | **Remove Later** |
| `myeventlane_event_studio.edit_tickets` | Same as above | **Remove Later** |
| `myeventlane_event_studio.edit_description` | Same as above | **Remove Later** |
| `myeventlane_event_studio.edit_preview` | Same as above | **Remove Later** |
| `myeventlane_event_studio.edit_publish` | Same as above | **Remove Later** |
| `myeventlane_vendor.manage_event.promote` | `ManageEventPlaceholderController` stub; listed in `ManageEventNavigation.php` and `ManageEventPlaceholderNoIndexSubscriber.php` only; **not linked from contextual help** per routing comment | **Remove Later** |
| `myeventlane_vendor.manage_event.payments` | Placeholder stub; one Twig link in legacy `event/tickets.html.twig` nav to non-functional page | **Redirect** → Studio section or **Remove Later** |
| `myeventlane_vendor.manage_event.comms` | Placeholder stub; nav service only | **Remove Later** |
| `myeventlane_vendor.manage_event.advanced` | Placeholder stub; nav service only | **Remove Later** |
| `myeventlane_vendor.console.events_add` | Only `VendorEventCreateController` (redirect) + launch allowlist; no menu/Twig links | **Redirect** → gateway or **Keep** as bookmark alias |

---

## 2. Legacy wizard routes (vendor redirect-only)

| Route | Evidence | Candidate Action |
|-------|----------|------------------|
| `myeventlane_event.wizard.basics` | Vendors redirected by `VendorLegacyWizardRedirectSubscriber`; forms/templates remain for staff path | **Keep** until staff-only gate confirmed; then **Remove Later** |
| `myeventlane_event.wizard.when_where` | Same | **Keep** / **Remove Later** |
| `myeventlane_event.wizard.tickets` | Same + wizard twig (unreachable for vendors) | **Keep** / **Remove Later** |
| `myeventlane_event.wizard.details` | Same | **Keep** / **Remove Later** |
| `myeventlane_event.wizard.review` | Same | **Keep** / **Remove Later** |
| `myeventlane_event.wizard.publish` | Same | **Keep** / **Remove Later** |
| `myeventlane_event.wizard.success` | Same | **Keep** / **Remove Later** |

**Evidence:** `myeventlane_event.routing.yml:1-3` (comment: vendors redirected to Studio); `VendorLegacyWizardRedirectSubscriber.php:31-37`.

---

## 3. Alias routes (keep for bookmarks)

| Route | Evidence | Candidate Action |
|-------|----------|------------------|
| `myeventlane_vendor.manage_event.edit` | Redirect-only; subscriber reference only | **Keep** (alias) |
| `myeventlane_vendor.console.studio` | Redirect in `VendorStudioController::studio` | **Keep** (alias) → **Remove Later** after traffic audit |
| `myeventlane_vendor.console.event_editor` | Redirect in `VendorStudioController::eventEditor` | **Keep** (alias) |
| `myeventlane_vendor.manage_event.tickets` | Redirect to canonical tickets route | **Keep** (alias) |
| `myeventlane_event_studio.workspace_promotions` | Documented legacy bookmark → messaging | **Keep** (alias) |
| `myeventlane_event_studio.workspace_{merchandise,addons,add_ons}` | Redirect → extras | **Keep** (alias) |

---

## 4. Parallel surfaces (active — not dead)

These routes **duplicate** Event Studio but **have live references** and must not be classified as dead in this phase.

| Route | Evidence of use | Candidate Action |
|-------|-----------------|------------------|
| `myeventlane_vendor.console.studio.event_*` (JSON save API) | `VendorStudioController.php` builds endpoint URLs | **Redirect** / retire write API (WP-4) |
| `myeventlane_vendor.console.event_workspace` | `VendorEventTabsService.php`, workspace controllers | **Redirect** → Studio workspace (WP-5) |
| `myeventlane_vendor.console.event_overview` | Redirect subscriber + overview controller | **Redirect** → Studio |
| `myeventlane_vendor.manage_event.{design,content,checkout_questions,series}` | Active controllers + twig/dashboard links | **Redirect** → Studio sections (WP-5) |

---

## 5. Canonical routes (never candidates for removal)

| Route | Role |
|-------|------|
| `myeventlane_vendor.create_event_gateway` | Public create entry |
| `myeventlane_event_studio.create` | Authoring create (post-gateway) |
| `myeventlane_event_studio.edit` | Authoring edit entry |
| `myeventlane_event_studio.workspace*` | Unified management |
| `myeventlane_event_studio.publish` | Canonical publish POST |
| `myeventlane_event.duplicate` | Distinct rebook feature |

---

## 6. Verification commands (before any removal in a future phase)

```bash
# Replace ROUTE_NAME before running in a future phase:
ddev drush php:eval "print_r(array_keys(\Drupal::service('router.route_provider')->getRoutesByPattern('')));"
rg -l "ROUTE_NAME" web/modules/custom web/themes/custom --glob '*.{php,twig,yml,js}'
rg -l "/vendor/events/add" web/modules/custom web/themes/custom
```

**Residual risk:** Bookmarked URLs, external marketing links, and email templates may hit alias/legacy paths not captured in PHP/Twig grep (e.g. hard-coded `https://myeventlane.com.au/create-event` in email template).
