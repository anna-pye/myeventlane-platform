# MEL governance rationalisation — global audit (Step 1)

**Scope:** Inventory of orchestration-heavy surfaces, parallel logic, and governance consumption gaps. No code deletion has been performed in this document’s pass; it supports Step 7 (“delete only after verified adoption”).

**Related:** Vendor dashboard governance stack is described in [mel-governance-hardening-vendor-dashboard-slice.md](mel-governance-hardening-vendor-dashboard-slice.md). Event Studio adoption is covered in [mel-event-studio-governance-consolidation-audit.md](mel-event-studio-governance-consolidation-audit.md).

---

## 1. How governance is injected today (single authority)

Universal attachment happens in `SurfaceNegotiator::attachPageMetadata()` (via `hook_preprocess_page` in `myeventlane_surface.module`). It wires:

- **MELWorkflowSystem** → `mel_workflow`, `mel_workflow_region_primary`, `mel_workflow_region_progress`
- **MELStateSystem** → `mel_state`
- **MELExperienceSystem** → `mel_experience` (+ libraries / drupalSettings)
- **MELIntelligenceSystem** → `mel_intelligence`, `mel_intelligence_panel`
- **MELOperationalPolicySystem** → `mel_operational_policy` (+ drupalSettings)
- **MELObservabilitySystem** → gated diagnostics (`MelObservabilityDiagnosticsAccess`)

Vendor layout consumes the full stack in `mel-vendor-dashboard-governance-stack.html.twig`. Public customer **base** page template only surfaces intelligence + observability in the main column — not workflow regions (see §3.1).

---

## 2. Customer surfaces

**Step 1 checklist (customer):** `/my-account`, order confirmation, RSVP confirmation, ticket confirmation, category follow (batch/email), onboarding/help flows — the table below is the live inventory for in-app surfaces; cron/queue follow behaviour stays out of checkout suppression semantics.

| Surface / route | Template / layout | Workflow regions visible? | Duplication / drift notes |
|-----------------|-------------------|---------------------------|---------------------------|
| `/my-account` (`myeventlane_account.dashboard`) | Custom dashboard theme; **not** `page--account` | **No** — `page.html.twig` has no `mel_workflow_region_*` | Hub uses embedded sidebar in dashboard template; **misses same workflow completion + checklist UI** that `page--account.html.twig` renders for other account routes. |
| `/my-settings/{user}` | `page--account` | **Yes** | Settings: governed; link builder separate from workflow. |
| `/my-events` (`myeventlane_dashboard.customer`) | `page--account` | **Yes** | `CustomerDashboardController` builds ticket-centric rows; no use of `mel_card` / data presentation contracts for those lists (presentation debt, not second orchestration). |
| `/my-tickets`, order detail | `page--account` | **Yes** | Workflow completion routes registered for order detail / RSVP thank-you in registry (see architecture doc). |
| `/my-past-events` | Dashboard-style template (module comment: sidebar in template) | **Unclear / likely partial** | Same class of issue as `/my-account` if template omits workflow regions — verify in Twig. |
| Customer onboarding steps (`CustomerOnboardMyTicketsController`, etc.) | Step themes + hard-coded CTAs | **Parallel** | Static “Go to My Events / Browse” links; not driven by `MelWorkflowManager` / experience contracts. |
| Category follow | Email/cron (`CategoryDigestGenerator`, queue workers) | N/A (batch) | No surface orchestration; privacy via existing queries — do not merge with checkout suppression. |

**Duplicated / parallel customer patterns**

- **Continuation:** `MelWorkflowManager` customer checklist + completion panels vs static onboarding step actions.
- **CTA ordering:** Account hub relies on `AccountLinksService` + local review CTAs; no conflict with workflow **rendering** because hub does not show workflow regions today — risk is **missing** governed next-step when checklist would apply (`route.my_account_dashboard` signal exists in `MelWorkflowResolver`).

---

## 3. Vendor surfaces

**Step 1 checklist (vendor):** onboarding, analytics, reports, payout/setup flows, event listings, ticket management, attendee exports — table focuses on orchestration-heavy UI; exports/reports are presentation/data-debt unless they add second CTA bands.

| Surface | Governed consumption | Legacy / parallel orchestration |
|---------|----------------------|----------------------------------|
| Vendor dashboard | Full stack in `page.html.twig` include | **`VendorDashboardController`** previously duplicated the delegated primary CTA in `onboarding_panel`; when `myeventlane_surface` is enabled and `MelWorkflowManager` renders a primary region, the body `onboarding_panel` is **omitted** (single authority). **`CreateEventGatewayController`** and **`VendorSettingsForm`** still embed `myeventlane_vendor_onboarding_panel` where the dashboard governance stack is absent — review in a follow-up slice. |
| Vendor KPIs / action queue | Dashboard metrics | **`VendorActionQueueBuilder`** interprets `dashboardModel['readiness']` with local completeness helpers — parallel **readiness vocabulary** vs `mel_state.readiness_summaries`. |
| Growth / boost cards | Suppressed via `MelOperationalPolicyManager` (per hardening slice) | Still separate **insight** pipeline; suppression is aligned — good. |
| Event Studio | Governance builder + components | **JS** still merges readiness from multiple sources (`mel-event-studio.js` — see §8). |

---

## 4. Checkout

**Step 1 checklist (checkout):** completion pages, trust panels, CTA ordering, suppression handling — trust/CTA theme markup should be audited separately from workflow attachment (no second hierarchy).

| Concern | Governed behaviour | Notes |
|---------|-------------------|--------|
| CTA / banner during checkout | `MelWorkflowCTAHelper` returns empty when `commerce_checkout` route + checkout-sensitive | Documented in `docs/architecture/mel-workflow-system.md`. |
| Primary deferral | `MelStateManager::shouldSuppressPrimaryWorkflowCta()` — payment processing, moderation hold | Interpretation only; Commerce untouched. |
| Completion | Order detail / completion signals on workflows | Verify trust panels in theme do not introduce a second CTA hierarchy (audit visual trust docs separately from workflow). |

---

## 5. Support / help

**Step 1 checklist (support):** escalation prompts, support continuity, moderation notices.

| Component | Behaviour | Governance gap |
|-----------|-----------|----------------|
| `HelpAssistantService` / `HelpResponseBuilder` | LLM + retrieval; merges **SupportActionBuilder** rows and **context followups** | Not wired to `MelExperienceManager` / workflow IDs — **parallel “next action” semantics** for help surfaces only (acceptable if bounded to help UI; document if product wants single continuity story). |
| Escalation copy | `buildEscalationText`, `escalation_recommended` flags | Separate from MELOperationalPolicy escalation state names — ensure wording never leaks moderation internals (currently article/support oriented). |

---

## 6. Cross-cutting duplication matrix

| Pattern | Authoritative server source | Parallel implementations |
|---------|----------------------------|---------------------------|
| Vendor next-step route | `OnboardingManager::getNextActionForAuthenticatedVendor()` (+ workflow delegate) | Vendor onboarding panel Twig chain; gateway/settings embeds |
| Vendor invite readiness | `OnboardingManager::isInviteReady()` | `MelWorkflowResolver::previewVendorInviteReady()` — **must stay aligned** (mirror logic; document when changing either). |
| Customer onboarding resume | Signals from `OnboardingManager::loadCustomerStateByUid()` | Static onboarding controllers |
| Suppression (marketing / cross-sell) | `MelOperationalPolicyRegistry` + interpretation | Growth dismiss/suppression in notifications (`NotificationDecisionEngine` uses `suppression_key` — **different concern**: inbox cooldown, not policy registry) |
| Continuity hints | `MelContinuationHelper` + experience registry | Help assistant followups; dashboard-specific copy |

---

## 7. JavaScript authority (Step 8 preview)

| Asset | Risk |
|-------|------|
| `myeventlane_surface/js/mel-experience.js`, `mel-policy.js`, `mel-intelligence.js` | Should remain **presentation** (consume drupalSettings / DOM data). Audit diff-by-diff for any ordering decisions. |
| `myeventlane_event_studio/js/mel-event-studio.js` | **Readiness / card merge** — candidate to shrink to display-only once server payload is complete. |
| `myeventlane_help_assistant/js/help-assistant.js` | Renders actions from server JSON — generally OK; watch for client-side reordering. |

---

## 8. Observability / explainability / privacy (Steps 11–12)

- Staff diagnostics: permission + surface gate already documented in vendor dashboard slice.
- **Vendor dashboard governance stack** exposes categorical experience labels (“Resume path: @id”) — ensure copy stays non-sensitive (current design uses IDs only).
- Do not expose `mel_observability` traces on public/customer routes (existing gates).

---

## 9. Accessibility (Step 13)

- Workflow regions use `MelWorkflowAccessibilityHelper` landmark wrapping (`MelWorkflowManager`).
- Vendor stack uses explicit headings (`mel-vendor-dashboard-governance-stack.html.twig`).
- Any consolidation that removes duplicate panels must **preserve** one landmarked primary next-step region per page.

---

## 10. Testing gaps (Step 14 — proposals only)

Add **targeted** tests (avoid duplicating existing governance snapshot tests):

- Route/template contract: `/my-account` renders workflow regions OR explicitly delegates to a single alternative primary guidance region (once product chooses).
- Vendor dashboard: when workflow primary is present, onboarding panel does not duplicate the same primary URL (regression once consolidated).
- Checkout: completion moment still emits `mel_success_panel` when signals satisfied.

---

## 11. File reference index (high signal)

| Path | Role |
|------|------|
| `web/modules/custom/myeventlane_surface/src/SurfaceNegotiator.php` | Universal governance attachment |
| `web/modules/custom/myeventlane_surface/src/MelWorkflowManager.php` | Completion vs primary CTA vs customer checklist |
| `web/modules/custom/myeventlane_surface/src/MelWorkflowCTAHelper.php` | Checkout suppression, vendor delegate |
| `web/modules/custom/myeventlane_surface/src/MelWorkflowManager.php` | Includes `willRenderPrimaryWorkflowRegion()` for dedupe callers |
| `web/modules/custom/myeventlane_surface/src/MelWorkflowResolver.php` | Signals + vendor/customer onboarding mirrors |
| `web/themes/custom/myeventlane_theme/templates/page.html.twig` | Customer: intelligence/observability only in main |
| `web/themes/custom/myeventlane_theme/templates/page--account.html.twig` | Customer: **workflow regions** |
| `web/modules/custom/myeventlane_account/myeventlane_account.module` | **`page__account` route list excludes dashboard** |
| `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php` | Onboarding panel + growth |
| `web/themes/custom/myeventlane_vendor_theme/templates/includes/mel-vendor-dashboard-governance-stack.html.twig` | Vendor governance stack |
| `web/modules/custom/myeventlane_core/src/Service/OnboardingManager.php` | Canonical onboarding sequencing |

---

## Residual risk

- Hub `/my-account` may **silently omit** workflow UX while subpages show it — inconsistent customer continuity.
- **`CreateEventGatewayController`** may still show `myeventlane_vendor_onboarding_panel` alongside other guidance; gated separately from dashboard.
- **`willRenderPrimaryWorkflowRegion()`** invokes the same primary builder twice per request when used (attachments + preview) — acceptable for dashboard until a shared internal resolver is warranted.
