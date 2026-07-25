# Vendor Workspace v2 — Publishing Runtime Discovery

**Status:** Product discovery (Sprint 3A) — documentation only  
**Date:** 2026-07-25  
**Branch:** `feature/vendor-workspace-foundation`  
**Scope:** Complete Publishing architecture for Event Workspace / Event Studio  
**Constraint:** No runtime behaviour changes. Mission Control is FROZEN.

---

## 1. Repository safety (Stage 1)

| Check | Result |
| --- | --- |
| Branch | `feature/vendor-workspace-foundation` (tracks `origin/feature/vendor-workspace-foundation`) |
| `git status` | Clean working tree |
| `git diff` | Empty |
| `git fetch origin` | OK — branch up to date |
| DDEV | OK — Drupal 11.4.4 · PHP 8.3 · MariaDB 10.11 |
| `ddev drush status` | Bootstrap successful |
| `ddev drush config:status` | **Known local drift** (Klaro apps, payment gateways, some field/config) — pre-existing; not introduced by this sprint |
| `composer validate` | `./composer.json is valid` |

**Assumption hold:** Tree clean; DDEV healthy; composer valid. Config drift noted; does not block design discovery. No stash/clean performed.

---

## 2. Architecture map

```text
┌─ Organiser UI (vendor theme + Event Studio shell) ─────────────────────────┐
│  Hero / Topbar  ── authoritative primary CTA (Continue setup | Publish | Share)
│  Mission Control (FROZEN) ── checklist / next-action / quality (read-only reuse)
│  Section: Publishing ── Launch Centre (target) / publishing hub (today)
└────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─ Routes ───────────────────────────────────────────────────────────────────┐
│  GET  /vendor/events/{node}/studio/publishing
│       → myeventlane_event_studio.workspace_publishing
│       → EventStudioController::workspace (section=publishing)
│  POST /vendor/events/{node}/studio/publish
│       → myeventlane_event_studio.publish
│       → EventStudioPublishController::publish (AJAX JSON)
└────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─ Enforcement ──────────────────────────────────────────────────────────────┐
│  EventStudioPublishController
│    → dirty / stale / autosave draft guards (409)
│    → EventStudioSaveService::setNodePublishedState
│         → PublishEligibilityEvaluator::evaluate   ← SOURCE OF ENFORCEMENT
│              1. VendorPublishRequirementsGate
│              2. PaidPublishStripeGate (paid|both)
│              3. EventReadinessService::evaluate
│         → node status + moderation_state sync + revision save
└────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─ Presentation / confidence ────────────────────────────────────────────────┐
│  EventReadinessFacade → publish + promotion bundle
│  EventWorkspaceOverviewBuilder → Mission Control + resolveAuthoritativePrimaryCta
│  EventStudioWorkspacePresentation → AJAX readiness / degraded MC payload
│  EventStudioPreprocess::buildPublishSuccessHandoff → celebrate / share
│  mel-event-studio-shell.js → fetch publish, refresh chrome + MC + feedback
└────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. What owns publishing?

| Concern | Owner | Evidence |
| --- | --- | --- |
| **Publish / unpublish mutation** | `EventStudioSaveService::setNodePublishedState` | `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php` |
| **Eligibility enforcement** | `PublishEligibilityEvaluator` (`myeventlane_event_studio.publish_eligibility`) | `src/Service/PublishEligibilityEvaluator.php` |
| **Readiness display + checklist** | `EventReadinessService` (`myeventlane_event_studio.readiness`) | `src/Service/EventReadinessService.php` |
| **Publish + promotion bundle** | `EventReadinessFacade` | `src/Service/EventReadinessFacade.php` |
| **AJAX operation** | `EventStudioPublishController` | `src/Controller/EventStudioPublishController.php` |
| **Publishing section UI** | `PublishingSection` + `EventStudioSectionRenderer::buildPublishingHub` | Section plugin + renderer |
| **Publish action card UI** | `EventStudioPublishForm` / `EventSettingsForm` | Forms embed card with `data-mel-publish-*` |
| **Authoritative Hero CTA** | `EventWorkspaceOverviewBuilder::resolveAuthoritativePrimaryCta` | Overview builder |
| **Success handoff** | `EventStudioPreprocess::buildPublishSuccessHandoff` | Preprocess + shell JS |
| **Vendor / Stripe gates** | `VendorPublishRequirementsGate`, `PaidPublishStripeGate` | `myeventlane_vendor` |
| **Access** | `EventStudioAccess::access` + controller re-check via `EventVendorAccessChecker` | Access + publish controller |

**Source of truth for “may this event go live?”:**  
`PublishEligibilityEvaluator` (enforcement). Display readiness is `EventReadinessService` / facade — **not** a second publish path, but eligibility re-runs vendor + Stripe + readiness before mutation.

**Source of truth for node live flag:** Drupal node `status` (+ `moderation_state` when present), mutated only through orchestrated save for Studio.

---

## 4. Routes (complete)

| Route ID | Path | Handler | Role |
| --- | --- | --- | --- |
| `myeventlane_event_studio.workspace_publishing` | `/vendor/events/{node}/studio/publishing` | `EventStudioController::workspace` | Canonical Publishing section |
| `myeventlane_event_studio.publish` | `/vendor/events/{node}/studio/publish` | `EventStudioPublishController::publish` | POST AJAX publish/unpublish |
| `myeventlane_event_studio.edit_publish` | `/vendor/events/{node}/edit/publish` | `redirectLegacyEditToSettings` | Legacy → Settings |
| `myeventlane_vendor.console.event_publish` | `/vendor/events/{event}/publish` | Vendor workspace controller | Legacy; vendors redirected |
| `myeventlane_vendor.console.event_unpublish` | `/vendor/events/{event}/unpublish` | `EventUnpublishForm` | Legacy confirm; vendors redirected |
| `myeventlane_event.wizard.publish` | `/vendor/events/{event}/build/publish` | `EventWizardPublishForm` | Legacy wizard |
| `myeventlane_event.wizard.success` | `/vendor/events/{event}/build/success` | Wizard success | Legacy |

Access on Studio routes: `_custom_access: EventStudioAccess::access`.  
Publish POST also requires `_csrf_request_header_token: TRUE`.

---

## 5. Controllers, forms, services

### Controllers

- **`EventStudioPublishController::publish`** — JSON publish/unpublish; returns readiness AJAX bundle, topbar CTA, optional `handoff`.
- **`EventStudioController::workspace`** — builds `mel_event_studio_workspace`; section `publishing` renders hub; `?mel_celebrate=1` attaches handoff.

### Forms

| Form | Form ID | Role |
| --- | --- | --- |
| `EventStudioPublishForm` | `mel_event_studio_wizard_publish` | Publish action card UI |
| `EventSettingsForm` extends PublishForm | `myeventlane_event_studio_settings_form` | Visibility + publish card; **embedded in publishing hub** |
| `EventWizardPublishForm` | `event_wizard_publish_form` | Legacy; separate validator |
| `EventUnpublishForm` | `myeventlane_vendor_event_unpublish` | Legacy direct unpublish |

### Key services

| Service ID | Class |
| --- | --- |
| `myeventlane_event_studio.publish_eligibility` | `PublishEligibilityEvaluator` |
| `myeventlane_event_studio.readiness` | `EventReadinessService` |
| `myeventlane_event_studio.readiness_facade` | `EventReadinessFacade` |
| `myeventlane_vendor.publish_requirements_gate` | `VendorPublishRequirementsGate` |
| `myeventlane_vendor.paid_publish_stripe_gate` | `PaidPublishStripeGate` |
| `myeventlane_event_studio.save` | `EventStudioSaveService` |
| `myeventlane_event_studio.overview_builder` | `EventWorkspaceOverviewBuilder` |
| `myeventlane_event_studio.workspace_presentation` | `EventStudioWorkspacePresentation` |
| `myeventlane_event_studio.section_renderer` | `EventStudioSectionRenderer` |
| `myeventlane_event.featured_readiness` | `FeaturedEventReadinessService` (promotion only) |

---

## 6. Publish vs Readiness

| | **Readiness** | **Publish eligibility** |
| --- | --- | --- |
| Service | `EventReadinessService` | `PublishEligibilityEvaluator` |
| Purpose | Confidence UI — checklist, Mission Control, “ready” boolean | **Hard gate** before `setPublished(TRUE)` |
| Output | `EventReadinessResult` `{ready, errors, warnings, completed, recommendations}` | `{allowed, reason, messages}` |
| Vendor / Stripe | Folded into readiness errors | Checked again first (`vendor_denied`, `stripe`) |
| Blocks publish? | Indirectly (`ready === false` → eligibility fails) | Directly throws / 422 |
| Unpublish | N/A | **Not required** — unpublish skips eligibility |

**Rule for Launch Centre product language:**  
Readiness answers *“What’s left?”*  
Eligibility answers *“May we flip live?”*  
Organisers should never see two conflicting greens.

---

## 7. Readiness checks (actual)

Monolithic evaluator — tagged `Readiness/` provider classes exist as **future contracts only** (not wired for gating).

| Check | Severity when failing |
| --- | --- |
| Title present / not “Untitled event” | error |
| Start + end dates; end after start | error |
| Booking mode (`field_event_type`) | error |
| External URL when external | error |
| ≥1 active paid ticket (paid/both) | error |
| Stripe charge-ready (`PaidPublishStripeGate`) for paid/both | error |
| Organiser profile / terms / Stripe connect (`VendorPublishRequirementsGate`) | error |
| Capacity validity | error / warning |
| Question template findings | blocker / warning |
| Cover image missing | recommendation |
| Summary / donations / accessibility contact | recommendations |

**Promotion readiness** (`FeaturedEventReadinessService` via facade) does **not** block publish.

---

## 8. Vendor / Stripe gate detail

### `VendorPublishRequirementsGate::getLivePublishDenialReasons`

- Signed in
- Vendor membership + entity
- Terms accepted
- Onboarding completed
- Stripe connected (studio create helper)

Staff/admin bypass for uid 1 / `administer site configuration`.

### `PaidPublishStripeGate::validatePaidPublishAllowed`

For paid/both events: Commerce store, valid `acct_*`, `charges_enabled` (and related store fields). Returns blocked message or `NULL`.

**Product note:** Vendor gate requires Stripe connect for **all** organisers in the denial list, while charge-readiness is paid/both-specific — both can surface as readiness errors for paid events. Launch Centre copy must stay honest without double-shaming.

---

## 9. Publishing hub (today)

`EventStudioSectionRenderer::buildPublishingHub()` returns a render array (no dedicated Twig theme):

- Headline: “Ready to publish” / “Your event is live” / “A few things left…”
- Explanation + item_list checklist (completed ✔ / remaining ○)
- Embedded `EventSettingsForm` (visibility + publish action card)

Section plugin: `PublishingSection` — `renderTarget: publishing_hub`, route `workspace_publishing`, `readiness_participant: TRUE`.

---

## 10. AJAX / JS / cache

| Layer | Behaviour |
| --- | --- |
| Shell JS | `js/mel-event-studio-shell.js` — `fetch(publishUrl)` with `action: publish|unpublish`; updates topbar, Mission Control, panels, feedback; `renderPublishSuccessFeedback(handoff)` |
| Selectors | `[data-mel-publish-action]`, `[data-mel-card-publish-action]`, `[data-mel-unpublish-action]`, `[data-mel-publish-feedback]` |
| Legacy JS | `js/mel-event-studio.js` — builder celebrate / panels |
| Workspace cache | `#cache` contexts include `url.query_args:mel_celebrate` |
| Publish JSON | Uncached response; returns changed + revisionId for concurrency |

Pre-mutation guards: dirty section (409), stale changed/revision (409), pending autosave draft (409).

---

## 11. Success / celebrate

1. AJAX 200 + `state: published` → `payload.handoff` from `buildPublishSuccessHandoff`
2. Query `?mel_celebrate=1` on workspace → same handoff attached to render
3. Form path (`EventStudioPublishForm`) redirects to workspace with celebrate query when first going live
4. Handoff fields: `title` (“Your event is now live”), `message`, `view_url`, social `share`, `boost_url`, `calendar_url`

---

## 12. Unpublish

| Path | Eligibility? |
| --- | --- |
| Shell AJAX `action: unpublish` → `setNodePublishedState(..., FALSE)` | No — still dirty/stale/autosave guards |
| Legacy `EventUnpublishForm` | Direct `setUnpublished()`; typical vendors redirected to Settings |

---

## 13. Scheduled / future publish

**Not supported** for Event Studio node publish. No schedule-publish service/UI in focus modules. “Future” in product language means event start in the future while already published — not deferred publish.

---

## 14. Legacy vs canonical

```text
CANONICAL
  workspace_publishing + Hero Publish CTA
  POST myeventlane_event_studio.publish
  PublishEligibilityEvaluator → setNodePublishedState
  EventSettingsForm inside publishing hub
  mel-event-studio-shell.js

LEGACY (still in repo; vendors redirected by VendorLegacyWizardRedirectSubscriber)
  wizard.publish / wizard.success
  console.event_publish / event_unpublish
  EventWizardPublishForm + EventWizardPublishValidator
  EventUnpublishForm (ungated)
  mel-event-studio.html.twig builder celebrate
```

Legacy redirect target for typical vendors: **Settings** (not always Publishing section) — product friction for Launch Centre IA.

---

## 15. Templates & assets (evidence)

| Asset | Path |
| --- | --- |
| Workspace shell | `templates/mel-event-studio-workspace.html.twig` |
| Mission Control | `templates/mel-event-studio-mission-control.html.twig` |
| Topbar | `templates/mel-event-studio-topbar.html.twig` |
| Boost CTA include | `templates/mel-publish-boost-cta.html.twig` |
| Legacy builder | `templates/mel-event-studio.html.twig` |
| Settings page (vendor theme) | `myeventlane_vendor_theme/.../mel-event-settings-page.html.twig` |
| Shell JS | `js/mel-event-studio-shell.js` |

---

## 16. Business rules — where enforced

| Rule | Enforcement point |
| --- | --- |
| Vendor ownership / parity | `EventStudioAccess` + publish controller |
| CSRF on publish POST | Route requirement |
| Autosave / concurrency | Publish controller 409 guards |
| Organiser profile / terms / Stripe connect | `VendorPublishRequirementsGate` |
| Paid charges enabled | `PaidPublishStripeGate` |
| Event content completeness | `EventReadinessService` |
| Combined publish allow | `PublishEligibilityEvaluator` inside `setNodePublishedState` |
| Moderation sync | `setNodePublishedState` |
| CTA single authority (Hero) | `resolveAuthoritativePrimaryCta` |

Twig and Mission Control **must not** invent eligibility.

---

## 17. Residual discovery risks

1. Dual unpublish paths (Studio orchestrated vs legacy form) — mitigated by redirects for typical vendors.
2. Dual publish validators (Studio eligibility vs legacy wizard validator) — different rule sets.
3. Stripe messaging can appear twice (vendor gate + paid gate).
4. Publishing hub embeds full Settings form → visual/decision overload vs Launch Centre intent.
5. Provider architecture scaffolding unused — do not design as if tagged providers gate publish today.

---

## Verdict (discovery)

Publishing is **owned**, **gated**, and **AJAX-capable** in Event Studio. The gap is product composition: today’s Publishing section is a checklist + settings form + publish card — not yet a calm Launch Centre narrative.

**Next docs:** product audit (`16`), state model (`17`), wireframes (`18`).
