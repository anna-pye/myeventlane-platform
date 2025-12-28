# MyEventLane Radix Theme - Implementation Checklist

**Date:** 2025-01-27  
**Branch:** theme/radix-foundation  
**Status:** Ready for Testing

---

## ✅ Completed Tasks

### Task Group 1: Event Wizard Template Migration
- [x] Created Radix-compatible Event form template (`form--node--event--form.html.twig`)
- [x] Created generic wizard component template (`components/mel-wizard.html.twig`)
- [x] Created wizard component SCSS (`components/_wizard.scss`)
- [x] Updated main.scss to include wizard component
- [x] Preserved all form IDs, element names, and states
- [x] Used Bootstrap 5 markup (grid, cards, buttons, utilities)

### Task Group 2: Commerce Checkout Theming
- [x] Created Radix-compatible checkout template (`commerce/commerce-checkout-form.html.twig`)
- [x] Applied Bootstrap 5 markup (cards, grid, alerts, buttons)
- [x] Preserved checkout configuration and Commerce services
- [x] Maintained AJAX wrapper IDs and form structure
- [x] Added accessibility improvements (ARIA labels, semantic HTML)

### Task Group 3: Bootstrap Purge & Performance
- [x] Created comprehensive purge strategy documentation (`PURGE_STRATEGY.md`)
- [x] Documented safelist patterns for Bootstrap utilities and components
- [x] Provided tool-agnostic configuration examples
- [x] Created performance checklist
- [x] Updated existing `purgecss.config.js` with detailed comments

### Task Group 4: SDC Component Conversion
- [x] Created Event Card SDC component
- [x] Created Ticket Row SDC component
- [x] Created Price Display SDC component
- [x] Created Wizard Step Header SDC component
- [x] Created SDC implementation guide (`components/SDC_IMPLEMENTATION.md`)
- [x] Documented incremental adoption strategy

---

## ⚠️ Safety Notes

### DO NOT:
- ❌ Remove, disable, or alter Stable9 theme
- ❌ Change the active default theme
- ❌ Modify PHP logic in custom modules (EventFormAlter.php, etc.)
- ❌ Break AJAX functionality (preserve wrapper IDs)
- ❌ Break form validation (preserve form element IDs)
- ❌ Remove Commerce checkout configuration
- ❌ Alter pane ordering in checkout flow

### Safe to Commit:
- ✅ New Radix theme templates (they won't affect Stable9)
- ✅ SCSS component files
- ✅ SDC component examples
- ✅ Documentation files
- ✅ PurgeCSS configuration

---

## 🧪 Manual Testing Required

### Event Wizard
- [ ] Event form renders correctly with wizard structure
- [ ] Step navigation works (clicking steps changes active step)
- [ ] Back/Next buttons work correctly
- [ ] Form validation works (errors display on current step only)
- [ ] AJAX step transitions work (no full page reload)
- [ ] Save draft button works
- [ ] Publish event button works
- [ ] All form fields are accessible and functional
- [ ] Mobile responsive (sidebar collapses, actions sticky)
- [ ] Keyboard navigation works (Tab through form fields)

### Commerce Checkout
- [ ] Checkout form renders correctly
- [ ] Progress steps display correctly
- [ ] Checkout panes render in correct order
- [ ] Form validation works (errors display correctly)
- [ ] AJAX pane refresh works (no full page reload)
- [ ] Stripe payment fields initialize correctly
- [ ] Order summary sidebar displays correctly
- [ ] Mobile checkout is usable (responsive layout)
- [ ] Form submission works (order creation)
- [ ] Error messages display with proper styling

### Bootstrap 5 Integration
- [ ] Bootstrap grid works (row/col classes)
- [ ] Bootstrap cards render correctly
- [ ] Bootstrap buttons work (primary, secondary, etc.)
- [ ] Bootstrap forms work (form-control, form-label, etc.)
- [ ] Bootstrap utilities work (spacing, display, etc.)
- [ ] Bootstrap modals work (if used)
- [ ] Bootstrap dropdowns work (if used)

### SDC Components
- [ ] Event Card component renders correctly
- [ ] Ticket Row component renders correctly
- [ ] Price Display component renders correctly
- [ ] Wizard Step Header component renders correctly
- [ ] Components are reusable across different contexts
- [ ] Component props validation works

### CSS Purging (Production)
- [ ] PurgeCSS configuration works (if enabled)
- [ ] All required Bootstrap classes are preserved
- [ ] All MyEventLane classes (mel-*) are preserved
- [ ] JavaScript-dependent classes are preserved
- [ ] CSS file size is reduced appropriately
- [ ] No missing styles after purging

---

## 📋 Pre-Merge Checklist

### Code Quality
- [ ] All templates use Bootstrap 5 markup correctly
- [ ] All form IDs and element names are preserved
- [ ] All AJAX wrapper IDs are preserved
- [ ] Accessibility attributes are present (ARIA labels, semantic HTML)
- [ ] Mobile-first responsive design is implemented
- [ ] SCSS follows MyEventLane design tokens

### Documentation
- [ ] All new files have proper file headers
- [ ] Inline comments explain key decisions
- [ ] Component usage is documented
- [ ] Purge strategy is documented
- [ ] SDC implementation guide is complete

### Testing
- [ ] Event Wizard tested manually
- [ ] Commerce Checkout tested manually
- [ ] Bootstrap components tested
- [ ] SDC components tested (if enabled)
- [ ] No console errors
- [ ] No PHP errors in logs

---

## 🚀 Deployment Steps

### 1. Development Testing
```bash
# Switch to Radix theme (temporarily for testing)
ddev drush config:set system.theme default myeventlane_radix
ddev drush cr

# Test Event Wizard
# Navigate to: /vendor/events/add

# Test Checkout
# Navigate to: /checkout
```

### 2. Build Assets (if using Vite)
```bash
cd web/themes/custom/myeventlane_radix
ddev exec npm run build
```

### 3. Clear Cache
```bash
ddev drush cr
```

### 4. Revert to Stable9 (if not ready for production)
```bash
ddev drush config:set system.theme default myeventlane_theme
ddev drush cr
```

---

## 📝 Notes

### Assumptions Made
1. **Radix theme is installed:** `ddev composer require drupal/radix`
2. **Bootstrap 5 is included:** Via Radix base theme
3. **Build tooling is configured:** Vite/Webpack/Gulp for SCSS compilation
4. **SDC module is available:** Drupal 11 includes SDC module

### Known Limitations
1. **Stripe initialization:** Checkout template does not include Stripe.js initialization scripts (should be in module or library)
2. **Form alter logic:** EventFormAlter.php structure is preserved exactly - no PHP changes made
3. **Commerce configuration:** Checkout pane ordering and configuration unchanged

### Future Enhancements
1. **SDC adoption:** Gradually migrate more components to SDCs
2. **Performance optimization:** Implement CSS purging in production builds
3. **Accessibility audit:** Full WCAG AA compliance audit
4. **Browser testing:** Test across major browsers (Chrome, Firefox, Safari, Edge)

---

## 🔗 Related Files

### Templates
- `templates/form--node--event--form.html.twig` - Event form wrapper
- `templates/components/mel-wizard.html.twig` - Generic wizard component
- `templates/commerce/commerce-checkout-form.html.twig` - Checkout form

### SCSS
- `src/scss/components/_wizard.scss` - Wizard component styles
- `src/scss/components/_checkout.scss` - Checkout component styles
- `src/scss/components/_forms.scss` - Form component styles

### SDCs
- `components/event-card/` - Event card component
- `components/ticket-row/` - Ticket row component
- `components/price-display/` - Price display component
- `components/wizard-step-header/` - Wizard step header component

### Documentation
- `PURGE_STRATEGY.md` - CSS purging strategy
- `components/SDC_IMPLEMENTATION.md` - SDC implementation guide
- `README.md` - Theme overview

---

## ✅ Final Status

**All four task groups are complete and ready for testing.**

The Radix theme templates are safe to commit as they:
- Do not affect the Stable9 theme
- Do not modify PHP logic
- Preserve all form IDs and AJAX functionality
- Use Bootstrap 5 markup correctly
- Include comprehensive documentation

**Next Steps:**
1. Manual testing (see checklist above)
2. Fix any issues found during testing
3. Enable theme temporarily for user acceptance testing
4. Plan incremental rollout strategy

---

**Last Updated:** 2025-01-27
