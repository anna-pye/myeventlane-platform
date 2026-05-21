# MEL Product Reset Phase 1 — Deferred

Items intentionally out of scope for Phase 1 foundation. Revisit in Phase 2+.

| Item | Reason |
|------|--------|
| **Online events quick filter** | No `page_online` (or equivalent) route in `upcoming_events`; `field_event_type` filter exists but no public chip path — add display + path in a config slice, not copy-only. |
| **New discovery search engine** | Search API + Views exposed filters already exist; no parallel engine. |
| **Rebuild Event Studio save/publish** | `EventStudioSaveService` and publish controllers are stable; guided shell only. |
| **Stripe / Commerce checkout changes** | Payment flow, panes, webhooks unchanged per Phase 1 rules. |
| **Second vendor dashboard** | `VendorDashboardViewModelBuilder` already provides `attention_events` and `action_queue`. |
| **Waitlist UX overhaul** | Waitlist CTA exists via `BookingFlowResolver` / partial; no new waitlist service. |
| **Merge sidebar carousel branch** | Active on `feature/sidebar-carousel-convergence`; merge before event full page structural changes. |
| **Views `all_events` removal** | Deprecated but still in config; cleanup is config hygiene, not product reset. |
| **Public “Create your event” CTA** | Requires authenticated vendor route; empty states link to browse/help only unless marketing route is defined. |
| **Event card redesign** | `mel-event-card.html.twig` already encodes date, location, mode, single CTA — further density changes are Phase 2. |
| **Admin dashboard product reset** | Phase 1 targets public discovery, event page, studio, checkout, vendor organiser dashboard only. |
