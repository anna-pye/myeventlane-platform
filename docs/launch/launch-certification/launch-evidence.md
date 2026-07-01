# Launch Evidence Log

Raw evidence captured 2026-06-26, DDEV live. Authenticated sessions: Pro vendor (uid 92),
non-Pro vendor (uid 2), admin (uid 1).

## OB-1 root cause

```
# Service itself is fine:
\Drupal::service('myeventlane_reporting.event_insights') → SERVICE OK: EventInsightsController

# Routing used FQCN access on a DI-less base:
_custom_access: '\Drupal\myeventlane_reporting\Controller\EventInsightsController::access'
VendorConsoleBaseController → "abstract class VendorConsoleBaseController {"  (NO ContainerInjectionInterface)
→ ClassResolver does `new EventInsightsController()` → ArgumentCountError "0 passed" → 500 in access check

# Permissions admin-only:
view event insights  => administrator
view vendor insights => administrator
request exports      => administrator
uid92 roles=authenticated,vendor,mel_pro | view event insights=N | owns1755=Y

# Attendees tab:
Error: Call to undefined method TicketAttendee::getSource() in EventInsightsController->attendees()
AttendeeInterface methods: getEventId,getAttendeeId,getDisplayName,getEmail,getTicketLabel,isCheckedIn,getCheckedInAt
(source lives on repository::getSourceType() and the EventAttendee entity, not the DTO)
```

## OB-1 fix applied

```
routing: _custom_access FQCN → service notation (event_insights/chart_data/export_centre)
config:  drush role:perm:add mel_pro 'view event insights' | 'view vendor insights' | 'request exports'  → exported (cex)
code:    $source = $attendee instanceof TicketAttendee ? 'ticket' : 'rsvp';   (2 call sites + import)
```

## OB-1 after — all 200

```
/vendor/events/1755/insights            200
/vendor/events/1755/insights/sales      200
/vendor/events/1755/insights/attendees  200
/vendor/events/1755/insights/checkins   200
/vendor/events/1755/insights/traffic    200
/vendor/insights                        200  (h1 "Vendor Insights")
/vendor/exports                         200  (h1 "Export Centre")
```

## OB-2 evidence

```
myeventlane_vendor_comms.routing.yml → "# Shell-owned routes moved to myeventlane_vendor.routing.yml."
current routes:
  /vendor/event/{event}/comms           myeventlane_vendor.manage_event.comms      (302 → studio)
  /vendor/events/{node}/studio/messaging myeventlane_event_studio.workspace_messaging  → 200
MessagingSection plugin routeName: 'myeventlane_event_studio.workspace_messaging'
/vendor/events/1755/comms (plural)     → 404  (legacy, unlinked)
```

## OB-3 evidence

```
ProAccessCheck: on deny → dispatch ProFeatureAccessDeniedEvent + AccessResult::forbidden()
New subscriber (priority 100, KernelEvents::EXCEPTION):
  if AccessDeniedHttpException && route->hasRequirement('_myeventlane_pro_access')
     && authenticated && !isUserProActive → RedirectResponse(/vendor/pro?return_to=…) + warning

non-Pro /vendor/analytics        → 302 https://…/vendor/pro?return_to=/vendor/analytics
non-Pro /vendor/settings/branding → 302 https://…/vendor/pro?return_to=/vendor/settings/branding
landing page grep: "Pro feature" ×1, "Upgrade to unlock" ×1, "Run events like a professional" ×1, return_to ×2
Pro      /vendor/analytics        → 200 (no redirect)
anon     /vendor/analytics        → 403 (normal flow)
```

## Phase 2 a11y + Phase 5 perf (per page, Pro vendor)

```
route                                 time     http  h1 aria label skip lang
/vendor/dashboard                     1.228s   200   1  125  0     2    2
/vendor/events                        0.572s   200   2  84   4     2    2
/vendor/payouts                       0.863s   200   2  67   0     2    2
/vendor/analytics                     0.522s   200   3  69   0     2    2
/vendor/events/1755/studio            0.924s   200   2  89   0     2    2
/vendor/events/1755/studio/tickets    0.962s   200   2  113  41    2    2
/vendor/events/1755/insights/sales    0.506s   200   2  64   0     2    2
/vendor/events/1755/check-in          0.894s   200   2  64   0     2    2
/vendor/settings                      1.028s   200   2  108  25    2    2
```

## Release hygiene

```
ddev composer validate        → ./composer.json is valid
ddev drush config:status      → No differences between DB and sync directory
phpunit MelReadinessHelperCustomerTest → OK (3 tests, 22 assertions)
phpcs ProUpgradeRedirectSubscriber.php → clean
git diff --stat → 4 files +30/−13, +1 new subscriber
regression: dashboard 200, insights/attendees 200, home(anon) 200, search(anon) 200
```
