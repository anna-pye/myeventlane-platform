# Event Wizard — Mobile & Width Standardization Audit

## Width Standardization (Completed)

### Before
- `.mel-event-wizard__main`: `max-width: 900px`
- `.mel-wizard-step-card`: `max-width: 760px` (nested, further constraining)

### After
- `.mel-event-wizard__main`: `max-width: 67.5rem` (1080px — matches `$layout-content-max-width`)
- Content centred with `margin: 0 auto`
- `.page--vendor-event-wizard` overrides ensure frame and card also use 67.5rem

### Files Changed
- `web/modules/custom/myeventlane_event/css/event-wizard.css`
- `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_event-form.scss`

---

## Mobile Layout Audit

### Current Implementation

| Component | Desktop | Mobile (< 1024px) |
|-----------|---------|-------------------|
| **Sidebar** | Fixed/sticky, 280px | `transform: translateX(-100%)`; toggled via `[data-sidebar-toggle]` |
| **Wizard steps** | In sidebar | Same — sidebar must be opened to access |
| **Main content** | Centred, max 1080px | Full width minus padding |
| **Sticky CTA footer** | Inline with content | `position: fixed; bottom: 0` |
| **Form fields** | Standard | Touch targets ≥44px (WCAG) |

### Mobile Concerns

1. **Sidebar access** — Wizard steps live in the left sidebar. On mobile, the sidebar is off-screen until the user opens it. First-time vendors may not discover the step navigation.
2. **Recommendation**: Consider a horizontal step indicator (pills/chips) in the main content on mobile, or a floating "Steps" button that opens a bottom sheet with the 6 steps.
3. **Sticky footer** — Already implemented in `_wizard.scss` (`.mel-wizard-form-body .mel-wizard__actions` at `respond-down(md)`). Good.
4. **Form density** — Padding reduced at 480px (16px). Adequate.

### Existing Mobile Optimizations

- `padding-bottom: 6rem` on form body to prevent content hiding behind sticky footer
- `min-height: 3rem` on primary buttons
- `safe-area-inset` for notched devices

### Recommended Follow-up (Backlog)

- Add a mobile-specific step indicator (e.g. horizontal stepper or "Steps (3/6)" pill) when viewport < 768px
- Ensure sidebar toggle is visible and labelled for accessibility
