# COOKiES Consent Implementation — Setup

**Branch:** `feature/cookies-consent`

## Prerequisites

- Drupal 11
- `drupal/cookies:^1.2` (already in composer.json)
- `web/libraries/cookiesjsr` (present)

## Installation Steps

### 1. Enable modules

```bash
drush en cookies myeventlane_privacy -y
```

Optional (for WYSIWYG iframe/script blocking):

```bash
drush en cookies_filter -y
```

### 2. Import configuration

If `config/sync` only has the cookie files (sparse sync), a full `drush cim` will try to delete all other config and uninstall modules. Use one of these approaches:

**Option A: Partial import (try first)**

```bash
ddev drush cim -y --partial
```

**Option B: Full sync + cookie config** (required when Option A fails due to sparse sync)

```bash
# Run the script from repo root:
./scripts/import-cookie-config.sh
```

Or manually:

```bash
# 1. Enable modules first (so they appear in core.extension)
ddev drush en cookies myeventlane_privacy -y

# 2. Backup cookie config
mkdir -p config/sync/.cookie-backup
cp config/sync/cookies.config.yml config/sync/block.block.*.cookies_ui.yml config/sync/.cookie-backup/

# 3. Export active config (populates sync with full site config)
ddev drush cex -y

# 4. Restore our cookie config over the export
cp config/sync/.cookie-backup/*.yml config/sync/

# 5. Import
ddev drush cim -y

# 6. Clear cache
ddev drush cr
```

**Option C: Enable modules first, then import only cookie config**

```bash
# 1. Enable modules (creates their config in active)
ddev drush en cookies myeventlane_privacy -y

# 2. Import just our cookie config via partial
ddev drush cim -y --partial
```

This applies:

- `cookies.config` (deny all, #editCookieSettings, etc.)
- Block placement for myeventlane_theme, myeventlane_radix, myeventlane_vendor_theme

### 3. Build theme assets

Rebuild SCSS so cookie consent overrides are compiled:

```bash
# From theme directory, e.g.:
cd web/themes/custom/myeventlane_theme && npm run build
cd web/themes/custom/myeventlane_vendor_theme && npm run build
cd web/themes/custom/myeventlane_radix && npm run build  # if used
```

### 4. Clear cache

```bash
drush cr
```

### 5. Configure tracking (optional)

1. Go to **Configuration → System → Privacy & Tracking**
2. Enter GTM, GA4, Meta Pixel, Hotjar, or reCAPTCHA IDs as needed
3. Create matching cookie services in **Configuration → System → COOKiES → Cookie services** with IDs: `gtm`, `gtag`, `meta_pixel`, `hotjar`, `recaptcha`

## Blank preferences dialog?

If the settings dialog opens but shows no content (empty categories), ensure cookie services exist:

1. Go to **Configuration → System → COOKiES → Cookie services**
2. If empty, the cookies module default config may not have been imported. Run `drush cim` again after the full sync (Option B), or create at least one cookie service group and service.

### 6. Environment-based tracking (hardening)

Set `$settings['myeventlane_environment']` in `settings.php`:

- **`prod`** — Tracking IDs are exposed; scripts load after consent.
- **`dev`** or **`staging`** — GTM/GA4/Meta/Hotjar suppressed; no analytics bleed. reCAPTCHA still available.

`example.settings.local.php` sets `myeventlane_environment = 'dev'` for local development. Production must set `'prod'`.

## Verification

1. **Incognito + DevTools Network:** Load site → no requests to googletagmanager.com, connect.facebook.net, hotjar.com before consent
2. **Banner:** Cookie banner appears on first visit
3. **Preferences button:** White/light pill button labeled "Cookie settings" or "Preferences" – clearly visible on dark banner
4. **Footer link:** Click "Privacy & Cookie Settings" → settings dialog opens (#editCookieSettings)
5. **Dialog content:** Service groups (Necessary, Marketing, etc.) and toggles are visible
6. **Accept all:** After consent, tracking scripts load (if configured)
7. **Deny all:** No tracking requests

## Files Changed / Added

- `config/sync/cookies.config.yml` — COOKiES config
- `config/sync/block.block.*.cookies_ui.yml` — Block placement (3 themes)
- `web/modules/custom/myeventlane_privacy/` — Privacy module (dispatcher, config form)
- `web/themes/custom/*/templates/includes/footer.html.twig` — Privacy & Cookie Settings link
- `web/themes/custom/*/src/scss/components/_cookies-consent.scss` — Theme overrides
- `docs/privacy/tracking-audit.md` — Audit results
