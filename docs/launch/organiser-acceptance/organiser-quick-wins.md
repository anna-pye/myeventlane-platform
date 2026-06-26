# Organiser Quick Wins

Small, isolated, high-leverage changes. Each is Drupal 11 / Commerce 3 safe and does not change
architecture. **Not implemented in this programme** — the acceptance policy permits only verified,
small, isolated edits, and two of these touch shared/uncertain code paths (see notes). They are
hand-off-ready for a focused implementation PR.

## QW-1 — Pro upgrade CTA at lock points (OB-3)
- **Now:** non-Pro hitting `/vendor/pro/manage`, `/vendor/analytics`, `/vendor/settings/branding`
  sees "This area is invite-only" / generic "Access denied".
- **Change:** when a route is denied specifically by the Pro gate (`_myeventlane_pro_access`),
  return a branded "Upgrade to Pro" response (value bullets + CTA → `/vendor/pro/subscribe`) instead
  of the shared 403.
- **Why deferred:** the 403 page is shared with genuinely invite-only routes — implement as a
  Pro-gate-specific response, **not** a global 403 copy edit. Verify the access-check can emit a
  redirect/custom response.
- **Impact:** direct Pro conversion at peak intent.
- **Acceptance:** non-Pro on a Pro route → "Upgrade to Pro" screen with working CTA; invite-only
  routes unchanged.

## QW-2 — Restore "Message attendees" (OB-2)
- **Now:** `/vendor/events/{id}/comms` → 404 for a published, owned event.
- **Change:** repair/relink the comms route (likely orphaned during Studio consolidation) and surface
  it from the Studio attendees/overview tab.
- **Why deferred:** root cause (route relocation vs controller bug) needs a short trace first —
  verify before editing.
- **Impact:** restores a table-stakes day-of capability.
- **Acceptance:** organiser opens and sends an attendee update for an owned published event (200).

## QW-3 — Dashboard page-level h1 (OB-6)
- **Now:** `/vendor/dashboard` heading order starts at section h2s; no clear page `<h1>`.
- **Change:** add one semantic `<h1>` (e.g., "Organiser dashboard") in the dashboard template/shell.
- **Why safe:** isolated template change, presentation-only.
- **Impact:** a11y + screen-reader orientation.
- **Acceptance:** exactly one `<h1>`; heading hierarchy validates; visual layout unchanged.

## QW-4 — Retire the dead "vendor insights" entry (part of OB-4)
- **Now:** `/vendor/insights` returns 403 "invite-only" even for Pro; confuses vs `/vendor/analytics`.
- **Change:** remove/redirect the `myeventlane_reporting.vendor_insights` organiser entry to
  `/vendor/analytics` (or hide its nav link) so there is one analytics home.
- **Why deferred:** confirm nothing else links to it; pair with OB-1 insights fix decision.
- **Acceptance:** no organiser-facing dead analytics link; single analytics entry point.

> Note: **QW-1, QW-2, QW-4 are not single-line/isolated** (shared 403, route relocation, nav/redirect
> wiring). Per the implementation policy they were documented, not applied. **QW-3** is the only truly
> isolated one and is safe to apply directly in a follow-up.
