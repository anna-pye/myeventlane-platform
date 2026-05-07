# MEL governance stabilisation — surface convergence phase

This document is the **Step 1–14 bundle** for the stabilisation phase: convergence of operational presentation, continuity language, accessibility behaviour, empty states, support/help continuity, recommendation language, and template ownership — **without** new governance/orchestration frameworks.

**Assumption (verified in repo, 2026-05-07):** Canonical surface systems live in `myeventlane_surface` (MELComponentSystem, MELDataPresentationSystem, MELInteractionSystem, MELWorkflowSystem, MELExperienceSystem, MELOperationalPolicySystem, MELObservabilitySystem). `mel_empty_state` is registered and preprocessed in `MelComponentPreprocess`; primary **runtime** consumers found in code search include Event Studio governance builders.

---

## 1. Surface convergence audit (drift inventory)

### 1.1 Customer

| Area | Finding | Drift / risk |
|------|---------|----------------|
| **Recommendations** | `MelRecommendationResolver`, `MelExperienceManager` + `MelContinuationHelper`; UI strings also appear in `ConversionAnalyticsService` (bottleneck copy), commerce ticket rows, help assistant | Mix of **governed intelligence** and **ad-hoc string** recommendations |
| **Category follow** | `myeventlane_core/templates/myeventlane-my-categories.html.twig` — plain paragraphs when empty | Not using `mel_empty_state` / data presentation contracts |
| **Empty states** | `mel-empty`, `mel-empty-state`, inline cards, search listing empty, views empty | **Parallel systems**; inconsistent `role="status"` |
| **Continuation** | RSVP thank-you uses `mel_continuity`; `mel-experience` attachments elsewhere | Generally governed; **template duplication** (below) |
| **Support/help** | `myeventlane_help_assistant`, `myeventlane_core` studio support mount, vendor contextual help | Multiple “next step” phrasings |
| **Ticket/order summaries** | Checkout flow + account templates; `myeventlane-my-tickets` had **theme vs module drift** (fixed this pass) | Override caused **continuity regression** |
| **Profile completion** | Surfaced via customer settings shells / forms — not fully mapped in this audit slice | Follow-up: grep `profile` + `complete` in account module |

### 1.2 Vendor

| Area | Finding |
|------|---------|
| **Analytics cards** | `analytics-dashboard.html.twig` — `mel-empty-state` + `mel-vendor-analytics-v2__empty`; KPI grids separate |
| **Action queues / prompts** | Dashboard `empty_state` from `VendorDashboardViewModelBuilder`; event workspace empty states |
| **Onboarding help** | Event Studio banners (`role="status"`), vendor help panel + modal dispatch |
| **Empty states** | `empty-state.html.twig` (vendor_theme), `event-table.html.twig` hard-coded copy, payouts/boost `mel-empty` |
| **Event management** | Duplicated attendee card markup removed — **canonical** `myeventlane_checkout_flow/templates/components/mel-attendees-event-card.html.twig` |

### 1.3 Support / help

| Area | Finding |
|------|---------|
| **Escalation continuity** | `myeventlane_escalations_policy`; operational copy in handlers — align with workflow/experience/policy interpretation |
| **Contextual help** | Vendor attendees dashboard — `mel_vendor_contextual_help`; was **missing in theme override** (fixed this pass) |
| **Fallback messaging** | 404: module fallback + theme branded `mel-404` (intentional; keep module doc stating theme owns assets) |

### 1.4 Interactions

| Pattern | Locations |
|---------|-----------|
| **Governed modal shell** | `myeventlane_surface/templates/interactions/mel-modal.html.twig` |
| **Native `<dialog>`** | `mel-event-card-remove.js`, removal Twig |
| **Mobile drawer** | `role="dialog"` on panel |
| **Vendor help “modal”** | `data-modal` + `vendor-help-panel.js` |
| **Cookie / consent** | Klaro, legal module |
| **Loading** | `mel-empty-loading.html.twig`, Event Studio `aria-live` regions |

### 1.5 Stale Twig ownership (high priority)

| Asset | Ownership issue |
|-------|------------------|
| **RSVP thank-you** | **Thin theme delegate** + `mel-template-parity.json` / `template-parity-audit.php` — canonical markup in `myeventlane_rsvp`; theme override is `{% include '@myeventlane_rsvp/templates/mel-rsvp-thankyou.html.twig' %}` |
| **My tickets** | Theme override **lagged** module (empty copy, `<details open>`) — **corrected** |
| **Vendor attendees** | Module inlined card; theme used include — **converged** on module partial + contextual help in both |
| **mel-404** | Module fallback + theme override + library attach on theme — acceptable; document “edit theme first” |

### 1.6 Accessibility inconsistencies (audit notes)

- Empty regions: some use `role="status"` (`mel-empty--browse`, vendor directory), many card-based empties do not.
- Event Studio: many `aria-live="polite"` regions — verify **one** primary live region per step where possible to reduce noise.
- Modal: mix of `<dialog>`, `mel-modal`, and `role="dialog"` drawers — **document** which is authoritative per surface; align focus restore per pattern.

---

## 2. Governed empty-state consolidation (target state)

**Target:** Operational empties should be built from `#theme` `mel_empty_state` (and related `mel_card_*` fragments) where a **rich** empty is needed, per `MelComponentRegistry` / `MelComponentPreprocess`.

**Current gap:** Widespread ad-hoc `mel-empty-state` CSS classes **without** the `mel_empty_state` theme hook (grep shows PHP usage concentrated in Event Studio builders).

**Next actions:** For each candidate template, either (a) migrate render arrays to `#theme => 'mel_empty_state'` with structured variables, or (b) use a Twig embed of the canonical template **only where** render arrays are not available — prefer (a) for cache/metadata.

---

## 3. Support + help continuity alignment (target state)

**Target:** CTAs and sequencing consume `MelWorkflowResolver` / experience attachments / `MelOperationalPolicyManager` interpretation — not ad-hoc ordering in Twig.

**Next actions:** Inventory `suppress_*` and “next step” strings in Twig (see `scripts/governance/architecture-audit.php` heuristics for vendor Twig); migrate to interpretation payload or experience continuation keys.

---

## 4. Recommendation + action language (target state)

**Target:** User-facing recommendation tiers flow from intelligence registry + state evaluation; avoid duplicate operational sentences in analytics services and help modules.

**Notable duplicate source:** `ConversionAnalyticsService` English bottleneck strings vs governed intelligence copy.

---

## 5. Template convergence (this pass + backlog)

| Change | Status |
|--------|--------|
| `myeventlane-my-tickets` theme ↔ module continuity | **Theme updated** to match module |
| Vendor attendees card | **Single partial** under `myeventlane_checkout_flow`; theme duplicate **removed** |
| Vendor attendees contextual help | **Theme override** now includes block present in module |
| RSVP thank-you duplicate | **Documented** — consider CI `diff` guard or single docblock-only module stub |

---

## 6. Interaction system convergence

**Target:** Prefer `mel-modal` + shared interaction JS (`mel-interactions.js` in surface) for new work; deprecate **duplicate** overlay CSS/JS only when a safe migration path exists.

**Do not:** Replace working native `<dialog>` flows (event card removal) without product sign-off.

---

## 7. Accessibility consistency

**Next actions:** Define a short **checklist** per template class: landmark heading level, empty `role="status"` or `aria-live`, disclosure `aria-expanded`, modal focus trap + restore (native `dialog` vs custom).

---

## 8. Performance + cacheability

**Next actions:** Trace duplicate preprocess for the same route; ensure `#cache` keys on view models attached to dashboard/analytics; avoid double-building governance payloads (Experience + Intelligence + Observability) where attachments can share a single lazy builder (incremental — no big bang).

---

## 9. Observability + explainability

**Existing:** `MelSuppressionTraceHelper`, `MelObservabilityRegistry` — staff-gated.

**Next actions:** Readability pass on staff-only templates/JS; ensure suppression strings reuse `MelOperationalPolicyManager` messages.

---

## 10. Stale legacy cleanup (verified only)

**Removed this pass:** `web/themes/custom/myeventlane_theme/templates/components/mel-attendees-event-card.html.twig` (superseded by module partial).

**Do not remove without verification:** RSVP theme/module pair (Drupal override pattern); `mel-404` module fallback; legacy `events` branch in attendees dashboard.

---

## 11. Testing (targeted additions)

Suggested **non-duplicative** tests:

| Test idea | Placement |
|-----------|-----------|
| Attendees Twig includes resolve | Kernel/render pipeline smoke for checkout route |
| My tickets theme override regressions | Optional: Twig snapshot or kernel render |
| Empty state uses `mel_empty_state` variables | Expand Event Studio builder tests |

---

## 12. Validation (2026-05-07 run)

| Command | Result |
|---------|--------|
| `composer validate` | OK |
| `php scripts/governance/surface-audit.php` | OK (390 routes) |
| `php scripts/governance/architecture-audit.php` | OK |
| `composer governance:test` | **2 errors** — `commerce_checkout.review` route missing in kernel environment (`MelGovernanceSnapshotKernelTest`, `MelOperationalPolicyKernelTest`). **Residual:** fix test bootstrap/route fixture, not blamed on this Twig pass |
| `npm run mel:lint` | OK |
| `npm run mel:build` | OK |
| `ddev drush cr` | Not run here (needs local DDEV) |

---

## 13. Manual smoke tests (checklist)

**Customer:** My Tickets empty + past foldout; RSVP thank-you CTAs; category follow empty; browse empty state.

**Vendor:** Attendees dashboard cards + contextual help strip; analytics empty; payouts/boost empty rows.

**Staff:** Diagnostics pages — no extra traces vs permission.

**Public:** No observability payloads on anonymous pages without permission.

---

## 14. File-by-file implementation summary (this session)

| File | Change |
|------|--------|
| `web/themes/custom/myeventlane_theme/templates/myeventlane-my-tickets.html.twig` | Aligned with module: empty-state continuity copy, `<details open>` when no upcoming orders |
| `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-vendor-attendees-dashboard.html.twig` | Replaced inlined card markup with `@myeventlane_checkout_flow/components/mel-attendees-event-card.html.twig` |
| `web/themes/custom/myeventlane_theme/templates/myeventlane-vendor-attendees-dashboard.html.twig` | Same include as module; added `mel_vendor_contextual_help` block |
| `web/themes/custom/myeventlane_theme/templates/components/mel-attendees-event-card.html.twig` | **Deleted** — canonical in checkout_flow module |
| `web/themes/custom/myeventlane_theme/src/scss/components/_attendees-event-card.scss` | Doc comment points to module template path |
| `docs/adoption/mel-governance-stabilisation-surface-convergence.md` | **This report** |

---

## Residual risk

- **`composer governance:test` failures** pre-exist route provisioning in PHPUnit kernel config; stabilize before relying on CI.
- **Subthemes** that overrode only `mel-attendees-event-card.html.twig` under `myeventlane_theme` must move override to **`myeventlane_checkout_flow` namespace** or copy the checkout_flow template path explicitly.
- **RSVP thank-you** still has two files — maintain parity or automate diff check.

---

## Suggested backlog ordering (waves)

1. **Wave A — Template parity:** CI or script: `diff` module vs theme for known paired templates (RSVP, order detail if paired).
2. **Wave B — Empty states:** Migrate top 10 customer/vendor empties to `mel_empty_state`.
3. **Wave C — Language:** Centralise analytics bottleneck strings behind translation + policy-friendly contracts.
4. **Wave D — Interaction audit:** Map each modal pattern to owner (surface vs native dialog vs vendor JS).
