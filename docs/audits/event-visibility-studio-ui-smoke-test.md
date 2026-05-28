# Event Visibility Studio UI — Smoke Test

Feature branch: `feature/event-visibility-studio-ui`

## Functional Checks

- [ ] **1. Public event appears in listings and direct URL works.**
  Set visibility to Public, save. Confirm event appears in public listings, search, calendar, and category pages. Direct URL (`/event/{slug}`) loads for anonymous visitors.

- [ ] **2. Unlisted event direct URL works but does not appear in listings/search/category/calendar.**
  Set visibility to Unlisted, save. Direct URL works for anonymous. Event does not appear in public listings, search results, category pages, or calendar feeds. `noindex` header is present.

- [ ] **3. Private event direct URL is denied for anonymous/non-owner.**
  Set visibility to Private, save. Anonymous visitor receives access denied on direct URL. Owner, vendor team member, admin, and staff can still view.

- [ ] **4. Passcode event redirects/gates anonymous direct visitor.**
  Set visibility to Passcode protected, enter a passcode, save. Anonymous visitor on direct URL sees a passcode gate (redirect or interstitial). Event does not appear in public discovery.

- [ ] **5. Correct passcode unlocks event.**
  Enter the correct passcode at the gate. Event page loads. Booking flow is accessible after unlock.

- [ ] **6. Booking route respects passcode unlock.**
  Without unlocking, attempt to access the booking route for a passcode-protected event. Access is denied. After unlocking via passcode, booking route is accessible.

- [ ] **7. Changing passcode replaces hash.**
  Open Event Studio settings for a passcode-protected event. Enter a new passcode, save. Old passcode no longer works. New passcode unlocks correctly.

- [ ] **8. Switching away from passcode clears or safely disables passcode hash.**
  Change visibility from Passcode protected to Public (or any other), save. Confirm `field_event_passcode_hash` is cleared on the entity. Switching back to Passcode protected without entering a new passcode shows a validation error.

- [ ] **9. Hash never appears in page source, JS payload, logs, API, or Twig.**
  Inspect page source on the event page — no bcrypt hash string.
  Inspect the Vendor Studio JSON payload (`settings_config`) — only `has_passcode: true/false`, `visibility`, `visibility_label` exposed. No `field_event_passcode_hash`.
  Check Drupal logs — no passcode hash values logged.
  Check JSON:API / REST responses if enabled — passcode hash field not exposed.

- [ ] **10. Vendor can see current state in Studio.**
  Open Event Studio settings section. Current visibility is shown with a clear label (Public / Unlisted / Private / Passcode protected). Radio selector matches the stored value. Help text is visible for all four options.

## Security Checks

- [ ] Only event owner, vendor team member, admin, or staff can edit visibility (anonymous cannot access save endpoints).
- [ ] CSRF checks remain intact on both Studio form save and Vendor Studio JSON endpoint.
- [ ] Passcode hash is not cache-leaked (check page cache headers, Drupal render cache).
- [ ] No sensitive value (hash or plaintext passcode) appears in browser console, network tab responses, or server logs.

## Validation Commands

```bash
composer validate
find web/modules/custom/myeventlane_event web/modules/custom/myeventlane_event_studio web/modules/custom/myeventlane_vendor -name "*.php" -print0 | xargs -0 -n1 php -l
npm run mel:lint
npm run mel:build
ddev drush cr
ddev drush updb -y
ddev drush cim -y
ddev drush cr
git status -sb
```
