# Migration from COOKiES to Klaro

This document describes the migration from `drupal/cookies` (COOKiES + cookiesjsr) to `drupal/klaro` (Klaro! Consent Manager).

## Summary

- **Removed:** `drupal/cookies` module
- **Added:** `drupal/klaro` module
- **Updated:** `myeventlane_privacy` consent dispatcher now integrates with Klaro
- **Updated:** Themes use Klaro-specific styling (`_klaro-consent.scss`)
- **Updated:** Footer links open Klaro modal via `#klaro` and `open-consent-manager` class

## Prerequisites

- Drupal 11
- Composer (with working dependency resolution)

**Note:** If `composer update` fails due to PHP version constraints (e.g. dompdf with PHP 8.5), run `composer update` in an environment with a compatible PHP version (e.g. PHP 8.4 in ddev), or use `composer update --ignore-platform-req=php` if appropriate for your setup.

## Installation Steps

### 1. Run Composer

```bash
composer update
```

This will:
- Install `drupal/klaro` and its dependency `drupal/klaro_js`
- Remove `drupal/cookies`

### 2. Uninstall COOKiES and enable Klaro

```bash
drush pmu cookies -y
drush en klaro myeventlane_privacy -y
```

### 3. Import configuration

```bash
drush cim -y
```

This will remove the old cookies config and block placements from active config.

### 4. Configure Klaro services

1. Go to **Configuration → User interface → Klaro** (`/admin/config/user-interface/klaro`)
2. Create Klaro **services** with these IDs to match the consent dispatcher:
   - `gtm` — Google Tag Manager
   - `gtag` — GA4 (if not using GTM)
   - `meta_pixel` — Meta (Facebook) Pixel
   - `hotjar` — Hotjar
   - `recaptcha` — reCAPTCHA v3

3. Assign services to appropriate **purposes** (e.g. Marketing, Analytics)

### 5. Rebuild theme assets

```bash
cd web/themes/custom/myeventlane_theme && npm run build
cd web/themes/custom/myeventlane_vendor_theme && npm run build
cd web/themes/custom/myeventlane_radix && npm run build
```

### 6. Clear cache

```bash
drush cr
```

## Footer and Cookie Links

- **Privacy & Cookie Settings:** Links now use `href="#klaro"` and class `open-consent-manager` to open the Klaro consent modal
- **Cookie policy page** (`/cookies`): Includes a "Manage cookie preferences" link that opens Klaro

## Verification

1. **Incognito + DevTools Network:** Load site → no requests to googletagmanager.com, connect.facebook.net, hotjar.com before consent
2. **Banner:** Klaro consent notice appears on first visit
3. **Footer link:** Click "Privacy & Cookie Settings" → Klaro modal opens
4. **Accept all:** After consent, tracking scripts load (if configured)
5. **Deny all:** No tracking requests

## myeventlane_legal Integration

The `myeventlane_legal` module has a legacy cookie banner and preferences form. When Klaro is enabled:
- The legacy banner is **not shown** (avoids duplicate banners)
- The `/cookies` preferences page shows a "Manage cookie preferences" link that opens Klaro instead of the legacy form
