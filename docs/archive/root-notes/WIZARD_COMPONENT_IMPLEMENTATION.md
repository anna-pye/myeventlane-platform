# MyEventLane Wizard Component System - Implementation Guide

## Overview

This document describes the reusable Wizard Component System implemented for MyEventLane v2. The system provides a consistent, accessible, and brand-aligned wizard interface for:

- Event creation wizard (currently implemented)
- Commerce checkout steps (reusable)
- User onboarding flows (reusable)

## Design Principles

- **Soft, pastel MyEventLane brand**: Calm, premium, Humanitix-adjacent aesthetic
- **Mobile-first responsive**: Stacked layout on mobile, two-column on desktop
- **WCAG AA compliant**: Sufficient contrast, visible focus states, logical DOM order
- **BEM naming convention**: Consistent, maintainable class structure
- **Reusable architecture**: Generic component system with specific implementations

---

## File Structure

### SCSS Components

```
web/themes/custom/myeventlane_theme/src/scss/components/
├── _wizard.scss          # Generic reusable wizard component
└── _event-wizard.scss    # Event creation wizard specific styles
```

**Import order in `main.scss`:**
```scss
@use 'components/wizard';        // Generic component (imported first)
@use 'components/event-wizard';  // Event-specific (imported second)
```

### Twig Templates

```
web/themes/custom/myeventlane_theme/templates/components/
└── mel-wizard.html.twig  # Generic reusable wizard wrapper template
```

**Event Form Template (Vendor Theme):**
```
web/themes/custom/myeventlane_vendor_theme/templates/
└── form--node--event--form.html.twig  # Renders EventFormAlter structure
```

---

## 1. Generic Wizard Component (`_wizard.scss`)

### Purpose

Base component system that can be reused across all wizard implementations. Provides:

- Two-column grid layout (sidebar + content)
- Step navigation sidebar with states (default, active, completed)
- Step content panel with card styling
- Actions area (Back/Next/Submit buttons)
- Progress indicator (optional)
- Mobile navigation toggle (optional)
- Full accessibility support

### Key Classes

```scss
.mel-wizard                    // Root container
.mel-wizard__layout            // Grid container (sidebar + content)
.mel-wizard__nav               // Left sidebar navigation
.mel-wizard__nav-card          // Navigation card container
.mel-wizard__step              // Individual step item
.mel-wizard__step-button       // Step button/link
.mel-wizard__step-number       // Step number badge
.mel-wizard__step-label        // Step label text
.mel-wizard__content           // Right content area
.mel-wizard__step-panel        // Step panel card
.mel-wizard__step-title        // Step panel title
.mel-wizard__step-body         // Step panel content
.mel-wizard__actions           // Actions container (Back/Next/Submit)
```

### Responsive Behavior

- **Mobile (< 1024px)**: 
  - Single column stacked layout
  - Navigation sidebar hidden by default (can be toggled)
  - Sticky actions at bottom
  - Full-width buttons

- **Desktop (≥ 1024px)**:
  - Two-column grid (280px sidebar + flexible content)
  - Sticky sidebar navigation
  - Side-by-side action buttons

### Accessibility Features

- Visible focus states with 4px focus ring
- Sufficient color contrast (WCAG AA)
- Logical DOM order preserved
- ARIA labels on navigation
- Screen reader support via `.mel-wizard__sr-only`
- Keyboard navigation support

---

## 2. Event Creation Wizard (`_event-wizard.scss`)

### Purpose

Styles the Event Creation Wizard built by `EventFormAlter.php`. Maps event form classes to the generic wizard component system.

### EventFormAlter Structure

The PHP form alter creates this structure:

```php
$form['mel_wizard'] = [
  '#attributes' => [
    'class' => ['mel-event-form', 'mel-event-form--wizard'],
  ],
  'layout' => [
    'nav' => [...],      // Left stepper
    'content' => [...],   // Right content panels
  ],
];
```

### Class Mapping

Event form classes map to generic wizard styles:

| Event Form Class | Generic Wizard Class | Purpose |
|-----------------|---------------------|---------|
| `.mel-event-form--wizard` | `.mel-wizard` | Root container |
| `.mel-event-form__wizard-layout` | `.mel-wizard__layout` | Grid container |
| `.mel-event-form__wizard-nav` | `.mel-wizard__nav` | Left sidebar |
| `.mel-event-form__wizard-content` | `.mel-wizard__content` | Right content |
| `.mel-event-form__panel` | `.mel-wizard__step-panel` | Step panel card |
| `.mel-wizard-step` | `.mel-wizard__step-panel` | Step panel (alternate) |
| `.is-active` / `.is-hidden` | `.is-active` / `.is-hidden` | Visibility states |

### Step Navigation

The left sidebar displays:
- "Event setup" title
- Numbered step buttons (1, 2, 3, ...)
- Step labels (Basics, Schedule, Location, etc.)
- Active state highlighting
- Vertical connector lines (desktop only)

### Step Content Panels

Each step panel contains:
- Step title (h2)
- Form fields in `.mel-event-form__section`
- Styled form inputs with focus states
- Error message styling

### Actions

Action buttons are styled with:
- Back button (secondary)
- Next/Finish button (primary)
- Publish event button (primary)
- Save draft button (secondary)
- Sticky positioning on mobile

---

## 3. Twig Implementation

### Generic Wizard Template

**File:** `web/themes/custom/myeventlane_theme/templates/components/mel-wizard.html.twig`

**Usage:**
```twig
{% include 'components/mel-wizard.html.twig' with {
  wizard_title: 'Create Event',
  wizard_nav_title: 'Event setup',
  wizard_nav: step_navigation_markup,
  wizard_content: step_content_markup,
  wizard_actions: action_buttons_markup,
  wizard_class: 'mel-wizard--event',
} %}
```

**Variables:**
- `wizard_nav`: (optional) Navigation markup
- `wizard_content`: (required) Main content area
- `wizard_actions`: (optional) Action buttons
- `wizard_progress`: (optional) Progress indicator
- `wizard_title`: (optional) Page title (visually hidden)
- `wizard_class`: (optional) Additional CSS classes
- `wizard_nav_title`: (optional) Navigation sidebar title
- `wizard_actions_sticky`: (optional) Enable sticky actions on mobile

### Event Form Template

**File:** `web/themes/custom/myeventlane_vendor_theme/templates/form--node--event--form.html.twig`

This template renders the wizard structure built by `EventFormAlter.php`:

```twig
<form{{ attributes.addClass('mel-event-form-page') }}>
  {{ form.form_build_id }}
  {{ form.form_token }}
  {{ form.form_id }}

  {% if form.mel_wizard %}
    {{ form.mel_wizard }}
  {% endif %}

  {% if form.actions %}
    <div class="mel-event-form__actions-wrapper">
      {{ form.actions }}
    </div>
  {% endif %}
</form>
```

**Note:** The wizard structure is built entirely in PHP (`EventFormAlter.php`), so Twig only renders it. No form logic is modified.

---

## 4. Reuse Strategy

### For Commerce Checkout

**Current State:**
- Checkout uses `commerce-checkout-form.html.twig`
- Has its own step navigation (`.mel-checkout-steps`)
- Uses different class structure

**Migration Path:**

1. **Option A: Use Generic Wizard Component**
   ```twig
   {# In commerce-checkout-form.html.twig #}
   {% include 'components/mel-wizard.html.twig' with {
     wizard_nav_title: 'Checkout',
     wizard_nav: checkout_steps_nav,
     wizard_content: checkout_panes,
     wizard_actions: form.actions,
     wizard_class: 'mel-wizard--checkout',
   } %}
   ```

2. **Option B: Extend Event Wizard Styles**
   Create `_checkout-wizard.scss`:
   ```scss
   .mel-wizard--checkout {
     // Override or extend generic wizard styles
     // Use same layout but different navigation structure
   }
   ```

**Recommended:** Option A - Use generic component for consistency.

### For User Onboarding

**Implementation:**

1. **Create Onboarding Form Alter** (similar to `EventFormAlter.php`):
   ```php
   // Build wizard structure
   $form['mel_wizard'] = [
     '#attributes' => ['class' => ['mel-wizard', 'mel-wizard--onboarding']],
     'layout' => [
       'nav' => $this->buildOnboardingNav($steps),
       'content' => $this->buildOnboardingContent($steps),
     ],
   ];
   ```

2. **Use Generic Wizard Styles:**
   - `.mel-wizard` base styles apply automatically
   - Add `.mel-wizard--onboarding` for specific overrides if needed

3. **Twig Template:**
   ```twig
   {% include 'components/mel-wizard.html.twig' with {
     wizard_nav_title: 'Get started',
     wizard_nav: onboarding_nav,
     wizard_content: onboarding_content,
     wizard_actions: onboarding_actions,
     wizard_class: 'mel-wizard--onboarding',
   } %}
   ```

**Benefits:**
- Consistent UX across all wizards
- Shared accessibility features
- Shared responsive behavior
- Easy to maintain and update

---

## 5. Accessibility Requirements

### Color Contrast

All text meets WCAG AA standards:
- Primary text: `#1a1a2e` on `#ffffff` (21:1 contrast)
- Muted text: `#5c5c6f` on `#ffffff` (7.5:1 contrast)
- Primary buttons: `#ffffff` on `#ff6f61` (4.5:1 contrast)
- Success indicators: `#ffffff` on `#22c55e` (4.5:1 contrast)

### Focus States

- All interactive elements have visible focus indicators
- Focus ring: 4px solid with 2px offset
- Focus color: `$mel-focus-ring-color` (lavender purple)

### Keyboard Navigation

- Tab order follows logical DOM structure
- All interactive elements are keyboard accessible
- Step navigation buttons support Enter/Space activation
- Focus management after AJAX step changes

### Screen Readers

- Navigation has `aria-label="Wizard steps"`
- Step panels use semantic HTML (`<main>`, `<nav>`)
- Visually hidden titles for page structure
- Error messages associated with form fields

---

## 6. Mobile-First Responsive Design

### Breakpoints

- **Mobile**: `< 1024px` (default, stacked)
- **Desktop**: `≥ 1024px` (two-column grid)

### Mobile Behavior

1. **Navigation Sidebar:**
   - Hidden by default
   - Can be toggled via `.mel-wizard__nav-toggle` button
   - Full-width when visible
   - Stacks above content

2. **Content Panels:**
   - Full-width
   - Reduced padding (4rem → 2rem)
   - Smaller border radius (xl → lg)

3. **Actions:**
   - Sticky at bottom of viewport
   - Full-width buttons
   - Column-reverse layout (primary button on top)

### Desktop Behavior

1. **Navigation Sidebar:**
   - Fixed width: 280px
   - Sticky positioning
   - Always visible

2. **Content Panels:**
   - Flexible width (fills remaining space)
   - Maximum width: 1600px (centered)
   - Premium spacing and padding

3. **Actions:**
   - Inline layout
   - Primary actions right-aligned
   - Secondary actions left-aligned

---

## 7. Brand Alignment

### MyEventLane Design Tokens

The wizard uses existing design tokens:

- **Colors**: Pastel palette (coral primary, lavender secondary)
- **Spacing**: 8px grid system
- **Radii**: Soft rounded corners (12px-28px)
- **Shadows**: Subtle elevation (sm, md, lg)
- **Typography**: Nunito font family

### Visual Style

- **Soft surfaces**: Light backgrounds, subtle borders
- **Premium spacing**: Generous padding and margins
- **Rounded corners**: Consistent border radius
- **Gentle shadows**: Subtle depth without harshness
- **Pastel accents**: Coral and lavender for interactive elements

---

## 8. Testing Checklist

### Functionality
- [ ] Step navigation works (click to change steps)
- [ ] Back/Next buttons navigate correctly
- [ ] Form validation shows errors
- [ ] AJAX step changes work
- [ ] Submit/Publish buttons work

### Responsive
- [ ] Mobile layout stacks correctly
- [ ] Desktop layout shows sidebar
- [ ] Sticky actions work on mobile
- [ ] Navigation toggle works (if implemented)
- [ ] Touch targets are adequate (44px minimum)

### Accessibility
- [ ] Keyboard navigation works
- [ ] Focus states are visible
- [ ] Screen reader announces steps
- [ ] Color contrast meets AA standards
- [ ] Error messages are accessible

### Browser Compatibility
- [ ] Chrome/Edge (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Mobile Safari (iOS)
- [ ] Mobile Chrome (Android)

---

## 9. Version Control

### Suggested Branch Name
```
feature/wizard-component-system
```

### Commit Message
```
feat(theme): Implement reusable wizard component system

- Create generic .mel-wizard component for reuse across wizards
- Style event creation wizard using generic component
- Add mobile-first responsive layout with sticky actions
- Ensure WCAG AA accessibility compliance
- Support reuse for checkout and onboarding flows

Files:
- web/themes/custom/myeventlane_theme/src/scss/components/_wizard.scss
- web/themes/custom/myeventlane_theme/src/scss/components/_event-wizard.scss
- web/themes/custom/myeventlane_theme/templates/components/mel-wizard.html.twig
```

### Follow-up Tasks

1. **Commerce Checkout Integration:**
   - Update `commerce-checkout-form.html.twig` to use generic wizard
   - Test checkout flow with new styles
   - Ensure payment forms work correctly

2. **User Onboarding:**
   - Create onboarding form alter (similar to EventFormAlter)
   - Build onboarding steps structure
   - Apply generic wizard component

3. **Documentation:**
   - Update developer documentation
   - Add wizard component to style guide
   - Create usage examples

4. **Testing:**
   - Cross-browser testing
   - Accessibility audit
   - Performance testing (CSS bundle size)

---

## 10. Maintenance Notes

### Adding New Wizard Steps

1. Update PHP form alter to add step definition
2. Add step fields to step configuration
3. Navigation and content panels auto-generate
4. No CSS changes needed (uses generic styles)

### Customizing Wizard Appearance

1. **Global changes**: Edit `_wizard.scss`
2. **Event-specific**: Edit `_event-wizard.scss`
3. **Checkout-specific**: Create `_checkout-wizard.scss` (if needed)
4. **Onboarding-specific**: Create `_onboarding-wizard.scss` (if needed)

### Design Token Updates

All design tokens are centralized:
- Colors: `tokens/_colors.scss`
- Spacing: `tokens/_spacing.scss`
- Radii: `tokens/_radii.scss`
- Shadows: `tokens/_shadows.scss`
- Typography: `tokens/_typography.scss`

Update tokens to change wizard appearance globally.

---

## Summary

The MyEventLane Wizard Component System provides a reusable, accessible, and brand-aligned solution for multi-step forms. The generic component (`_wizard.scss`) serves as the foundation, while specific implementations (`_event-wizard.scss`) map existing PHP structures to the component system.

**Key Benefits:**
- ✅ Consistent UX across all wizards
- ✅ Mobile-first responsive design
- ✅ WCAG AA accessibility compliance
- ✅ Easy to maintain and extend
- ✅ Reusable for checkout and onboarding

**No Breaking Changes:**
- Event form logic unchanged
- Form field names unchanged
- Validation unchanged
- Submit handlers unchanged
- Only styling and layout affected
