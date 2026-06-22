# Git Branch Merge Plan - Vendor Console Phase 2

## Branch Information

**Source Branch:** `chore/drupal-core-update` (or current feature branch)  
**Target Branch:** `main` (or `develop` if using Git Flow)  
**Phase:** Vendor Console Build - Phase 2

## Pre-Merge Checklist

### 1. Code Quality

- [ ] All PHP files pass PHPCS: `ddev exec vendor/bin/phpcs web/modules/custom/myeventlane_vendor`
- [ ] All PHP files pass PHPStan: `ddev exec vendor/bin/phpstan analyze web/modules/custom/myeventlane_vendor`
- [ ] No syntax errors: `ddev drush cr` (should complete without errors)
- [ ] All services registered: `ddev drush cr` (check for service errors)

### 2. Dependencies

- [ ] `composer.json` updated (if any new dependencies)
- [ ] `composer.lock` committed
- [ ] `package.json` updated in vendor theme
- [ ] `package-lock.json` committed in vendor theme
- [ ] Chart.js dependency added: `chart.js: ^4.4.4`

### 3. Configuration

- [ ] No hardcoded configuration values
- [ ] Domain settings configurable via `myeventlane_core.domain_settings`
- [ ] Theme negotiation working via `VendorThemeNegotiator`
- [ ] All routes properly registered in `myeventlane_vendor.routing.yml`

### 4. Testing

- [ ] DDEV multi-domain checklist completed (see `DDEV_MULTI_DOMAIN_CHECKLIST.md`)
- [ ] All vendor console routes tested
- [ ] Domain redirects verified
- [ ] Charts rendering correctly
- [ ] Event form working with location autocomplete
- [ ] Conditional booking fields working

## Files Changed

### New Files

**Controllers:**
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorConsoleBaseController.php` (enhanced)
- All vendor console controllers (already existed, enhanced)

**Services:**
- `web/modules/custom/myeventlane_vendor/src/Service/MetricsAggregator.php`
- `web/modules/custom/myeventlane_vendor/src/Service/TicketSalesService.php`
- `web/modules/custom/myeventlane_vendor/src/Service/RsvpStatsService.php`
- `web/modules/custom/myeventlane_vendor/src/Service/CategoryAudienceService.php`
- `web/modules/custom/myeventlane_vendor/src/Service/BoostStatusService.php`

**Theme - Templates:**
- `web/themes/custom/myeventlane_vendor_theme/templates/includes/header.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/includes/sidebar.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/includes/footer.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/layout/console-page.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/event/overview.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/event/tickets.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/event/rsvps.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/event/analytics.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/event/settings.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/payouts.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/boost.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/audience.html.twig`

**Theme - Styles:**
- `web/themes/custom/myeventlane_vendor_theme/src/scss/components/_console.scss`

**Theme - JavaScript:**
- `web/themes/custom/myeventlane_vendor_theme/src/js/main.js` (enhanced with Chart.js)

**Documentation:**
- `DDEV_MULTI_DOMAIN_CHECKLIST.md`
- `GIT_MERGE_PLAN.md`

### Modified Files

**Module:**
- `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml` (service definitions)
- `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml` (routes already existed)
- All vendor console controllers (enhanced with services)

**Theme:**
- `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme` (preprocess hooks)
- `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.info.yml` (already existed)
- `web/themes/custom/myeventlane_vendor_theme/templates/page.html.twig` (shell layout)
- `web/themes/custom/myeventlane_vendor_theme/src/scss/main.scss` (import console styles)
- `web/themes/custom/myeventlane_vendor_theme/src/scss/components/_buttons.scss` (mel-btn classes)
- `web/themes/custom/myeventlane_vendor_theme/src/scss/layout/_navigation.scss` (shell styles)
- `web/themes/custom/myeventlane_vendor_theme/src/scss/layout/_header.scss` (header styles)
- `web/themes/custom/myeventlane_vendor_theme/package.json` (Chart.js dependency)

## Merge Strategy

### Option 1: Direct Merge (Recommended)

```bash
# Ensure you're on target branch
git checkout main
git pull origin main

# Merge feature branch
git merge chore/drupal-core-update

# Resolve any conflicts
# Test thoroughly
# Push
git push origin main
```

### Option 2: Squash Merge (Clean History)

```bash
# On target branch
git checkout main
git pull origin main

# Squash merge
git merge --squash chore/drupal-core-update
git commit -m "feat: Implement Vendor Console Phase 2

- Add vendor console routes and controllers
- Implement metrics services (sales, RSVPs, audience, boost)
- Create vendor theme templates and components
- Integrate Chart.js for dashboard visualizations
- Add domain enforcement and theme negotiation
- Complete event form UX improvements

See DDEV_MULTI_DOMAIN_CHECKLIST.md for verification steps."

git push origin main
```

### Option 3: Rebase and Merge (Linear History)

```bash
# On feature branch
git checkout chore/drupal-core-update
git rebase main

# Resolve conflicts if any
# Test

# Switch to main and merge
git checkout main
git merge chore/drupal-core-update
git push origin main
```

## Post-Merge Steps

### 1. Update Dependencies

```bash
# Install Composer dependencies (if any)
ddev composer install

# Install npm dependencies for vendor theme
cd web/themes/custom/myeventlane_vendor_theme
ddev npm install
ddev npm run build
cd ../../../../..
```

### 2. Clear Caches

```bash
ddev drush cr
ddev drush cache:rebuild
```

### 3. Import Configuration (if needed)

```bash
# If config changes were made
ddev drush cim -y
ddev drush cr
```

### 4. Verify Services

```bash
# Check services are registered
ddev drush ev "print_r(array_keys(\Drupal::getContainer()->getServiceIds('myeventlane_vendor')));"
```

### 5. Run Tests

- [ ] Complete DDEV multi-domain checklist
- [ ] Test all vendor console routes
- [ ] Verify charts render
- [ ] Test event form
- [ ] Verify domain redirects

### 6. Update Documentation

- [ ] Update main README if needed
- [ ] Document new vendor console features
- [ ] Update deployment notes

## Rollback Plan

If issues are discovered post-merge:

```bash
# Revert the merge commit
git revert -m 1 <merge-commit-hash>

# Or reset to previous state (if no one else has pulled)
git reset --hard HEAD~1
```

## Deployment Notes

### Production Deployment

1. **Database:**
   - No schema changes required
   - No migration needed

2. **Configuration:**
   - Ensure `myeventlane_core.domain_settings` is configured
   - Verify vendor theme is enabled
   - Check permissions for vendor role

3. **Assets:**
   - Build vendor theme assets: `npm run build` in theme directory
   - Clear Drupal cache after deployment

4. **Domain Setup:**
   - Ensure both domains point to same server
   - SSL certificates for both domains
   - DNS configured correctly

5. **Monitoring:**
   - Watch for service errors
   - Monitor Chart.js loading
   - Check domain redirects are working

## Known Issues / Future Work

### Current Limitations

- Chart data is placeholder (services return sample data)
- Real Commerce/RSVP queries need to be implemented
- Boost status is placeholder
- Payout data is placeholder

### Future Enhancements

- Replace placeholder data with real queries
- Add export functionality (CSV, PDF)
- Implement real-time updates (WebSocket/SSE)
- Add more chart types (pie, bar, etc.)
- Enhance mobile responsiveness
- Add keyboard shortcuts
- Implement bulk actions

## Sign-off

- [ ] Code review completed
- [ ] All tests passing
- [ ] Documentation updated
- [ ] Ready for merge
- [ ] Deployment plan reviewed

---

**Created:** 2025-12-09  
**Phase:** Vendor Console Build - Phase 2  
**Status:** Ready for Review
