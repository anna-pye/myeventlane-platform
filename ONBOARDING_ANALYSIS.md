# MyEventLane Onboarding Analysis & Improvement Plan

**Date:** 2025-01-27  
**Status:** Analysis Complete, Implementation Starting

> **Note:** Before starting development, review `CURSOR_GIT_RULES.md` for Git safety guidelines and repository workflow rules.

---

## Current State Analysis

### Vendor Onboarding Flow

**Current Path:**
1. User clicks "Create Organiser Account" → `/user/register?vendor=1`
2. Registration form with vendor notice banner
3. After registration → redirects to `/vendor/onboard`
4. Vendor entity form (VendorForm) - single page with all fields
5. After save → redirects to `/create-event` gateway
6. Stripe Connect is separate at `/vendor/stripe/connect` (not integrated)
7. Event creation is separate journey

**Key Problems:**
- ❌ No step-by-step progression or progress indicators
- ❌ Forms render at default Drupal width (not mobile-optimized)
- ❌ No clear guidance on what to do next
- ❌ Stripe Connect is disconnected from onboarding
- ❌ No "create first event" guided journey
- ❌ Vendor form uses default Drupal styling (fieldsets, not branded)
- ❌ No breadcrumbs or navigation context
- ❌ Missing conditional field states (#states)
- ❌ No validation feedback or helpful error messages
- ❌ Admin theme leaks through on some pages

### Customer Onboarding Flow

**Current Path:**
1. User clicks "Create Account" → `/user/register`
2. Standard Drupal registration form
3. After registration → redirects to `/user` (default)
4. Customer dashboard at `/my-events` shows tickets/RSVPs
5. No introduction or explanation

**Key Problems:**
- ❌ No onboarding flow - just registration
- ❌ No explanation of RSVP vs Paid events
- ❌ No "My Tickets" introduction panel
- ❌ No email verification guidance
- ❌ No account recovery explanation
- ❌ Customer dashboard appears without context

---

## Improved Flows

### Vendor Onboarding (5 Steps)

**Step 1: Account Creation**
- Route: `/vendor/onboard/account`
- Purpose: Create user account (if not logged in) or verify existing
- Features:
  - Branded registration/login page
  - Clear messaging: "Create your organiser account"
  - Mobile-first responsive layout
  - Email verification guidance

**Step 2: Vendor Profile Setup**
- Route: `/vendor/onboard/profile`
- Purpose: Set up vendor/organiser profile
- Features:
  - Logo upload (with preview)
  - Public name/business name
  - Bio/description
  - Contact information (email, phone, website)
  - Conditional fields using #states
  - Progress indicator (Step 2 of 5)
  - Breadcrumbs

**Step 3: Stripe Connect Onboarding**
- Route: `/vendor/onboard/stripe`
- Purpose: Connect Stripe account for payments
- Features:
  - Clear explanation of why Stripe is needed
  - "Connect Stripe" button → external Stripe onboarding
  - Callback handling with status verification
  - Progress indicator (Step 3 of 5)
  - Option to skip (with warning)

**Step 4: Create First Event (Guided)**
- Route: `/vendor/onboard/first-event`
- Purpose: Guided event creation journey
- Features:
  - Simplified event form (essential fields only)
  - Step-by-step guidance
  - Pre-filled vendor information
  - Progress indicator (Step 4 of 5)
  - "Save Draft" and "Continue" options

**Step 5: Dashboard Introduction**
- Route: `/vendor/onboard/complete`
- Purpose: Welcome to dashboard, show key features
- Features:
  - Welcome message
  - Dashboard tour highlights
  - Quick links to:
    - Create another event
    - View dashboard
    - Manage profile
  - Progress indicator (Complete!)

### Customer Onboarding (4 Steps)

**Step 1: Account Creation / Sign In**
- Route: `/onboard/account`
- Purpose: Create account or sign in
- Features:
  - Branded registration/login page
  - Clear distinction: "Customer account" vs "Organiser account"
  - Mobile-first responsive layout
  - Email verification guidance

**Step 2: Understanding RSVPs & Tickets**
- Route: `/onboard/explore`
- Purpose: Explain how MyEventLane works
- Features:
  - Visual explanation of RSVP vs Paid events
  - How to find events
  - How to RSVP
  - How to purchase tickets
  - Interactive cards/illustrations

**Step 3: First Purchase / RSVP Flow**
- Route: `/onboard/first-action`
- Purpose: Guide to first event interaction
- Features:
  - Browse featured events
  - Try RSVP or purchase flow
  - Progress indicator
  - Helpful tips

**Step 4: My Tickets Introduction**
- Route: `/onboard/my-tickets`
- Purpose: Show "My Events" dashboard
- Features:
  - Welcome to "My Events"
  - Explanation of what appears here
  - How to download .ics files
  - How to view ticket codes
  - Link to dashboard

---

## Technical Implementation Plan

### New Routes

**Vendor Onboarding:**
- `myeventlane_vendor.onboard.account` → `/vendor/onboard/account`
- `myeventlane_vendor.onboard.profile` → `/vendor/onboard/profile`
- `myeventlane_vendor.onboard.stripe` → `/vendor/onboard/stripe`
- `myeventlane_vendor.onboard.first_event` → `/vendor/onboard/first-event`
- `myeventlane_vendor.onboard.complete` → `/vendor/onboard/complete`

**Customer Onboarding:**
- `myeventlane_core.onboard.account` → `/onboard/account`
- `myeventlane_core.onboard.explore` → `/onboard/explore`
- `myeventlane_core.onboard.first_action` → `/onboard/first-action`
- `myeventlane_core.onboard.my_tickets` → `/onboard/my-tickets`

### New Controllers

**Vendor:**
- `VendorOnboardAccountController`
- `VendorOnboardProfileController`
- `VendorOnboardStripeController` (enhance existing)
- `VendorOnboardFirstEventController`
- `VendorOnboardCompleteController`

**Customer:**
- `CustomerOnboardAccountController`
- `CustomerOnboardExploreController`
- `CustomerOnboardFirstActionController`
- `CustomerOnboardMyTicketsController`

### New Forms

- `VendorOnboardProfileForm` (enhanced VendorForm)
- `VendorOnboardFirstEventForm` (simplified event form)

### New Theme Templates

- `page--vendor-onboard.html.twig` (wrapper with step indicator)
- `vendor-onboard-step.html.twig` (reusable step template)
- `page--customer-onboard.html.twig` (wrapper)
- `customer-onboard-step.html.twig` (reusable step template)

### New CSS

- `src/scss/pages/_onboarding.scss` (onboarding-specific styles)
- Mobile-first responsive layout
- Step indicator component
- Progress bar
- Form improvements

---

## Design Principles

1. **Mobile-First:** All layouts start with mobile, enhance for desktop
2. **Accessibility:** WCAG AA compliant (focus states, ARIA labels, keyboard navigation)
3. **Clear Language:** Plain, friendly, gender-neutral text
4. **Visual Consistency:** Aligned with MyEventLane brand colors and typography
5. **Progressive Disclosure:** Show only what's needed at each step
6. **Error Prevention:** Clear validation, helpful error messages
7. **Guidance:** Contextual help, tooltips, progress indicators

---

## Next Steps

1. ✅ Analysis complete
2. ⏳ Implement vendor onboarding controllers
3. ⏳ Implement customer onboarding controllers
4. ⏳ Create theme templates
5. ⏳ Add CSS styling
6. ⏳ Test and refine

















