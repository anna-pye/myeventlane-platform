# Cart → Checkout → Confirmation Flow Audit
**Date:** 2026-01-24  
**Phase:** 1 - Audit Only (No Code Changes)

## Executive Summary

This audit identifies UX, data accuracy, styling consistency, and accessibility issues across the cart, checkout, and confirmation pages. All findings are documented for Phase 2 implementation.

---

## 1. CART PAGE (`commerce-cart-form.html.twig`)

### 1.1 Styling & Layout Issues

**Issue:** Cart items are rendered via Commerce Views (`form.output`), but the template doesn't provide clear event grouping.
- **Impact:** Users may not see which tickets belong to which event clearly
- **Severity:** MEDIUM
- **Location:** Lines 24-25

**Issue:** Cart summary sidebar uses different styling patterns than vendor theme.
- Uses `mel-cart-summary` instead of `mel-card` pattern
- Missing consistent card header/body structure
- **Impact:** Visual inconsistency with rest of MEL
- **Severity:** LOW
- **Location:** Lines 42-65

**Issue:** Empty state uses emoji (🛒) which may not render consistently.
- **Impact:** Accessibility and cross-platform rendering
- **Severity:** LOW
- **Location:** Line 32

### 1.2 Data & Terminology Issues

**Issue:** Item count shows "X items" but doesn't clarify if this is ticket count or order item count.
- Example: 3 tickets across 2 order items could show "2 items"
- **Impact:** Confusion about what's being counted
- **Severity:** MEDIUM
- **Location:** Line 18

**Issue:** "Order Summary" label in sidebar may be premature (order doesn't exist until checkout).
- Should be "Cart Summary" or "Summary"
- **Impact:** Terminology confusion
- **Severity:** LOW
- **Location:** Line 43

**Issue:** Total price display relies on `form['#order']` which may not always be available.
- No fallback if order entity is missing
- **Impact:** Potential empty total display
- **Severity:** MEDIUM
- **Location:** Lines 54-56

### 1.3 Missing Information

**Issue:** No event grouping visible in cart items.
- Tickets from multiple events appear as flat list
- No clear visual separation by event
- **Impact:** User confusion when buying tickets for multiple events
- **Severity:** HIGH
- **Location:** Cart items rendering (Commerce Views output)

**Issue:** No event details (date, location) shown in cart items.
- Users can't verify event details before checkout
- **Impact:** Reduced trust, potential errors
- **Severity:** MEDIUM

**Issue:** No clear indication of ticket type details beyond variation name.
- **Impact:** Users may not remember what ticket type they selected
- **Severity:** LOW

### 1.4 Accessibility Issues

**Issue:** Cart count uses `<span>` without proper ARIA label.
- Screen readers may not announce count clearly
- **Impact:** Accessibility barrier
- **Severity:** MEDIUM
- **Location:** Line 18

**Issue:** "Secure checkout" text has no icon alternative text.
- Uses emoji (🔒) which may not be announced
- **Impact:** Accessibility
- **Severity:** LOW
- **Location:** Line 64

**Issue:** Empty state button lacks descriptive text for screen readers.
- **Impact:** Accessibility
- **Severity:** LOW
- **Location:** Line 35

### 1.5 Mobile Responsiveness

**Issue:** Cart layout uses fixed sidebar width (380px) which may be too narrow on tablets.
- **Impact:** Layout issues on medium screens
- **Severity:** LOW
- **Location:** SCSS line 23

**Issue:** Cart items table (from Commerce Views) may not be responsive.
- No mobile-specific layout for cart items
- **Impact:** Horizontal scrolling on mobile
- **Severity:** MEDIUM

---

## 2. CHECKOUT FLOW (`commerce-checkout-form.html.twig`)

### 2.1 Progress Indicator Issues

**Issue:** Progress steps are rendered via `form.progress` but styling may not match MEL design system.
- Uses `mel-checkout__progress` but actual step rendering is in Commerce module
- **Impact:** Potential styling inconsistencies
- **Severity:** LOW
- **Location:** Lines 20-23

**Issue:** No visible step numbers or clear "Step X of Y" indication.
- **Impact:** Users may not know how many steps remain
- **Severity:** MEDIUM

### 2.2 Checkout Pane Issues

**Issue:** Ticket holder information pane (`TicketHolderParagraphPane`) uses generic fieldset titles.
- Title: "Ticket Holder Information for: @title" - may be too verbose
- **Impact:** Visual noise, unclear hierarchy
- **Severity:** LOW
- **Location:** `TicketHolderParagraphPane.php` line 97

**Issue:** Ticket holder form uses `<details>` elements which may collapse important information.
- All fieldsets are `#open => TRUE` but still collapsible
- **Impact:** Users may accidentally collapse required fields
- **Severity:** LOW
- **Location:** `TicketHolderParagraphPane.php` line 115

**Issue:** Payment pane styling has extensive `!important` overrides for Stripe Elements.
- Indicates potential CSS conflicts
- **Impact:** Maintenance difficulty, potential styling issues
- **Severity:** LOW
- **Location:** `_checkout.scss` lines 299-451

### 2.3 Order Summary Sidebar Issues

**Issue:** Order summary uses Views (`GroupedSummaryPane`) which may not match cart exactly.
- Different rendering path than cart
- **Impact:** Potential data mismatch between cart and checkout
- **Severity:** HIGH
- **Location:** `GroupedSummaryPane.php`

**Issue:** No clear indication if Boost items are excluded from summary.
- Boost should be excluded but no visual confirmation
- **Impact:** Potential confusion if Boost appears
- **Severity:** MEDIUM

**Issue:** Sidebar summary may not show event grouping clearly.
- **Impact:** Users can't verify event details during checkout
- **Severity:** MEDIUM

### 2.4 Payment Section Issues

**Issue:** Payment method selection styling may not match MEL button patterns.
- Uses custom `.mel-payment-method-label` instead of standard button classes
- **Impact:** Visual inconsistency
- **Severity:** LOW
- **Location:** `_checkout.scss` lines 222-245

**Issue:** Stripe Elements iframe containers have extensive visibility overrides.
- Suggests previous issues with hidden payment fields
- **Impact:** Potential future issues if Commerce/Stripe updates
- **Severity:** LOW
- **Location:** `_checkout.scss` lines 329-415

### 2.5 Missing Information

**Issue:** No "What happens next" messaging during checkout.
- Users don't know what to expect after payment
- **Impact:** Reduced trust, uncertainty
- **Severity:** MEDIUM

**Issue:** No clear indication of ticket delivery method (email, download, etc.).
- **Impact:** User uncertainty
- **Severity:** LOW

### 2.6 Accessibility Issues

**Issue:** Payment method radio buttons use `opacity: 0` and `pointer-events: none`.
- May cause focus issues for keyboard navigation
- **Impact:** Accessibility barrier
- **Severity:** MEDIUM
- **Location:** `_checkout.scss` lines 216-219

**Issue:** Checkout panes may not have proper heading hierarchy.
- **Impact:** Screen reader navigation
- **Severity:** LOW

---

## 3. CONFIRMATION PAGE (`commerce-checkout-completion.html.twig`)

### 3.1 Success Messaging Issues

**Issue:** Success icon uses emoji (🎉) which may not render consistently.
- **Impact:** Accessibility and cross-platform rendering
- **Severity:** LOW
- **Location:** Line 15

**Issue:** Confirmation message assumes email was sent successfully.
- No conditional messaging if email fails
- **Impact:** False expectations if email delivery fails
- **Severity:** LOW
- **Location:** Lines 23-26

### 3.2 Event Information Issues

**Issue:** Event date formatting uses `date('F j, Y')` which may not match user locale.
- No timezone consideration
- **Impact:** Potential date/time confusion
- **Severity:** LOW
- **Location:** Line 60

**Issue:** Event location displays raw field value without formatting.
- May show address components separately or unformatted
- **Impact:** Poor readability
- **Severity:** MEDIUM
- **Location:** Line 72

**Issue:** Event time display logic is nested and complex.
- Checks `field_event_start` twice (lines 57, 62)
- **Impact:** Code maintainability, potential bugs
- **Severity:** LOW
- **Location:** Lines 57-69

### 3.3 Ticket Details Issues

**Issue:** Ticket items loop shows "Quantity: X" but doesn't show individual ticket holder names clearly.
- Attendee names are in a nested list which may be hard to scan
- **Impact:** Users may miss attendee information
- **Severity:** MEDIUM
- **Location:** Lines 109-125

**Issue:** Ticket price shows total price but not unit price.
- Users can't verify per-ticket cost
- **Impact:** Reduced transparency
- **Severity:** LOW
- **Location:** Line 128

### 3.4 Missing Information

**Issue:** No "What happens next" section explaining:
- When tickets will be emailed
- How to access tickets online
- What to do if tickets don't arrive
- **Impact:** User uncertainty
- **Severity:** HIGH

**Issue:** Calendar download link uses route that may not exist or may require authentication.
- Route: `myeventlane_rsvp.ics_download`
- No error handling if route fails
- **Impact:** Broken link if route doesn't exist
- **Severity:** MEDIUM
- **Location:** Line 80

**Issue:** No contact information for event organizer.
- Users can't easily contact organizer with questions
- **Impact:** Reduced support options
- **Severity:** LOW

**Issue:** "View My Tickets" link may not work if user is not logged in.
- Route: `entity.commerce_order.user_view`
- No conditional check for user authentication
- **Impact:** Broken link for guest checkout users
- **Severity:** HIGH
- **Location:** Line 169

### 3.5 Data Accuracy Issues

**Issue:** Boost items are correctly excluded (line 36-37) but no visual confirmation.
- **Impact:** Good - Boost exclusion is working
- **Severity:** N/A (working as intended)

**Issue:** Donation total calculation uses `+` operator on Price objects.
- May cause type coercion issues
- Should use Price::add() method
- **Impact:** Potential calculation errors
- **Severity:** MEDIUM
- **Location:** Line 35

### 3.6 Styling Issues

**Issue:** Confirmation page uses mix of MEL classes (`mel-card`, `mel-btn`) and custom classes.
- Inconsistent with vendor theme patterns
- **Impact:** Visual inconsistency
- **Severity:** LOW
- **Location:** Throughout template

**Issue:** Confirmation actions use `mel-btn-ghost` which may not exist in frontend theme.
- **Impact:** Styling may not render correctly
- **Severity:** MEDIUM
- **Location:** Line 174

### 3.7 Accessibility Issues

**Issue:** Confirmation icon (emoji) has no alternative text.
- **Impact:** Screen readers won't announce icon meaning
- **Severity:** LOW
- **Location:** Line 15

**Issue:** Order number uses `<strong>` but no semantic emphasis for screen readers.
- **Impact:** Minor accessibility issue
- **Severity:** LOW
- **Location:** Line 20

---

## 4. CROSS-PAGE CONSISTENCY ISSUES

### 4.1 Terminology

**Issue:** Mixed use of "Order" vs "Cart" terminology.
- Cart page: "Order Summary"
- Checkout: "Order Summary"
- Confirmation: "Your Order"
- **Impact:** Terminology inconsistency
- **Severity:** LOW

### 4.2 Styling Patterns

**Issue:** Cart and checkout use different card patterns.
- Cart: Custom `mel-cart-summary` class
- Checkout: `mel-checkout-order-summary` class
- Vendor theme: `mel-card` pattern
- **Impact:** Visual inconsistency
- **Severity:** MEDIUM

**Issue:** Button classes inconsistent.
- Cart: `mel-btn mel-btn-primary`
- Confirmation: `mel-btn mel-btn-primary` and `mel-btn mel-btn-ghost`
- Need to verify all classes exist in frontend theme
- **Impact:** Potential broken styles
- **Severity:** MEDIUM

### 4.3 Data Flow

**Issue:** Cart → Checkout → Confirmation may show different totals if:
- Items are removed during checkout
- Prices change between cart and checkout
- **Impact:** User confusion, potential errors
- **Severity:** HIGH (needs verification)

---

## 5. PRIORITY SUMMARY

### HIGH PRIORITY (Fix in Phase 2)
1. ✅ Event grouping not visible in cart items
2. ✅ Order summary sidebar may not match cart exactly
3. ✅ Missing "What happens next" messaging on confirmation
4. ✅ "View My Tickets" link may not work for guest users
5. ✅ Donation total calculation uses wrong method

### MEDIUM PRIORITY
1. Item count terminology unclear
2. No event details in cart items
3. Payment method keyboard navigation
4. Ticket holder names hard to scan
5. Event location formatting
6. Calendar download route may not exist

### LOW PRIORITY
1. Emoji usage (accessibility)
2. Terminology consistency
3. Styling pattern consistency
4. Code maintainability improvements

---

## 6. RECOMMENDATIONS FOR PHASE 2

1. **Cart Page:**
   - Add event grouping with clear visual separation
   - Show event details (date, location) for each ticket
   - Clarify item count terminology
   - Improve empty state messaging

2. **Checkout Flow:**
   - Ensure order summary matches cart exactly
   - Improve ticket holder form clarity
   - Add "What happens next" messaging
   - Fix payment method keyboard navigation

3. **Confirmation Page:**
   - Add comprehensive "What happens next" section
   - Fix "View My Tickets" link for guest users
   - Improve ticket holder name display
   - Fix donation calculation
   - Add error handling for calendar download

4. **Cross-Page:**
   - Standardize terminology (Cart vs Order)
   - Use consistent card/button patterns
   - Verify all CSS classes exist in frontend theme
   - Add data consistency checks

---

## END OF AUDIT

**Next Steps:** Proceed to Phase 2 implementation with priority fixes.
