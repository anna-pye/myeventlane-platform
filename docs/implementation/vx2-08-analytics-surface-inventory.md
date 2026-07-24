# VX2-08 — Analytics surface inventory (Stage 1)

**Date:** 2026-07-24  
**Branch:** `feature/vx2-analytics`  
**Authority:** MEL Product System · Vendor Experience Convergence · Screen Spec A8/B12/F

## Disposition map

| Surface | Route / path | Disposition | Notes |
| --- | --- | --- | --- |
| Account Analytics hub | `myeventlane_analytics.dashboard` `/vendor/analytics` | **KEEP** | Canonical Event Intelligence Centre; free pulse unlocked |
| Studio event Analytics | `myeventlane_event_studio.workspace_analytics` | **KEEP** | Free per-event pulse; primary Event Analytics CTA |
| Deep event Analytics | `myeventlane_analytics.event` | **KEEP** (Pro) | Longer trends / charts; upgrade CTA when locked |
| PDF / Excel export | `…export_pdf` / `…export_excel` | **KEEP** (Pro) | Linked from Pro depth + event Analytics |
| Vendor Insights | `/vendor/insights` | **REDIRECT** | → `/vendor/analytics` |
| Event Insights tabs | `/vendor/events/{id}/insights*` | **REDIRECT** | → Studio Analytics |
| Export Centre list | `/vendor/exports` | **REDIRECT** | → `/vendor/analytics#exports` |
| Charts JSON APIs | `/vendor/charts/*` | **HIDE** (product) / **KEEP** (Pro API) | Not a navigable product |
| Dashboard KPI strip | `/vendor/dashboard` | **KEEP** | Feeder only — not a second Analytics product |
| Console Growth Analytics | `/vendor/events/{id}/analytics` | **REDIRECT** (existing) | Already → Studio Analytics |
| Audience page | `/vendor/audience` | **HIDE** (shell) | Nest under Analytics/Marketing later |
| Boost metric panels | Boost + embedded analytics | **MERGE** | Shown as Marketing pulse on hub |
| Support Analytics | `/vendor/support/analytics` | **KEEP** | Support product — out of A8 scope |
| Check-in analytics local task | tickets module | **HIDE** as product | Door Mode / Attendees owns ops metrics |
| Refund analytics card | event overview | **KEEP** | Payments/overview ownership |

## Product language

| Retire as product name | Use |
| --- | --- |
| Insights | Analytics |
| Charts | Analytics (charts as UI pattern only) |
| Reports / Reporting (organiser) | Analytics |
| Export Centre | Exports (inside Analytics) |

## Free vs Pro

| Free | Pro |
| --- | --- |
| Business health | Longer-range trends |
| Launch readiness | Comparisons |
| Event pulse rows | PDF / spreadsheet exports |
| Simple sections + recent activity | Advanced segmentation |
| Next recommended action | Historical analysis depth |

**Never bare 403** on `/vendor/analytics`. Pro depth explains value + upgrade.

## Launch Readiness signals (existing data only)

| Signal | Source |
| --- | --- |
| Tickets configured | Event domain state (`has_product` / RSVP) |
| Stripe connected | `VendorPaymentsHealthService` |
| Messages ready | Vendor `field_msg_from_name` or vendor name |
| Door Mode ready | Tickets/RSVP present (Door Mode entry exists) |
| Refunds awaiting | Escalations refunds metrics (when module present) — same pending formula + `vendor_summary_window_days` as Payments hub |
| Publishing issues | Draft event count |

## Missing collectors (document only — do not invent)

| Success metric event | Status |
| --- | --- |
| `analytics_viewed` | Logger + `data-mel-analytics-event` on hub |
| `pro_upgrade_clicked` | Twig data attribute on upgrade CTAs |
| `pro_upgrade_completed` | Existing Pro subscribe paths (unchanged) |

Pipeline wiring to a product analytics collector remains deferred (same pattern as VX2-06/07).
