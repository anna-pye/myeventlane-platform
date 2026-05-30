# Event Studio Branding Form Serialization Audit

**Date:** 2026-05-30  
**Route:** `/vendor/events/{node}/studio/branding`  
**Form:** `EventBrandingForm` (`myeventlane_event_studio_branding_form`)  
**Mode:** Audit-first; evidence from repository + DDEV runtime.

---

## Executive summary

Two **non-serializable instance-method callbacks** on `BrandingHeroFocalAugmenter` are attached to the branding hero field tree. When Drupal caches the form for AJAX (image upload, crop refresh, media library), serialization fails with `Serialization of 'Closure' is not allowed` (often surfaced in watchdog as *database connection is not serializable*). Contrib widgets (`image_widget_crop`, `media_library_widget`) use **serializable static** callbacks; only MEL branding focal augmenter breaks cache.

---

## Phase 1 — `#after_build` and related callback inventory

Runtime trace: `ddev drush php:eval` built `EventBrandingForm` for node 1381 as uid 1, walked `#after_build`, `#process`, `#element_validate`.

### 1. EventBrandingForm

| Property | Callback | Source | Line | Callable type | Serializable | Enters form cache |
|----------|----------|--------|------|---------------|--------------|-------------------|
| *(none attached directly)* | — | `EventBrandingForm.php` | — | — | — | — |

Form delegates hero widget to entity form display + `BrandingHeroFocalAugmenter::attachAfterBuild()` at line 271.

### 2. BrandingHeroFocalAugmenter (MEL — **FAIL**)

| Property | Callback | Source | Line | Callable type | Serializable | Enters form cache |
|----------|----------|--------|------|---------------|--------------|-------------------|
| `#after_build` | `[$this, 'afterBuildFieldFromElement']` | `BrandingHeroFocalAugmenter.php` | 56 | **Service instance method** | **NO** | **YES** — on `$form['mel']['field_event_image']` |
| `#element_validate` | `[$this, 'validateFocalPointElement']` | `BrandingHeroFocalAugmenter.php` | 141, 161 | **Service instance method** | **NO** | **YES** — on `$form['mel']['field_event_image']['widget'][0]['focal_point']` (injected in after_build) |

**Runtime proof:**

```
form/mel/field_event_image #after_build[0] instance BrandingHeroFocalAugmenter::afterBuildFieldFromElement serializable=no
form/mel/field_event_image/widget/0/focal_point #element_validate[0] instance BrandingHeroFocalAugmenter::validateFocalPointElement serializable=no
HERO_SERIALIZE=fail Serialization of 'Closure' is not allowed
FORM_SERIALIZE=fail Serialization of 'Closure' is not allowed
```

Comment at line 54 claims callables must be serializable; instance `[$this, …]` on an injected service violates that (service holds `TranslationInterface` / closures).

### 3. image_widget_crop (`ImageCropWidget`)

| Property | Callback | Source | Callable type | Serializable | Enters form cache |
|----------|----------|--------|---------------|--------------|-------------------|
| `#after_build` | `[ImageCropWidget::class, 'afterBuild']` | Contrib widget | **Static class method** | **YES** | YES — `$form['mel']['field_event_image']['widget']` |
| `#process` | `[ImageCropWidget::class, 'process']` | Contrib widget | Static | YES | YES — widget delta |
| `#element_validate` | `[ImageCropWidget::class, 'validateRequiredFields']` | Contrib widget | Static | YES | YES — alt field |

### 4. media_library_widget

| Property | Callback | Source | Callable type | Serializable | Enters form cache |
|----------|----------|--------|---------------|--------------|-------------------|
| `#process` / `#after_build` | Core/contrib static methods on `Submit`, `Hidden`, etc. | Media library field widget | Static / string | **YES** | YES — `$form['mel']['field_mel_event_gallery']` |

No non-serializable callbacks found on gallery widget path.

### 5. Branding preview (injected elements)

| Element | Callbacks | Serializable |
|---------|-----------|--------------|
| `$form['mel']['branding_preview']` | Render array from `EventBrandingPreviewBuilder::build()` — no `#after_build` | N/A |
| Focal preview `#attached` drupalSettings | No form callbacks | N/A |

Preview panel does not add form cache callbacks.

### Other form-level callbacks (all serializable)

- `conditional_fields_element_after_build` / `conditional_fields_form_after_build` — string
- Core `#process` — static class methods (`Container`, `ManagedFile`, `Radios`, etc.)
- `EventStudioMelOptionCards::processRadios` — static

---

## Phase 2 — Root cause confirmation

### A. Does `[$this, 'afterBuildFieldFromElement']` survive form cache serialization?

**NO.**

Evidence: runtime `serialize($callback)` fails; `serialize($form['mel']['field_event_image'])` fails.

### B. Does it become part of `$form` or `$form_state` before AJAX rebuilds?

**YES — `$form`.**

Drupal `FormBuilder` stores the built form array (including `#after_build` on elements) in form cache when `$form_state->setCached(TRUE)` (AJAX/file upload paths). Callbacks live on `$form['mel']['field_event_image']`, not `$form_state`.

### C. Can the exact watchdog error be reproduced from repository logic?

**YES.**

- Watchdog (2026-05-30): *The database connection is not serializable…*
- Runtime: *Serialization of 'Closure' is not allowed* when serializing the augmenter callback/service graph.
- Pattern: first full page load succeeds (no cache write); first AJAX/crop/media interaction triggers cache → section render failure → unavailable empty state.

---

## Phase 3 — Correct Drupal 11 pattern (audit only)

| Option | MEL precedent | Survives cache | AJAX-safe | Diff size |
|--------|---------------|----------------|-----------|-----------|
| **A. Static class callback** | `EventWizardBaseForm::ensureFieldAttributes` (`myeventlane_event.module:447`) | Yes | Yes | **Smallest** — one file |
| B. Module-level callback | `myeventlane_commerce_payment_information_after_build` | Yes | Yes | Small — `.module` + service |
| C. Container callback | Not used in MEL for forms | Yes | Yes | Larger |
| D. Existing MEL pattern | **A + B both proven** | Yes | Yes | — |

**Recommendation:** **Option A** — add `public static` wrappers on `BrandingHeroFocalAugmenter` that resolve the service from the container and delegate to existing instance methods. Replace `[$this, …]` with `[self::class, 'formAfterBuild…']` and `[self::class, 'formValidate…']`.

**Constraints honored:** no logic, UX, CSS, image style, gallery, preview, save, or autosave changes — callback references only.

---

## Phase 4 — Implementation note

Proceed only after Phase 2 — **confirmed above**.

---

## References

- `web/modules/custom/myeventlane_event_studio/src/Service/BrandingHeroFocalAugmenter.php:50-57, 141, 161`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventBrandingForm.php:271`
- `web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioController.php:238-253`
- `config/sync/core.entity_form_display.node.event.studio_branding.yml`
- MEL pattern: `web/modules/custom/myeventlane_event/myeventlane_event.module:447`
- MEL pattern: `web/modules/custom/myeventlane_commerce/myeventlane_commerce.module:691`
