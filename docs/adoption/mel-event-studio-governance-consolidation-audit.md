# MEL Event Studio — governance consolidation audit

**Status:** Step 1 complete — findings documented; **no runtime consolidation applied in this document.**

**Scope:** `myeventlane_event_studio`, vendor Studio shell (`VendorStudioController`), related vendor subscribers/alerts, theme templates and JS. The governance stack (`myeventlane_surface` — State, Workflow, Experience, Intelligence, Operational Policy, Observability, Interaction, Data Presentation, Component) is treated as **authoritative** and **already complete**; this audit maps gaps where Event Studio still implements parallel behaviour.

---

## 1. Event Studio governance audit (findings)

### 1.1 Surface inventory

| Area | Location | Role today |
|------|----------|------------|
| Controllers | `EventStudioController`, `EventStudioAutosaveController`, `EventStudioPreviewController`, `EventStudioAiController`, `EventStudioTicketSuggestionsController` | Create/edit routing, autosave, AI/ticket helpers |
| Forms | `EventStudioForm`, step forms (`EventStudioBasicForm`, …), `EventStudioPublishForm` | Canonical builder; uses `VendorPublishRequirementsGate`, `EventStudioSaveService` |
| Theme / Twig | `templates/mel-event-studio.html.twig`, nav/preview/wizard partials | Large inline layout: sidebar preview, **hardcoded** publish warnings, celebrate panel, AI modal shell |
| Preprocess | `EventStudioPreprocess` + `myeventlane_event_studio_preprocess_mel_event_studio()` | **Vendor membership**, **onboarding state**, **Stripe flag**, publish block flags, celebrate query param |
| JS orchestration | `js/mel-event-studio.js` (~5.4k lines) | **Wizard IO**, **strength score**, **insights checklist**, **next-best / primary CTA**, **publish readiness text**, preview sync, ticket panels, **AI diff modal** |
| Vendor shell (staff) | `VendorStudioController` | JSON payloads: checklist booleans, health labels, moderation badges; parallel to studio heuristics |
| Access / gates | `EventStudioVendorOnboardingGateSubscriber` | Requires vendor entity for studio routes; explicitly **not** full onboarding/Stripe (publish-time validation elsewhere) |

### 1.2 Duplicated governance-style logic (high severity)

1. **Publish / organiser readiness**  
   - **Server:** `VendorPublishRequirementsGate` (terms, profile completion, Stripe) — used for real publish denial in save path.  
   - **Preprocess:** `EventStudioPreprocess` repeats vendor membership, loads vendor onboarding state, sets `mel_publish_blocked` / `mel_publish_stripe_gate` for **paid** paths via `field_event_type` + `stripe_connected` flag.  
   - **Twig:** Conditional banners (`mel_publish_blocked`, `mel_publish_stripe_gate`) — **not** unified with moderation, operational suppression, or workflow primary action.  
   - **Gap vs MEL:** No consumption of **MELStateSystem** / **MELOperationalPolicySystem** for trust-safe visibility or suppression on this surface.

2. **Listing / ticket “readiness” and nudges**  
   - **Client-only:** `buildStructuredInsights()`, `publishReadiness()`, `calculateScore()`, `strengthLabelForScore()`, `melUpdateNextBest()`, `melUpdatePrimaryCta()` in `mel-event-studio.js`.  
   - These encode field heuristics (cover, category, title length, venue modes, paid product/tiers) **independent** of server gates and **independent** of **MELIntelligenceSystem** / **MELWorkflowSystem** ordering rules.  
   - **Risk:** Client and server can disagree; moderation / payout / escalation signals never influence the sidebar “intelligence.”

3. **Workflow / CTA priority**  
   - Primary CTA label/target is derived from **first insight row** in JS (`melUpdatePrimaryCta`), not from **MELWorkflowSystem** “next primary action” or operational safety ordering (Stripe > publish > growth).  
   - Footer styling (`updateFooterCtaState`) uses **local score ≥ 70** + publish checkbox — another parallel “readiness” signal.

4. **Lifecycle / moderation labels**  
   - Staff Studio payload: `normalizeModerationState()`, `buildHealthLabel()` in `VendorStudioController` — vendor-facing Event Studio Twig/JS does not align with a single **MELStateSystem** interpretation for banners (draft vs review vs live vs hold).

5. **Onboarding / continuity**  
   - Sidebar: `_myeventlane_vendor_theme_build_onboarding_stages()` + `show_onboarding_sidebar` driven by **raw** onboarding completion in preprocess.  
   - **MELExperienceRegistry** already defines experiences (e.g. `vendor_event_draft_continuation` with `activationRoutePrefixes: ['myeventlane_event_studio.']`) — **Event Studio does not attach or render** experience/intelligence payloads from `myeventlane_surface`.

6. **Interactions**  
   - **AI diff modal** (`#mel-ai-diff-modal`), preview drawer, attendee limit warnings — bespoke DOM/ARIA patterns; not routed through **MELInteractionSystem** disclose/modal/async contracts.

7. **Explainability**  
   - No **MELObservabilitySystem** attachment on Event Studio routes for staff; no governance traces in `drupalSettings` for controlled inspection (contrast with dashboard work elsewhere).

### 1.3 Module boundary

- **`myeventlane_event_studio.info.yml`** does **not** list `myeventlane_surface` as a dependency.  
- **Grep:** zero references to `myeventlane_surface`, `MelState`, workflow/experience managers in `myeventlane_event_studio`.  
- **Conclusion:** Event Studio is **not** a consumer of the governance stack today.

---

## 2. Governance ownership map (target)

| Concern | Owning system | Event Studio should… |
|--------|---------------|----------------------|
| Publish readiness, lifecycle, trust-safe states | **MELStateSystem** | Render interpreted state + safe labels from server; stop parallel Twig/JS “truth” |
| Next action, CTA ordering, onboarding progression | **MELWorkflowSystem** | Drive primary/secondary CTAs and step emphasis from contract output |
| Resume / draft / payout continuity | **MELExperienceSystem** | Replace duplicate onboarding banners where experience contracts apply |
| Prioritised prompts, nudges | **MELIntelligenceSystem** | Replace `buildStructuredInsights` **as the source of ordered prompts**; keep form-field validation separate |
| Suppression, escalation-safe UX | **MELOperationalPolicySystem** | Authoritative over growth/analytics prompts; Twigs must not hardcode competing CTAs |
| Staff explainability | **MELObservabilitySystem** | Attach traces only for permitted roles; never leak to public/customer |
| Modals / disclosures / async | **MELInteractionSystem** | Consolidate AI review drawer/modal patterns where feasible |
| Metrics cards / checklist UI | **MELDataPresentationSystem** + **MELComponentSystem** | Align sidebar cards with governed components |

**Important:** `VendorPublishRequirementsGate` and `EventStudioSaveService` remain **Commerce/access lawful** writers; consolidation should **call into** governance for **interpretation and ordering**, not reimplement publish eligibility inside surface helpers.

---

## 3. Duplication cleanup report (planned targets)

| Duplicate | Keep (canonical behaviour) | Remove or demote |
|-----------|----------------------------|------------------|
| Stripe/vendor publish flags | Save-path gate + entity access | Mirror logic in `EventStudioPreprocess` + Twig branches → single injected interpretation |
| Insight ordering | **MELIntelligence** (+ policy suppression) | `buildStructuredInsights` priority rules |
| Next-best / primary CTA | **MELWorkflow** + operational safety | `melUpdateNextBest` / `melUpdatePrimaryCta` heuristic |
| Strength % / labels | Optional cosmetic **or** data presentation | Must not contradict state/workflow; clarify single owner |
| Publish readiness copy | **MELState** summary | `publishReadiness()` string composition in JS |
| Staff card checklist | Governed data panels | Raw booleans in `VendorStudioController::buildEventPayload` only if no governed equivalent |

---

## 4. CTA consolidation report

**Today:** Twig hardcodes publish warning CTAs (Stripe connect vs generic setup); JS sets primary button from first insight row; footer submit styling uses score threshold.

**Target ordering (product rule):**  
1. Operational safety (Stripe Connect, legal/terms if blocking)  
2. Workflow continuation (resume onboarding, complete draft)  
3. Publish readiness (moderation-safe)  
4. Revenue operations  
5. Discovery/growth  

**Actions:** Feed ordered CTA list from server render array or `drupalSettings` slice produced by workflow + policy managers; Twig holds **structure only**, not priority logic.

---

## 5. Lifecycle consolidation report

**Today:** `mel_event_is_published`, publish panels in Twig, `moderation_state` in staff JSON only partially normalized.

**Target:** Single server-built **state summary** (draft / review / published / deferred-with-reason) from **MELStateSystem** + **MELOperationalPolicySystem**, with **no moderation internals** in HTML. Client may reflect status but must not **infer** hold reasons independently.

---

## 6. Interaction consolidation report

**Candidates:** AI diff modal, preview drawer toggle, attendee question limit banner, ticket soft warnings.

**Target:** Map to **MELInteractionSystem** patterns (disclosure, modal, async) where the surface module already exposes helpers; avoid a second interaction registry inside Event Studio.

---

## 7. Explainability adoption report

**Today:** No observability payload on Event Studio routes.

**Target:** Reuse existing negotiator / staff-only attachment patterns from `myeventlane_surface` (same as vendor dashboard governance slice): traces for suppression + workflow resolution; **no** Event Studio–specific debug channels.

---

## 8. Security / privacy validation report

| Risk | Mitigation in consolidation |
|------|----------------------------|
| Cross-vendor data | Continue `assertEventOwnership` / `_entity_access`; governance inputs scoped per session + node |
| Moderation leakage | State labels only; no raw workflow state IDs in customer HTML |
| Stripe / payout internals | Keep existing Connect checks; no account IDs in traces/HTML |
| Staff traces on vendor routes | Attach observability only when surface + permission checks pass |

---

## 9. Accessibility report (preserve)

**Strengths today:** `aria-live` on insights/next-best/preview hints; `role="tabpanel"` on steps; reduced-motion branches in JS; publish celebrate region with labelled heading.

**Consolidation must:** Preserve semantic regions (`aside` tools vs `main`), focus management when replacing modals, avoid duplicate `aria-live` announcements when server-driven continuity messages appear.

---

## 10. File-by-file implementation summary (reference)

| File / area | Relevance |
|-------------|-----------|
| `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.info.yml` | Add optional/required `myeventlane_surface` when wiring consumers |
| `myeventlane_event_studio.services.yml` | Register injected builders (state/workflow/experience facade) |
| `src/EventStudioPreprocess.php` | Replace duplicate readiness with governed summary + cache metadata |
| `src/Form/EventStudioForm.php` | Attach governed `drupalSettings` / render arrays; keep `melEventStudio` for field sync until JS shrink |
| `templates/mel-event-studio.html.twig` | Structural regions toward `mel-event-studio` target tree; remove conditional business logic where possible |
| `js/mel-event-studio.js` | Thin client: DOM sync + governed payload application; delete duplicated ordering/heuristics incrementally |
| `myeventlane_vendor/.../VendorStudioController.php` | Align checklist/health with data presentation; avoid third copy of insights |
| `myeventlane_vendor/.../VendorPublishRequirementsGate.php` | Remains save authority; expose flags to governance adapters if needed |
| `myeventlane_vendor_theme` onboarding partials | Coordinate with experience continuity outputs |
| `web/themes/.../_event-studio.scss` | Reuse governance partials; no parallel design system |

---

## Recommended phase order (implementation — not done here)

1. **Dependency + adapter:** Optional `myeventlane_surface` services facade from Event Studio (no new orchestration framework).  
2. **Server-first payload:** One render/`drupalSettings` contract: state summary, workflow CTAs, intelligence rows (post-suppression), experience continuity.  
3. **Twig dedupe:** Strip branching that duplicates payload.  
4. **JS deletion:** Remove insight/publish-readiness/primary-CTA heuristics as server becomes source of truth.  
5. **Tests:** Extend existing governance kernel tests for vendor route + Event Studio route attachment and ordering (no duplicate business assertions vs Commerce).

---

## Assumptions

- Governance managers in `myeventlane_surface` can accept **route + account + optional node** context for vendor Event Studio without new entity fields.  
- If a manager requires signals not yet emitted for events, **ask product/backend** before inventing flags — **no guessing** on moderation or Commerce.

---

## Residual risk

Until consolidation lands, **client-side insights can contradict** server publish denial and **cannot** respect operational suppression or moderation deferral — inconsistent vendor UX and potential over-eager growth messaging.
