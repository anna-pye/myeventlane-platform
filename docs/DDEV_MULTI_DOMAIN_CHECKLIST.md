# DDEV Multi-Domain Verification Checklist

## Prerequisites

- [ ] DDEV is running (`ddev status`)
- [ ] Both domains are configured in `.ddev/config.yaml`
- [ ] DNS/hosts file entries point to DDEV IP for both domains

## Domain Configuration

### 1. DDEV Config Verification

Check `.ddev/config.yaml` contains:

```yaml
additional_hostnames:
  - vendor.myeventlane.ddev.site
```

Or verify both domains are listed in `hostnames` array.

### 2. Hosts File / DNS

**macOS/Linux:**
```bash
# Check hosts file
cat /etc/hosts | grep myeventlane

# Should show:
127.0.0.1 myeventlane.ddev.site
127.0.0.1 vendor.myeventlane.ddev.site
```

**Windows:**
- Check `C:\Windows\System32\drivers\etc\hosts`
- Should have both domain entries pointing to 127.0.0.1

### 3. SSL Certificates

```bash
# Verify SSL certs exist for both domains
ddev describe | grep -A 5 "SSL"
```

Both domains should have valid SSL certificates.

## Drupal Configuration

### 4. Domain Settings Config

```bash
# Check domain settings exist
ddev drush config:get myeventlane_core.domain_settings

# Should show:
# public_domain: 'https://myeventlane.ddev.site'
# vendor_domain: 'https://vendor.myeventlane.ddev.site'
# force_redirects: true
```

If missing, create via:
```bash
ddev drush config:set myeventlane_core.domain_settings public_domain 'https://myeventlane.ddev.site'
ddev drush config:set myeventlane_core.domain_settings vendor_domain 'https://vendor.myeventlane.ddev.site'
ddev drush config:set myeventlane_core.domain_settings force_redirects true
```

### 5. Theme Configuration

```bash
# Verify vendor theme is enabled
ddev drush config:get system.theme

# Should show myeventlane_vendor_theme as default for vendor domain
# (handled by VendorThemeNegotiator)
```

### 6. Permissions

```bash
# Verify vendor role has 'access vendor console' permission
ddev drush role:perm vendor

# Should include:
# - access vendor console
# - edit own event content
```

## Functional Testing

### 7. Public Domain Access

- [ ] Visit `https://myeventlane.ddev.site`
- [ ] Should use `myeventlane_theme` (pastel theme)
- [ ] Should NOT show vendor console links
- [ ] Event pages should be accessible
- [ ] RSVP/booking forms should work

### 8. Vendor Domain Access

- [ ] Visit `https://vendor.myeventlane.ddev.site`
- [ ] Should use `myeventlane_vendor_theme` (neutral admin theme)
- [ ] Should show vendor console sidebar
- [ ] Should NOT show pastel theme elements

### 9. Domain Redirects

**From Public to Vendor:**
- [ ] Visit `https://myeventlane.ddev.site/vendor/dashboard`
- [ ] Should redirect to `https://vendor.myeventlane.ddev.site/vendor/dashboard`
- [ ] Should use vendor theme

**From Vendor to Public:**
- [ ] Visit `https://vendor.myeventlane.ddev.site/events` (if public route)
- [ ] Should redirect to `https://myeventlane.ddev.site/events`
- [ ] Should use public theme

### 10. Vendor Console Routes

Test all vendor console routes on vendor domain:

- [ ] `/vendor/dashboard` - Dashboard with KPIs
- [ ] `/vendor/events` - Events list
- [ ] `/vendor/events/{id}/overview` - Event overview
- [ ] `/vendor/events/{id}/tickets` - Ticket sales
- [ ] `/vendor/events/{id}/rsvps` - RSVP stats
- [ ] `/vendor/events/{id}/analytics` - Analytics
- [ ] `/vendor/events/{id}/settings` - Event settings
- [ ] `/vendor/payouts` - Payouts page
- [ ] `/vendor/boost` - Boost campaigns
- [ ] `/vendor/audience` - Audience insights
- [ ] `/vendor/settings` - Vendor settings

All should:
- Require vendor domain
- Require 'access vendor console' permission
- Use vendor theme
- Redirect if accessed from public domain

### 11. Event Form Access

- [ ] Visit `https://vendor.myeventlane.ddev.site/node/add/event`
- [ ] Should redirect to `/create-event` gateway
- [ ] Should use vendor theme
- [ ] Form should have location autocomplete
- [ ] Booking type should show/hide fields conditionally

### 12. Theme Negotiation

- [ ] Admin routes (`/admin/*`) should use Gin theme on both domains
- [ ] Vendor console routes should use vendor theme (vendor domain only)
- [ ] Public routes should use public theme (public domain only)

## JavaScript & Assets

### 13. Chart.js Integration

- [ ] Visit `/vendor/dashboard`
- [ ] Open browser console (F12)
- [ ] Check for Chart.js errors
- [ ] Verify charts render (sales, RSVPs)
- [ ] Check `drupalSettings.vendorCharts` exists

### 14. Asset Build

```bash
# Build vendor theme assets
cd web/themes/custom/myeventlane_vendor_theme
ddev npm install
ddev npm run build

# Verify dist/ folder has:
# - main.js
# - main.css
```

### 15. Sidebar Navigation

- [ ] Sidebar should be sticky
- [ ] Menu toggle should work on mobile
- [ ] Active section should be highlighted
- [ ] "Back to main site" link should work

## Performance & Caching

### 16. Cache Clear

```bash
# Clear all caches
ddev drush cr

# Rebuild container
ddev drush cr
```

### 17. Route Cache

```bash
# Rebuild routes
ddev drush cr

# Verify routes are registered
ddev drush route:list | grep vendor
```

## Troubleshooting

### Common Issues

**Issue: Routes not redirecting**
- Check `force_redirects` is enabled in domain settings
- Verify `VendorDomainSubscriber` is registered
- Clear cache: `ddev drush cr`

**Issue: Wrong theme on vendor domain**
- Check `VendorThemeNegotiator` is registered in `myeventlane_core.services.yml`
- Verify theme is enabled: `ddev drush theme:list`
- Clear cache: `ddev drush cr`

**Issue: Charts not rendering**
- Check browser console for errors
- Verify Chart.js is installed: `ddev npm list chart.js`
- Rebuild assets: `ddev npm run build`
- Check `drupalSettings.vendorCharts` in browser console

**Issue: Permission denied on vendor routes**
- Verify user has 'access vendor console' permission
- Check user is associated with a vendor entity
- Verify domain is vendor domain

**Issue: Sidebar not showing**
- Check template includes are working
- Verify `page.html.twig` includes sidebar
- Check SCSS is compiled: `ddev npm run build`

## Final Verification

- [ ] All vendor console routes accessible on vendor domain
- [ ] All public routes accessible on public domain
- [ ] Domain redirects working correctly
- [ ] Themes applied correctly per domain
- [ ] Charts rendering with data
- [ ] Forms working (event creation, editing)
- [ ] Navigation working (sidebar, links)
- [ ] No console errors in browser
- [ ] No PHP errors in logs (`ddev logs`)

## Sign-off

- [ ] All checks passed
- [ ] Ready for production deployment
- [ ] Documentation updated

---

**Last Updated:** 2025-12-09
**Phase:** Vendor Console Build - Phase 2
