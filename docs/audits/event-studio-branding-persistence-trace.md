# Event Studio Branding — Persistence Trace Audit

**Date:** 2026-05-30  
**Branch target:** `audit/event-studio-file-upload-persistence`  
**Test node:** 1381  
**Mode:** Audit-only. Temporary watchdog instrumentation added and removed. No config changes.

---

## Executive summary

Upload **does not persist by itself**. Persistence requires a successful **Save branding** submit that passes **Form API validation** before `saveBrandingHero()` / `saveBrandingGalleryField()` run.

**Primary failure layer (hero):** Required **`event_hero` crop validation** runs **before** `submitContinue()` → `saveBrandingHero()`. When a file is uploaded but crop is not applied, validation fails, **save never fires**, and `field_event_image` stays empty.

**Gallery:** Build/save structure **matches**. Gallery extraction and node assignment work when save completes (runtime proof). If hero upload is present without required crop, **the entire form submit is blocked**, so gallery also fails to persist in the same request.

**Build vs save structure:** No mismatch found for either field.

---

## Phase 1 — Hero save call chain

```text
EventBrandingForm
  actions.continue #submit → EventStudioBaseForm::submitContinue() [L359]
    → EventBrandingForm::persistWizardMel() [L102-120]
      → EventStudioSaveService::saveBrandingHero($node, $merged, $form_state, $draft) [L948]
        → $mel_values = $form_state->getValue('mel')
        → $mel_structure = $form_state->getCompleteForm()['mel']
        → $widget->extractFormValues($items, $mel_structure, $form_state) [L975]
        → fallback: EventStudioMelPayloadService::normalizeHeroFromMelFragment($mel_values) [L986]
        → if fid: heroFileIsRenderable() gate [L1003]
        → $node->set('field_event_image', …) [L1039]
        → saveBrandingGalleryField() [L1046]
        → $node->save() [L1058]
```

### Expected form keys (hero)

| Key | Source | Purpose |
|-----|--------|---------|
| `mel[field_event_image][0][fids]` | image_widget_crop / managed_file | File id(s) |
| `mel[field_event_image][0][image_crop][crop_wrapper][event_hero][crop_container][values][*]` | image_widget_crop | Crop coordinates + `crop_applied` |
| `mel[field_event_image_alt]` | EventBrandingForm textfield | Alt (required when fid present and `$draft === FALSE`) |
| `mel[field_event_image][0][focal_point]` | BrandingHeroFocalAugmenter | Focal point (optional on save enrich) |

### Expected `$mel_values['field_event_image']` structure (runtime, successful save)

```json
[{"fids":["454"],"display":1,"focal_point":"50,50"}]
```

### `extractFormValues()` output (runtime, successful save)

```json
{"display":1,"focal_point":"50,50","target_id":"454"}
```

### Validation that prevents assignment

| Validator | When | Effect |
|-----------|------|--------|
| `ImageCrop::cropRequired()` | Form validate, before submit handlers | Blocks submit if `event_hero` crop not applied when file present |
| `EventStudioBaseForm::validateForm()` | Stale revision check | Blocks submit |
| `EventBrandingForm::validateForm()` | Page style/colour | Blocks submit |
| `saveBrandingHero()` alt check | During save, `$draft === FALSE` only | Returns error, no node save |
| `heroFileIsRenderable()` | During save | Clears field if file missing on disk |

**Crop occurs before node assignment:** `cropRequired()` is `#element_validate` on crop element during **form validation**. `saveBrandingHero()` never runs when validation fails.

---

## Phase 2 — Gallery save call chain

```text
EventBrandingForm::buildBrandingGalleryField() [L393-434]
  → media_library_widget->form() at mel[field_mel_event_gallery]

saveBrandingHero() [L1046]
  → saveBrandingGalleryField($node, $mel_structure, $form_state) [L1078]
    → if !isset($mel_structure['field_mel_event_gallery']) return [] [L1093]
    → $widget->extractFormValues($items, $mel_structure, $form_state) [L1099]
    → $node->set('field_mel_event_gallery', $items->getValue()) [L1100]
```

### Expected `$mel_values['field_mel_event_gallery']` (runtime)

```json
{"selection":[{"target_id":"10","weight":0}]}
```

### Extraction result (runtime)

```json
[{"target_id":"10","weight":0}]
```

Widget extraction **is reached** and **produces items** when save completes.

---

## Phase 3 — Build vs save comparison

| Field | Build structure | Save expectation | Match? |
|-------|-----------------|----------------|--------|
| `field_event_image` | `mel/field_event_image/widget/0` parents `["mel","field_event_image",0]`; inputs `mel[field_event_image][0][fids]`, crop under `…[image_crop][crop_wrapper][event_hero]…` | `$mel_structure['field_event_image']` passed to `extractFormValues()`; fallback `normalizeHeroFromMelFragment($mel_values)` | **YES** |
| `field_mel_event_gallery` | `mel/field_mel_event_gallery/widget` with `selection`, `media_library_selection`; input `mel[field_mel_event_gallery][media_library_selection]` | `$mel_structure['field_mel_event_gallery']`; `$mel_values['field_mel_event_gallery']['selection']` | **YES** |

No branding-wrapper `#parents` drift. `mel['#tree'] = TRUE` and `mel['#parents'] = ['mel']` align with save service reading `$form_state->getValue('mel')` and `$form_state->getCompleteForm()['mel']`.

---

## Phase 4 — Runtime submitted values (event 1381)

### Save attempt WITHOUT crop (failed validation)

- HTTP POST with `mel[field_event_image][0][fids]=454`, alt, page style
- Response: crop wrapper `class="error"`, `aria-invalid="true"`, “1 error has been found”
- **No** `AUDIT submitContinue` / `AUDIT saveBrandingHero` watchdog entries
- `node__field_event_image`: empty

### Save attempt WITH crop (validation passed)

Watchdog (temporary instrumentation, since removed):

| WID | Payload summary |
|-----|-----------------|
| 931503 | `mel_keys=field_event_image,field_event_image_alt,field_mel_page_style,field_mel_theme_colour,field_mel_event_gallery`; hero=`[{"fids":["454"],"display":1,"focal_point":"50,50"}]`; gallery selection media 10; `draft=1` |
| 931504 | post-extract `fid=454`, `items_empty=0`, extract `target_id=454` |
| 931505 | gallery items `[{"target_id":"10","weight":0}]` |

After successful save:

```sql
node__field_event_image: entity_id=1381, target_id=454
node__field_mel_event_gallery: entity_id=1381, target_id=10
file_managed.fid=454 status=1 (permanent)
file_usage: fid=454 module=file type=node id=1381 count=1
```

**Note:** This HTTP repro wrote test data to node 1381 as audit evidence only.

---

## Phase 5 — Crop validation (`event_hero`)

| Item | Evidence |
|------|----------|
| Required crop type | `event_hero` — `core.entity_form_display.node.event.studio_branding.yml` L83-84 `crop_types_required: [event_hero]` |
| Crop type config | `config/sync/crop.type.event_hero.yml` aspect `1200:630` |
| Validation location | `image_widget_crop` `ImageCrop::cropRequired()` — runs on form validate |
| Before/after save | **Before** — failed validation prevents `submitContinue()` |
| Clears image? | Does not clear; **prevents save** from running |
| File entity without field | **YES** — fid 454 existed (status 0) while `field_event_image` empty until successful save |

---

## Phase 6 — Root cause ranking (evidence only)

| Rank | Cause | Hero | Gallery | Evidence |
|------|-------|------|---------|----------|
| **1** | **Save never fires** — required `event_hero` crop validation fails when file uploaded without crop | **YES** | **YES** (same submit) | No AUDIT logs without crop; crop error in HTML; persistence succeeds with crop coords |
| **2** | Upload alone does not persist (by design) | **YES** | **YES** | Temp fid 454 existed with empty node field until save |
| **3** | `heroFileIsRenderable()` clears broken files on save | **YES** (historical) | N/A | fid 271 missing on disk; prior audit |
| **4** | Build/save structure mismatch | **NO** | **NO** | Form dump + successful extract |
| **5** | Gallery widget extraction broken | **NO** | **NO** | WID 931505 items populated |

### Shared cause?

**Partially shared:** Hero crop validation failure **blocks the entire form submit**, so gallery cannot persist in the same request when hero has an uncropped upload. Gallery-only changes can persist if hero field has no uploaded file triggering crop validation (not re-tested in this run).

---

## Smallest safe future fix target (no implementation)

| Priority | Target | Rationale |
|----------|--------|-----------|
| **1 (UX/product)** | Crop completion gate before Save branding | Users upload successfully but skip crop; validation silently blocks persistence |
| **2 (optional code)** | `EventBrandingForm::validateForm()` or user-facing message when crop pending | Surface crop requirement explicitly (contrib validates in `ImageCrop::cropRequired`) |
| **Not needed** | `saveBrandingHero()` extract path | Works when validation passes |
| **Not needed** | `saveBrandingGalleryField()` | Works when validation passes |

---

## Validation (audit run)

| Command | Result |
|---------|--------|
| Audit instrumentation removed | Verified — `EventStudioSaveService.php`, `EventStudioBaseForm.php` clean of AUDIT logs |
| `composer validate` | OK |
| PHP lint event_studio | OK |
| `ddev drush cr` | OK |
| `ddev drush config:status` | No drift |
