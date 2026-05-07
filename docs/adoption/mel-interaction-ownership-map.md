# Canonical interaction ownership map (Step 2)

This map is the **target authority model** for the convergence phase. It does not replace code by itself; it governs future changes and cleanup (Steps 3–12).

---

## MELInteractionSystem — owns

| Concern | Implementation surface |
| --- | --- |
| **Modal shells** | `mel_modal`, `mel_modal_confirmation`, preprocess + `MelModalHelper`, `mel-interactions.js` (`[data-mel-modal]`) |
| **Governed overlays** | Shared overlay/panel semantics, checkout-trust profile behaviour, scroll lock tokens |
| **Loading / async presentation** | `mel_loading_state`, `mel_processing_state`, `mel_saving_state`, `MelAsyncStateHelper`, `MelInteractionAccessibilityHelper` live-region defaults |
| **Drawers** | `mel_drawer` + `bindDrawer()` (`[data-mel-drawer]`) |
| **Governed disclosures** | `mel-disclosure` pattern + `bindDisclosure()` |
| **Progressive aria-live** | `[data-mel-state-live]` hydration in `mel-interactions.js` |

**Compose only:** Feature modules supply **payloads** (titles, bodies, CTAs, IDs) via render arrays — not parallel shell markup.

---

## Native `<dialog>` and `confirm()` — allowed only for

| Use | Condition |
| --- | --- |
| **`<dialog>` + `showModal()`** | Small, destructive, accessibility-reviewed flows where MEL modal is unnecessary overhead — must document contract ID or exception reference. |
| **`window.confirm()` / inline confirm** | **Lightweight destructive confirms** or **staff bulk operations** with explicit security/access review — not for customer/checkout flows unless product exception. |

**Not allowed:** New feature modals/loaders that bypass MEL shells without an approved exception recorded in governance docs.

---

## Drupal / core overlays — infra

| Mechanism | Role |
| --- | --- |
| **Form API AJAX + `drupal.dialog.ajax`** | Admin/configuration flows remain infra; do not duplicate MEL modal for the same UX goal. |

---

## Theme components — allowed parallel patterns with boundaries

| Component | Boundary |
| --- | --- |
| **Mobile `details`-based drawer** | Public nav pattern; must not duplicate **vendor/event-studio** drawer semantics — consider wrapping or delegating open/close behaviours to MELDrawer contract when feasible. |
| **List/card skeletons** | Performance-only; must not impersonate governance “saving/processing” states. |

---

## Feature modules — may / may not

**May**

- Compose **interaction payloads** (render arrays, `#theme`, messages, `#attached` libraries that call MEL APIs).
- Add **surface-appropriate** `aria-live` on feature-specific outcome regions when no MEL contract fits — prefer extending `MelComponentAccessibilityHelper` first.

**May not**

- Fork **modal** infrastructure (overlay + dialog panel + bespoke JS trap) without exception.
- Fork **loading** infrastructure (duplicate spinners for the same semantic “saving/order update”) without extending MEL contracts.
- Stack **multiple overlay systems** for one user intent (pick one authority per flow).

---

## Presentation boundaries (interaction-adjacent)

| System | Responsibility |
| --- | --- |
| **MELStateSystem / Workflow / Experience** | Operational prompts, continuity, onboarding nudge **wording contracts** fed as data — not hardcoded operational copy in Twig. |
| **DataPresentation / sanitisation layer** | Strip governance metadata, suppression traces, signal keys, and explainability strings **before** render for public/customer/vendor tiers as required by policy. |

---

## Escalations / notifications / bells

| Surface | Ownership |
| --- | --- |
| **mel-notifications** dropdown panel | Functional module UI — should align **keyboard, focus escape, aria** with MEL interaction accessibility checklist; convergence option: reuse drawer semantics or document as **menu + dialog hybrid**. |
| **Escalations AI drawer** | Convergence candidate toward `mel_drawer` or explicit “feature exception” entry. |

---

**Related:** [Interaction authority audit](./mel-interaction-authority-audit.md).
