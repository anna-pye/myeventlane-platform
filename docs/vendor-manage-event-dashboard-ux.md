# Vendor Manage event dashboard UX

Canonical route: `/vendor/events/{event}` (`myeventlane_vendor.console.event_workspace`).

## Purpose

Mission-control dashboard for organisers: current event state, next best actions, and compact sales/readiness signals without a long stacked scroll.

## Page structure (top to bottom)

1. **Compact hero** — status chip, title, date, booking type, readiness %; primary **Edit event**, secondary **Preview public event**, tertiary **Advanced ticket manager**.
2. **Today's focus** — one state-aware message and up to three CTAs (edit tickets, view orders, merch, promote, preview) derived from publication state, readiness score, and booking activity.
3. **Sales snapshot** — compact metric cards (gross sales, tickets sold, check-ins, orders/RSVPs as applicable).
4. **Merch & add-ons** (configured summary card) — what products exist, visibility, stock signals, top 3 configured products, CTA to Studio extras. Empty state when none configured.
5. **Ticket sales** (collapsed `<details>`) — compact table: Ticket | Sold | Remaining | Status. Attendance capacity only; not merged with merch/add-on stock.
6. **Merch & add-on sales** (collapsed `<details>`) — summary metrics + category rows; **Operational documents** mini-card (packing/parking/labels/export — coming soon). Tickets excluded.
7. **Manage this event** — grouped action grid: Sales, Setup, Growth.
8. **Operational readiness** — four grouped accordions (public visibility, booking setup, day-of, event content). Issue groups open by default; all-ready shows compact summary. **Show all readiness checks** expands the legacy checklist.
9. **Event guidance** — collapsed lifecycle recommendations (formerly full-page lifecycle section).

## Event management sub-route styling

Routes linked from **Manage this event** share `mel_event_workspace` shell:

- Compact header: event title, type/date/status chips (`workspace-header.html.twig`)
- **Back to Manage event** → `/vendor/events/{event}`
- Wrapper: `.mel-event-management-shell` + `.mel-workspace__content--event-management` (max-width, card/table overflow)

Applied to: orders, attendees, check-in/door, analytics, tickets, Studio extras (Studio uses its own shell; link back via topbar).

Legacy `/vendor/events/{event}/overview` redirects; tab label is **Manage event** (not Overview).

## Overview → Manage event

| Location | Label |
|----------|--------|
| Workspace tab key `overview` | Manage event |
| `myeventlane_vendor.links.task.yml` | Manage event |
| Sub-route back link | Back to Manage event |
| Legacy overview route title | Manage event |

Unchanged: vendor dashboard “Overview”, admin platform Overview, Pro overview, Studio API `saveOverview` field names.

Presentation alerts (ticket/pricing setup) remain above the fold when present.

## What belongs where

| Surface | Responsibility |
|--------|----------------|
| **Manage event** (`/vendor/events/{event}`) | State, focus actions, sales snapshot, compact ticket sales, **merch & add-on sales monitoring**, readiness summary, shortcuts |
| **Tickets** (`/vendor/events/{event}/tickets`, studio tickets) | Ticket types, pricing, capacity configuration |
| **Merch & add-ons** (`/vendor/events/{node}/studio/extras`) | Operational extras stock and product editor only |
| **Orders / attendees / check-in** | Linked from action grid; no Commerce admin routes |

## Why readiness is collapsed

Detailed readiness and lifecycle copy duplicated hero signals and pushed metrics below the fold. Grouped `<details>` panels keep keyboard-accessible expansion, show issues only where needed, and preserve the full checklist under **Show all readiness checks**.

## Implementation map

| Layer | File |
|-------|------|
| Route | `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml` |
| Controller | `web/modules/custom/myeventlane_vendor/src/Controller/EventWorkspaceController.php` |
| View model | `web/modules/custom/myeventlane_vendor/src/Service/VendorEventWorkspaceViewModelBuilder.php` |
| Template | `web/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig` |
| Styles | `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_event-mission-control.scss` |
| Ticket panel | `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-ticket-sales-panel.html.twig` |
| Extras sales panel | `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-extras-sales-panel.html.twig` |
| Extras sales service | `web/modules/custom/myeventlane_commerce/src/Service/EventOperationalExtrasSalesSummaryBuilder.php` |
| Sales monitoring docs | `docs/vendor-commerce-sales-monitoring.md` |
| Operational documents (plan) | `docs/vendor-operational-documents.md` |

## Manual QA checklist

- [ ] Open `/vendor/events/{nid}` — first screen shows hero, Today's focus, and sales snapshot without long scroll.
- [ ] Ticket sales panel appears on Manage event; compact columns when expanded.
- [ ] Merch & add-on sales panel appears separately; categories show when data exists.
- [ ] Workspace tab label reads **Manage event** (not Overview).
- [ ] Sub-routes show **Back to Manage event** linking to `/vendor/events/{event}`.
- [ ] `/vendor/events/{nid}/studio/extras` does **not** show full ticket sales table.
- [ ] Action grid links: orders, attendees, check-in, edit event, edit tickets, merch, promote, analytics.
- [ ] Readiness groups collapse when all ready; issue group opens automatically.
- [ ] Event guidance expands on click only.
- [ ] Mobile: focus before sales; tap targets ≥ 44px; accordions usable with keyboard.
- [ ] No raw Commerce admin URLs exposed.
