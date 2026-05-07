# MELFormSystem architecture

Drupal Form API remains the single submission, validation, CSRF, and Commerce pipeline. **MELFormSystem** is the product presentation layer: shared wrappers, classification, density, button hierarchy, and Twig partials—implemented in `myeventlane_surface` and styled in `myeventlane_theme`.

## 1. Architecture map

| Layer | Responsibility |
| --- | --- |
| `MelFormClassification` | Enum: `auth`, `customer`, `vendor`, `checkout`, `admin`, `support`. |
| `MelFormClassificationResolver` | Maps **route + path + `SurfaceResolver`** → classification (one place; no scattered route checks). |
| `MelFormManager` | `shouldGovernForms()` (public/vendor themes only; never Staff/admin shell) + `getClassification()`. |
| `MelFormHelper` | Canonical CSS class lists for `<form>`, form items, submit buttons. |
| `MelFormAccessibilityHelper` | Additive metadata for Twig (`mel_form_description_suffix`); core keeps label/error wiring. |
| `MelFormPreprocess` | `hook_preprocess_form`, `form_element`, `form_element_label`, `input__submit` + optional `#pre_render` bridge. |
| `MelFormRootPreRenderBridge` | `hook_element_info_alter()` → `#pre_render` on render element `form` (rare roots). |
| `SurfaceNegotiator` | Adds `mel_form_classification` to page variables when governance applies. |

## 2. Form classification map

| Classification | When |
| --- | --- |
| **admin** | `MelSurfaceId::Staff` (Gin/admin routes). Governance **off**; classification still `admin` if read while staff (page variable may be `NULL` when governance off). |
| **checkout** | Paths `/cart`, `/checkout`; routes prefixed `commerce_cart.`, `commerce_checkout.`, `commerce_payment.`, `commerce_order.`; route `myeventlane_commerce.event_book`. |
| **support** | Paths `/my/support`, `/vendor/support`; customer/vendor escalation portal route prefixes. |
| **auth** | Auth surface (`SurfaceResolver`). |
| **vendor** | Vendor surface. |
| **customer** | Customer surface or public marketing surface (friendly default). |

## 3. Shared component map (Twig)

Templates live in `web/modules/custom/myeventlane_surface/templates/forms/` and register via `hook_theme()`:

| Template | Role |
| --- | --- |
| `mel-form-wrapper.html.twig` | Page-level chrome + optional title/lede. |
| `mel-form-section.html.twig` | Card grouping / section body. |
| `mel-form-field.html.twig` | Manual field composition (optional; default remains core `form_element`). |
| `mel-form-actions.html.twig` | Action row alignment. |
| `mel-form-error-summary.html.twig` | `role="alert"` summary landmark. |
| `mel-form-help.html.twig` | Helper text aligned with `.description`. |
| `mel-form-status.html.twig` | SUCCESS / WARNING / ERROR / INFO / LOADING / DISABLED chips. |

Customer profile settings shell composes `mel-form-section` (replaces a one-off card wrapper only).

## 4. Removed duplication report

| Before | After |
| --- | --- |
| `_mel-surface-forms.scss` (narrow partial) | Merged into `_mel-forms.scss` + `_mel-form-states.scss` + `_mel-buttons.scss`; old partial **removed**. |
| Customer settings card-only markup | Uses shared `mel-form-section` + keeps header copy in shell template. |
| Ad hoc `.mel-form-system` width | Centralised in `_mel-forms.scss` with density + checkout sticky action band. |

**Not removed in this slice** (functional / high-churn; safe follow-ups): bespoke `form--*.html.twig` layouts (RSVP, vendor profile, auth card markup)—they continue to work; they now inherit governed field + button classes from preprocess.

## 5. Accessibility report

- **Preserved**: Core `form_element` / `form_element_label` structure, Drupal error messaging, existing `aria-*` from elements—preprocess only **adds** classes and optional suffix variables.
- **Added**: `mel-label` / `mel-label-required` on labels; `data-mel-form-classification` on `<form>`; submit buttons `data-mel-button-tier`; error summary template documents `role="alert"` + focus target (`tabindex="-1"`).
- **Targets**: `.mel-form-system` enforces **44×44px** minimum on actions via `_mel-buttons.scss`.
- **Risk**: Sticky checkout action bar may overlap very long pages—verify with real checkout layout + order summary panes.

## 6. Surface-aware behaviour map

| Surface / class | UX intent |
| --- | --- |
| `mel-form--auth` + density **minimal** | Calm, single-CTA friendly spacing. |
| `mel-form--customer` + density **friendly** | Card-friendly rhythm (settings, contact). |
| `mel-form--vendor` + density **comfortable** | Tighter vertical rhythm for operational flows. |
| `mel-form--checkout` + density **trust** | Larger stack + sticky `.form-actions` gradient band for payment trust layouts. |
| `mel-form--support` | Uses customer-friendly density unless overridden later. |

## 7. Commerce safety

- No changes to checkout panes, payment plugins, or submit handlers.
- Submit styling respects existing `.button--primary` / `.button--secondary` classes; `mel-btn-*` classes are **additive**.

## 8. File-by-file summary

**Module (`myeventlane_surface`)**

- `src/MelFormClassification.php` — enum.
- `src/MelFormClassificationResolver.php` — classification rules.
- `src/MelFormManager.php` — governance gate + facade.
- `src/MelFormHelper.php` — BEM-style class contracts.
- `src/MelFormAccessibilityHelper.php` — Twig-oriented metadata.
- `src/MelFormPreprocess.php` — preprocess implementations.
- `src/MelFormRootPreRenderBridge.php` — render bridge for `element_info_alter`.
- `src/SurfaceNegotiator.php` — exposes `mel_form_classification` on pages.
- `myeventlane_surface.module` — hooks + theme registrations.
- `myeventlane_surface.services.yml` — DI wiring.
- `templates/forms/*.html.twig` — seven shared partials.
- `templates/mel-surface-customer-profile-settings.html.twig` — uses `mel-form-section`.

**Theme (`myeventlane_theme`)**

- `src/scss/components/_mel-forms.scss` — layout, density, customer shell, checkout sticky band.
- `src/scss/components/_mel-form-states.scss` — shared status surfaces.
- `src/scss/components/_mel-buttons.scss` — destructive variant + governed action sizing.
- `src/scss/main.scss` — imports the three partials (replaces `_mel-surface-forms`).

## 9. Verification commands

From repo root (after code changes):

- `php -l` on new/changed PHP files
- `composer validate`
- `npm run mel:lint` && `npm run mel:build`
- `ddev drush cr` (or project equivalent)

Manual smoke tests are listed in the product checklist (auth, customer settings, vendor profile, checkout flows).
