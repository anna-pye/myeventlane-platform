# Event Studio Branding — Media Library AJAX Audit

**Date:** 2026-05-30  
**Branch (requested):** `audit/event-studio-branding-media-library-ajax`  
**Actual branch at audit time:** `fix/event-studio-branding-workspace-parity` (clean except audit artifacts)  
**Route:** `myeventlane_event_studio.workspace_branding`  
**URL:** `/vendor/events/{node}/studio/branding`  
**Test node:** 1381 (RSVP Test 2)  
**Mode:** Audit-only — no behavior changes, no commits.

---

## Executive summary

Media Library, hero upload, and crop AJAX on Event Studio workspace routes fail because **`EventStudioController::buildWorkspaceSectionContent()` catches Drupal’s intentional `FormAjaxException`** (empty message by design) and returns the governed “unavailable” empty state instead of letting `FormAjaxSubscriber` build a JSON AJAX response.

This is a **workspace wrapper bug (root cause E)**, not route-parameter loss, not a Media Library widget defect, and not the serialization issue already fixed in `BrandingHeroFocalAugmenter`.

Secondary UX gaps on node 1381 (missing hero thumbnail / branding preview image) are largely **local data integrity** (file entity 271 exists; physical file missing under `public://events/2026-03/`).

---

## Phase 1 — Swallowed exception

### Failure handler (repository)

```238:253:web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioController.php
  private function buildWorkspaceSectionContent(
    EventStudioSectionInterface $sectionPlugin,
    NodeInterface $node,
  ): array {
    try {
      return $this->sectionRenderer->build($sectionPlugin, $node);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Event Studio section render failed for event @eid section @section target @target: @message', [
        '@eid' => (string) $node->id(),
        '@section' => (string) $sectionPlugin->getPluginId(),
        '@target' => $sectionPlugin->renderTarget(),
        '@message' => $exception->getMessage(),
      ]);
      return $this->emptyStateBuilder->unavailableSection($sectionPlugin->title());
    }
  }
```

**Problem:** logs only `$exception->getMessage()`. Several Drupal form AJAX control-flow exceptions use an **empty default message**.

### Exception class (confirmed via runtime + core contract)

| Property | Value |
|----------|--------|
| **Class** | `Drupal\Core\Form\FormAjaxException` |
| **File** | `web/core/lib/Drupal/Core/Form/FormAjaxException.php` |
| **Thrown from** | `web/core/lib/Drupal/Core/Form/FormBuilder.php` line **366** |
| **Default message** | `""` (empty string) |
| **Previous exception** | none (control-flow exception, not a failure wrapper) |

Core throw site (after successful AJAX element processing):

```php
if ($ajax_form_request && $form_state->isProcessingInput() && $request->get('form_id') == $form_id) {
  throw new FormAjaxException($form, $form_state);
}
```

Intended handler: `FormAjaxSubscriber::onException()` → `FormAjaxResponseBuilder::buildResponse()` → JSON `AjaxResponse` with `OpenModalDialogCommand` for Media Library.

**Watchdog correlation (node 1381):**

| WID | Time | Severity | Notes |
|-----|------|----------|-------|
| 930904 | 13:17:48 | Error | `Event Studio section render failed … @message` **empty** — curl AJAX repro |
| 930741 | 13:13:48 | Error | Same pattern — browser “Add media” click |
| 930734–930738 | 13:09–13:11 | Error | Repeated empty-message errors during manual testing |

Debug entry immediately after 930904:

- `route=myeventlane_event_studio.workspace_branding`
- `event_id=1381`
- `section=branding`
- `section_content_keys` include empty-state theme keys (not form keys)

### Other empty-message exceptions (ruled out for happy-path Add media)

| Class | Empty message? | When |
|-------|----------------|------|
| `Drupal\Core\Form\Exception\BrokenPostRequestException` | yes | `ajax_form=1` but POST body missing `form_id` (broken/large upload) |
| `Symfony\Component\HttpKernel\Exception\NotFoundHttpException` | yes | No route `node` and no `$parameter_node` in `EventStudioBaseForm::getRouteNode()` |

These produce the same empty `@message` in watchdog but are **not** the Add media failure when POST includes valid `form_id`, `form_build_id`, and `form_token` (verified via curl).

### Context available at failure

| Context | Available? | Evidence |
|---------|------------|----------|
| Route name | yes | `_route=myeventlane_event_studio.workspace_branding` in debug logs |
| Node | yes | `{node}` route parameter; `$node` passed to `sectionRenderer->build()` |
| Section | yes | Route default `section=branding`; plugin `BrandingSection` |
| `form_build_id` | yes | Present in POST (curl extracted from prior GET) |
| AJAX URL | yes | `POST /vendor/events/1381/studio/branding?ajax_form=1&_wrapper_format=drupal_ajax` |

---

## Phase 2 — Media Library AJAX execution flow

### 1. User action

Click **Add media** on gallery field `field_mel_event_gallery` inside `#myeventlane-event-studio-branding-form`.

Widget config: `config/sync/core.entity_form_display.node.event.studio_branding.yml` → `media_library_widget`.

### 2. Request

| Item | Value |
|------|--------|
| **Method** | POST |
| **URL** | `/vendor/events/1381/studio/branding?ajax_form=1&_wrapper_format=drupal_ajax` |
| **Route** | `myeventlane_event_studio.workspace_branding` |
| **Controller** | `EventStudioController::workspace()` |
| **Triggering element** | `field_mel_event_gallery-media-library-open-button-mel` |
| **form_id** | `myeventlane_event_studio_branding_form` |

### 3. Server path

```
EventStudioController::workspace($node, section=branding)
  → buildWorkspaceSectionContent($sectionPlugin, $node)   ← catch (\Throwable) HERE
    → EventStudioSectionRenderer::build($section, $node)
      → formBuilder->getForm(EventBrandingForm::class, $node)
        → EventBrandingForm builds gallery via entity form display + media_library_widget
        → MediaLibraryWidget open_button #ajax callback: openMediaLibrary()
        → FormBuilder::processForm()
        → throw FormAjaxException($form, $form_state)     ← SHOULD propagate
  ✗ caught → unavailableSection() → HTML empty state
```

Core widget AJAX callback (returns modal command, does not navigate away):

```php
public static function openMediaLibrary(array $form, FormStateInterface $form_state) {
  $library_ui = \Drupal::service('media_library.ui_builder')->buildUi(...);
  return (new AjaxResponse())
    ->addCommand(new OpenModalDialogCommand(...));
}
```

Source: `web/core/modules/media_library/src/Plugin/Field/FieldWidget/MediaLibraryWidget.php` (~line 818).

### 4. Intended AJAX response path (blocked)

```
FormAjaxException
  → FormAjaxSubscriber::onException()
  → FormAjaxResponseBuilder::buildResponse()
  → HTTP 200, Content-Type: application/json (drupal-ajax commands array)
```

### 5. Actual response path (observed)

```
FormAjaxException caught in buildWorkspaceSectionContent()
  → EventStudioController continues workspace render
  → HTTP 200, Content-Type: text/html; charset=UTF-8
  → Full workspace page HTML containing mel_event_studio_empty_state--unavailable
  → Browser: “Oops, something went wrong…” (Drupal AJAX expects JSON)
```

### 6. Route parameters

| Parameter | Lost on AJAX? | Evidence |
|-----------|---------------|----------|
| `{node}` | **No** | URL retains nid; route param converter runs |
| `section` | **No** | Route default `branding` |
| Route match | **No** | Debug logs show correct route on AJAX requests |

Route definition: `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml` lines 52–64.

---

## Phase 3 — Questionnaire

| ID | Question | Answer | Evidence |
|----|----------|--------|----------|
| **A** | Does Media Library AJAX rebuild the form? | **YES** | `ajax_form=1` POST to branding URL; `FormBuilder` processes input and rebuilds |
| **B** | Does rebuild lose node / section / routeMatch? | **NO** | Route params present; `$node` passed as `getForm()` second argument |
| **C** | Does `EventBrandingForm` depend on route context unavailable during AJAX? | **NO** (for this failure) | `getRouteNode($parameter_node)` succeeds when controller passes `$node`; hidden `nid` exists but is not the failure point |
| **D** | Does `media_library_widget` work outside Event Studio? | **YES** (by architecture + core tests; not browser-verified in this audit) | Commerce product forms use same widget on standard entity edit routes (`core.entity_form_display.commerce_product.operational_bundle.default.yml`); core FunctionalJavascript tests in `web/core/modules/media_library/tests/`; those routes are **not** wrapped by `buildWorkspaceSectionContent()` |
| **E** | Does `image_widget_crop` AJAX work independently? | **Not independently on workspace routes** | Hero field uses `image_widget_crop` on same form display; same AJAX POST path and same `catch (\Throwable)` wrapper — upload/crop AJAX is subject to the same swallowing |

---

## Phase 4 — Runtime verification

### Browser automation (Cursor IDE browser)

- Attempted vendor one-time login URLs → **Access denied** (link/session constraints in automation environment).
- Prior manual session evidence (same audit thread): Add media → generic Drupal AJAX error; no `role=dialog` modal.

### curl reproduction (confirmed this audit)

```bash
# Login as uid 1, fetch branding form, POST Add media AJAX
LOGIN=$(ddev drush user:login --uid=1 --uri=https://vendor.myeventlane.ddev.site | tail -1)
curl -sL -c /tmp/mel-audit-cookies.txt "$LOGIN" -o /dev/null
curl -sL -b /tmp/mel-audit-cookies.txt \
  'https://vendor.myeventlane.ddev.site/vendor/events/1381/studio/branding' \
  -o /tmp/mel-branding.html
# Extract form_build_id + form_token, then:
curl -sL -b /tmp/mel-audit-cookies.txt -X POST \
  'https://vendor.myeventlane.ddev.site/vendor/events/1381/studio/branding?ajax_form=1&_wrapper_format=drupal_ajax' \
  -H 'X-Requested-With: XMLHttpRequest' \
  --data-urlencode 'form_id=myeventlane_event_studio_branding_form' \
  --data-urlencode "form_build_id=<from GET>" \
  --data-urlencode "form_token=<from GET>" \
  --data-urlencode 'field_mel_event_gallery-media-library-open-button-mel=Add media'
```

**Results:**

| Check | Result |
|-------|--------|
| HTTP status | 200 |
| Content-Type | `text/html; charset=UTF-8` (**wrong** — expected JSON) |
| Body | Full HTML workspace page |
| Contains | `mel_event_studio_empty_state--unavailable` |
| Watchdog | WID **930904** — empty `@message` error for event 1381 branding |

### Hero thumbnail / preview (node 1381 — separate from AJAX)

- `field_event_image` empty on node 1381 at audit time.
- File 271 referenced historically; physical file missing locally.
- Branding page **renders**; missing thumbnails/previews are consistent with **missing source files**, not AJAX alone.

---

## Phase 5 — Root cause

**Primary: E — Workspace wrapper bug**

`EventStudioController::buildWorkspaceSectionContent()` uses `catch (\Throwable)` for section resilience but **incorrectly treats `FormAjaxException` as a render failure**. That exception is Drupal core’s normal mechanism to short-circuit HTML rendering and return AJAX commands.

**Not primary:**

| Option | Verdict |
|--------|---------|
| A Route context loss | Ruled out — params present |
| B Missing node parameter | Ruled out — `$node` passed through |
| C Missing form cache data | Partial — outdated cache yields different exceptions; valid same-session POST still fails due to swallowing |
| D Media Library integration bug | Ruled out — core widget + config correct |
| F Something else | Hero/preview gaps on 1381 = missing files (data), not this bug |

---

## Phase 6 — Validation (audit run)

| Command | Result |
|---------|--------|
| `git status -sb` | Clean on `fix/event-studio-branding-workspace-parity`; audit doc + temp scripts untracked |
| `composer validate` | OK |
| `php -l` on `myeventlane_event_studio/src/**/*.php` | OK |
| `ddev drush cr` | OK |
| `ddev drush config:status` | No differences between DB and sync |

---

## Deliverables

### 1. Exact exception class

`Drupal\Core\Form\FormAjaxException`

### 2. Exact failing route

`myeventlane_event_studio.workspace_branding`

### 3. Exact failing request

```
POST /vendor/events/1381/studio/branding?ajax_form=1&_wrapper_format=drupal_ajax
Content-Type: application/x-www-form-urlencoded
Body includes: form_id=myeventlane_event_studio_branding_form, form_build_id, form_token,
               field_mel_event_gallery-media-library-open-button-mel=Add media
```

### 4. Root cause

Workspace section wrapper catches and suppresses `FormAjaxException`, preventing `FormAjaxSubscriber` from returning drupal-ajax JSON. Media Library modal never opens; hero upload/crop AJAX on the same form path fails the same way.

### 5. Files involved

| File | Role |
|------|------|
| `web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioController.php` | `buildWorkspaceSectionContent()` — **bug location** |
| `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSectionRenderer.php` | Embeds form via `formBuilder->getForm()` |
| `web/modules/custom/myeventlane_event_studio/src/Form/EventBrandingForm.php` | Branding form; gallery + hero fields |
| `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioBaseForm.php` | Route node resolution (not this failure) |
| `config/sync/core.entity_form_display.node.event.studio_branding.yml` | `media_library_widget` + `image_widget_crop` |
| `web/core/lib/Drupal/Core/Form/FormBuilder.php` | Throws `FormAjaxException` |
| `web/core/lib/Drupal/Core/Form/EventSubscriber/FormAjaxSubscriber.php` | Intended AJAX response handler |
| `web/core/modules/media_library/.../MediaLibraryWidget.php` | `openMediaLibrary` AJAX callback |

### 6. Smallest safe fix (DO NOT IMPLEMENT without approval)

In `buildWorkspaceSectionContent()`, **re-throw** Drupal form transport exceptions before the generic catch:

```php
use Drupal\Core\Form\FormAjaxException;
use Drupal\Core\EventSubscriber\EnforcedResponseException;

// inside catch (\Throwable $exception):
if ($exception instanceof FormAjaxException || $exception instanceof EnforcedResponseException) {
  throw $exception;
}
```

Also improve logging: add `@class => get_class($exception)` (and optionally file/line) so future empty-message exceptions are diagnosable.

**Scope note:** Applies to **all** workspace sections embedding forms (`information`, `branding`, `content`, `tickets`, etc.) — any `#ajax` on those forms benefits from the same fix.

### 7. Risk assessment

| Area | Risk |
|------|------|
| **Fix** | Low — aligns with Drupal core form AJAX contract; matches how block/page controllers allow `FormAjaxException` to bubble |
| **Regression** | Low if limited to `FormAjaxException` + `EnforcedResponseException` |
| **Impact if unfixed** | High — gallery, hero upload, crop, and other form AJAX on workspace routes remain broken |
| **Access / Commerce** | None — no access or payment logic touched |
| **Cache** | None — form cache already works post-serialization fix |
| **Config** | None required |

---

## Residual items

1. Create branch `audit/event-studio-branding-media-library-ajax` if this audit should be isolated from `fix/event-studio-branding-workspace-parity`.
2. Browser automation blocked on vendor login in CI/automation context; curl + watchdog provide sufficient evidence.
3. Re-verify hero thumbnail/preview after fixing AJAX **and** restoring/re-uploading hero file on node 1381.
