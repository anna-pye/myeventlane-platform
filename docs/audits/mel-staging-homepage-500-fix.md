# MEL Staging Homepage 500 Triage

Date: 2026-05-01

## Summary

Fresh staging checks did not reproduce the reported homepage 500. Both `/` and `/home` returned `200`, the returned HTML was a complete page, and no new severity error was recorded for the homepage after the capture start watchdog ID.

No code fix was applied because there is no current failing View, block, Twig, PHP error, or watchdog row to anchor a narrow fix.

## Preflight

- Branch: `cursor/docs-recovery-update-29bd3`
- Dirty files before edits: none
- Recent launch-fix commits present:
  - `c9d68935 fix(launch): clear pre-smoke watchdog blockers`
  - `571f252d fix(launch): resolve staging P1 watchdog issues`
- `composer validate`: passed, `./composer.json is valid`
- `ddev drush cr`: passed, cache rebuild complete

## Staging Failure Capture

- `START_WID`: `373603`
- `/` before fix attempt: `200`
- `/home` before fix attempt: `200`
- HTML tail: complete Drupal page output ending with closing `</html>`
- New severity `<= 3` watchdog rows after `START_WID`: none

New watchdog rows after `START_WID` were severity 7 anonymous vendor-access notices only:

- `373607 myeventlane_vendor VendorConsoleAccess path=/vendor/settings decision=forbidden_anonymous`
- `373606 myeventlane_vendor VendorConsoleAccess path=/vendor/events/add decision=forbidden_anonymous`
- `373605 myeventlane_vendor VendorConsoleAccess path=/vendor/events decision=forbidden_anonymous`
- `373604 myeventlane_vendor VendorConsoleAccess path=/vendor/dashboard decision=forbidden_anonymous`

## Route, View, and Block Confirmation

- Front page config: `system.site:page.front` is `/home`
- `/home` owner: `view.frontpage.page_1`
- `frontpage` View: exists and enabled
- `mel_home_events` View: exists and enabled
- Staging `frontpage` direct execution: `frontpage rows=0`
- Staging `mel_home_events` direct execution:
  - `default rows=3`
  - `discover rows=3`
  - `embed_discover rows=1`
  - `near_you rows=3`
  - `tonight rows=0`
  - `under_20 rows=1`

Homepage-related block probe on staging:

- `myeventlane_theme_homeheromyeventlane | region=hero | plugin=myeventlane_home_hero | status=1`
- `myeventlane_theme_vendorprofiles | region=content | plugin=entity_field:user:vendor_profiles | status=0`
- `myeventlane_theme_views_block__mel_home_events_discover | region=home_discover | plugin=views_block:mel_home_events-discover | status=0`

## Local Reproduction

- Local front page config: `/home`
- Local route owner: `view.frontpage.page_1: /home`
- Local `/`: `200`
- Local `/home`: `200`
- Local `frontpage` direct execution: `frontpage rows=0`
- Local `mel_home_events` direct execution:
  - `default rows=1`
  - `discover rows=1`
  - `embed_discover rows=0`
  - `near_you rows=1`
  - `tonight rows=0`
  - `under_20 rows=0`

Recent local watchdog errors were unrelated to homepage rendering:

- `php` assertion noise for missing `myeventlane_vendor_settings.info.yml`
- `myeventlane_pro` abandoned cart scheduler `Order::getType()` error

## Root Cause

The current homepage 500 is not active. Fresh staging and local evidence indicate the earlier 500 was a cleared transient cache or release state, not a currently reproducible homepage View, block, Twig, or PHP fatal.

## Files Changed

- `docs/audits/mel-staging-homepage-500-fix.md`

## Fix Summary

No code/config fix was made. The homepage now renders successfully on staging and locally, and the diagnostic probes did not expose a failing homepage-only code path.

## Verification

Local:

- `composer validate`: passed
- `ddev drush cr`: passed
- `curl -k https://myeventlane.ddev.site/`: `200`
- `curl -k https://myeventlane.ddev.site/home`: `200`
- Homepage View execution probes completed without exceptions

Staging:

- `curl https://staging.myeventlane.com.au/`: `200`
- `curl https://staging.myeventlane.com.au/home`: `200`
- New severity error watchdog rows after `START_WID`: none
- Homepage View execution probes completed without exceptions

Not run:

- `npm run mel:lint`: no theme, Twig, SCSS, or JS files changed
- `npm run mel:build`: no theme, Twig, SCSS, or JS files changed
- `php -l`: no PHP files changed

## Remaining Blockers

No active staging homepage blocker is present from this capture. The unrelated local watchdog items remain outside this task:

- Missing `myeventlane_vendor_settings.info.yml` assertion noise
- Abandoned cart scheduler `Order::getType()` error

## Smoke Readiness

Staging smoke testing can start for the homepage gate: `/` and `/home` both return `200`, and there are no new homepage severity errors after `START_WID`.
