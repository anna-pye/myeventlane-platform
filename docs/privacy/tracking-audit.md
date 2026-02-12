# Tracking Audit — Cookie Consent Implementation

**Branch:** `feature/cookies-consent`  
**Date:** 2026-02-12  
**Purpose:** Ensure no third-party tracking scripts load before user consent.

## Audit Commands Run

```bash
# Scan for tracking patterns (excluding vendor, node_modules, core)
rg -n --hidden --glob '!**/vendor/**' --glob '!**/node_modules/**' --glob '!**/web/core/**' \
  "(googletagmanager|gtag\(|google-analytics|GTM-|UA-|G-|fbq\(|fbevents|connect\.facebook\.net|hotjar|hj\(|matomo|_paq|plausible|segment|mixpanel|posthog|clarity|recaptcha|g-recaptcha|doubleclick|adsbygoogle|tiktok|snap|pinterest)" .

# Scan Twig templates for inline scripts
rg -n --glob '**/*.twig' "(<script|googletagmanager|gtag\(|fbq\(|hotjar|matomo|recaptcha)" web/themes web/modules

# Scan config for tracking module IDs
rg -n --glob '**/*.yml' "(google_tag|gtm|analytics|matomo|pixel|hotjar|recaptcha)" config
```

## Findings

### 1. Twig Templates

| File | Content | Status |
|------|---------|--------|
| `category-pie-chart.html.twig` | Chart.js (first-party, local chart rendering) | ✅ No tracking |
| `page--front.html.twig` | Adaptive text contrast for event cards | ✅ No tracking |
| `views-view-unformatted--featured-events-carousel*.twig` | Carousel navigation + adaptive contrast | ✅ No tracking |

**Conclusion:** No third-party tracking scripts in Twig. All inline scripts are first-party UI behavior.

### 2. Config

No tracking IDs found in `config/` (GTM, GA4, Matomo, Hotjar, recaptcha, etc. not present in committed config).

**Conclusion:** Tracking IDs are stored only in `myeventlane_privacy.settings` (admin UI) and injected after consent via the dispatcher.

### 3. COOKiES Contrib Submodules

The `drupal/cookies` contrib module has optional submodules (`cookies_gtag`, `cookies_facebook_pixel`, `cookies_matomo`, etc.) that integrate with those services. These are **not enabled by default**. Only enable if you use them and configure via our `myeventlane_privacy` dispatcher or the submodule’s own config.

### 4. WYSIWYG / Filter

- **cookies_filter:** Enable if editor-embedded iframes or third-party scripts (e.g. YouTube, Twitter) appear in content. Blocks until consent.
- `drush en cookies_filter -y` when needed.

## Verification Checklist

- [ ] No third-party scripts in Twig templates
- [ ] No third-party scripts in HTML head via settings (drupalSettings used only for IDs; scripts injected after consent)
- [ ] Tracking IDs stored only in config (`myeventlane_privacy.settings`) and injected after consent
- [ ] Consent revocation works (footer “Privacy & Cookie Settings” opens via `#editCookieSettings`)
- [ ] Browser DevTools: no requests to `googletagmanager.com`, `connect.facebook.net`, `static.hotjar.com`, etc. **before** consent

## Remediation Notes

1. **Adding new tracking:** Create a cookie service in COOKiES (`/admin/config/system/cookies/cookies-service`) with ID matching the dispatcher (e.g. `gtm`, `gtag`, `meta_pixel`, `hotjar`, `recaptcha`). Add the config form field in `myeventlane_privacy` if needed.
2. **Embedded content:** Use `cookies_filter` for WYSIWYG embeds.
3. **GTM/GA4:** If using contrib `cookies_gtag`, either disable its auto-injection and use our dispatcher, or use the contrib integration and remove gtag from our dispatcher to avoid duplication.
