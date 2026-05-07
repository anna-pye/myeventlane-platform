# MEL governance slice: customer hub continuity + vendor onboarding dedupe

Date: 2026-05-07  
Scope: governance adoption completion (no new orchestration layer).

---

## 1. Customer hub governance continuity

**Finding:** `/my-account` and `/my-past-events` were intentionally excluded from `page__account`; they embedded a full duplicate shell (sidebar + `<main>`). `mel_workflow_region_primary` / `mel_workflow_region_progress` only exist in `page--account.html.twig`, while `page.html.twig` omits them — so the dashboard never showed governed workflow regions despite `SurfaceNegotiator` populating variables on every page.

**Change:** Register `myeventlane_account.dashboard` and `myeventlane_account.past_events` for the same `page__account` shell as tickets, orders, My Events, and settings. Dashboard/past-events Twig templates are body-only (sections/cards), preserving a single outer `<main>` from `page.html.twig` and one account layout from `page--account.html.twig`.

---

## 2. Workflow region unification

**Source of truth:** Still `myeventlane_surface` → `SurfaceNegotiator::attachPageMetadata()` → `MelWorkflowManager::buildPageAttachments()` (MELWorkflowSystem). No duplicate region builders.

**Rendering:** One pair of slots in `page--account.html.twig` (`mel_workflow_region_primary`, `mel_workflow_region_progress`) for all customer hub routes using that suggestion.

---

## 3. Vendor onboarding dedupe

| Location | Before | After |
|----------|--------|--------|
| `VendorDashboardController` | Panel skipped when `MelWorkflowManager::willRenderPrimaryWorkflowRegion()` | Unchanged (reference contract) |
| `VendorSettingsForm` (MEL Vendor Settings) | Always showed `myeventlane_vendor_onboarding_panel` when incomplete | Same guard as dashboard |
| `CreateEventGatewayController` | Rendered onboarding panel into messenger before redirect to Event Studio | Removed; keeps plain warning + Event Studio’s governed primary (`myeventlane_event_studio.*` workflow activation) |

**Assumption:** Redirect targets always use Event Studio routes where vendor workflow primary is authoritative; gateway runs on a non-vendor surface route, so `willRenderPrimaryWorkflowRegion()` is not used there.

---

## 4. Continuity consolidation

Customer continuity (next steps, checklist, progression) remains from MEL workflow + experience payloads attached in `SurfaceNegotiator`; no new dashboard PHP orchestration. Vendor duplicate CTAs reduced by suppressing redundant panel/messenger renderings when workflow primary already covers the same delegate.

---

## 5. Accessibility

- Single `#main-content` / `<main role="main">` from parent `page.html.twig` (dashboard body no longer nests `<main>`).
- One workflow primary/progress block in the account shell; landmark helpers in `MelWorkflowAccessibilityHelper` still apply to region render arrays.
- Past Events: page H1 comes from shell (`account_heading`); list uses `<h2>` on cards as before.

---

## 6. Security / privacy

- No change to access checks, vendor isolation, or observability gating.
- Create-event gateway no longer injects HTML onboarding panel into status messages (reduces risk of confusing status content; no new data exposure).

---

## 7. File-by-file summary

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_account/myeventlane_account.module` | Add dashboard + past_events to `page__account` suggestions; preprocess: `DisplayNameResolver`, `account_show_header_logo`, `account_heading` |
| `web/themes/custom/myeventlane_theme/templates/page--account.html.twig` | Optional logo row (dashboard); optional custom H1 (past events) |
| `myeventlane_account/templates/myeventlane-my-account-dashboard.html.twig` | Body-only sections (remove duplicate nav/header/layout) |
| `myeventlane_account/templates/myeventlane-my-account-past-events.html.twig` | Body-only list |
| `myeventlane_vendor/.../CreateEventGatewayController.php` | Drop renderer + onboarding panel messenger; docblock tweak |
| `myeventlane_vendor_settings/.../VendorSettingsForm.php` | Optional `MelWorkflowManager`; dedupe panel |
| `myeventlane_vendor_settings.info.yml` | Declare `myeventlane_surface` dependency |
| `myeventlane_account/tests/.../CustomerHubPageAccountKernelTest.php` | Suggestion + negotiator variable assertions |
| `web/phpunit-governance.xml` | Register new kernel test |

---

## Validation (this slice)

- `php -l` on touched PHP paths: OK  
- `composer validate`: OK  
- `SIMPLETEST_DB=sqlite://localhost/:memory:` + PHPUnit `CustomerHubPageAccountKernelTest.php`: OK  
- `npm run mel:lint`: OK  
- `npm run mel:build`: OK  
- `ddev drush cr`: OK  

**Note:** Full `phpunit-governance.xml` still reports two pre-existing Kernel errors (`commerce_checkout.review` route missing in minimal graph); not introduced by this slice.

---

## Residual risk

- Past Events + Dashboard header wording now centralized in shell; confirm UX with stakeholders (logo shown only on dashboard).
- Org settings onboarding panel disappears when workflow primary renders; ensure `/vendor/settings` always exposes progression via workflow/checklist UI (parity with dashboard).
- Messenger no longer echoes onboarding HTML on `/create-event`; users rely on warning string + Event Studio governed region.
