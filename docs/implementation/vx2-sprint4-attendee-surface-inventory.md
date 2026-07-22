# VX2 Sprint 4 — Attendee surface inventory

**Branch:** `feature/vx2-attendee-workspace`  
**Authority:** Vendor Experience Convergence (VX2-05 / B7 / B8)  
**Date:** 2026-07-22

Organisers must think **Attendees** only — not RSVP list, Ticket holders, Orders, Waitlist, Check-in module, or Door module as separate products.

| Surface | Path / route | Purpose today | Duplicate? | Disposition | Future location |
| --- | --- | --- | --- | --- | --- |
| Event guest list | `/vendor/events/{id}/attendees` (`vendor_list`) | Full guest list (staff/uid1); organisers redirected to Studio | Partial vs Studio metrics | **Redirect** organisers → Workspace Attendees (keep route for staff + deep links) | **Attendees** |
| Studio Attendees | `/vendor/events/{id}/studio/attendees` | Metrics-only (pre–Sprint 4) | Thin duplicate of guest list | **Merge** — become One Attendee Workspace | **Attendees** (canonical) |
| Global Attendees hub | `/vendor/attendees` | Cross-event hub | Complements event list | **Keep** | Global **Attendees** |
| RSVPs console | `/vendor/events/{id}/rsvps` | RSVP-only list | Yes vs Attendees filter | **Redirect** → Attendees `?filter=rsvp` | Attendees · RSVP filter |
| Legacy Manage RSVPs | `/vendor/event/{id}/rsvps` | Singular RSVP list | Yes | **Redirect** → Attendees | Attendees |
| Waitlist manage | `/vendor/event/{id}/waitlist` | Waitlist-only admin | Yes | **Redirect** → Attendees `?filter=waitlist` | Attendees · Waitlist filter |
| Door Mode hub | `/vendor/events/{id}/operations` | Venue ops + metrics | Sibling of door | **Keep** as Attendees mode entry | Attendees · Door Mode |
| Door Mode scanner | `/vendor/events/{id}/operations/door` | Canonical check-in | Target | **Keep** (canonical) | Attendees · Door Mode |
| Legacy check-in page | `/vendor/events/{id}/check-in` | Parallel UI | Yes | **Redirect** → Door Mode | Door Mode |
| Legacy check-in list | `/vendor/events/{id}/check-in/list` | Parallel list | Yes | **Redirect** → Door Mode | Door Mode |
| Legacy check-in scan | `/vendor/events/{id}/check-in/scan` | Already 302 → Door | — | **Keep** redirect | Door Mode |
| Ticket check-in form | `/event/{id}/tickets/checkin` | Organiser ticket check-in | Yes | **Redirect** organiser GET → Door Mode; APIs retained | Door Mode |
| RSVP check-in / QR | `/vendor/event/{id}/rsvps/checkin`, `/scan` | RSVP-only door | Yes | **Redirect** → Door Mode | Door Mode |
| Export (canonical) | `/vendor/events/{id}/attendees/export` | CSV via MelAttendeeExportBuilder | — | **Keep** — label “Export attendees” | Attendees · Export |
| Views / paragraph / RSVP / waitlist CSV | various | Parallel exporters | Yes | **Merge** entry points; keep async backends where present | Attendees · Export |
| Message attendees (Pro) | `/vendor/events/{id}/message` | Blast compose | Parallel vs Studio Messages | **Keep** writer; CTA from Attendees | Messages (VX2-06) + Attendees entry |
| Studio Messages | `/studio/messages` | Informational | Placeholder | **Keep** until VX2-06 | Messages |
| Refund form | `/vendor/orders/{order}/refund` | Order refund | — | **Keep**; entry from attendee card when order exists | Payments + Attendees quick action |
| Order detail attendees | `/vendor/events/{id}/orders/{order}` | Order ↔ guests | Complementary | **Keep** bi-link | Orders |
| Attendance analytics | Insights / Analytics / ticket check-in analytics | Reporting | Overlaps | **Keep** under Analytics; soft-link from Attendees | Analytics |
| Vendor API attendees | `/api/v1/vendor/events/{id}/attendees` | API twin | — | **Keep** | API |

## Mental model (after Sprint 4)

```text
Attendees
  ↓ Search
  ↓ Filters (ticket type / RSVP / waitlist / checked in / not checked in / refunded / cancelled)
  ↓ Guest list (cards; dense table for large events)
  ↓ Actions (View · Message · Refund · Check in · Door Mode · Export)
```
