# Event Wizard — Deep Dive Audit: Why Amendments Are Not Working

**Date:** 2025-02-02  
**Purpose:** Trace why the wizard save fixes (fallback copy, enctype, form alter) are not resolving the reported issues.

---

## CRITICAL FINDING: Active Config Does Not Match Intended Design

**The form displays in the database are wrong.** Verification on this site shows:

### wizard_step_1 (Basics) — WRONG
| Expected (config/install) | Active config has |
|---------------------------|-------------------|
| title, field_event_intro, field_category, field_event_image | title, **body**, field_category, field_event_image, **field_event_type** |
| field_event_intro | **Missing** — has body instead |
| — | field_event_type (belongs in Tickets step) |

### wizard_step_2 (When & Where) — WRONG
| Expected | Active config has |
|----------|-------------------|
| field_event_start, field_event_end, field_location, field_venue_name | All of those PLUS **field_location_latitude**, **field_location_longitude** (visible, number type), **field_external_url** |
| field_location uses myeventlane_location_address_autocomplete | field_location uses **address_default** |
| Lat/lng hidden | **Lat/lng visible** — causes "Latitude/Longitude is not a valid number" |

### wizard_step_4 (Tickets) — BROKEN
| Expected | Active config has |
|----------|-------------------|
| field_event_type, field_capacity, field_waitlist_capacity, field_external_url, field_ticket_types, field_product_target | **Only revision** — no ticket fields at all |

**Conclusion:** wizard_step_4 has no ticket fields. That explains "the event is paid but there is no Ticket options". wizard_step_2 has visible lat/lng fields (causing validation errors) and wrong widget. wizard_step_1 has body instead of field_event_intro.

**Fix:** The form displays must be repaired. Either:
1. Run `ddev drush config:import` to import config from config/sync (if it has the correct displays), or
2. Add/run an update hook that programmatically repairs wizard_step_1, wizard_step_2, and wizard_step_4 to match the intended design.

---

## Executive Summary

The amendments we made follow ContentEntityForm patterns but **cannot work** because the active form display config is wrong. wizard_step_4 is empty (no ticket fields), wizard_step_2 has visible lat/lng causing validation errors, and wizard_step_1 has the wrong fields.

---

## 1. Form Display May Not Exist (Critical)

**Finding:** `EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_1')` loads the form display from config. If `node.event.wizard_step_1` does **not** exist in active config, it creates an **empty** runtime display:

```php
// EntityFormDisplay.php:126-132
if (empty($display)) {
  $display = $storage->create([
    'targetEntityType' => $entity_type,
    'bundle' => $bundle,
    'mode' => $form_mode,
    'status' => TRUE,
  ]);
}
```

An empty display has `content = []`, so:
- `buildForm()` adds **no** form elements
- `extractFormValues()` extracts **nothing**
- The fallback only helps if form_state has values — but with no form elements, the user can't submit any

**Verification:** Run `ddev drush config:get core.entity_form_display.node.event.wizard_step_1` — if it returns "The configuration key does not exist", the display was never created.

**Fix:** Ensure config is imported, or that update hooks (e.g. `myeventlane_event_update_11005`–`11011`) create/repair the wizard form displays.

---

## 2. Fallback Copies Wrong Value Format

**Finding:** The fallback does:
```php
foreach ($form_state->getValues() as $name => $values) {
  if ($event->hasField($name) && !isset($extracted[$name])) {
    $event->set($name, $values);
  }
}
```

`$form_state->getValues()` returns the raw submitted structure. Some fields expect a specific format:

| Field | Expected by Entity | Form State Might Have |
|-------|--------------------|------------------------|
| `title` | `[['value' => 'x']]` or string | `['0' => ['value' => 'x']]` or nested |
| `field_external_url` | `[['uri' => '...', 'title' => '...']]` | Same, but keys may differ |
| `field_location` | Address value object structure | Nested array from widget |

If the format doesn't match, `$entity->set()` can throw or silently fail. ContentEntityForm's fallback assumes "fields not rendered through widgets" — i.e. simple top-level values. Complex widgets (link, address, image) use nested structures that may not match.

**Verification:** Log `$form_state->getValue('title')` and `$form_state->getValue('field_external_url')` in submitForm before copyFormValuesToEvent. Compare to what the entity expects.

---

## 3. extractFormValues Only Runs for Fields With a Widget

**Finding:** `extractFormValues` iterates over `$entity` and only extracts when `getRenderer($name)` returns a widget:

```php
foreach ($entity as $name => $items) {
  if ($widget = $this->getRenderer($name)) {
    $widget->extractFormValues($items, $form, $form_state);
    $extracted[$name] = $name;
  }
}
```

`getRenderer($name)` requires:
1. `getComponent($name)` — component in form display **content** (not just hidden)
2. `getFieldDefinition($name)` — field exists on entity

If the Node `title` base field uses a different widget or the form display has no component for it, `getRenderer('title')` returns NULL and title is never extracted. The fallback would then run — but only if the value format is correct (see #2).

**Verification:** Confirm `wizard_step_1` has `title` in its `content` section (not just hidden). Check with `ddev drush config:get core.entity_form_display.node.event.wizard_step_1 content`.

---

## 4. Form Alter Runs After buildForm — Path/Revision May Not Exist

**Finding:** We hide `path` and `revision_information` in `myeventlane_event_form_alter`:

```php
if (isset($form['path'])) {
  $form['path']['#access'] = FALSE;
}
if (isset($form['revision_information'])) {
  $form['revision_information']['#access'] = FALSE;
}
```

Wizard forms are built with `EntityFormDisplay::buildForm()` only. That method **does not** add `path` or `revision_information` — those are added by NodeForm / ContentEntityForm when using the default entity form. Our wizard forms never call `NodeForm::form()` or `ContentEntityForm::form()`, so those elements are never added. The alter is a no-op.

**Impact:** If path/revision were appearing, they'd come from somewhere else (e.g. a theme or another module). The real question: are they appearing at all? If not, this alter is harmless but unnecessary.

---

## 5. Submit Handler Order — Location Saves After We Redirect

**Finding:** `_myeventlane_location_save_coordinates` is added to `$form['actions']['submit']['#submit'][]`. Submit handlers run in order. Our `EventWizardWhenWhereForm::submitForm` runs first, then the location handler.

Our submitForm:
1. Calls `copyFormValuesToEvent` (extracts + fallback + save)
2. Calls `redirectToNextStep` (sets redirect in form_state)

The location handler runs **after** our submitForm. It gets the entity via `getEvent()`, sets lat/lng, and calls `$node->save()`. So the order is correct — we save first, then the location handler saves again with coordinates. No conflict.

**Potential issue:** The location handler uses `$form_state->getUserInput()` and `$form_state->getValue()` for `myeventlane_location_latitude` / `myeventlane_location_longitude`. Those are custom hidden fields added by the location module's form alter. If the alter doesn't run on the wizard form, or the fields aren't in the form, the handler gets nothing. Need to confirm the alter runs for `event_wizard_when_where_form`.

---

## 6. #states and Value Submission

**Finding:** For Tickets step, `field_ticket_types`, `field_external_url`, etc. are shown via `#states` when `field_event_type` is paid/both/external. When `#states` hides an element (e.g. display:none), the element stays in the DOM and its value is still submitted — unless something removes it or sets `#access = FALSE`.

**Potential issue:** If the paid fields are hidden on initial load (event_type empty or rsvp), and the user changes to "paid" via JavaScript, the fields become visible. But if the form was built with those fields having `#access = FALSE` somewhere, they might not submit. Our `applyTicketConditionalLogic` only sets `#states`; it doesn't touch `#access`. So values should submit.

**Verification:** Inspect the Tickets form in browser DevTools. When event_type = "paid", confirm `field_external_url` and `field_ticket_types` are in the DOM and have `name` attributes. Submit and check the POST payload.

---

## 7. File Upload — enctype and Temporary File Handling

**Finding:** We added `$form['#attributes']['enctype'] = 'multipart/form-data'` to Basics. That is correct for file uploads.

**Potential issue:** The image widget uses `managed_file`. On upload, the file is stored temporarily. The widget's `extractFormValues` must promote the temp file to permanent and set `target_id` on the entity. If the widget expects a specific form structure (e.g. `#parents`), and our form structure differs, extraction can fail.

Also: `EntityFormDisplay::buildForm` sets `$form['#parents'] = []` by default. Child elements inherit. The image widget may expect `#parents` to be `['field_event_image']` or similar. If the form is wrapped in extra containers that change `#parents`, values could end up in the wrong place in form_state.

**Verification:** After submitting Basics with an image, check `$form_state->getValue('field_event_image')` in submitForm. It should be `[0 => ['fids' => [123]]]` or similar. If empty, the widget didn't receive the upload.

---

## Recommended Diagnostic Steps

1. **Verify form displays exist:**
   ```bash
   ddev drush config:get core.entity_form_display.node.event.wizard_step_1
   ddev drush config:get core.entity_form_display.node.event.wizard_step_2
   ddev drush config:get core.entity_form_display.node.event.wizard_step_4
   ```

2. **Add temporary logging in EventWizardBasicsForm::submitForm (before copyFormValuesToEvent):**
   ```php
   \Drupal::logger('myeventlane_event')->debug('Basics submit: title=@t, fields=@f', [
     '@t' => print_r($form_state->getValue('title'), TRUE),
     '@f' => implode(', ', array_keys(array_filter($form_state->getValues(), fn($k) => str_starts_with($k, 'field_') || $k === 'title', ARRAY_FILTER_USE_KEY))),
   ]);
   ```

3. **Add logging after copyFormValuesToEvent:**
   ```php
   \Drupal::logger('myeventlane_event')->debug('After copy: title=@t', [
     '@t' => $event->label(),
   ]);
   ```

4. **Check form display content:**
   ```bash
   ddev drush config:get core.entity_form_display.node.event.wizard_step_1 content
   ```
   Confirm `title`, `field_event_intro`, `field_category`, `field_event_image` are present.

5. **Run config import if displays are missing:**
   ```bash
   ddev drush config:import -y
   # or
   ddev drush updb -y
   ```

---

## Summary: Most Likely Root Causes

| Issue | Most Likely Cause |
|-------|--------------------|
| Event name not saving | Form display missing or title not in content; or widget extractFormValues fails and fallback gets wrong format |
| Image not saving | Widget extractFormValues fails (form structure / #parents); or temp file not promoted |
| External URL not saving | #states hides field until JS runs; or field not in form when event_type=external; or link widget expects different value format |
| Venue/address not saving | Address widget extractFormValues fails; or form structure doesn't match widget expectations |
| Paid ticket options not showing | #states selector wrong (e.g. `field_event_type[0][value]` vs actual name); or JS not loaded |
| Path/URL alias | Alter is no-op (elements never added to wizard form) — low priority |
| Review card preview | Implementation should work; verify teaser view mode exists and event has required fields |

---

## Next Steps

1. Run the diagnostic commands above.
2. Add the temporary logging and reproduce the issues.
3. Share the log output and config:get results to narrow down the root cause.
4. If form displays are missing, run `drush updb` and/or `drush config:import`.
5. If value formats are wrong, consider a custom extraction step for problem fields instead of relying on the generic fallback.
