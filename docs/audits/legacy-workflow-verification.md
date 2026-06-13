# Legacy Event Workflow Verification — Phase 1B

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** WP-1 (legacy `/build/*` wizard) and WP-2 (legacy Event Studio `edit_*` step routes) only.

**Evidence method:** Static grep across `web/` (PHP, Twig, JS, YAML, tests). No assumptions beyond repository files.

---

## Summary

| Work package | Outcome |
|--------------|---------|
| **WP-2** — Legacy Event Studio `edit_*` routes | **Retired as form routes.** Six paths preserved as **302 redirect aliases** into workspace sections. Orphan step forms/controllers removed; workspace form classes retained. |
| **WP-1** — Legacy `/build/*` wizard | **Retained (staff-only).** Vendors redirected to Event Studio by `VendorLegacyWizardRedirectSubscriber`. Full removal blocked: staff with `administer nodes` still reach wizard forms. |

---

## A. Legacy Event Studio edit routes (`myeventlane_event_studio.edit_*`)

| Route | Controller/Form (pre-1B) | Redirect only (vendors)? | References found | Action |
|-------|---------------------------|---------------------------|------------------|--------|
| `myeventlane_event_studio.edit_basic` | `EventStudioBasicForm` | Yes — subscriber + now route redirect | Routing YAML only (no Twig/JS/menu links) | **Remove** form route → **Alias** redirect to `workspace_information` |
| `myeventlane_event_studio.edit_datetime` | `EventStudioDateForm` | Yes | Routing YAML only | **Remove** form route → **Alias** redirect to `workspace_information` |
| `myeventlane_event_studio.edit_tickets` | `EventStudioTicketsForm` | Yes (route); form **embedded in workspace** | `EventStudioSectionRenderer::buildTicketsStack()` embeds `EventStudioTicketsForm` | **Remove** route form binding → **Alias** redirect; **Keep** form class |
| `myeventlane_event_studio.edit_description` | `EventStudioDescriptionForm` | Yes (route); form **extended by workspace** | `EventContentForm extends EventStudioDescriptionForm`; `ContentSection` plugin | **Remove** route form binding → **Alias** redirect; **Keep** form class |
| `myeventlane_event_studio.edit_preview` | `EventStudioPreviewController::build` | Yes | Preview twig linked to other `edit_*` routes (removed with controller) | **Remove** controller + template → **Alias** redirect to `workspace_content` |
| `myeventlane_event_studio.edit_publish` | `EventStudioPublishForm` | Yes (route); form **extended by workspace** | `EventSettingsForm extends EventStudioPublishForm`; two PHP CTAs updated to `workspace_settings` in 1B | **Remove** route form binding → **Alias** redirect; **Keep** form class |

### WP-2 verification checklist

| Check | Result | Evidence |
|-------|--------|----------|
| 1. No user-facing links to `edit_*` routes | **Pass (after 1B)** | Pre-1B: `EventStudioEventExtrasForm.php`, `EventStudioExtrasConfiguredSummaryBuilder.php`, `mel-event-studio-wizard-preview.html.twig`. All updated or removed in 1B. Post-grep: zero `fromRoute('myeventlane_event_studio.edit_*')` in `web/`. |
| 2. No controllers depend on legacy step routes | **Pass** | `EventStudioController::workspace` uses section plugins + `EventStudioSectionRenderer`, not legacy routes. |
| 3. No forms depend on legacy routes (except route-only step forms) | **Pass with retention** | Workspace retains `EventStudioTicketsForm`, `EventStudioDescriptionForm` (via `EventContentForm`), `EventStudioPublishForm` (via `EventSettingsForm`). Route-only classes `EventStudioBasicForm`, `EventStudioDateForm` removed. |
| 4. Workspace does not embed legacy routes | **Pass** | Workspace embeds form **classes**, not legacy route URLs. `VendorEventWorkspaceViewModelBuilder` uses `workspace_tickets`, not `edit_tickets` route. |

### WP-2 files removed in Phase 1B

- `src/Form/EventStudioBasicForm.php`
- `src/Form/EventStudioDateForm.php`
- `src/Controller/EventStudioPreviewController.php`
- `templates/mel-event-studio-wizard-preview.html.twig`

### WP-2 redirect mapping (post-1B)

| Legacy path | Target workspace route |
|-------------|------------------------|
| `/vendor/events/{node}/edit/basic` | `myeventlane_event_studio.workspace_information` |
| `/vendor/events/{node}/edit/datetime` | `myeventlane_event_studio.workspace_information` |
| `/vendor/events/{node}/edit/tickets` | `myeventlane_event_studio.workspace_tickets` |
| `/vendor/events/{node}/edit/description` | `myeventlane_event_studio.workspace_content` |
| `/vendor/events/{node}/edit/preview` | `myeventlane_event_studio.workspace_content` |
| `/vendor/events/{node}/edit/publish` | `myeventlane_event_studio.workspace_settings` |

**Implementation:** `EventStudioController::{redirectLegacyEditToInformation,redirectLegacyEditToTickets,redirectLegacyEditToContent,redirectLegacyEditToSettings}` in `myeventlane_event_studio.routing.yml`.

---

## B. Legacy wizard routes (`myeventlane_event.wizard.*`)

| Route | Controller/Form | Redirect only (vendors)? | References found | Action |
|-------|-----------------|--------------------------|------------------|--------|
| `myeventlane_event.wizard.basics` | `EventWizardBasicsForm` | Yes — vendor redirect | Wizard internal: `EventWizardBaseForm`, theme sidebar (`myeventlane_vendor_theme.theme`), `VendorLegacyWizardRedirectSubscriber` | **Keep** (staff-only) |
| `myeventlane_event.wizard.when_where` | `EventWizardWhenWhereForm` | Yes | Same + `event-wizard-tickets.html.twig` | **Keep** (staff-only) |
| `myeventlane_event.wizard.tickets` | `EventWizardTicketsForm` | Yes | Same + `EventWizardReviewForm`, `WizardReviewSummaryBuilder` | **Keep** (staff-only) |
| `myeventlane_event.wizard.details` | `EventWizardDetailsForm` | Yes | Same + `event-wizard-review.html.twig` | **Keep** (staff-only) |
| `myeventlane_event.wizard.review` | `EventWizardReviewForm` | Yes | Same + `VendorEventWizardController`, twig templates | **Keep** (staff-only) |
| `myeventlane_event.wizard.publish` | `EventWizardPublishForm` | Yes | Same + `wizard_publish_validator` service | **Keep** (staff-only) |
| `myeventlane_event.wizard.success` | `VendorEventWizardController::success` | Yes | `EventWizardPublishForm` redirect target | **Keep** (staff-only) |

### WP-1 classification

| Question | Answer | Evidence |
|----------|--------|----------|
| Vendor routes? | **No (for rendering)** | `VendorLegacyWizardRedirectSubscriber.php:167-172` — users without `administer nodes` (and not uid 1) receive 302 to Event Studio before forms render. |
| Staff-only routes? | **Yes** | `myeventlane_event.routing.yml:1-2` header comment; subscriber bypass for `administer nodes`. Staff/support can still complete `/vendor/events/{event}/build/*` wizard steps. |
| Unused routes? | **No** | Active form classes, services (`wizard_review_summary_builder`, `wizard_publish_validator`), vendor theme wizard page template, help context resolver. |

### WP-1 decision

**Do not remove** wizard routes or forms in Phase 1B. Vendors already cannot reach wizard UI (redirect). Removing wizard would break staff/support workflows.

**Recommended follow-up (future phase):** Staff-gate wizard routes at the routing layer (`administer nodes` requirement) and/or migrate support tooling to Event Studio, then decommission wizard forms.

---

## C. Subscriber changes (Phase 1B)

`VendorLegacyWizardRedirectSubscriber`:

- Removed `STUDIO_LEGACY_STEP_ROUTES` — legacy `edit_*` paths now redirect via dedicated route controllers (no double-redirect).
- Retained `WIZARD_STEP_ROUTES` — vendor `/build/*` bookmarks still redirect to Studio sections.

---

## D. Related routes explicitly out of scope (Phase 1B)

Not modified: Event Studio workspace routes, publish POST, autosave, vendor ownership, Commerce, tickets, RSVP, messaging, analytics.

---

## E. Validation commands (Phase 1B)

```bash
git diff --stat
git diff
grep -R "edit_basic" web/
grep -R "edit_datetime" web/
grep -R "edit_tickets" web/
grep -R "myeventlane_event.wizard" web/
ddev drush cr
npm run mel:lint
npm run mel:build
```
