# Interaction authority audit (Step 1)

**Scope:** Active interaction surfaces as of repository state — modals, drawers, async/loading, disclosures, and overlap with governance/observability presentation. **No replacements** in this document; findings only.

**References:** [MELInteractionSystem architecture](../architecture/mel-interaction-system.md), `web/modules/custom/myeventlane_surface/js/mel-interactions.js`, `MelInteractionPreprocess`, `MelInteractionRegistry`.

---

## 1. Canonical MEL layer (single orchestration entry)

| Layer | Role | Key paths |
| --- | --- | --- |
| **MELInteractionSystem** | Modal/drawer/disclosure shells, focus trap patterns, checkout-trust overrides, progressive `aria-live` via `[data-mel-state-live]` | `mel-interactions.js`, `MelInteractionPreprocess.php`, `MelModalHelper.php`, `MelInteractionAccessibilityHelper.php` |
| **Theme primitives** | `mel-modal`, `mel-drawer`, `mel-loading-state`, `mel-processing-state`, `mel-saving-state`, mel-disclosure | `myeventlane_surface` templates under `templates/interactions/`, `templates/components/` |
| **SCSS** | Shared elevation, scroll lock (`mel-drawer-open`, `mel-modal-open`) | `myeventlane_theme` `_mel-modals.scss`, `_mel-overlays.scss`, `_mel-loading` |

This is the **primary** interaction authority for governed shells.

---

## 2. Modals — inventory

| Mechanism | Where | Ownership | Notes |
| --- | --- | --- | --- |
| **`mel_modal` (+ confirmation variant)** | Module theme hooks + preprocess | **MELInteractionSystem** | Escape, overlay dismiss (non–checkout-trust), focus trap / relaxed checkout path per `mel-interactions.js`. |
| **Native `<dialog>` + `showModal()`** | Vendor event card removal | **Feature-local** (`mel-event-card-remove.js`, `mel-event-card-removal-dialog.html.twig`) | Legitimate lightweight destructive UI; parallel to mel-modal stack. |
| **`window.confirm()`** | Venue quick-create delete, event wizard navigation guard | **Infrastructure / legacy confirm** | Acceptable per product policy only if retained as minimal native confirms. |
| **Inline `onclick` / `onsubmit` + `confirm()`** | Staff payout batch Twig (`myeventlane_admin_dashboard`) | **Staff-only native confirm** | Not MEL shells; intentional bulk operations. |
| **Notification bell panel** | `mel-notification-bell.html.twig` | **Feature overlay** (`role="dialog"`, `aria-modal="false"`) | Dropdown-style panel; **not** wired through `mel_modal`; separate JS (`mel-notifications-ui.js`). |
| **Drupal core AJAX dialog** | `core/drupal.dialog.ajax` attached in venue/settings forms | **Drupal Form API / core** | Infra overlay for admin-ish flows — outside MEL shell but expected. |
| **AI escalation drawer markup** | `mel-ai-drawer` in escalations templates | **Feature-local overlay** | Mirrors modal/drawer semantics (overlay + panel) **without** `[data-mel-drawer]` binding. |

**Duplicate modal authority signals**

- Three conceptual “dialog owners”: **mel-modal**, **native `<dialog>`**, **popover-style `role="dialog"`** (notifications).
- Escalations **custom drawer CSS/JS** duplicates overlay-dismiss patterns already centralised in `mel-interactions.js` for `[data-mel-drawer]`.

---

## 3. Drawers — inventory

| Mechanism | Where | Ownership | Notes |
| --- | --- | --- | --- |
| **`mel_drawer`** | Surface module + `bindDrawer()` | **MELInteractionSystem** | Escape closes; overlay click closes (non–checkout-trust); `body.mel-drawer-open`. |
| **Mobile nav `details`-based drawer** | `mobile-drawer.twig`, SCSS `_mobile-drawer.scss` | **Theme component** | Native disclosure + `role="dialog"` on panel; **no** `[data-mel-drawer]`. |
| **Studio editor drawer** | `studio-drawer.js`, `mel-studio-shell` layout | **Event Studio shell** | Async fetch + `is-loading`; aria-hidden toggle; parallel loading pattern to `mel-async-state`. |
| **Escalations `mel-ai-drawer`** | Escalations module templates + bespoke attributes | **Feature-local** | Not using governed drawer contract. |

**Overlap:** At least **four** drawer-like implementations (mel-drawer, mobile details drawer, studio drawer, AI drawer).

---

## 4. Async and loading — inventory

| Pattern | Where | Owner | Notes |
| --- | --- | --- | --- |
| **`mel_loading_state` / component preprocess** | Surface | **MEL** | Message from PHP; preprocess sets structure. |
| **`mel-processing-state` / `mel-saving-state`** | Surface async templates | **MEL** | Spinner markup + semantics via helpers. |
| **Theme skeleton system** | `skeleton.js`, `mel-event-skeleton-block.html.twig`, views | **Public theme performance** | IntersectionObserver; distinct from governance async contracts. |
| **Card media skeleton** | `mel-card-media.js` | **Theme** | Image-load placeholder. |
| **Checkout / booking summary live region** | `myeventlane-event-book.html.twig` (`aria-live`) | **Commerce feature** | Justified live region for summary updates. |
| **Studio form `aria-live` on elements** | `EventStudioForm.php` inline attributes | **FAPI** | Duplicates pattern also present in governance inline templates (`EventStudioGovernanceComponentBuilder`). |
| **Support / check-in / RSVP result regions** | Various templates | **Feature** | Polite/assertive regions scattered. |

**Duplicate loading authority:** Skeleton (card/list) vs **MelAsyncStateHelper** contracts vs **feature-local spinners** (e.g. support “Thinking…” string in JS).

---

## 5. Disclosures — inventory

| Pattern | Where | Owner | Notes |
| --- | --- | --- | --- |
| **`mel-disclosure`** + `bindDisclosure()` | MEL interactions JS | **MELInteractionSystem** | Toggle + panel `hidden`; `aria-expanded`. |
| **`<details>` / `<summary>`** | Mobile drawer, intelligence panel “why”, core patterns | Mixed | Intelligence `details` is **product copy + operational payload** exposure point (see §7). |
| **Accordion-style (CSS-only or custom)** | Search filters (SCSS comments), possibly Radix-adjacent | Theme | Needs follow-up when hardening parity tooling. |

---

## 6. Accessibility consistency (interaction-specific)

**Aligned with MEL**

- Modal focus trap + restore on release (except checkout-trust first-focus-only path).
- Escape-to-close on mel-modal and mel-drawer (when visible).
- `MelInteractionAccessibilityHelper` / component helpers centralise live region roles for several contracts.

**Inconsistencies observed**

| Topic | Finding |
| --- | --- |
| **Focus restoration** | Native `<dialog>` close in vendor removal JS restores only if focus stayed inside dialog lifecycle; differs from mel-modal `releaseTrap` pattern. |
| **aria-modal** | Notification panel uses `role="dialog"` with `aria-modal="false"` (overlay menu pattern) vs mel-modal `aria-modal="true"` semantics. |
| **Drawer vs dialog** | Mobile drawer panel uses `role="dialog"` without mel-modal trapping; reliance on `<details>` behaviour. |
| **Live regions** | Many polite regions set in Twig/JS/helpers — risk of competing announcements without a single “async message” channel per surface. |
| **Reduced motion** | Not systematically audited in this pass; spinner/skeleton animations exist in vendor theme (`mel-skeleton` keyframes) — verify against `prefers-reduced-motion` in later step. |

---

## 7. Governance / observability leakage (public presentation)

**Evidence — must be filtered server-side before Twig (Step 3 target)**

`web/modules/custom/myeventlane_surface/templates/components/mel-intelligence-panel.html.twig` exposes:

- Translatable strings: **“Why you are seeing this”**, **“Signals evaluated”**.
- **`item.trigger_signal_keys`** rendered to the DOM for non-staff contexts when the negotiator attaches the panel — e.g. public `page.html.twig` and vendor governance stack includes `mel_intelligence_panel`.

**Staff-only debug**

- `mel-governance-debug.js` adds a body class; ensure library attachment stays **staff-gated** (verify `myeventlane_surface.libraries.yml` consumers).

**PHP-side operational payloads**

- `EventStudioGovernanceBuilder` computes `suppressionActiveIds` and related maps for Studio — confirm tier gating so JSON/attributes do not leak to unintended audiences.

---

## 8. Stale or parallel systems

| Item | Assessment |
| --- | --- |
| **drupal.dialog.ajax** | Active for specific forms — not stale; classify as infra. |
| **`confirm()` in Twig/js** | Stale UX pattern on staff payout pages; acceptable only if classified under “native destructive confirm”. |
| **Escalations AI drawer** | Parallel overlay implementation — convergence candidate toward `mel_drawer` contract or documented exception. |

---

## 9. Recommendation / onboarding wording drift (Step 4–5 precursors)

- **Vendor analytics guest path:** `VendorAnalyticsViewModelBuilder::emptyGuestModel()` defines guest copy (“Sign in to view analytics”). Align with shared **MelReadinessHelper** / experience vocabulary elsewhere (`VendorEventIndexViewModelBuilder::guestModel()` uses a parallel pattern).
- **Operational strings in Twig:** Intelligence panel mixes translatable operational labels (`Signals evaluated`) with data-driven fields — convergence should move **all** headline/CTA/prompt strings to governed PHP builders.

---

## 10. Risks called out for later steps

1. **Converge** native `<dialog>`, dropdown `role="dialog"`, and mel-modal behind explicit policy (destroy vs migrate).
2. **Sanitise** intelligence render arrays by surface tier **before** `mel_intelligence_panel` preprocess.
3. **Reduce** duplicate JS hydration (`once()` scopes multiply — audit attachment points in Step 7).
4. **Extend** `scripts/governance/template-parity-audit.php` / registry for interaction fragment drift (Step 8).

---

## File index (audit trail)

| Area | Representative paths |
| --- | --- |
| MEL JS | `web/modules/custom/myeventlane_surface/js/mel-interactions.js`, `mel-governance-debug.js` |
| MEL preprocess/helpers | `MelInteractionPreprocess.php`, `MelAsyncStateHelper.php`, `MelModalHelper.php`, `MelIntelligenceManager.php`, `MelObservabilityManager.php` |
| Intelligence UI | `templates/components/mel-intelligence-panel.html.twig`, `SurfaceNegotiator.php`, `page.html.twig` |
| Drawers | `templates/interactions/mel-drawer.html.twig`, `mobile-drawer.twig`, `studio-drawer.js`, escalations templates |
| Native dialog | `mel-event-card-removal-dialog.html.twig`, `mel-event-card-remove.js` |
| confirms | `myeventlane_venue/js/quick-create.js`, `event-wizard.js`, admin payout Twig |

---

**Next document:** [Canonical interaction ownership map](./mel-interaction-ownership-map.md) (Step 2).
