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

```bash
drush cim -y
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

## Verification

1. **Incognito + DevTools Network:** Load site → no requests to googletagmanager.com, connect.facebook.net, hotjar.com before consent
2. **Banner:** Cookie banner appears on first visit
3. **Footer link:** Click "Privacy & Cookie Settings" → settings dialog opens (#editCookieSettings)
4. **Accept all:** After consent, tracking scripts load (if configured)
5. **Deny all:** No tracking requests

## Files Changed / Added

- `config/sync/cookies.config.yml` — COOKiES config
- `config/sync/block.block.*.cookies_ui.yml` — Block placement (3 themes)
- `web/modules/custom/myeventlane_privacy/` — Privacy module (dispatcher, config form)
- `web/themes/custom/*/templates/includes/footer.html.twig` — Privacy & Cookie Settings link
- `web/themes/custom/*/src/scss/components/_cookies-consent.scss` — Theme overrides
- `docs/privacy/tracking-audit.md` — Audit results
