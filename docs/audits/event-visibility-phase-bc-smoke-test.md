# Event Visibility Phase B + C — Smoke Test Checklist

## Public event

- [ ] Appears in homepage listings, category pages, calendar, API
- [ ] Appears in JSON-LD structured data
- [ ] Direct URL accessible by anonymous
- [ ] Booking URL accessible by anonymous
- [ ] No `X-Robots-Tag: noindex` header on canonical page
- [ ] Appears in search results

## Unlisted event

- [ ] Hidden from homepage listings, category pages, calendar, API
- [ ] Hidden from JSON-LD structured data
- [ ] Direct URL accessible by anonymous (via shared link)
- [ ] Booking URL accessible by anonymous
- [ ] `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` on canonical page
- [ ] Not in search results or recommendations

## Private event

- [ ] Hidden from all listings, API, JSON-LD, search, recommendations
- [ ] Direct URL denied for anonymous users
- [ ] Direct URL accessible to event owner
- [ ] Direct URL accessible to admin/staff
- [ ] Booking URL denied for anonymous
- [ ] Booking URL accessible to owner/admin/staff
- [ ] `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` on canonical page

## Passcode event

- [ ] Hidden from all listings, API, JSON-LD, search, recommendations
- [ ] Direct URL redirects anonymous to `/event/{id}/passcode`
- [ ] Passcode form renders with title "This event requires a passcode"
- [ ] Incorrect passcode shows generic error, no hint
- [ ] Correct passcode unlocks and redirects to event canonical page
- [ ] After unlock, event page renders normally
- [ ] Booking URL denied before passcode unlock
- [ ] Booking URL accessible after passcode unlock
- [ ] Booking URL accessible to owner/admin/staff without passcode
- [ ] `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` on canonical page
- [ ] `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` on passcode form page
- [ ] Changing passcode invalidates previous session unlock

## Security checks

- [ ] No passcode value appears in page source (view-source)
- [ ] No passcode value in API responses (`/vendor/studio/...`)
- [ ] No passcode hash in `buildEventPayload` JSON (only `has_passcode: true/false`)
- [ ] No passcode value in logs (check dblog)
- [ ] No passcode in `drupalSettings` JavaScript object
- [ ] No passcode in cache metadata
- [ ] Passcode stored as bcrypt hash in database, not plaintext
- [ ] Generic save endpoint blocks `field_event_passcode_hash`
- [ ] Session stores SHA-256 fingerprint of hash, not the passcode or full hash

## Vendor Studio

- [ ] Setting visibility to `passcode` with a passcode value saves correctly
- [ ] Setting visibility to `passcode` without a passcode returns 422 error
- [ ] Clearing visibility from passcode keeps hash (does not use it)
- [ ] `settings_config.has_passcode` is `true` when hash exists
- [ ] `settings_config.has_passcode` is `false` when no hash exists
- [ ] Passcode hash never returned in any JSON payload

## Update hooks

- [ ] `drush updb -y` runs `myeventlane_event_update_11021` and `11022`
- [ ] After 11021: all events have explicit `field_event_visibility` value
- [ ] After 11022: `field_event_passcode_hash` field exists on event nodes
- [ ] Running update hooks again is idempotent (no errors, no duplicate work)

## Existing systems unaffected

- [ ] Ticket-tier access codes still work independently
- [ ] Booking flow resolver still determines paid/rsvp/external correctly
- [ ] Event wizard still works for creating events
- [ ] Admin/staff event forms unaffected
- [ ] Invite-only not implemented (no new visibility value)

## Commands

```bash
ddev drush cr
ddev drush updb -y
ddev drush cim -y
ddev drush cex -y
ddev drush config:status
```
