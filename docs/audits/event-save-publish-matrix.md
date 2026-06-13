# Event Save / Publish Ownership Matrix — Phase 1C

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** All repository-confirmed save and publish code paths for vendor event authoring and management.

**Legend:** **Canonical** = intended primary path for vendors per Phase 1A/1B ownership map. **Parallel** = still implemented; may redirect or serve staff only.

---

## Summary

| Path type | Canonical for vendors | Evidence |
|-----------|----------------------|----------|
| Workspace form submit | **Yes** | `EventStudioBaseForm` → `EventStudioSaveService::save()` |
| Workspace autosave POST | **Yes** | `EventStudioAutosaveController` → draft store + optional `saveService->save()` |
| Workspace publish POST | **Yes** | `EventStudioPublishController` → readiness + publish state |
| Vendor Studio JSON POST | **No** | No UI hooks in current `studio.html.twig`; vendors redirected away from shell |
| Vendor console `event_publish` GET | **Alias** | Redirect to `myeventlane_event_studio.edit` |
| Legacy wizard publish | **Staff-only** | `EventWizardPublishForm`; vendors redirected |

---

## Matrix

| Function | Route | HTTP | Controller / form | Service / persistence | Canonical? |
|----------|-------|------|-------------------|----------------------|------------|
| **Autosave (draft)** | `myeventlane_event_studio.autosave` | POST | `EventStudioAutosaveController::handle` | `EventStudioAutosaveService::storeDraft()`; may call `EventStudioSaveService::save()` when applying | **Yes** |
| **Publish event** | `myeventlane_event_studio.publish` | POST | `EventStudioPublishController::publish` | `EventStudioSaveService`; `EventReadinessService` / `EventReadinessFacade`; clears autosave drafts | **Yes** |
| **Save event (workspace form)** | `myeventlane_event_studio.workspace_*` (per section) | POST (Form API) | Section forms extending `EventStudioBaseForm` | `EventStudioSaveService::save()` | **Yes** |
| **Save information** | `workspace_information` | POST | `EventInformationForm` | `EventStudioSaveService::save()` via base form | **Yes** |
| **Save branding** | `workspace_branding` | POST | `EventBrandingForm` | `EventStudioSaveService::save()` | **Yes** |
| **Save content** | `workspace_content` | POST | `EventContentForm` | `EventStudioSaveService::save()` | **Yes** |
| **Save tickets** | `workspace_tickets` | POST | `EventStudioTicketsForm`, `EventStudioOperationalTicketsForm` | `EventStudioSaveService::save()` + operational Commerce managers | **Yes** |
| **Save questions** | `workspace_questions` | POST | `EventCheckoutQuestionsForm` | `EventStudioQuestionTemplateManager` / save service | **Yes** |
| **Save extras** | `workspace_extras` | POST | `EventStudioEventExtrasForm` | Commerce product/variation writes + extras builders | **Yes** |
| **Save fulfilment** | `workspace_fulfilment` | POST | `EventStudioOperationalCapabilityForm` | Operational capability + autosave draft clear | **Yes** |
| **Save messaging** | `workspace_messaging` | POST | `MessagingForm` | Form-specific save (extends base patterns) | **Yes** |
| **Save settings** | `workspace_settings` | POST | `EventSettingsForm` (extends `EventStudioPublishForm`) | `EventStudioSaveService::save()` + publish readiness UI | **Yes** |
| **Save merchandise** | `workspace_merchandise` (alias → extras) | POST | `EventStudioProductisationForm` | Productisation manager | **Yes** |
| **Generic Vendor Studio save** | `myeventlane_vendor.studio_event_save` | POST | `VendorStudioController::saveEvent` | Direct `$event->set()` on scalar fields + revision | **Parallel / UNUSED UI** |
| **Save overview (JSON)** | `myeventlane_vendor.console.studio.event_overview_save` | POST | `VendorStudioController::saveOverview` | `EventStudioSaveService::patchOverviewBasics()` | **Parallel / UNUSED UI** |
| **Save tickets (JSON config)** | `myeventlane_vendor.console.studio.event_tickets_save` | POST | `VendorStudioController::saveTickets` | Writes `field_ticket_config` JSON on node | **Parallel / UNUSED UI** |
| **Save attendees (JSON)** | `myeventlane_vendor.console.studio.event_attendees_save` | POST | `VendorStudioController::saveAttendees` | Paragraph attendee questions | **Parallel / UNUSED UI** |
| **Save promotion (JSON)** | `myeventlane_vendor.console.studio.event_promotion_save` | POST | `VendorStudioController::savePromotion` | Writes `field_promo_config` JSON | **Parallel / UNUSED UI** |
| **Save settings (JSON)** | `myeventlane_vendor.console.studio.event_settings_save` | POST | `VendorStudioController::saveSettings` | `field_event_capacity`, visibility, passcode hash | **Parallel / UNUSED UI** |
| **Publish (JSON review submit)** | `myeventlane_vendor.console.studio.event_publish` | POST | `VendorStudioController::publishEvent` | Sets `moderation_state=review` only | **Parallel / UNUSED UI** |
| **Submit review (form POST)** | `myeventlane_vendor.console.studio.submit_review` | POST | `VendorStudioController::submitReview` | Sets `moderation_state=review`; redirect | **Parallel / UNUSED UI** |
| **Vendor console publish page** | `myeventlane_vendor.console.event_publish` | GET | `EventWorkspaceController::publish` | Redirect → `myeventlane_event_studio.edit` | **Alias** |
| **Wizard publish** | `myeventlane_event.wizard.publish` | POST | `EventWizardPublishForm` | Wizard publish validator + node publish | **Staff-only legacy** |
| **Legacy edit publish form** | `myeventlane_event_studio.edit_publish` | GET | `EventStudioController::redirectLegacyEditToSettings` | Redirect only (Phase 1B) | **Alias** |
| **Unpublish** | `myeventlane_vendor.console.event_unpublish` | POST | `EventUnpublishForm` | Node unpublish workflow | **Canonical (unpublish)** — not authoring save |
| **Governance refresh** | `myeventlane_event_studio.governance_refresh` | POST | `EventStudioGovernanceRefreshController` | Readiness/governance components | **Support** (not entity save) |
| **AI assist** | `myeventlane_event_studio.ai_assist` | POST | `EventStudioAiController` | AI proxy; no direct node save | **Support** |

---

## Client-side callers

| Client | Endpoint | Trigger |
|--------|----------|---------|
| `mel-event-studio-shell.js` | `drupalSettings.myeventlaneEventStudio.autosaveUrl` | Debounced input on writable workspace forms |
| `mel-event-studio-shell.js` | `drupalSettings.myeventlaneEventStudio.publishUrl` | `[data-mel-publish-action]` click |
| `mel-event-studio.js` | Same autosave URL (legacy bundle paths) | Form change handlers |
| `vendor-studio.js` | `/vendor/studio/event/{id}/*` | Requires `[data-mel-studio]` + save/tab UI (**absent** in current template) |

---

## EventStudioSaveService (shared core)

**File:** `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php`

| Method | Called from | Purpose |
|--------|-------------|---------|
| `save()` | `EventStudioBaseForm`, `EventStudioForm`, autosave apply | Full MEL payload merge + revision |
| `patchOverviewBasics()` | `VendorStudioController::saveOverview` | Title, summary, type patch |

---

## Publish flow comparison

| Aspect | `EventStudioPublishController` | `VendorStudioController::publishEvent` |
|--------|-------------------------------|----------------------------------------|
| Readiness checks | Yes (`EventReadinessFacade`) | No |
| Stripe / legal gates | Yes (via readiness bundle) | No |
| Autosave stale check | Yes | No |
| Moderation | Supports publish + unpublish actions | Sets `moderation_state=review` only |
| Response | JSON for shell AJAX | JSON |
| Vendor UI | **Active** (topbar publish button) | **None** in current staff template |

---

## Phase 1D implications

1. **Do not remove** `EventStudioSaveService` or publish controller — canonical.
2. **Candidate removal (WP-4):** Vendor Studio JSON POST routes after confirming no external/staff dependency on JSON shell.
3. **WP-5:** Vendor workspace routes that only redirect can become explicit link targets to Studio; routes with richer UI (orders, analytics) need parity before retirement.
