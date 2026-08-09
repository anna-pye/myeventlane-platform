# MyEventLane Check-in retirement

This directory and its empty extension are retained temporarily while active
environments shed role configuration dependencies on `myeventlane_checkin`.
Keep the shim enabled during this transition: uninstalling an extension in the
same configuration import can delete and recreate dependent roles, removing
existing user-role assignments.

The canonical organiser check-in surface is Door Mode, owned by
`myeventlane_event_attendees` and implemented through
`myeventlane_checkout_flow`. The former page, list and scan route names remain
as access-controlled redirects in `myeventlane_event_attendees.routing.yml`.

The legacy toggle and search mutation surfaces are intentionally not retained.
Do not enable this compatibility shim on new installations. Disabling and
physical removal are separate release gates after every active environment has
imported the cleaned role configuration.
