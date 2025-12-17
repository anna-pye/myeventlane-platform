# Theme Verification Checklist

Use this checklist to verify all improvements are working correctly after deployment.

## Pre-Deployment

- [ ] All files committed to git
- [ ] Vite build completed successfully: `ddev npm run build`
- [ ] No console errors in build output
- [ ] Drupal cache cleared: `ddev drush cr`

## Menu System

- [ ] Main menu block placed in Header region (`/admin/structure/block`)
- [ ] Desktop navigation displays menu items correctly
- [ ] Mobile navigation displays menu items correctly
- [ ] Menu active trail works (current page highlighted)
- [ ] Menu hierarchy works (if submenus exist)
- [ ] Fallback hard-coded menu works if block not configured

## Header Component

- [ ] Header renders consistently across all pages
- [ ] Logo links to homepage
- [ ] Desktop navigation visible on screens 768px+
- [ ] Mobile toggle button visible on screens < 768px
- [ ] Account dropdown works (if logged in)
- [ ] Shopping cart displays (if items in cart)
- [ ] "Create Event" button visible and functional

## Mobile Menu

- [ ] Mobile toggle button opens menu
- [ ] Mobile menu slides in from right
- [ ] Overlay appears behind menu
- [ ] Close button (×) closes menu
- [ ] Clicking overlay closes menu
- [ ] Escape key closes menu
- [ ] Focus trap works (Tab cycles within menu)
- [ ] Focus returns to toggle when menu closes
- [ ] Menu closes when resizing to desktop width
- [ ] All menu links are minimum 44px height
- [ ] Menu accessible with keyboard only
- [ ] Screen reader announces menu state correctly

## Accessibility

- [ ] All interactive elements are 44px × 44px minimum
- [ ] Focus indicators visible on all interactive elements
- [ ] Skip link works (jumps to main content)
- [ ] ARIA attributes correct (aria-expanded, aria-hidden, etc.)
- [ ] Keyboard navigation works throughout
- [ ] Screen reader tested (VoiceOver/NVDA/JAWS)
- [ ] Color contrast meets WCAG AA (4.5:1 for text)

## Spacing & Layout

- [ ] Spacing uses 8px grid system
- [ ] No visual regressions in layout
- [ ] Mobile-first responsive breakpoints work
- [ ] Header sticky positioning works
- [ ] Footer displays correctly

## JavaScript

- [ ] No inline JavaScript in templates
- [ ] All JavaScript uses Drupal behaviors
- [ ] Mobile menu JavaScript loads correctly
- [ ] Account dropdown JavaScript loads correctly
- [ ] No console errors in browser
- [ ] JavaScript works after AJAX updates

## Templates

- [ ] `page.html.twig` uses header include
- [ ] `page.html.twig` uses footer include
- [ ] `page.html.twig` has title_prefix/suffix
- [ ] Menu templates render correctly
- [ ] No duplicate header markup in other templates

## SCSS

- [ ] Vite compiles SCSS without errors
- [ ] All spacing tokens used correctly
- [ ] Color tokens used consistently
- [ ] Mobile-first approach verified
- [ ] No CSS specificity issues

## Browser Testing

- [ ] Chrome/Edge (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Mobile Safari (iOS)
- [ ] Chrome Mobile (Android)

## Performance

- [ ] Page load time acceptable
- [ ] CSS file size reasonable
- [ ] JavaScript file size reasonable
- [ ] No render-blocking resources
- [ ] Images optimized

## Drupal Integration

- [ ] Contextual links work (edit buttons)
- [ ] Block system works
- [ ] Menu management works
- [ ] Theme settings work (if any)
- [ ] Cache works correctly

## Known Issues

List any issues found during testing:

1. 
2. 
3. 

---

## Quick Test Commands

```bash
# Clear cache
ddev drush cr

# Rebuild assets
cd web/themes/custom/myeventlane_theme
ddev npm run build

# Check for linting errors
ddev exec vendor/bin/phpcs web/themes/custom/myeventlane_theme

# Check for PHP errors
ddev exec vendor/bin/phpstan web/themes/custom/myeventlane_theme
```

---

**Last Updated:** 2024


















