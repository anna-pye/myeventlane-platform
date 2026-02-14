# Cookie Consent Setup (Klaro)

**Branch:** `feature/cookies-consent`

## Overview

MyEventLane uses [Klaro!](https://www.drupal.org/project/klaro) (drupal/klaro) for cookie consent management.

For migration from the previous COOKiES implementation, see [KLARO_MIGRATION.md](./KLARO_MIGRATION.md).

## Prerequisites

- Drupal 11
- `drupal/klaro:^3.0` (in composer.json)
- `drupal/klaro_js` (installed as Klaro dependency)

## Installation Steps

### 1. Run Composer

```bash
composer update
```

### 2. Enable modules

```bash
drush en klaro myeventlane_privacy -y
```

### 3. Import configuration

```bash
drush cim -y
```

Or use the import script:

```bash
./scripts/import-cookie-config.sh
```

### 4. Build theme assets

```bash
cd web/themes/custom/myeventlane_theme && npm run build
cd web/themes/custom/myeventlane_vendor_theme && npm run build
cd web/themes/custom/myeventlane_radix && npm run build
```

### 5. Configure Klaro services

1. Go to **Configuration → User interface → Klaro** (`/admin/config/user-interface/klaro`)
2. Create services with IDs: `gtm`, `gtag`, `meta_pixel`, `hotjar`, `recaptcha`
3. Assign to purposes (Analytics, Marketing, etc.)

### 6. Configure tracking (optional)

1. Go to **Configuration → System → Privacy & Tracking**
2. Enter GTM, GA4, Meta Pixel, Hotjar, or reCAPTCHA IDs

### 7. Environment-based tracking

Set `$settings['myeventlane_environment']` in settings.php:

- **`prod`** — Tracking IDs exposed; scripts load after consent.
- **`dev`** or **`staging`** — GTM/GA4/Meta/Hotjar suppressed.

## Verification

1. **Incognito + DevTools Network:** No tracking requests before consent
2. **Banner:** Klaro consent notice on first visit
3. **Footer link:** "Privacy & Cookie Settings" opens Klaro modal
4. **Accept all:** Tracking scripts load
5. **Deny all:** No tracking requests
