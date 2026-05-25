# Event Visibility Phase B + C — Smoke Test Results

Tested: 2026-05-25 on `fix/event-visibility-passcode-stability`
Event: node/1583 (Join the at the Anna Show This Weekend! Event)

## Public event

- [x] Direct URL accessible by anonymous (HTTP 200)
- [x] No `X-Robots-Tag` header on canonical page
- [x] Booking URL accessible (not blocked by visibility)

## Unlisted event

- [x] Direct URL accessible by anonymous (HTTP 200 via shared link)
- [x] `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` on canonical page

## Private event

- [x] Direct URL denied for anonymous users (HTTP 403)
- [x] Direct URL accessible to admin/owner (uid=1, access=allowed)
- [x] `X-Robots-Tag` present (403 page, not rendered)

## Passcode event (with hash)

- [x] Direct URL redirects anonymous to `/event/{id}/passcode` (HTTP 302)
- [x] Passcode form renders (HTTP 200) with title "This event requires a passcode"
- [x] `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` on passcode form page
- [x] Booking URL denied before passcode unlock (HTTP 403)
- [x] Wrong passcode rejected (`verifyPasscode('wrongpass') = false`)
- [x] Correct passcode accepted (`verifyPasscode('testpass123') = true`)
- [x] No redirect loop

## Passcode event (no hash stored)

- [x] Direct URL returns 200 (no redirect — `requiresPasscode()` is false)
- [x] Passcode gate returns 404 (form throws NotFoundHttpException)
- [x] No redirect loop

## Session safety

- [x] `EventPasscodeAccess::hasSessionContext()` returns false during CLI
- [x] `EventPasscodeAccess::isUnlocked()` returns false during CLI (no throw)
- [x] No "Failed to start the session" errors in watchdog after fixes
- [x] `hook_node_access()` returns neutral for public/unlisted (no session touch)
- [x] `hook_node_access()` returns neutral for passcode (defers to gate subscriber)
- [x] `hook_node_access()` returns neutral during CLI for all visibility modes

## Config integrity

- [x] `drush config:status` → "No differences between DB and sync directory"
- [x] `drush updb -y` → "No pending updates"
- [x] `drush cim -y` → "There are no changes to import"
- [x] All 22 display config files committed with correct hidden-field entries

## Validation

- [x] `composer validate` → valid
- [x] `php -l` on all Phase B/C PHP files → no syntax errors
- [x] `npm run mel:lint` → pass
- [x] `npm run mel:build` → pass

## Ticket save observation

Watchdog entry 921397 at 13:45 confirms variation 4128 WAS updated:
`Updated variation 4128 for ticket type "Full Price"`

The "Tier 93 blocked by access rules" notices are from the customer-facing
preview context during Studio form render, not from the ticket save path.
Tier 93 has `visibility_mode = access_code` (tier-level access code system),
which correctly blocks it in the public preview. This is pre-existing and
unrelated to Phase B/C event visibility.

## Commands run

```bash
ddev drush cr
ddev drush updb -y
ddev drush cim -y
ddev drush config:status
ddev drush ws --count=30
composer validate
php -l (9 files)
npm run mel:lint
npm run mel:build
```
