# MyEventLane Check-in retirement

This directory is retained temporarily so deployments can uninstall the former
`myeventlane_checkin` extension cleanly.

The canonical organiser check-in surface is Door Mode, owned by
`myeventlane_event_attendees` and implemented through
`myeventlane_checkout_flow`. The former page, list and scan route names remain
as access-controlled redirects in `myeventlane_event_attendees.routing.yml`.

The legacy toggle and search mutation surfaces are intentionally not retained.
Do not enable this compatibility shim on new installations. Physical removal
is a separate release gate after staging acceptance.
