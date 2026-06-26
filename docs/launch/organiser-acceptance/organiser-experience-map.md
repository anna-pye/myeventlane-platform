# Organiser Experience Map

End-to-end first-time-organiser journey with verified routes, transitions, and friction points.
`→` = verified redirect/transition (authenticated probe). 🟢 strong · 🟡 friction · 🔴 defect.

```
DISCOVER / SIGN UP
  /user/register  →  /vendor/onboard  → 302 /vendor/onboard/profile        🟢 resume-aware
        │
BECOME A VENDOR (guided, resumable)
  /vendor/onboard/profile → account → stripe → branding → first-event → boost → complete   🟢
        │                              │
        │                     CONNECT STRIPE  /vendor/stripe/connect + callback              🟢
        ▼
CREATE EVENT (canonical front door)
  /create-event  → 302 /vendor/events/{id}/edit  → Event Studio                              🟢
        │
EVENT STUDIO (canonical; legacy routes redirect IN)
  /vendor/events/{id}/overview  → 302 …/studio
  …/tickets   → 302 …/studio/tickets      (create ticket type, add-ticket modal)             🟢
  …/attendees → 302 …/studio/attendees    (list, export CSV)                                  🟢
  …/orders    → 302 …/studio/orders                                                           🟢
  …/settings  → 302 …/studio/settings                                                         🟢
  …/publish   /submit-review              (single 'editorial' workflow)                       🟢
        │
PROMOTE
  /vendor/events/{id}/boost/wizard → step-1..5 → pay                                          🟢
  /vendor/events/{id}/comms                                   → 🔴 404 (OD-2) attendee msg
        │
SELL  (customer checkout verified separately — money/refund/webhook PASS)
        │
EVENT DAY
  /vendor/events/{id}/check-in   (200) + PWA offline + QR scan + search + toggle             🟢
  capacity / waitlist                                                                         🟢
  message attendees                                            → 🔴 404 (OD-2)
        │
MANAGE / REFUND
  /vendor/events/{id}/refund-requests (200) approve/reject ; buyer flow guarded              🟢
        │
MONITOR
  /vendor/dashboard  (free): Revenue · Tickets sold · Attendees · Needs attention · Next     🟢
  /vendor/payouts    (200, refund-netted)                                                     🟢
  /vendor/analytics  (Pro 200 / non-Pro 403)                                                  🟡 deep analytics Pro-gated
  /vendor/events/{id}/insights/*                              → 🔴 500 (OD-1)
  /vendor/insights                                            → 🟡 403 invite-only (OD-4)
        │
GROW / PRO
  /vendor/pro (200 marketing) → /vendor/pro/subscribe → /success                              🟢
  Pro lock points (non-Pro)   → 🟡 "invite-only"/"Access denied", no upgrade CTA (OD-3)
        │
SUPPORT
  /vendor/settings (200) · /vendor/help → /help · /vendor/support · notifications             🟢
```

## Friction summary along the journey

| Stage | State | Issue |
| --- | --- | --- |
| Sign up → onboard → Stripe | 🟢 | Smooth, guided, resumable |
| Create → Studio → tickets → publish | 🟢 | Canonical, consolidated |
| Promote (Boost) | 🟢 | Working 5-step wizard |
| Promote/Day-of (Message attendees) | 🔴 | OD-2 404 |
| Event day (check-in) | 🟢 | Launch-grade, PWA/QR |
| Refunds | 🟢 | Guarded, verified |
| Monitor (dashboard/payouts) | 🟢 | Free KPIs + accurate payouts |
| Monitor (event insights) | 🔴 | OD-1 500 |
| Monitor (vendor insights) | 🟡 | OD-4 restricted duplicate |
| Pro conversion at lock points | 🟡 | OD-3 no upgrade CTA |

**Net:** the spine of the journey (sign-up → sell → run → get paid) is 🟢 end-to-end. The 🔴/🟡
points are off-spine analytics/messaging/Pro-conversion surfaces — important, but not blocking the
core ability to run and monetise an event.
