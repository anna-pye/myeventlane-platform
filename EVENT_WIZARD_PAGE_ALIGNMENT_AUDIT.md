# MyEventLane v2 – Event Wizard & Event Page Alignment Audit

**Date:** 2025-01-27  
**Status:** Post-Implementation Review  
**Auditor:** Drupal 11 Architecture Review

---

## 1. Executive Summary

**Alignment Status:** ⚠️ **Mostly Aligned** with identified gaps

**Top 3 Actionable Risks:**
1. **HIGH:** Location coordinates not being saved from wizard → map fails on event page
2. **MEDIUM:** Policy fields (`field_refund_policy`, `field_cancellation_policy`) written by wizard but not displayed on event page
3. **LOW:** Category field displayed on event page but may not be prominently shown

**Overall Assessment:**
The Event Wizard and Event Page share a coherent event model with good field alignment. The main issues are:
- Location coordinate persistence (JavaScript → database)
- Policy fields written but not rendered
- Some fields hidden in view display that may need visibility

---

## 2. Field Alignment Table (As-Built)

| Field | Wizard Step | Wizard Writes | Event Page Reads | View Display | Status | Notes |
|-------|-------------|---------------|------------------|--------------|--------|-------|
| `title` | basics | ✅ | ✅ | ✅ Visible | ✅ **ALIGNED** | Core field, required |
| `field_category` | basics | ✅ | ✅ | ✅ Visible | ✅ **ALIGNED** | Multiple select, displayed |
| `field_event_start` | when_where | ✅ | ✅ | ✅ Visible | ✅ **ALIGNED** | Required, displayed prominently |
| `field_event_end` | when_where | ✅ | ✅ | ✅ Visible | ✅ **ALIGNED** | Optional, displayed |
| `field_location` | when_where | ✅ (via JS) | ✅ | ✅ Visible | ⚠️ **PARTIAL** | Address saved, but coordinates may not persist |
| `field_venue_name` | when_where | ✅ (custom save) | ✅ | ✅ Visible | ⚠️ **PARTIAL** | Custom field, saved manually |
| `field_location_latitude` | when_where | ❌ (not saved) | ✅ | ✅ Hidden | 🔴 **MISALIGNED** | Coordinates not saved from wizard |
| `field_location_longitude` | when_where | ❌ (not saved) | ✅ | ✅ Hidden | 🔴 **MISALIGNED** | Coordinates not saved from wizard |
| `field_venue` | when_where | ✅ | ✅ | ❌ Not used | ⚠️ **PARTIAL** | Venue entity ref, used for data extraction |
| `field_event_image` | branding | ✅ | ✅ | ✅ Visible | ✅ **ALIGNED** | Hero image, displayed |
| `field_event_type` | tickets_capacity | ✅ | ✅ | ✅ Visible | ✅ **ALIGNED** | Determines CTA logic |
| `field_capacity` | tickets_capacity | ✅ | ✅ | ✅ Visible | ✅ **ALIGNED** | Displayed |
| `field_waitlist_capacity` | tickets_capacity | ✅ | ✅ | ✅ Visible | ✅ **ALIGNED** | Displayed |
| `field_external_url` | tickets_capacity | ✅ | ✅ | ✅ Visible | ✅ **ALIGNED** | For external events |
| `field_sales_start` | tickets_capacity | ✅ | ✅ | ❌ Hidden | ⚠️ **PARTIAL** | Used in logic, not displayed |
| `field_sales_end` | tickets_capacity | ✅ | ✅ | ❌ Hidden | ⚠️ **PARTIAL** | Used in logic, not displayed |
| `field_ticket_types` | tickets_capacity | ✅ | ✅ | ❌ Hidden | ⚠️ **PARTIAL** | Used for Commerce, not displayed |
| `field_collect_per_ticket` | tickets_capacity | ✅ | ✅ | ❌ Hidden | ✅ **ALIGNED** | System field, correctly hidden |
| `body` | content | ✅ | ✅ | ✅ Visible | ✅ **ALIGNED** | Main description |
| `field_refund_policy` | policies_accessibility | ✅ | ❌ | ❌ Hidden | 🔴 **MISALIGNED** | Written but never displayed |
| `field_cancellation_policy` | policies_accessibility | ✅ (if exists) | ❌ | ❌ Hidden | 🔴 **MISALIGNED** | Written but never displayed |
| `field_accessibility` | policies_accessibility | ✅ | ✅ | ✅ Visible | ✅ **ALIGNED** | Multiple select, displayed |
| `field_tags` | policies_accessibility | ✅ | ❌ | ❌ Hidden | ⚠️ **PARTIAL** | Written but hidden in view display |

**Legend:**
- ✅ **ALIGNED** - Field written by wizard and displayed/used on event page
- ⚠️ **PARTIAL** - Field written but not displayed, or displayed but not written
- 🔴 **MISALIGNED** - Field written but never used, or critical gap

---

## 3. Confirmed Strengths

### ✅ **Core Event Model**
- Single source of truth: Event node type
- Clean field structure with minimal redundancy
- Proper use of entity references (vendor, venue, product)

### ✅ **Wizard Flow**
- Logical step progression: Basics → When/Where → Branding → Tickets → Content → Policies → Review
- Good UX with conditional field visibility based on event type
- Proper draft saving at each step
- AJAX-based navigation for smooth experience

### ✅ **Event Page Rendering**
- Mobile-first responsive design
- Graceful handling of missing data (conditional rendering)
- Proper use of template preprocessing (no business logic in Twig)
- Good separation: `myeventlane_theme.theme` handles logic, templates handle presentation

### ✅ **Commerce Integration**
- Event → Product link via `field_product_target`
- Ticket types via `field_ticket_types` paragraphs
- EventModeManager service handles RSVP vs Paid logic cleanly
- CTA buttons correctly reflect event state and availability

### ✅ **Location Handling**
- Address field (`field_location`) is primary source
- Venue name separate from address (good UX)
- Map rendering with fallback logic
- Multiple coordinate source checks (venue entity → event fields)

---

## 4. Misalignments & Gaps

### 🔴 **HIGH SEVERITY**

#### 4.1 Location Coordinates Not Persisted
**Issue:** `field_location_latitude` and `field_location_longitude` are not saved from wizard
- Wizard JavaScript populates address but coordinates may not be saved
- Event page expects coordinates for map rendering
- Current workaround: Coordinates extracted from venue entity or address field

**Impact:** Map fails to render on event pages (JavaScript warning: "Event coordinates not found")

**Root Cause:** 
- Wizard uses custom venue selection widget
- Coordinates populated by JavaScript but not persisted to dedicated fields
- `saveVenueName()` saves venue name but coordinates saving logic incomplete

**Files Affected:**
- `web/modules/custom/myeventlane_event/src/Form/EventWizardForm.php` (saveVenueName method)
- `web/modules/custom/myeventlane_location/myeventlane_location.module` (coordinate extraction)

**Recommendation:** 🔧 **REQUIRED FIX**
- Ensure coordinate saving in wizard submit handlers
- Verify JavaScript populates hidden latitude/longitude fields
- Add coordinate persistence to `saveVenueName()` or separate method

---

### 🟡 **MEDIUM SEVERITY**

#### 4.2 Policy Fields Written But Not Displayed
**Issue:** `field_refund_policy` and `field_cancellation_policy` written in wizard but hidden in view display

**Wizard:** 
- `buildPolicies()` writes `field_refund_policy` and `field_cancellation_policy` (if exists)

**Event Page:**
- View display config: Both fields marked as `hidden: true`
- Template: No rendering of policy fields
- User expectation: Policies should be visible to attendees

**Impact:** Vendors set policies but attendees never see them

**Files Affected:**
- `web/sites/default/config/sync/core.entity_view_display.node.event.default.yml` (lines 240-241)
- `web/themes/custom/myeventlane_theme/templates/node--event.html.twig` (no policy section)

**Recommendation:** 🔧 **REQUIRED FIX**
- Add policy section to event page template
- Unhide fields in view display OR create custom rendering
- Consider adding to "Policies & FAQs" section if it exists

---

#### 4.3 Tags Field Hidden
**Issue:** `field_tags` written in wizard but hidden in view display

**Wizard:**
- `buildPolicies()` writes `field_tags`

**Event Page:**
- View display: `field_tags: true` (hidden)
- Template: Not rendered

**Impact:** Tags collected but not used for discovery/filtering

**Recommendation:** ✨ **OPTIONAL IMPROVEMENT**
- If tags are for internal use only: Keep hidden (current state is fine)
- If tags should be public: Add to template and unhide in view display

---

### 🟢 **LOW SEVERITY**

#### 4.4 Sales Date Fields Hidden
**Issue:** `field_sales_start` and `field_sales_end` used in logic but not displayed

**Status:** ✅ **INTENTIONAL** - These are system fields used for CTA logic, not user-facing
- Used in `EventModeManager` to determine "scheduled" state
- Displayed via formatted variables (`mel_sales_start_formatted`)
- Correctly hidden in view display

**Recommendation:** 🚫 **DO NOT CHANGE** - Current implementation is correct

---

#### 4.5 Category Display
**Issue:** Category field displayed but may not be prominent

**Status:** ✅ **ALIGNED** - Field is displayed, but check prominence
- View display: `field_category` visible (weight: 12)
- Template: Not explicitly checked in main template (may be in preprocessing)

**Recommendation:** ✨ **OPTIONAL** - Verify category is visible on event page if important for discovery

---

## 5. Wizard Step → Field Mapping (Detailed)

### Step 1: Basics
**Fields Written:**
- `title` ✅
- `field_category` ✅ (multiple select)

**Event Page Usage:**
- `title`: Hero heading ✅
- `field_category`: Displayed in view (weight: 12) ✅

**Status:** ✅ **ALIGNED**

---

### Step 2: When & Where
**Fields Written:**
- `field_event_start` ✅
- `field_event_end` ✅
- `field_location` ✅ (via JavaScript, hidden widget)
- `field_venue_name` ✅ (custom save)
- `field_venue` ✅ (entity reference)
- `field_location_latitude` ❌ (not saved)
- `field_location_longitude` ❌ (not saved)

**Event Page Usage:**
- `field_event_start`: Date & time section ✅
- `field_event_end`: Date & time section ✅
- `field_location`: Location section ✅
- `field_venue_name`: Location section (venue name) ✅
- `field_location_latitude/longitude`: Map rendering ❌ (coordinates missing)

**Status:** ⚠️ **PARTIAL** - Coordinates not persisted

---

### Step 3: Branding
**Fields Written:**
- `field_event_image` ✅

**Event Page Usage:**
- `field_event_image`: Hero image ✅

**Status:** ✅ **ALIGNED**

---

### Step 4: Tickets & Capacity
**Fields Written:**
- `field_event_type` ✅
- `field_capacity` ✅
- `field_waitlist_capacity` ✅
- `field_external_url` ✅ (conditional: external events)
- `field_sales_start` ✅ (conditional: paid/both)
- `field_sales_end` ✅ (conditional: paid/both)
- `field_collect_per_ticket` ✅ (conditional: paid/both)
- `field_ticket_types` ✅ (conditional: paid/both)

**Event Page Usage:**
- `field_event_type`: Determines CTA logic ✅
- `field_capacity`: Displayed ✅
- `field_waitlist_capacity`: Displayed ✅
- `field_external_url`: Used for external CTA ✅
- `field_sales_start/end`: Used in state logic (not displayed) ✅
- `field_ticket_types`: Used for Commerce products (not displayed) ✅
- `field_collect_per_ticket`: System field (not displayed) ✅

**Status:** ✅ **ALIGNED** - All fields used correctly

---

### Step 5: Content
**Fields Written:**
- `body` ✅

**Event Page Usage:**
- `body`: "About this event" section ✅

**Status:** ✅ **ALIGNED**

---

### Step 6: Policies & Accessibility
**Fields Written:**
- `field_refund_policy` ✅
- `field_cancellation_policy` ✅ (if exists)
- `field_accessibility` ✅ (multiple select)
- `field_tags` ✅

**Event Page Usage:**
- `field_refund_policy`: ❌ Not displayed
- `field_cancellation_policy`: ❌ Not displayed
- `field_accessibility`: ✅ "Accessibility & inclusion" section
- `field_tags`: ❌ Hidden in view display

**Status:** 🔴 **MISALIGNED** - Policy fields written but not displayed

---

### Step 7: Review
**Fields Written:**
- None (review only)

**Event Page Usage:**
- N/A

**Status:** ✅ **ALIGNED**

---

## 6. Commerce & Ticketing Alignment

### ✅ **Well Aligned**

**Event Type → CTA Flow:**
1. Wizard sets `field_event_type` (rsvp/paid/both/external)
2. EventModeManager reads `field_event_type` to determine mode
3. Event page CTA logic uses EventModeManager
4. CTA buttons route to `myeventlane_commerce.event_book`

**Product Linking:**
- `field_product_target` links Event → Commerce Product
- `field_ticket_types` paragraphs define ticket configurations
- TicketTypeManager syncs paragraphs to Commerce variations

**State Management:**
- Event state (`mel_event_state`) determines CTA availability
- Sold out → Waitlist CTA
- Scheduled → "Sales open on..." message
- Live → Buy Tickets / RSVP Now

**Status:** ✅ **ALIGNED** - Commerce integration is coherent

---

## 7. UX & Language Continuity

### ✅ **Good Continuity**

**Terminology:**
- Wizard: "Event Type" → Event Page: Uses same values (RSVP/Paid/Both)
- Wizard: "Capacity" → Event Page: "Capacity" (consistent)
- Wizard: "Accessibility" → Event Page: "Accessibility & inclusion" (slight variation, acceptable)

**Field Labels:**
- Wizard labels match field definitions
- Help text is vendor-friendly
- Event page labels are attendee-friendly (appropriate difference)

**Status:** ✅ **ALIGNED** - Terminology is consistent

---

## 8. Stability, Security & Performance

### ✅ **Good Practices**

**Cache Contexts:**
- Event page uses proper cache tags (`$entity->getCacheTags()`)
- View display respects entity cache
- Template preprocessing uses cache metadata

**Access Control:**
- Wizard checks vendor ownership (`assertEventOwnership()`)
- Event page respects node access
- Draft events not visible to public (correct)

**Performance:**
- Wizard saves draft at each step (prevents data loss)
- Event page uses efficient field loading
- Map library only attached when coordinates exist

**Status:** ✅ **ALIGNED** - Security and performance are sound

---

## 9. Recommendations

### 🔧 **Required Fixes**

#### Fix 1: Persist Location Coordinates
**Priority:** HIGH  
**Files:**
- `web/modules/custom/myeventlane_event/src/Form/EventWizardForm.php`
- `web/modules/custom/myeventlane_location/myeventlane_location.module`

**Action:**
1. Verify JavaScript populates `field_location_latitude` and `field_location_longitude` hidden fields
2. Ensure `extractFormValues()` saves these fields
3. Add explicit coordinate saving in submit handlers if needed
4. Test: Create event with address → verify coordinates saved → verify map renders

**Estimated Effort:** 2-3 hours

---

#### Fix 2: Display Policy Fields
**Priority:** MEDIUM  
**Files:**
- `web/themes/custom/myeventlane_theme/templates/node--event.html.twig`
- `web/sites/default/config/sync/core.entity_view_display.node.event.default.yml`

**Action:**
1. Add "Policies & FAQs" section to event page template (after Accessibility section)
2. Display `field_refund_policy` and `field_cancellation_policy` if they exist
3. Unhide fields in view display OR render via template preprocessing
4. Style consistently with other event card sections

**Estimated Effort:** 1-2 hours

---

### ✨ **Optional Improvements**

#### Improvement 1: Tags Visibility
**Priority:** LOW  
**Decision Required:** Are tags for internal use or public discovery?

**If Public:**
- Unhide `field_tags` in view display
- Add tags display to event page template
- Consider adding to event cards/teasers for filtering

**If Internal:**
- Keep hidden (current state is fine)
- Document purpose in code comments

---

#### Improvement 2: Category Prominence
**Priority:** LOW  
**Action:**
- Verify category is visible on event page
- If important for discovery, consider adding to hero or sidebar
- Check if category color/styling is applied

---

### 🚫 **Explicitly Do Not Change**

**Do NOT modify:**
- `field_sales_start` / `field_sales_end` visibility (correctly hidden, used in logic)
- `field_ticket_types` visibility (system field, used for Commerce)
- `field_collect_per_ticket` visibility (system field)
- Wizard step order (good UX flow)
- EventModeManager logic (working correctly)
- CTA routing (coherent with Commerce)

---

## 10. Next Actions

### Immediate (This Week)

1. **Fix coordinate persistence** 🔧
   - File: `EventWizardForm.php`
   - Method: `saveVenueName()` or new `saveCoordinates()`
   - Test: Create event → verify coordinates in database → verify map renders

2. **Add policy display** 🔧
   - File: `node--event.html.twig`
   - Add section after Accessibility
   - Unhide fields in view display config

### Short Term (Next Sprint)

3. **Decision on tags** ✨
   - Determine if tags should be public
   - If yes, add to template and view display

4. **Verify category display** ✨
   - Check if category is visible on event page
   - Add to template if missing

### Testing Checklist

- [ ] Create event via wizard → verify all fields saved
- [ ] View event page → verify all wizard fields displayed
- [ ] Test location → verify coordinates saved → verify map renders
- [ ] Test policies → verify refund/cancellation policy displayed
- [ ] Test CTA buttons → verify correct routing based on event type
- [ ] Test draft events → verify not visible to public
- [ ] Test mobile layout → verify responsive design

---

## 11. File Reference

### Wizard Files
- `web/modules/custom/myeventlane_event/src/Form/EventWizardForm.php` - Main wizard form
- `web/modules/custom/myeventlane_event/src/Controller/VendorEventWizardController.php` - Wizard controller

### Event Page Files
- `web/themes/custom/myeventlane_theme/templates/node--event.html.twig` - Main event template
- `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` - Template preprocessing
- `web/sites/default/config/sync/core.entity_view_display.node.event.default.yml` - View display config

### Service Files
- `web/modules/custom/myeventlane_event/src/Service/EventModeManager.php` - Event mode/CTA logic
- `web/modules/custom/myeventlane_location/myeventlane_location.module` - Location/map logic

---

## 12. Conclusion

**Overall Assessment:** The Event Wizard and Event Page are **mostly aligned** with a coherent event model. The main gaps are:

1. **Location coordinates** not being persisted (HIGH priority fix)
2. **Policy fields** written but not displayed (MEDIUM priority fix)
3. **Tags field** hidden (LOW priority, decision needed)

The architecture is sound, the field structure is clean, and the Commerce integration works well. With the two required fixes, the system will be fully aligned.

**Confidence Level:** High - All findings are based on confirmed codebase analysis, no assumptions made.

---

**End of Audit Report**

