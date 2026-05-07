# MELComponentSystem (canonical UI components)

Drupal stays infrastructure (routes, entities, Commerce render arrays). **`myeventlane_surface`** owns **MELComponentSystem**: contracts, preprocess normalisation, accessibility helpers, shell navigation metadata, and shared Twig partials under `templates/components/`.

## 1. Architecture map

| Layer | Responsibility |
| --- | --- |
| **MelComponentRegistry** | Documents machine names, categories, surface affinity (hints). |
| **MelComponentManager** | Surface-aware density, card/dashboard modifiers, cache metadata helper. |
| **MelComponentPreprocess** | Classes, `data-mel-*` hooks, variant routing for governed theme hooks. |
| **MelComponentAccessibilityHelper** | Landmarks, alert vs status roles, loading live regions, `aria-labelledby` panels. |
| **MelNavigationManager** | Shell navigation **contracts** (classes + landmark labels), not link ACL. |
| **SurfaceNegotiator** | Publishes `mel_component_context`, `mel_navigation_contract`, existing surface keys. |

## 2. Component registry map

Registry keys mirror theme hooks (`mel_card`, `mel_shell_nav`, …). Categories: **card**, **status**, **cta**, **navigation**, **onboarding**.

## 3. Surface-aware behaviour map

| Surface | Density | Card modifiers (representative) |
| --- | --- | --- |
| **PublicSurface** | comfortable | `mel-card--hover`, `mel-card--governed` |
| **AuthSurface** | compact | `mel-card--compact`, `mel-card--bordered` |
| **CustomerShell** | comfortable | `mel-card--surface`, `mel-card--governed` |
| **VendorShell** | dense | `mel-card--compact`, `mel-card--governed` |

HTML documents already expose `data-mel-surface` from `SurfaceNegotiator`; components add `data-mel-component` and `data-mel-density` for SCSS.

## 4. Navigation governance map

| Contract variant | Scope |
| --- | --- |
| **customer_shell** | Customer account sidebar — integrated with `mel_shell_nav` + legacy BEM aliases (`mel-my-account__*`). |
| **vendor_shell** | Vendor console — structural classes for operational nav; routes unchanged. |
| **auth_shell** | Minimal chrome nav metadata. |
| **public_discovery** | Default / non-shell discovery. |

Link **URLs and permissions** remain in **`AccountLinksService`** and route access — contracts carry **presentation only**.

## 5. Empty-state governance map

**`mel_empty_state`** requires copy slots: **what_happened**, **why_empty**, **next_action**, optional **cta**, optional **illustration**. Prefer this theme hook over ad hoc “no results” markup in MEL surfaces.

## 6. Status governance map

| Hook | Role |
| --- | --- |
| **mel_status_panel** | Persistent panel; variants share one Twig partial. |
| **mel_alert** | Inline feedback; error → `role="alert"` + assertive live region; others → `role="status"`. |
| **mel_loading_state** | `role="status"`, `aria-busy="true"`. |
| **mel_form_status** | Existing MEL form system (unchanged). |

Use **`#theme` => `mel_alert`** / panels to wrap Drupal messages in governed markup when building custom render arrays (do not bypass Core message pipeline where it already owns the flow).

## 7. Duplication cleanup report

| Item | Status |
| --- | --- |
| Customer account sidebar markup | **Consolidated** to `mel_shell_nav` render array (`mel_account_shell_nav`) with fallback to legacy Twig loop. |
| Status variant panels | **Consolidated** to single `mel-status-panel.html.twig` template. |
| `.mel-card` visual baseline | **Reused** from existing `_cards.scss`; governed modifiers in `_mel-cards.scss`. |
| Contextual help / confirmation / event cards | **Deferred** — migrate incrementally to `#theme` => `mel_card` / `mel_empty_state` per screen (avoid big-bang template churn). |

## 8. Accessibility report

- Shell nav: `<nav>` + `aria-label` from contract; current link `aria-current="page"`.
- Alerts: error uses assertive live region; non-error uses `status` + polite.
- Loading: `aria-busy` + polite live region.
- Status panels: optional `aria-labelledby` when `heading_id` provided.
- Focus: shared focus ring tokens in shell nav links; component wrapper includes `:focus-visible` fallback.

Target: **WCAG 2.1 AA** — patterns are additive; consuming templates must preserve heading order.

## 9. File-by-file implementation summary

**PHP (`myeventlane_surface`)**

- `src/ComponentDefinition.php` — immutable registry row.
- `src/MelComponentRegistry.php` — component catalogue.
- `src/MelComponentManager.php` — surface behaviour + cache helper.
- `src/MelComponentAccessibilityHelper.php` — ARIA helpers.
- `src/MelComponentPreprocess.php` — preprocess routing.
- `src/MelNavigationManager.php` — navigation contracts.
- `src/SurfaceNegotiator.php` — attaches `mel_component_context`, `mel_navigation_contract`.
- `myeventlane_surface.services.yml` — service wiring.
- `myeventlane_surface.module` — `hook_theme`, preprocess bridges.

**Twig**

- `templates/components/*.html.twig` — governed partials (cards, status, CTA, shell, onboarding).

**Theme SCSS (`myeventlane_theme`)**

- `components/_mel-components.scss`, `_mel-cards.scss`, `_mel-navigation.scss`, `_mel-empty-states.scss`, `_mel-status-panels.scss`, `_mel-cta.scss` — imported from `main.scss`.

**Vendor theme**

- `myeventlane_vendor_theme/src/scss/main.scss` — loads the same MEL partials from the public theme path.

**Integration**

- `myeventlane_account.module` — builds `mel_account_shell_nav`.
- `page--account.html.twig` — renders governed nav + legacy fallback.

## 10. Commerce / security notes

- No checkout plugin or gateway changes.
- No entity access changes; contracts do not emit privileged IDs.
- Vendor isolation unchanged — navigation lists still produced only where existing services allow.
