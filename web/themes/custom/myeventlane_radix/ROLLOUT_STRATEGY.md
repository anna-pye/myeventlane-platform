# MyEventLane Radix Theme - Rollout Strategy

Incremental migration strategy from Stable9 theme to Radix (Bootstrap 5) theme.

## Overview

This document outlines the step-by-step approach to migrating from `myeventlane_theme` (Stable9) to `myeventlane_radix` (Bootstrap 5) without disrupting production.

## Principles

1. **Non-destructive**: Existing theme remains untouched
2. **Incremental**: Migrate page by page, component by component
3. **Testable**: Each phase can be tested independently
4. **Reversible**: Can roll back at any point
5. **Safe**: All changes on feature branch until ready

## Phase 1: Foundation (✅ COMPLETE)

- [x] Create theme structure
- [x] Extract design tokens
- [x] Set up build tooling (Vite)
- [x] Create base components (buttons, cards, forms)
- [x] Create layout components (header, footer, navigation)
- [x] Set up SDC structure
- [x] Create Twig templates
- [x] Configure PurgeCSS

**Status**: Complete. Theme is ready for development and testing.

## Phase 2: Component Development

### 2.1 Core Components
- [ ] Test button variants in isolation
- [ ] Test card components
- [ ] Test form components
- [ ] Test event-card component
- [ ] Verify Bootstrap 5 integration

### 2.2 Layout Components
- [ ] Test header component
- [ ] Test footer component
- [ ] Test navigation components
- [ ] Verify responsive behavior
- [ ] Test accessibility (keyboard, screen readers)

### 2.3 Build & Compile
- [ ] Install Radix theme: `ddev composer require drupal/radix`
- [ ] Install npm dependencies: `ddev npm install`
- [ ] Test SCSS compilation: `ddev npm run build`
- [ ] Verify CSS output in `dist/main.css`
- [ ] Test dev server: `ddev npm run dev`

## Phase 3: Page-by-Page Migration

### 3.1 Static Pages (Low Risk)
1. **About/Contact Pages**
   - Enable theme on specific routes via theme negotiator
   - Test visual consistency
   - Verify functionality
   - Document any issues

2. **Event Listing Pages**
   - Test event grid layout
   - Test event card display
   - Test filters and search
   - Verify pagination

3. **Category/Taxonomy Pages**
   - Test category listings
   - Test breadcrumbs
   - Verify navigation

### 3.2 Dynamic Pages (Medium Risk)
4. **Event Detail Pages**
   - Test event hero
   - Test event content layout
   - Test RSVP/ticket buttons
   - Test related events
   - Verify Commerce integration

5. **User Dashboard**
   - Test dashboard layout
   - Test user profile
   - Test event management
   - Verify forms

### 3.3 Commerce Pages (High Risk)
6. **Checkout Flow**
   - Test checkout panes
   - Test payment forms
   - Test order summary
   - Test Stripe/PayPal integration
   - **CRITICAL**: Verify payment processing works

7. **Cart**
   - Test cart display
   - Test quantity updates
   - Test remove items
   - Verify Commerce calculations

## Phase 4: Theme Negotiator Implementation

Create a custom theme negotiator to enable Radix theme on specific routes:

```php
// web/themes/custom/myeventlane_radix/src/Theme/MyEventLaneRadixNegotiator.php
namespace Drupal\myeventlane_radix\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

class MyEventLaneRadixNegotiator implements ThemeNegotiatorInterface {
  public function applies(RouteMatchInterface $route_match) {
    // Enable on specific routes for testing
    $route_name = $route_match->getRouteName();
    $test_routes = [
      'entity.node.canonical',
      'view.events.page_1',
      // Add more routes as needed
    ];
    return in_array($route_name, $test_routes);
  }

  public function determineActiveTheme(RouteMatchInterface $route_match) {
    return 'myeventlane_radix';
  }
}
```

**Benefits**:
- Test new theme without changing default
- Enable on specific routes only
- Easy to disable if issues arise

## Phase 5: Side-by-Side Comparison

### 5.1 Visual Regression Testing
- [ ] Set up visual regression tool (e.g., BackstopJS, Percy)
- [ ] Capture screenshots of key pages in both themes
- [ ] Compare and document differences
- [ ] Fix any visual inconsistencies

### 5.2 Functional Testing
- [ ] Test all forms (event creation, RSVP, checkout)
- [ ] Test all buttons and links
- [ ] Test responsive breakpoints
- [ ] Test accessibility (WCAG 2.1 AA)
- [ ] Test browser compatibility

## Phase 6: Performance Testing

### 6.1 CSS Performance
- [ ] Measure CSS file size (before/after PurgeCSS)
- [ ] Test page load times
- [ ] Verify critical CSS extraction
- [ ] Test on slow connections

### 6.2 JavaScript Performance
- [ ] Verify no JavaScript regressions
- [ ] Test Commerce payment JS compatibility
- [ ] Test mobile navigation
- [ ] Test form enhancements

## Phase 7: User Acceptance Testing (UAT)

### 7.1 Internal Testing
- [ ] Test with internal team
- [ ] Test with vendors
- [ ] Test with end users (if possible)
- [ ] Collect feedback
- [ ] Document issues

### 7.2 Beta Testing
- [ ] Enable on staging environment
- [ ] Invite beta testers
- [ ] Monitor error logs
- [ ] Collect analytics data
- [ ] Compare metrics (bounce rate, conversion, etc.)

## Phase 8: Production Rollout

### 8.1 Pre-Launch Checklist
- [ ] All tests passing
- [ ] Performance metrics acceptable
- [ ] Accessibility verified
- [ ] Browser compatibility confirmed
- [ ] Commerce payment processing verified
- [ ] Backup created
- [ ] Rollback plan documented

### 8.2 Launch Strategy
1. **Soft Launch** (Week 1)
   - Enable on non-critical pages first
   - Monitor error logs
   - Collect user feedback
   - Fix any critical issues

2. **Gradual Rollout** (Week 2-3)
   - Enable on more pages
   - Continue monitoring
   - Fix minor issues

3. **Full Migration** (Week 4)
   - Set as default theme
   - Disable old theme
   - Monitor for 48 hours
   - Be ready to rollback

### 8.3 Post-Launch
- [ ] Monitor error logs daily
- [ ] Collect user feedback
- [ ] Fix any issues promptly
- [ ] Document lessons learned
- [ ] Plan cleanup of old theme (if needed)

## Rollback Plan

If critical issues arise:

1. **Immediate Rollback**
   ```bash
   # Via Drush
   ddev drush config:set system.theme default myeventlane_theme -y
   ddev drush cr
   ```

2. **Disable Theme Negotiator**
   - Remove or disable custom theme negotiator
   - Old theme will be used automatically

3. **Revert Code**
   ```bash
   git checkout main
   # Or specific commit
   ```

## Success Metrics

Track these metrics before and after migration:

- **Performance**
  - Page load time
  - Time to First Contentful Paint (FCP)
  - Largest Contentful Paint (LCP)
  - CSS file size

- **User Experience**
  - Bounce rate
  - Conversion rate (event creation, ticket purchases)
  - User session duration
  - Error rate

- **Accessibility**
  - WCAG 2.1 AA compliance
  - Keyboard navigation
  - Screen reader compatibility

- **Technical**
  - JavaScript errors
  - CSS validation
  - Browser compatibility
  - Mobile responsiveness

## Timeline Estimate

- **Phase 1**: ✅ Complete (1 day)
- **Phase 2**: 2-3 days
- **Phase 3**: 1-2 weeks
- **Phase 4**: 1 day
- **Phase 5**: 3-5 days
- **Phase 6**: 2-3 days
- **Phase 7**: 1 week
- **Phase 8**: 1-2 weeks

**Total**: 4-6 weeks for complete migration

## Risk Mitigation

### High-Risk Areas
1. **Commerce Checkout**: Test thoroughly, have rollback ready
2. **Payment Processing**: Verify Stripe/PayPal integration
3. **Form Submissions**: Test all forms, especially event creation
4. **Mobile Navigation**: Test on real devices

### Mitigation Strategies
- Test in staging first
- Use theme negotiator for gradual rollout
- Keep old theme available for rollback
- Monitor error logs closely
- Have support team ready

## Communication Plan

1. **Internal Team**: Weekly updates during migration
2. **Vendors**: Notify before enabling on vendor dashboard
3. **End Users**: Optional - can be silent migration if visual changes are minimal
4. **Stakeholders**: Final approval before production launch

## Documentation

- [ ] Update theme README
- [ ] Document component usage
- [ ] Create style guide
- [ ] Document any breaking changes
- [ ] Update developer documentation

## Next Steps

1. Install Radix: `ddev composer require drupal/radix`
2. Install dependencies: `ddev npm install`
3. Build assets: `ddev npm run build`
4. Clear cache: `ddev drush cr`
5. Begin Phase 2 testing

---

**Last Updated**: 2025-12-26
**Status**: Phase 1 Complete, Ready for Phase 2
