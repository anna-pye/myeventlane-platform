# Event Creation Pipeline Audit

**Route:** `/vendor/events/add`  
**Date:** 2025-01-27  
**Architect:** Senior Drupal 11 Architect

---

## Step 1: Rendering Path Trace

### Route Definition
**File:** `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml:234-240`

```yaml
myeventlane_vendor.console.events_add:
  path: '/vendor/events/add'
  defaults:
    _controller: 'myeventlane_vendor.controller.vendor_event_create:buildForm'
    _title: 'Create event'
```

### Controller Method
**File:** `web/modules/custom/myeventlane_vendor/src/Controller/VendorEventCreateController.php:62-79`

```php
public function buildForm(): array {
  $this->assertVendorAccess();
  $event = $this->getOrCreateDraftEvent();
  
  // Uses Drupal's EntityFormBuilder - this handles all saving properly.
  $form = $this->entityFormBuilder->getForm($event, 'default');
  
  return $this->buildVendorPage('myeventlane_vendor_console_page', [
    '#title' => $this->t('Create event'),
    '#body' => $form,
  ]);
}
```

**Key Finding:**
- ✅ Uses `EntityFormBuilderInterface::getForm()` (correct approach)
- ✅ Passes mode `'default'` (triggers `node.event.default` form display)
- ✅ Does NOT extend `ContentEntityForm` or build Form API manually
- ✅ `EntityFormDisplay::buildForm()` IS called internally by `EntityFormBuilder::getForm()`

**Form Building Chain:**
1. `EntityFormBuilder::getForm($event, 'default')`
2. → Creates `NodeForm` instance (extends `ContentEntityForm`)
3. → Calls `ContentEntityForm::form()` 
4. → Calls `EntityFormDisplay::buildForm($entity, $form, $form_state, ['mode' => 'default'])`
5. → Form display `node.event.default` configures widgets for all fields
6. → Form alters run (see Step 2)

---

## Step 2: Field Responsibility Matrix

### Critical Fields Analysis

| Field | Storage Definition | Form Display Widget | Expected Location | Actual Attached Via | Conditionally Hidden |
|-------|-------------------|---------------------|-------------------|---------------------|---------------------|
| **field_ticket_types** | `field.storage.node.field_ticket_types` (Paragraphs) | `paragraphs` widget (weight: 12) | `booking_config.content.paid_fields.field_ticket_types` | `EventFormAlter::buildBookingConfig()` line 569 | ❌ NO - #states removed line 552-560 |
| **field_category** | `field.storage.node.field_category` (Entity Reference: taxonomy_term) | `entity_reference_autocomplete_tags` (weight: 13) | `visibility.content.field_category` | `EventFormAlter::buildVisibility()` line 644 | ❌ NO - #states removed line 632-640 |
| **field_accessibility** | `field.storage.node.field_accessibility` (Entity Reference: taxonomy_term) | `entity_reference_autocomplete_tags` (weight: 14) | `visibility.content.field_accessibility` | `EventFormAlter::buildVisibility()` line 644 | ❌ NO - #states removed line 632-640 |

### Form Display Configuration
**File:** `web/sites/default/config/sync/core.entity_form_display.node.event.default.yml`

```yaml
field_ticket_types:
  type: paragraphs
  weight: 12
  settings:
    title: 'Ticket Type'
    title_plural: 'Ticket Types'
    default_paragraph_type: ticket_type_config

field_category:
  type: entity_reference_autocomplete_tags
  weight: 13
  settings:
    match_operator: CONTAINS
    match_limit: 20

field_accessibility:
  type: entity_reference_autocomplete_tags
  weight: 14
  settings:
    match_operator: CONTAINS
    match_limit: 20
```

### Field Movement Flow

**EventFormAlter Service** (`web/modules/custom/myeventlane_event/src/Form/EventFormAlter.php`):

1. **field_ticket_types** (lines 550-571):
   - Located at root: `$form['field_ticket_types']`
   - Moved to: `$form['booking_config']['content']['paid_fields']['field_ticket_types']`
   - Access explicitly set: `$form['field_ticket_types']['#access'] = TRUE` (line 562)

2. **field_category** (lines 627-650):
   - Located at root: `$form['field_category']`
   - Moved to: `$form['visibility']['content']['field_category']`
   - Access explicitly set: `$form[$field_name]['#access'] = TRUE` (line 631)

3. **field_accessibility** (lines 627-650):
   - Located at root: `$form['field_accessibility']`
   - Moved to: `$form['visibility']['content']['field_accessibility']`
   - Access explicitly set: `$form[$field_name]['#access'] = TRUE` (line 631)

**Vendor Module Tab Wrapping** (`web/modules/custom/myeventlane_vendor/myeventlane_vendor.module:281-411`):

```php
$tab_mappings = [
  'event_basics' => ['tab' => 'basics', 'active' => TRUE],
  'location' => ['tab' => 'location', 'active' => FALSE],
  'date_time' => ['tab' => 'schedule', 'active' => FALSE],
  'booking_config' => ['tab' => 'tickets', 'active' => FALSE],  // ⚠️ NOT IN ARRAY!
  'visibility' => ['tab' => 'design', 'active' => FALSE],        // ⚠️ NOT IN ARRAY!
];

// Wrap each section in a tab pane.
foreach ($tab_mappings as $section => $config) {
  if (isset($form[$section]) && is_array($form[$section])) {
    // Wraps section in container...
  }
}
```

**Critical Finding:** 
- ✅ `booking_config` and `visibility` ARE in `$tab_mappings` array (lines 307-308)
- ✅ These sections ARE wrapped by the vendor module's tab logic (lines 383-411)
- ⚠️ **BUT:** The wrapping creates a double-nesting issue:
  - EventFormAlter creates: `$form['booking_config']['content']['paid_fields']`
  - Vendor module wraps it: `$form['booking_config']['content']['content']['paid_fields']`
  - This nested structure may cause rendering issues
- ⚠️ Non-active tabs are hidden via CSS: `.mel-simple-tab-pane{display:none !important}` (line 486)

---

## Step 3: Root Cause Analysis

### Issue 1: Ticket Types No Longer Appear

**Root Cause:**
- `field_ticket_types` is moved to `booking_config.content.paid_fields.field_ticket_types` by `EventFormAlter`
- Vendor module wraps `booking_config` section in tab pane, creating nested structure:
  - Before: `$form['booking_config']['content']['paid_fields']['field_ticket_types']`
  - After: `$form['booking_config']['content']['content']['paid_fields']['field_ticket_types']`
- The `booking_config` tab is NOT active by default (`'active' => FALSE` in line 307)
- CSS hides non-active tabs: `.mel-simple-tab-pane{display:none !important}` (line 486)
- JavaScript should show active tabs, but may not be executing correctly or tab button click not working

**Evidence:**
```php
// EventFormAlter.php:364-378
$form['booking_config'] = [
  '#type' => 'container',
  '#weight' => -7,
  '#access' => TRUE,
  'content' => [
    'paid_fields' => [
      'field_ticket_types' => [...]
    ]
  ]
];

// myeventlane_vendor.module:307
'booking_config' => ['tab' => 'tickets', 'active' => FALSE],  // ⚠️ NOT active by default

// myeventlane_vendor.module:383-410 (wrapping logic)
$form[$section] = [
  '#type' => 'container',
  'content' => $original,  // ⚠️ Creates double nesting: content.content.paid_fields
];
```

### Issue 2: Taxonomy Terms Render as Plain Fields or Not at All

**Root Cause:**
- `field_category` and `field_accessibility` use `entity_reference_autocomplete_tags` widget
- They are moved to `visibility.content` section by `EventFormAlter`
- Vendor module wraps `visibility` section, creating nested structure (same double-nesting issue)
- The `visibility` tab is NOT active by default (`'active' => FALSE` in line 308)
- CSS hides non-active tabs, so fields are not visible unless user clicks "Design" tab
- If JavaScript fails, tabs remain hidden

**Evidence:**
```php
// EventFormAlter.php:606-625
$form['visibility'] = [
  '#type' => 'container',
  '#weight' => -6,
  '#access' => TRUE,
  'content' => [
    'field_category' => [...],
    'field_accessibility' => [...]
  ]
];

// myeventlane_vendor.module:308
'visibility' => ['tab' => 'design', 'active' => FALSE],  // ⚠️ NOT active by default
```

### Issue 3: Twig Templates Never Activate

**Root Cause:**
- Twig template exists: `web/themes/custom/myeventlane_theme/templates/form--node--event--form.html.twig`
- Template expects sections: `form.event_basics`, `form.date_time`, `form.location`, `form.booking_config`, `form.visibility`
- **BUT:** Vendor module wraps form in `myeventlane_vendor_console_page` theme (line 73 of VendorEventCreateController)
- The form template suggestion `form--node--event--form.html.twig` may not match because:
  1. Form ID is `node_event_form` (correct)
  2. But the form is nested inside `myeventlane_vendor_console_page` template
  3. Template preprocessing may not apply form template correctly

**Evidence:**
```php
// VendorEventCreateController.php:73
return $this->buildVendorPage('myeventlane_vendor_console_page', [
  '#body' => $form,  // Form is nested inside page template
]);
```

**Template Debug Output:**
The template has debug output (lines 14-27) showing:
- Form ID detection
- Section existence checks
- If this debug output doesn't appear, the template isn't being used

---

## Step 4: Fix Strategies

### Fix 1: Minimal Surgical Fix (Keep Controller, Restore Widgets)

**Goal:** Make fields visible without changing controller architecture.

**Pre-Fix Diagnostic:**
1. Open browser DevTools on `/vendor/events/add`
2. Inspect DOM - check if `.mel-simple-tab-pane` elements exist
3. Check if `.is-active` class is on correct pane (should be `basics`)
4. Click "Tickets" tab button - verify JavaScript shows `booking_config` pane
5. If tabs don't work, JavaScript may not be executing (check console for errors)

**If JavaScript works but fields still missing:** Proceed with Fix 1A (structure fix)  
**If JavaScript doesn't work:** Fix JavaScript issue first, then verify fields appear

**Changes Required:**

#### File 1: `web/modules/custom/myeventlane_vendor/myeventlane_vendor.module`

**Location:** Lines 382-411 (fix tab wrapping to handle nested content structure)

**Problem:** EventFormAlter creates sections with `content` sub-key, but vendor module wraps entire section creating double-nesting.

**Solution Option A:** Preserve original structure when wrapping

```php
// BEFORE (lines 382-411):
foreach ($tab_mappings as $section => $config) {
  if (isset($form[$section]) && is_array($form[$section])) {
    $original = $form[$section];
    $weight = $original['#weight'] ?? 0;
    
    $form[$section] = [
      '#type' => 'container',
      '#attributes' => [...],
      '#weight' => $weight,
    ];
    
    // Move original content inside.
    $form[$section]['content'] = $original;  // ⚠️ Creates double-nesting
    unset($form[$section]['content']['#weight']);
  }
}

// AFTER:
foreach ($tab_mappings as $section => $config) {
  if (isset($form[$section]) && is_array($form[$section])) {
    $original = $form[$section];
    $weight = $original['#weight'] ?? 0;
    
    // If section already has 'content' key (from EventFormAlter), preserve it
    if (isset($original['content']) && is_array($original['content'])) {
      // Wrap the existing content structure
      $form[$section] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-tab-pane', 'mel-simple-tab-pane'],
          'data-tab-pane' => $config['tab'],
          'data-simple-tab-pane' => $config['tab'],
          'role' => 'tabpanel',
        ],
        '#weight' => $weight,
      ];
      
      if ($config['active']) {
        $form[$section]['#attributes']['class'][] = 'is-active';
      }
      
      // Preserve the original content structure (don't double-nest)
      $form[$section]['content'] = $original['content'];
      // Preserve other top-level keys (like 'header') if they exist
      foreach ($original as $key => $value) {
        if ($key !== 'content' && $key !== '#weight' && !str_starts_with($key, '#')) {
          $form[$section][$key] = $value;
        }
      }
    } else {
      // Original wrapping logic for sections without 'content' key
      $form[$section] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-tab-pane', 'mel-simple-tab-pane'],
          'data-tab-pane' => $config['tab'],
          'data-simple-tab-pane' => $config['tab'],
          'role' => 'tabpanel',
        ],
        '#weight' => $weight,
      ];
      
      if ($config['active']) {
        $form[$section]['#attributes']['class'][] = 'is-active';
      }
      
      $form[$section]['content'] = $original;
      unset($form[$section]['content']['#weight']);
    }
  }
}
```

**Solution Option B (Simpler - if JavaScript issue):** Fix inline JavaScript execution

**Location:** Lines 490-498 (verify JavaScript loads and executes)

The inline JavaScript should activate tabs on click. If it's not working:
- Check browser console for JavaScript errors
- Verify script tag is in DOM
- Test manually: `document.querySelectorAll('.mel-simple-tab')` should return buttons

**Risks:**
- ⚠️ Medium risk - changes wrapping logic
- ✅ No database changes
- ✅ No config changes
- ⚠️ Must test all sections (some have 'content', some don't)
- ⚠️ May break if EventFormAlter structure changes

**Migration Impact:**
- None - code-only change
- Clear cache: `ddev drush cr`

---

#### File 2: `web/themes/custom/myeventlane_theme/templates/form--node--event--form.html.twig`

**Issue:** Template may not be suggested correctly when form is nested in vendor console page.

**Solution:** Verify template suggestion or add explicit theme hook suggestion.

**Location:** Check if `hook_theme_suggestions_form_alter()` needed in `myeventlane_theme.theme`

**If template still doesn't load, add to `web/themes/custom/myeventlane_theme/myeventlane_theme.theme`:**

```php
/**
 * Implements hook_theme_suggestions_form_alter().
 */
function myeventlane_theme_theme_suggestions_form_alter(array &$suggestions, array $variables, $hook) {
  $form_id = $variables['element']['#form_id'] ?? '';
  $route_name = \Drupal::routeMatch()->getRouteName();
  
  // Ensure event form template is suggested even in vendor console.
  if ($form_id === 'node_event_form' && $route_name === 'myeventlane_vendor.console.events_add') {
    array_unshift($suggestions, 'form__node__event__form');
  }
}
```

**Risks:**
- ✅ Low risk - only adds template suggestion
- ⚠️ Template debug output should appear after fix

**Migration Impact:**
- None - theme hook only
- Clear cache: `ddev drush cr`

---

### Fix 2: Correct Long-Term Fix (Restore Native Node Form Rendering)

**Goal:** Use standard Drupal node form rendering without custom controller wrapper.

**Changes Required:**

#### File 1: `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml`

**Location:** Lines 234-240 (change route to use entity form)

```yaml
# BEFORE:
myeventlane_vendor.console.events_add:
  path: '/vendor/events/add'
  defaults:
    _controller: 'myeventlane_vendor.controller.vendor_event_create:buildForm'
    _title: 'Create event'

# AFTER:
myeventlane_vendor.console.events_add:
  path: '/vendor/events/add'
  defaults:
    _entity_form: 'node.default'  # ✅ Use entity form route
    _title: 'Create event'
  options:
    parameters:
      node:
        type: entity:node
        bundle: event
```

**Risks:**
- ⚠️ **HIGH RISK** - Changes routing architecture
- ⚠️ Breaks `getOrCreateDraftEvent()` logic (creates draft automatically)
- ⚠️ May break vendor console page wrapper
- ⚠️ Requires access control changes

**Migration Impact:**
- ⚠️ Requires testing all vendor event creation flows
- ⚠️ May break existing bookmarks/links to `/vendor/events/add`
- ⚠️ Needs access control handler update

---

#### File 2: Create Access Control Handler

**Location:** New file `web/modules/custom/myeventlane_vendor/src/Access/VendorEventFormAccess.php`

```php
<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Access control for vendor event creation.
 */
class VendorEventFormAccess implements AccessInterface {

  public function access(Route $route, AccountInterface $account): AccessResult {
    // Replicate VendorConsoleAccess::access() logic
    // ...
    return AccessResult::allowedIfHasPermission($account, 'create event content');
  }
}
```

**Risks:**
- ⚠️ **MEDIUM RISK** - New access control logic
- ⚠️ Must replicate existing access checks

**Migration Impact:**
- Update route access requirement
- Test permissions thoroughly

---

#### File 3: Handle Draft Event Creation

**Location:** Create form alter or event subscriber

**Option A:** Form alter in `myeventlane_vendor.module`

```php
/**
 * Implements hook_form_node_event_form_alter().
 */
function myeventlane_vendor_form_node_event_form_alter(array &$form, FormStateInterface $form_state, string $form_id): void {
  $route_name = \Drupal::routeMatch()->getRouteName();
  if ($route_name !== 'myeventlane_vendor.console.events_add') {
    return;
  }
  
  $node = $form_state->getFormObject()->getEntity();
  if ($node->isNew()) {
    // Replicate getOrCreateDraftEvent() logic here
    // Set vendor, set status to unpublished, etc.
  }
}
```

**Risks:**
- ⚠️ **MEDIUM RISK** - Moves draft creation logic to hook
- ⚠️ Must ensure vendor is set correctly

**Migration Impact:**
- Logic migration from controller to hook
- Test draft creation flow

---

#### File 4: Preserve Vendor Console Page Wrapper

**Location:** Use `hook_page_attachments_alter()` or template preprocessing

**Option:** Override page template suggestion for this route:

```php
/**
 * Implements hook_theme_suggestions_page_alter().
 */
function myeventlane_vendor_theme_suggestions_page_alter(array &$suggestions, array $variables) {
  $route_name = \Drupal::routeMatch()->getRouteName();
  if ($route_name === 'myeventlane_vendor.console.events_add') {
    $suggestions[] = 'page__vendor_console__event_form';
  }
}
```

**Risks:**
- ⚠️ **LOW-MEDIUM RISK** - Template override
- ✅ Preserves existing UX

**Migration Impact:**
- Create new page template variant
- Clear cache

---

### Fix Strategy Comparison

| Aspect | Fix 1 (Minimal) | Fix 2 (Long-term) |
|--------|-----------------|-------------------|
| **Files Changed** | 1-2 files | 4+ files |
| **Risk Level** | Low | High |
| **Migration Impact** | None | Significant |
| **Testing Required** | Low | Extensive |
| **Architectural Correctness** | Workaround | Proper Drupal pattern |
| **Maintainability** | Lower (technical debt) | Higher (standard approach) |
| **Time to Implement** | 15 minutes | 2-4 hours |

---

## Recommendations

1. **Immediate Fix:** Apply Fix 1 (minimal surgical fix)
   - Add `booking_config` and `visibility` to `$tab_mappings`
   - Verify Twig template suggestion
   - Test that fields appear in correct tabs

2. **Future Refactor:** Plan Fix 2 (long-term) for next sprint
   - Use proper entity form routing
   - Migrate draft creation logic to form alter
   - Test thoroughly in staging environment

3. **Verification Steps:**
   - Clear all caches: `ddev drush cr`
   - Visit `/vendor/events/add`
   - Verify ticket types appear in "Tickets" tab
   - Verify category/accessibility appear in "Design" tab
   - Check browser console for JavaScript errors
   - Verify form submission works

4. **Debug Checklist:**
   - [ ] Check if template debug output appears
   - [ ] Inspect DOM for `.mel-simple-tab-pane` elements
   - [ ] Verify CSS `display: none` is removed from active panes
   - [ ] Check form structure via `kint($form)` or Devel
   - [ ] Verify `EntityFormDisplay::buildForm()` was called (check form display cache)

---

## Appendix: Form Alter Execution Order

1. **EntityFormDisplay::buildForm()** - Creates field widgets from config
2. **hook_form_alter()** (general) - Runs for all forms
3. **hook_form_node_event_form_alter()** - Runs for event forms
   - `myeventlane_event_form_node_event_form_alter()` - Creates sections, moves fields
   - `myeventlane_vendor_form_node_event_form_alter()` - Wraps sections in tabs
   - Other modules' alters...

**Critical:** Vendor module's alter runs AFTER EventFormAlter, so sections must exist when vendor alter runs.
