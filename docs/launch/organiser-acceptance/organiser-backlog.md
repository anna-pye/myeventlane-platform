# Organiser Acceptance — Backlog

Every item: business impact · customer (organiser) impact · complexity · risk · effort ·
acceptance criteria. Priorities: **P0** blocker · **P1** pre-launch · **P2** fast-follow ·
**P3** future. IDs map to `organiser-acceptance.md` §11 where prefixed `OD-`.

## P0 — Launch blockers
**None.** No organiser task is blocked by a money, security, or access-integrity defect. (Payment,
refund, payout, webhook integrity verified in `docs/launch/customer-verification/`.)

## P1 — Pre-launch

### OB-1 (OD-1) — Fix Event Insights 500
- **Business impact:** broken analytics surface undermines "professional platform" positioning.
- **Organiser impact:** `/vendor/events/{id}/insights/*` errors; organisers can't open event insights.
- **Complexity:** medium (DI instantiation fallback — constructor/create/services all *look* correct; needs developer trace of why the controller is built with 0 args).
- **Risk:** low to fix; currently a hard 500.
- **Effort:** 0.5–1 day investigation + fix.
- **Acceptance:** `/vendor/events/{id}/insights/{overview,sales,attendees,checkins,traffic}` return 200 with data for an owner; no `ArgumentCountError` in dblog.

### OB-2 (OD-2) — Restore attendee messaging route
- **Business impact:** day-of communication is table-stakes vs Eventbrite/Humanitix.
- **Organiser impact:** `/vendor/events/{id}/comms` 404s for a published, owned event.
- **Complexity:** low–medium (orphaned/relocated route — likely moved during Studio consolidation).
- **Risk:** low.
- **Effort:** 0.5 day.
- **Acceptance:** organiser can open a "Message attendees" surface for an owned published event and send/queue an update; route resolves (200) and is linked from Studio.

### OB-3 (OD-3) — Upgrade CTA at Pro lock points
- **Business impact:** **direct revenue** — converts at the moment of highest intent. Currently lost.
- **Organiser impact:** non-Pro sees "This area is invite-only"/"Access denied" with no path forward.
- **Complexity:** low, but **not isolated** — the denial page is shared with genuinely invite-only routes; needs a Pro-aware denial variant rather than editing the shared 403.
- **Risk:** low if done as a dedicated Pro-gate response; medium if the shared 403 copy is changed globally.
- **Effort:** 0.5–1 day.
- **Acceptance:** non-Pro hitting a Pro-only route sees a branded "Upgrade to Pro" screen (value + CTA to `/vendor/pro/subscribe`), while truly invite-only routes keep their existing copy.

## P2 — Fast-follow

### OB-4 (OD-4) — Consolidate analytics surfaces
- Retire or fix `myeventlane_reporting` vendor insights (`/vendor/insights` 403 invite-only; `/insights/*` 500) so there is **one** analytics home (`/vendor/analytics` + dashboard).
- **Acceptance:** no organiser-facing 403/500 analytics route; single discoverable analytics entry.

### OB-5 (OD-5) — Duplicate event
- Add organiser "Duplicate event" (clone node + tickets) — parity with Eventbrite/Humanitix.
- **Acceptance:** organiser can duplicate an event into a new draft with tickets copied, dates cleared.

### OB-6 (OD-6) — Dashboard page-level h1
- Add a single semantic `<h1>` to `/vendor/dashboard` (heading order currently starts at h2).
- **Acceptance:** dashboard has exactly one `<h1>`; heading hierarchy validates.

### OB-7 (OD-7) — Resolve recurring booking-availability error
- Investigate repeated `Could not resolve paid booking availability for event 1755: … headers already sent` (cron + render context).
- **Acceptance:** error no longer logged on cron/render; booking availability resolves cleanly.

### OB-8 — Per-tab empty states (organiser)
- Verify/standardise empty states across Studio tabs (tickets, attendees, orders) — **Unable to verify** in this pass.
- **Acceptance:** each empty tab shows a branded, action-oriented empty state.

## P3 — Future enhancement
- **OB-9** — "Manage Pro" view for existing members at `/vendor/pro` (currently shows marketing page to Pro members).
- **OB-10** — Organiser deep-links/shortcuts (e.g., dashboard → most-urgent action) and keyboard shortcuts in Studio.

## Cross-cutting verification (not defects — must be closed before ≥9.5)
- **OV-1** WCAG 2.1 AA pass on organiser critical path (onboarding → create → tickets → publish → check-in): keyboard, screen reader, contrast, focus, touch targets. **Unable to verify** here.
- **OV-2** On-device mobile completion of every critical organiser task (Studio edit, attendees, check-in, messaging, analytics, payouts). **Unable to verify** here.
- **OV-3** Per-tab organiser empty/loading/error states. **Unable to verify** here.
