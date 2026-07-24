# VX2 — Event Workspace Home compositional redesign

**Status:** Implemented  
**Date:** 2026-07-22  
**Branch:** `feature/vx2-workspace-layout-convergence` (or current Home redesign branch)  
**Principle:** This is a compositional redesign, not a spacing update.

## Product decisions (locked)

| Decision | Choice |
| --- | --- |
| Shell | **1C** — Topbar + Boost banner only when Boost is active |
| Activity | **2B** — Recent bookings / sales / orders; messages later |
| Nav label | **Home** (machine id `overview` unchanged) |
| Health card | **Event Ready** (not Event Status) |
| KPI cards | Operational summaries with CTAs — not navigation dumps |
| Readiness | Compact summary + expandable checklist |

## Before → after

**Before:** Topbar → Event Health → Readiness strip → Homepage readiness → Boost → stacked Overview (full checklist + equal cards + Jump to).

**After:** Topbar → Boost (if active) → Home rows:

1. Event Ready | Next recommended action  
2. Compact expandable readiness  
3. Tickets | Attendees  
4. Sales | Marketing | Boost | Analytics  
5. Activity timeline  

## Files

- `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-workspace.html.twig`
- `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-overview.html.twig`
- `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-section.html.twig`
- `web/modules/custom/myeventlane_event_studio/src/Service/EventWorkspaceOverviewBuilder.php`
- `web/modules/custom/myeventlane_event_studio/src/Plugin/EventStudioSection/OverviewSection.php`
- `web/modules/custom/myeventlane_event_studio/css/mel-event-studio-shell.css`
- `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.services.yml`
- `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.module`

## Metrics policy

Only confirmed repository metrics: ticket types, sold, remaining (when capacity is numeric), booked/check-ins, waitlist (when waitlist service available), gross/orders, Boost active, homepage featured (`field_promoted`), conversion when capacity is finite. No invented page views.

## Follow-ups

- Activity: messages + system changes  
- Facebook shared signal if product confirms a source  
- Align Convergence docs IA “Overview” → “Home” wording across the pack  
- Visual QA screenshots desktop / ultra-wide / tablet / 390px  
