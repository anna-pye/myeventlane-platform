# VX2-09 — Marketing Hub (Event Growth Centre)

**Epic:** VX2-09 Marketing  
**Branch:** `feature/vx2-marketing`  
**Date:** 2026-07-24

## What shipped

- Organiser **Marketing** hub at `/vendor/marketing` (Event Growth Centre)
- Sections: Marketing Health · Share Event · Boost · Widgets & Embeds · Social Media · Audience Growth · Marketing Performance · Recommended Actions
- Shell nav **Marketing** points at the hub (was `/vendor/boost`)
- `/vendor/boost` redirects to `/vendor/marketing#boost`
- Event Workspace Marketing deepened (share channels, widgets, Boost, Marketing home link)
- Overview / next-action “Promote” → **Open Marketing** / **Share**
- Analytics “Open Marketing” CTA → Marketing hub
- AU English, warm, community-first; Boost remains the premium product name

## Architecture

```text
/vendor/marketing  ← VendorMarketingHubController
  └─ VendorMarketingHubBuilder
       ├─ TicketSalesService (managed events + bookings)
       ├─ DomainDetector (public URLs)
       ├─ optional BoostManager / BoostHelpContent
       ├─ optional EventStateResolver
       └─ optional QrCodeGenerator (share QR from public URL)
```

Convergence redirects:

```text
/vendor/boost                        → /vendor/marketing#boost
/vendor/event/{id}/promote           → /vendor/events/{id}/studio/marketing
```

## Boost model

- Boost stays a guided premium purchase (wizard unchanged)
- Hub explains what / who / expected outcome without overselling
- Active campaigns + eligible events listed with deep links
- Performance export deep-links existing CSV routes

## Growth strategy (in-product)

1. Publish → share public link / QR / social  
2. Optional Boost for discovery on MyEventLane  
3. Widgets for owned websites  
4. Audience page for return guests  
5. Analytics for deeper pulse (not invented here)

## Instrumentation (documented; logger + data attributes)

| Event | Where | Pipeline |
| --- | --- | --- |
| `marketing_opened` | Hub builder logger + Twig | Deferred collector |
| `share_link_copied` | Copy buttons + Twig attrs | Deferred collector |
| `share_channel_selected` | Social / email links | Deferred collector |
| `boost_started` | Boost CTAs | Deferred collector |
| `boost_completed` | Existing Boost purchase success (unchanged) | Existing |
| `widget_copied` | Widgets entry CTAs | Deferred collector |

Do not invent new telemetry tables in this epic.

## Accessibility

- Jump nav + section headings
- 44px share / CTA targets
- QR `alt` text names the event
- Copy status via `aria-live="polite"`
- Focus-visible rings; `prefers-reduced-motion` respected

## Manual QA checklist

- [ ] New organiser — no live events health + publish CTA
- [ ] Published event — public link, copy, QR, social channels
- [ ] Boost eligible — Start Boost deep link
- [ ] Active Boost — campaign status + end date
- [ ] Widgets entry opens Tickets widgets
- [ ] `/vendor/boost` redirects to `#boost`
- [ ] Analytics Open Marketing → hub
- [ ] Desktop / tablet / 390px
- [ ] Keyboard through jump → health → share → Boost
- [ ] Screen reader: health region, score, QR alt, copy status

## Inventory

See `docs/implementation/vx2-09-marketing-surface-inventory.md`.

## Remaining roadmap

- Wire product analytics collector for documented events
- Page views / traffic sources / conversion on Marketing home (instrumentation)
- Optional hide duplicate Boost local task after bake-in
- Optional redirect `/event/{id}/boost` → vendor workspace Boost
- Audience export route fix (dead `/vendor/audience/export` noted in inventory)
